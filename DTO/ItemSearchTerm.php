<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\DTO;

/**
 * One "alias:value" piece of an item list search.
 */
final class ItemSearchTerm
{
    public function __construct(public readonly string $alias, public readonly string $value)
    {
    }
}
