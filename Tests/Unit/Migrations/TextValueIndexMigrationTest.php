<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use MauticPlugin\CustomObjectsBundle\Migrations\Version_0_0_30;
use PHPUnit\Framework\TestCase;

class TextValueIndexMigrationTest extends TestCase
{
    public function testAddsTheIndexWhenItIsMissing(): void
    {
        $this->assertSame(
            ['ALTER TABLE custom_field_value_text ADD INDEX field_value_index (custom_field_id, value(64))'],
            $this->runMigration('', $this->buildSchema('', false))
        );
    }

    public function testUsesTablePrefixForTableAndIndexName(): void
    {
        $this->assertSame(
            ['ALTER TABLE mtc_custom_field_value_text ADD INDEX mtc_field_value_index (custom_field_id, value(64))'],
            $this->runMigration('mtc_', $this->buildSchema('mtc_', false))
        );
    }

    public function testDoesNothingWhenTheIndexAlreadyExists(): void
    {
        $this->assertSame([], $this->runMigration('', $this->buildSchema('', true)));
        $this->assertSame([], $this->runMigration('mtc_', $this->buildSchema('mtc_', true)));
    }

    public function testDoesNothingWhenTheTableDoesNotExist(): void
    {
        $this->assertSame([], $this->runMigration('', new Schema()));
    }

    /**
     * @return string[]
     */
    private function runMigration(string $prefix, Schema $schema): array
    {
        $executed      = [];
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('introspectSchema')->willReturn($schema);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('prepare')->willReturnCallback(function (string $sql) use (&$executed) {
            $executed[] = trim(preg_replace('/\s+/', ' ', $sql));

            return $this->createMock(Statement::class);
        });

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $migration = new Version_0_0_30($entityManager, $prefix);

        if ($migration->shouldExecute()) {
            $migration->execute();
        }

        return $executed;
    }

    private function buildSchema(string $prefix, bool $withIndex): Schema
    {
        $schema = new Schema();
        $table  = $schema->createTable($prefix.'custom_field_value_text');
        $table->addColumn('custom_field_id', Types::INTEGER);
        $table->addColumn('value', Types::TEXT);

        if ($withIndex) {
            $table->addIndex(['custom_field_id'], $prefix.'field_value_index');
        }

        return $schema;
    }
}
