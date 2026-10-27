<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\DTO;

use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;

/**
 * What an import of a definition did: the object (created, or the existing one that received fields), the aliases of the fields added and the
 * fields of the file that were left out because the object already has them, and the ones among those whose type differs from the type in the
 * file (a text each, already translated: the two instances have drifted apart), the title of a category that does not exist yet and is made with
 * the object, and the added fields that are required in an object that
 * already has items (those items have them empty).
 */
class CustomObjectDefinitionImportResult
{
    /**
     * @param string[] $addedAliases
     * @param string[] $typeMismatches
     * @param string[] $requiredWithItems
     */
    public function __construct(
        private CustomObject $customObject,
        private bool $created,
        private array $addedAliases,
        private array $typeMismatches = [],
        private array $requiredWithItems = [],
        private ?string $createdCategory = null
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
    public function getTypeMismatches(): array
    {
        return $this->typeMismatches;
    }

    /**
     * @return string[]
     */
    public function getRequiredWithItems(): array
    {
        return $this->requiredWithItems;
    }

    public function getCreatedCategory(): ?string
    {
        return $this->createdCategory;
    }
}
