<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use MauticPlugin\CustomObjectsBundle\Migrations\Version_0_0_32;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:co-objects')]
#[Group('risk:CRITICO')]
#[Group('risk:DADOS')]
class AliasUniqueIndexMigrationTest extends TestCase
{
    public function testReplacesThePlainIndexWithTheUniqueOneInASingleAlter(): void
    {
        $this->assertSame(
            ['ALTER TABLE custom_object DROP INDEX alias, ADD UNIQUE INDEX alias_unique (alias)'],
            $this->runMigration('', $this->buildSchema('', 'plain'))
        );
    }

    public function testUsesTablePrefixForTableAndIndexNames(): void
    {
        $this->assertSame(
            ['ALTER TABLE mtc_custom_object DROP INDEX mtc_alias, ADD UNIQUE INDEX mtc_alias_unique (alias)'],
            $this->runMigration('mtc_', $this->buildSchema('mtc_', 'plain'))
        );
    }

    public function testDoesNotDropAnOldIndexThatDoesNotExist(): void
    {
        $this->assertSame(
            ['ALTER TABLE custom_object ADD UNIQUE INDEX alias_unique (alias)'],
            $this->runMigration('', $this->buildSchema('', 'none'))
        );
    }

    public function testDropsAPlainIndexOnAliasWhateverItsName(): void
    {
        // an old install whose plain index has another name (the core may name or prefix it differently)
        $this->assertSame(
            ['ALTER TABLE custom_object DROP INDEX idx_custom_alias, ADD UNIQUE INDEX alias_unique (alias)'],
            $this->runMigration('', $this->buildSchema('', 'plain-other-name'))
        );
        $this->assertSame(
            ['ALTER TABLE mtc_custom_object DROP INDEX idx_custom_alias, ADD UNIQUE INDEX mtc_alias_unique (alias)'],
            $this->runMigration('mtc_', $this->buildSchema('mtc_', 'plain-other-name'))
        );
    }

    public function testDoesNotDropAnIndexThatHasMoreColumnsThanAlias(): void
    {
        $this->assertSame(
            ['ALTER TABLE custom_object ADD UNIQUE INDEX alias_unique (alias)'],
            $this->runMigration('', $this->buildSchema('', 'composite'))
        );
    }

    public function testDoesNothingWhenAUniqueIndexOnAliasExistsUnderAnotherName(): void
    {
        // decided by the columns, not the name: a second unique index would be redundant
        $this->assertSame([], $this->runMigration('', $this->buildSchema('', 'unique-other-name')));
        $this->assertSame([], $this->runMigration('mtc_', $this->buildSchema('mtc_', 'unique-other-name')));
    }

    public function testDoesNothingWhenTheUniqueIndexAlreadyExists(): void
    {
        $this->assertSame([], $this->runMigration('', $this->buildSchema('', 'unique')));
        $this->assertSame([], $this->runMigration('mtc_', $this->buildSchema('mtc_', 'unique')));
    }

    public function testDoesNothingWhenTheTableDoesNotExist(): void
    {
        $this->assertSame([], $this->runMigration('', new Schema()));
    }

    public function testDuplicatesStopTheMigrationNamingTheAliasAndTheIdsAndRunNoSql(): void
    {
        $executed = [];
        try {
            $this->runMigration('', $this->buildSchema('', 'plain'), [['alias' => 'curso', 'ids' => '3,9', 'total' => '2']], $executed);
            $this->fail('A duplicate alias must stop the migration');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('curso', $e->getMessage());
            $this->assertStringContainsString('3,9', $e->getMessage());
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
            $this->assertStringContainsString('Resolve them first', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('/resolva|atualizar/', $e->getMessage(), 'the message is English only');
        }

        $this->assertSame([], $executed, 'nothing may be altered or deleted when there are duplicates');
    }

    public function testTheDuplicateCheckReadsThePrefixedTableAndGroupsByAlias(): void
    {
        $queries = [];
        $this->runMigration('mtc_', $this->buildSchema('mtc_', 'plain'), [], $executed, $queries);

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('FROM mtc_custom_object', $queries[0]);
        $this->assertStringContainsString('GROUP BY alias', $queries[0]);
        $this->assertStringContainsString('HAVING COUNT(*) > 1', $queries[0]);
    }

    /**
     * @param mixed[][]     $duplicates what the duplicate check finds
     * @param string[]|null $executed   filled with the SQL that ran
     * @param string[]|null $queries    filled with the SELECTs of the duplicate check
     *
     * @return string[] the SQL that ran
     */
    private function runMigration(string $prefix, Schema $schema, array $duplicates = [], ?array &$executed = null, ?array &$queries = null): array
    {
        $executed      = [];
        $queries       = [];
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('introspectSchema')->willReturn($schema);
        $schemaManager->method('listTableIndexes')->willReturnCallback(
            static fn (string $table): array => $schema->getTable($table)->getIndexes()
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql) use (&$queries, $duplicates): array {
            $queries[] = trim(preg_replace('/\s+/', ' ', $sql));

            return $duplicates;
        });
        $connection->method('prepare')->willReturnCallback(function (string $sql) use (&$executed) {
            $executed[] = trim(preg_replace('/\s+/', ' ', $sql));

            return $this->createMock(Statement::class);
        });

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $migration = new Version_0_0_32($entityManager, $prefix);

        if ($migration->shouldExecute()) {
            $migration->execute();
        }

        return $executed;
    }

    /**
     * @param string $alias 'plain' = the old index named alias, 'plain-other-name', 'composite' (alias + name, not to be dropped),
     *                      'unique' = the new one, 'unique-other-name', 'none' = no index on alias
     */
    private function buildSchema(string $prefix, string $alias): Schema
    {
        $schema = new Schema();
        $table  = $schema->createTable($prefix.'custom_object');
        $table->addColumn('id', Types::INTEGER);
        $table->addColumn('alias', Types::STRING);
        $table->addColumn('name', Types::STRING);

        match ($alias) {
            'plain'             => $table->addIndex(['alias'], $prefix.'alias'),
            'plain-other-name'  => $table->addIndex(['alias'], 'idx_custom_alias'),
            'composite'         => $table->addIndex(['alias', 'name'], $prefix.'alias_name'),
            'unique'            => $table->addUniqueIndex(['alias'], $prefix.'alias_unique'),
            'unique-other-name' => $table->addUniqueIndex(['alias'], 'uniq_custom_alias'),
            default             => null,
        };

        return $schema;
    }
}
