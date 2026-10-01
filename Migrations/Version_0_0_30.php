<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Adds a B-tree index to look up text values by field and value. Doctrine can't map a prefix index
 * on a TEXT column (value(64)), so it lives in this migration and not in the entity metadata.
 */
class Version_0_0_30 extends AbstractMigration
{
    private string $table = 'custom_field_value_text';

    private string $index = 'field_value_index';

    protected function isApplicable(Schema $schema): bool
    {
        try {
            return !$schema->getTable($this->concatPrefix($this->table))->hasIndex($this->concatPrefix($this->index));
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD INDEX %s (custom_field_id, value(64))',
            $this->concatPrefix($this->table),
            $this->concatPrefix($this->index)
        ));
    }
}
