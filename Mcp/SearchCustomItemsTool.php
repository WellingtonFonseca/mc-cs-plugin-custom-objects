<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Mcp;

use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomItemSearchApiController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Wraps GET /api/customobjects/{alias}/items: same permission checks, same viewown filter, same filter rules.
 */
class SearchCustomItemsTool extends AbstractCustomObjectsTool
{
    public function __construct(private CustomItemSearchApiController $controller)
    {
    }

    public function name(): string
    {
        return 'custom_objects_search_items';
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
        return 'Read-only. List the items of one Custom Object, ordered by id, with their field values. "filters" is an object of field alias => value; every filter must match (AND); text fields match exactly, other types follow the item list search rules. Paged with "limit" (1-100, default 25) and "offset"; "total" is the number of matching items, not of the page. Writes nothing. Users limited to their own items see only those.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'alias'   => ['type' => 'string', 'description' => 'Alias of the Custom Object.'],
                'filters' => ['type' => 'object', 'description' => 'Field alias => value. Omit to list all items.'],
                'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                'offset'  => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            ],
            'required'             => ['alias'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'total'  => ['type' => 'integer', 'description' => 'All items that match the filters.'],
                'limit'  => ['type' => 'integer'],
                'offset' => ['type' => 'integer'],
                'items'  => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id'         => ['type' => 'integer'],
                    'name'       => ['type' => 'string'],
                    'attributes' => ['type' => 'object', 'description' => 'Field alias => value.'],
                ]]],
            ],
            'required' => ['total', 'limit', 'offset', 'items'],
        ];
    }

    public function call(array $args): array
    {
        if ($message = $this->unknownArguments($args, ['alias', 'filters', 'limit', 'offset'])) {
            return $this->error($message);
        }

        if (!isset($args['alias']) || !is_string($args['alias']) || '' === $args['alias']) {
            return $this->error('"alias" is required.');
        }

        $filters = $args['filters'] ?? [];
        if (!is_array($filters)) {
            return $this->error('"filters" must be an object of field alias => value.');
        }
        foreach ($filters as $alias => $value) {
            if (in_array($alias, ['limit', 'offset'], true) || !is_scalar($value)) {
                return $this->error("Filter '{$alias}' is not allowed: use a field alias with a text, number or boolean value.");
            }
        }

        $limit  = $args['limit'] ?? 25;
        $offset = $args['offset'] ?? 0;
        if (!is_int($limit) || $limit < 1 || $limit > 100) {
            return $this->error('"limit" must be an integer from 1 to 100.');
        }
        if (!is_int($offset) || $offset < 0) {
            return $this->error('"offset" must be an integer, 0 or more.');
        }

        $query = array_map(static fn ($value) => (string) $value, $filters) + ['limit' => $limit, 'offset' => $offset];

        return $this->fromApiResponse($this->controller->listAction(new Request($query), $args['alias']));
    }
}
