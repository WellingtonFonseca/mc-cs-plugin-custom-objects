<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Value table for the "decimal" Custom Field type
 * (CustomFieldType/DecimalType.php). Mirrors custom_field_value_int, with a
 * DECIMAL(20,6) value column.
 */
class Version_0_0_28 extends AbstractMigration
{
    private string $table = 'custom_field_value_decimal';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix($this->table));
    }

    protected function up(): void
    {
        $table = $this->concatPrefix($this->table);

        $this->addSql("CREATE TABLE {$table} (
                custom_field_id INT UNSIGNED NOT NULL,
                custom_item_id BIGINT UNSIGNED NOT NULL,
                value NUMERIC(20, 6) DEFAULT NULL,
                INDEX value_index (value),
                INDEX ".$this->generatePropertyName($this->table, 'IDX', ['custom_item_id'])." (custom_item_id),
                PRIMARY KEY(custom_field_id, custom_item_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        ");

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            $this->table,
            ['custom_field_id'],
            'custom_field',
            ['id'],
            'ON DELETE CASCADE'
        ));

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            $this->table,
            ['custom_item_id'],
            'custom_item',
            ['id'],
            'ON DELETE CASCADE'
        ));
    }
}
