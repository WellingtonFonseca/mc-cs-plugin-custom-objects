<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\DTO;

final class ParsedItemSearch
{
    /**
     * @param ItemSearchTerm[] $terms
     */
    public function __construct(private string $freeText, private array $terms)
    {
    }

    /**
     * What is left of the search after the "alias:value" terms were taken out.
     * Without any term it is the search exactly as given.
     */
    public function getFreeText(): string
    {
        return $this->freeText;
    }

    /**
     * @return ItemSearchTerm[]
     */
    public function getTerms(): array
    {
        return $this->terms;
    }

    public function hasTerms(): bool
    {
        return [] !== $this->terms;
    }
}
