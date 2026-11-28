<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Mcp;

use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomItemSearchApiController;

/**
 * Wraps GET /api/customobjects/{alias}/items/{itemId}.
 */
class GetCustomItemTool extends AbstractCustomObjectsTool
{
    public function __construct(private CustomItemSearchApiController $controller)
    {
    }

    public function name(): string
    {
        return 'custom_objects_get_item';
    }

    /**
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true];
    }

    public function description(): string
    {
        return 'Read-only. Get one item of a Custom Object by id: its name, its field values and the number of contacts linked to it. Writes nothing. Answers an error when the item does not exist on that Custom Object or the user may not see it.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'alias'  => ['type' => 'string', 'description' => 'Alias of the Custom Object.'],
                'itemId' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required'             => ['alias', 'itemId'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'                 => ['type' => 'integer'],
                'name'               => ['type' => 'string'],
                'attributes'         => ['type' => 'object', 'description' => 'Field alias => value.'],
                'linkedContactCount' => ['type' => 'integer'],
            ],
            'required' => ['id', 'name', 'attributes', 'linkedContactCount'],
        ];
    }

    public function call(array $args): array
    {
        if ($message = $this->unknownArguments($args, ['alias', 'itemId'])) {
            return $this->error($message);
        }

        if (!isset($args['alias']) || !is_string($args['alias']) || '' === $args['alias']) {
            return $this->error('"alias" is required.');
        }

        if (!isset($args['itemId']) || !is_int($args['itemId']) || $args['itemId'] < 1) {
            return $this->error('"itemId" must be a positive integer.');
        }

        return $this->fromApiResponse($this->controller->getAction($args['alias'], $args['itemId']));
    }
}
