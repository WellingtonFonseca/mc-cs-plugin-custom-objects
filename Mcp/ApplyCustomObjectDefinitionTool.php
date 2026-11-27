<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Mcp;

use MauticPlugin\CustomObjectsBundle\Exception\DefinitionImportException;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Helper\CustomObjectDefinitionImporter;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;

/**
 * Add-only: a new alias creates the object, an existing one receives the fields it does not have. Nothing is ever changed or deleted.
 */
class ApplyCustomObjectDefinitionTool extends AbstractCustomObjectsTool
{
    public function __construct(
        private CustomObjectDefinitionImporter $importer,
        private CustomObjectModel $customObjectModel,
        private CustomObjectPermissionProvider $permissionProvider,
    ) {
    }

    public function name(): string
    {
        return 'custom_objects_apply_definition';
    }

    public function description(): string
    {
        return 'Apply a Custom Object definition (the output of custom_objects_get_definition of another instance). Add-only: creates the object when the alias is new, or adds the fields it lacks; never changes or deletes anything. With "dryRun" true (default) nothing is saved and the report says what would happen; pass false to apply.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'definition' => ['type' => 'object', 'description' => 'The definition JSON (Clone tab format).'],
                'dryRun'     => ['type' => 'boolean', 'default' => true, 'description' => 'true (default): only report. false: save.'],
            ],
            'required'             => ['definition'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'dryRun'            => ['type' => 'boolean'],
                'alias'             => ['type' => 'string'],
                'created'           => ['type' => 'boolean', 'description' => 'The object is (dryRun: would be) created.'],
                'addedFields'       => ['type' => 'array', 'items' => ['type' => 'string']],
                'typeMismatches'    => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Fields that exist with another type; left unchanged.'],
                'requiredWithItems' => ['type' => 'array', 'items' => ['type' => 'string']],
                'createdCategory'   => ['type' => ['string', 'null']],
            ],
            'required' => ['dryRun', 'alias', 'created', 'addedFields', 'typeMismatches'],
        ];
    }

    public function call(array $args): array
    {
        if ($message = $this->unknownArguments($args, ['definition', 'dryRun'])) {
            return $this->error($message);
        }

        $definition = $args['definition'] ?? null;
        if (is_string($definition)) {
            $definition = json_decode($definition, true);
        }
        if (!is_array($definition) || !isset($definition['alias']) || !is_string($definition['alias'])) {
            return $this->error('"definition" must be the definition JSON object, with an "alias".');
        }

        $dryRun = $args['dryRun'] ?? true;
        if (!is_bool($dryRun)) {
            return $this->error('"dryRun" must be a boolean.');
        }

        try {
            $this->permissionProvider->canViewAtAll();
            try {
                $this->customObjectModel->fetchEntityByAlias($definition['alias']);
            } catch (NotFoundException) {
                // a new alias creates the object; an existing one is checked for edit by the importer
                $this->permissionProvider->canCreate();
            }

            $result = $this->importer->import((string) json_encode($definition), !$dryRun, false);
        } catch (ForbiddenException $e) {
            return $this->error($e->getMessage());
        } catch (DefinitionImportException $e) {
            return $this->error(implode("\n", $e->getErrors()));
        }

        return $this->ok([
            'dryRun'            => $dryRun,
            'alias'             => (string) $result->getCustomObject()->getAlias(),
            'created'           => $result->isCreated(),
            'addedFields'       => array_values($result->getAddedAliases()),
            'typeMismatches'    => $result->getTypeMismatches(),
            'requiredWithItems' => $result->getRequiredWithItems(),
            'createdCategory'   => $result->getCreatedCategory(),
        ]);
    }
}
