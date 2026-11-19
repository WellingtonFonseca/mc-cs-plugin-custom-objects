<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Exception;

/**
 * A create-only import found an object with the alias of the file: nothing was read further and nothing was saved.
 */
class DefinitionAlreadyExistsException extends DefinitionImportException
{
}
