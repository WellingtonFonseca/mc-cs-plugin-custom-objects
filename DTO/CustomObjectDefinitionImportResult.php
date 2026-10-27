<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\DTO;

use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;

/**
 * What an import of a definition did: the object (created, or the existing one that received fields), the aliases of the fields added and the
 * fields of the file that were left out because the object already has them, and the ones among those whose type differs from the type in the
 * file (a text each, already translated: the two instances have drifted apart).
 */
class CustomObjectDefinitionImportResult
{
    /**
     * @param string[] $addedAliases
     * @param string[] $skipped
     * @param string[] $typeMismatches
     */
    public function __construct(
        private CustomObject $customObject,
        private bool $created,
        private array $addedAliases,
        private array $skipped,
        private array $typeMismatches = []
    ) {
    }

    public function getCustomObject(): CustomObject
    {
        return $this->customObject;
    }

    public function isCreated(): bool
    {
        return $this->created;
    }

    /**
     * @return string[]
     */
    public function getAddedAliases(): array
    {
        return $this->addedAliases;
    }

    /**
     * @return string[]
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    /**
     * @return string[]
     */
    public function getTypeMismatches(): array
    {
        return $this->typeMismatches;
    }
}
