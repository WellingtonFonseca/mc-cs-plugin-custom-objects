<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;
use MauticPlugin\CustomObjectsBundle\Migrations\Version_0_0_12;
use MauticPlugin\CustomObjectsBundle\Migrations\Version_0_0_29;
use PHPUnit\Framework\TestCase;

class FulltextIndexMigrationsTest extends TestCase
{
    public function testVersion0029DoesNothingWhenAllFulltextIndexesExist(): void
    {
        $this->assertSame([], $this->runMigration(Version_0_0_29::class, '', $this->buildSchema('', true)));
    }

    public function testVersion0029AddsAllMissingFulltextIndexes(): void
    {
        $sql = $this->runMigration(Version_0_0_29::class, '', $this->buildSchema('', false));

        $this->assertSame([
            'ALTER TABLE custom_item ADD FULLTEXT INDEX name_fulltext (name)',
            'ALTER TABLE custom_field_value_text ADD FULLTEXT INDEX value_fulltext (value)',
            'ALTER TABLE custom_field_value_option ADD FULLTEXT INDEX value_fulltext (value)',
        ], $sql);
    }

    public function testVersion0029AddsOnlyTheMissingIndex(): void
    {
        $schema = $this->buildSchema('', true);
        $schema->getTable('custom_field_value_text')->dropIndex('value_fulltext');

        $this->assertSame(
            ['ALTER TABLE custom_field_value_text ADD FULLTEXT INDEX value_fulltext (value)'],
            $this->runMigration(Version_0_0_29::class, '', $schema)
        );
    }

    public function testVersion0029UsesTablePrefixForTablesAndIndexNames(): void
    {
        $sql = $this->runMigration(Version_0_0_29::class, 'mtc_', $this->buildSchema('mtc_', false));

        $this->assertSame([
            'ALTER TABLE mtc_custom_item ADD FULLTEXT INDEX mtc_name_fulltext (name)',
            'ALTER TABLE mtc_custom_field_value_text ADD FULLTEXT INDEX mtc_value_fulltext (value)',
            'ALTER TABLE mtc_custom_field_value_option ADD FULLTEXT INDEX mtc_value_fulltext (value)',
        ], $sql);
    }

    public function testVersion0029SkipsWhenTablesDoNotExist(): void
    {
        $this->assertSame([], $this->runMigration(Version_0_0_29::class, '', new Schema()));
    }

    /**
     * With an empty prefix the legacy index name equals the expected one, so 0.0.12 must not drop it
     * (it used to drop it without re-adding it, which left the tables without FULLTEXT indexes).
     */
    public function testVersion0012DoesNotDropExistingIndexesWithEmptyPrefix(): void
    {
        $this->assertSame([], $this->runMigration(Version_0_0_12::class, '', $this->buildSchema('', true)));
    }

    public function testVersion0012StillReplacesLegacyIndexNamesWhenPrefixIsSet(): void
    {
        $schema = $this->buildSchema('mtc_', false);
        $schema->getTable('mtc_custom_item')->addIndex(['name'], 'name_fulltext', ['fulltext']);

        $sql = $this->runMigration(Version_0_0_12::class, 'mtc_', $schema);

        $this->assertStringContainsString('DROP INDEX name_fulltext', $sql[0]);
        $this->assertStringContainsString('ADD FULLTEXT INDEX mtc_name_fulltext (name)', $sql[0]);
    }

    /**
     * @param class-string<AbstractMigration> $class
     *
     * @return string[]
     */
    private function runMigration(string $class, string $prefix, Schema $schema): array
    {
        $executed      = [];
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('introspectSchema')->willReturn($schema);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('prepare')->willReturnCallback(function (string $sql) use (&$executed) {
            $executed[] = trim(preg_replace('/\s+/', ' ', $sql));
            $statement = $this->createMock(Statement::class);

            return $statement;
        });

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $migration = new $class($entityManager, $prefix);

        if ($migration->shouldExecute()) {
            $migration->execute();
        }

        return $executed;
    }

    private function buildSchema(string $prefix, bool $withFulltext): Schema
    {
        $schema = new Schema();

        $item = $schema->createTable($prefix.'custom_item');
        $item->addColumn('name', Types::STRING);

        $text = $schema->createTable($prefix.'custom_field_value_text');
        $text->addColumn('value', Types::TEXT);

        $option = $schema->createTable($prefix.'custom_field_value_option');
        $option->addColumn('id', Types::BIGINT);
        $option->addColumn('value', Types::STRING);
        $option->setPrimaryKey(['id']);
        $option->addUniqueIndex(['value'], $prefix.'unique');

        if ($withFulltext) {
            $item->addIndex(['name'], $prefix.'name_fulltext', ['fulltext']);
            $text->addIndex(['value'], $prefix.'value_fulltext', ['fulltext']);
            $option->addIndex(['value'], $prefix.'value_fulltext', ['fulltext']);
        }

        return $schema;
    }
}
