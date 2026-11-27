<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Mcp;

use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Helper\CustomObjectDefinitionExporter;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;

class GetCustomObjectDefinitionTool extends AbstractCustomObjectsTool
{
    public function __construct(
        private CustomObjectModel $customObjectModel,
        private CustomObjectDefinitionExporter $exporter,
        private CustomObjectPermissionProvider $permissionProvider,
    ) {
    }

    public function name(): string
    {
        return 'custom_objects_get_definition';
    }

    public function description(): string
    {
        return 'Get the definition (structure: object and fields, no items) of one Custom Object by alias, as the JSON of the Clone tab. Pass it to custom_objects_apply_definition of another instance. Read-only.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['alias' => ['type' => 'string', 'description' => 'Alias of the Custom Object.']],
            'required'   => ['alias'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['definition' => ['type' => 'object']],
            'required'   => ['definition'],
        ];
    }

    public function call(array $args): array
    {
        if ($message = $this->unknownArguments($args, ['alias'])) {
            return $this->error($message);
        }
        $alias = isset($args['alias']) && is_string($args['alias']) ? trim($args['alias']) : '';
        if ('' === $alias) {
            return $this->error('Provide "alias".');
        }

        try {
            // before the lookup: a caller without permission must not learn which aliases exist
            $this->permissionProvider->canViewAtAll();
            $customObject = $this->customObjectModel->fetchEntityByAlias($alias);
            $this->permissionProvider->canView($customObject);
        } catch (ForbiddenException $e) {
            return $this->error($e->getMessage());
        } catch (NotFoundException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(['definition' => $this->exporter->toArray($customObject)]);
    }
}
