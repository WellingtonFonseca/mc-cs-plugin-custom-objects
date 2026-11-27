<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Mcp;

use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;

class ListCustomObjectsTool extends AbstractCustomObjectsTool
{
    public function __construct(
        private CustomObjectModel $customObjectModel,
        private CustomObjectPermissionProvider $permissionProvider,
    ) {
    }

    public function name(): string
    {
        return 'custom_objects_list';
    }

    /**
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return ['readOnlyHint' => true];
    }

    public function description(): string
    {
        return 'List the Custom Objects of this Mautic instance (structure only, no items): alias, name and number of fields. Read-only.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'count'   => ['type' => 'integer'],
                'objects' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'alias'      => ['type' => 'string'],
                            'name'       => ['type' => ['string', 'null']],
                            'fieldCount' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            'required' => ['count', 'objects'],
        ];
    }

    public function call(array $args): array
    {
        if ($message = $this->unknownArguments($args, [])) {
            return $this->error($message);
        }

        try {
            $this->permissionProvider->canViewAtAll();
        } catch (ForbiddenException $e) {
            return $this->error($e->getMessage());
        }

        $objects = [];
        foreach ($this->customObjectModel->fetchEntities(['ignore_paginator' => true]) as $customObject) {
            /** @var CustomObject $customObject */
            $objects[] = [
                'alias'      => (string) $customObject->getAlias(),
                'name'       => $customObject->getNameSingular(),
                'fieldCount' => $customObject->getCustomFields()->count(),
            ];
        }

        return $this->ok(['count' => count($objects), 'objects' => $objects]);
    }
}
