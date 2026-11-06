<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Entity;

/**
 * The size of the string columns of the plugin tables (custom_item, custom_object, custom_field, custom_field_option):
 * VARCHAR(191), the utf8mb4 index limit. Every Length constraint of these entities uses it, so a value that passes the
 * validation always fits the column.
 */
final class StringColumn
{
    public const LENGTH = 191;
}
