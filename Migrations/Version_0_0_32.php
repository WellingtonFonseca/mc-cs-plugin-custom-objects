<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Migrations;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Makes custom_object.alias unique (the plain index on alias, under any name, becomes the unique index `alias_unique`), so that two creates of one alias
 * cannot both succeed. It never deduplicates and never deletes: with duplicate aliases it stops BEFORE any SQL, naming them. The comparison
 * is the one of the column collation (utf8mb4_unicode_ci: 'Curso', 'curso' and 'cúrso' are the same alias). MySQL only; the DDL commits.
 */
class Version_0_0_32 extends AbstractMigration
{
    private string $table = 'custom_object';

    private string $uniqueIndex = 'alias_unique';

    protected function isApplicable(Schema $schema): bool
    {
        try {
            $indexes = $schema->getTable($this->concatPrefix($this->table))->getIndexes();
        } catch (SchemaException) {
            return false;
        }

        // by the COLUMNS, not by the name: a unique index on alias under any name is the goal already (a second one would be redundant)
        foreach ($indexes as $index) {
            if ($this->isUniqueOnAlias($index)) {
                return false;
            }
        }

        return true;
    }

    protected function up(): void
    {
        $table      = $this->concatPrefix($this->table);
        $duplicates = $this->findDuplicates($table);
        if ([] !== $duplicates) {
            throw new \RuntimeException(sprintf(
                'Cannot make %s.alias unique: these aliases are used by more than one Custom Object (alias: ids): %s. Nothing was changed. Resolve them first (rename or delete the extra objects), then run mautic:plugins:reload again.',
                $table,
                implode('; ', $duplicates)
            ));
        }

        // every plain single-column index on alias goes (any name; a clean install has none), in the same ALTER that adds the unique one,
        // so the table is never left without an index
        $drops = array_map(
            static fn (Index $index): string => sprintf('DROP INDEX %s, ', $index->getName()),
            $this->plainAliasIndexes($table)
        );

        $this->addSql(sprintf('ALTER TABLE %s %sADD UNIQUE INDEX %s (alias)', $table, implode('', $drops), $this->concatPrefix($this->uniqueIndex)));
    }

    /**
     * @return string[] "alias: id,id" for each alias that more than one object has
     */
    private function findDuplicates(string $table): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            sprintf('SELECT MIN(alias) AS alias, GROUP_CONCAT(id ORDER BY id) AS ids, COUNT(*) AS total FROM %s GROUP BY alias HAVING COUNT(*) > 1', $table)
        );

        return array_map(static fn (array $row): string => sprintf('%s: %s', $row['alias'], $row['ids']), $rows);
    }

    /**
     * @return Index[]
     */
    private function plainAliasIndexes(string $table): array
    {
        $indexes = $this->entityManager->getConnection()->createSchemaManager()->listTableIndexes($table);

        return array_values(array_filter(
            $indexes,
            static fn (Index $index): bool => !$index->isPrimary() && !$index->isUnique() && ['alias'] === array_map('strtolower', $index->getColumns())
        ));
    }

    private function isUniqueOnAlias(Index $index): bool
    {
        return $index->isUnique() && !$index->isPrimary() && ['alias'] === array_map('strtolower', $index->getColumns());
    }
}
