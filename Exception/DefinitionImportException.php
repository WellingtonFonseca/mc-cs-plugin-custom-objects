<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Exception;

/**
 * The definition of a Custom Object cannot be imported: every problem found is in getErrors() (already translated), nothing was saved.
 */
class DefinitionImportException extends \Exception
{
    /**
     * @param string[] $errors
     */
    public function __construct(private array $errors)
    {
        parent::__construct(implode("\n", $errors));
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
