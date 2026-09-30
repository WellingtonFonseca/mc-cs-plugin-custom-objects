<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Migration 0.0.12 used to drop the FULLTEXT indexes without re-creating them when the table prefix was empty.
 * Item search (MATCH ... AGAINST) fails with SQLSTATE 1191 without them, so re-create the ones that are missing.
 */
class Version_0_0_29 extends AbstractMigration
{
    /**
     * @var array<string, array{string, string}> table => [index name without prefix, column]
     */
    private const INDEXES = [
        'custom_item'               => ['name_fulltext', 'name'],
        'custom_field_value_text'   => ['value_fulltext', 'value'],
        'custom_field_value_option' => ['value_fulltext', 'value'],
    ];

    /**
     * @var string[]
     */
    private array $sql = [];

    protected function isApplicable(Schema $schema): bool
    {
        $this->sql = [];

        foreach (self::INDEXES as $table => [$index, $column]) {
            $tableName = $this->concatPrefix($table);
            $indexName = $this->concatPrefix($index);

            try {
                if (!$schema->getTable($tableName)->hasIndex($indexName)) {
                    $this->sql[] = "ALTER TABLE {$tableName} ADD FULLTEXT INDEX {$indexName} ({$column})";
                }
            } catch (SchemaException) {
                // The table does not exist, the plugin schema installation will create it with the index.
            }
        }

        return [] !== $this->sql;
    }

    protected function up(): void
    {
        foreach ($this->sql as $sql) {
            $this->addSql($sql);
        }
    }
}
