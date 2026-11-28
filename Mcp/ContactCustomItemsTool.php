<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Mcp;

use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomItemLinkApiController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Wraps GET /api/contacts/{contactId}/customitems (Controller/Api/CustomItemLinkApiController.php).
 */
class ContactCustomItemsTool extends AbstractCustomObjectsTool
{
    public function __construct(private CustomItemLinkApiController $controller)
    {
    }

    public function name(): string
    {
        return 'custom_objects_contact_items';
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
        return 'Read-only. List the Custom Items linked to one contact, grouped by Custom Object, optionally only one object ("alias"). At most "limit" items per object (default 10, max 100); "offset" pages through them. Objects the user may not view are left out. Writes nothing. Answers an error when the contact does not exist or the user may not view it.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'contactId' => ['type' => 'integer', 'minimum' => 1],
                'alias'     => ['type' => 'string', 'description' => 'Only this Custom Object (optional).'],
                'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10, 'description' => 'Max items per Custom Object.'],
                'offset'    => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Rounded down to a multiple of limit.'],
            ],
            'required'             => ['contactId'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'contactId' => ['type' => 'integer'],
                'limit'     => ['type' => 'integer'],
                'offset'    => ['type' => 'integer'],
                'objects'   => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id'    => ['type' => 'integer'],
                    'alias' => ['type' => 'string'],
                    'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id'          => ['type' => 'integer'],
                        'name'        => ['type' => 'string'],
                        'isPublished' => ['type' => 'boolean'],
                    ]]],
                ]]],
            ],
            'required' => ['contactId', 'limit', 'offset', 'objects'],
        ];
    }

    public function call(array $args): array
    {
        if ($message = $this->unknownArguments($args, ['contactId', 'alias', 'limit', 'offset'])) {
            return $this->error($message);
        }

        if (!isset($args['contactId']) || !is_int($args['contactId']) || $args['contactId'] < 1) {
            return $this->error('"contactId" must be a positive integer.');
        }

        $query = [];
        if (isset($args['alias'])) {
            if (!is_string($args['alias'])) {
                return $this->error('"alias" must be a string.');
            }
            $query['alias'] = $args['alias'];
        }

        foreach (['limit' => 1, 'offset' => 0] as $key => $min) {
            if (isset($args[$key])) {
                if (!is_int($args[$key]) || $args[$key] < $min) {
                    return $this->error('"'.$key.'" must be an integer >= '.$min.'.');
                }
                $query[$key] = $args[$key];
            }
        }

        return $this->fromApiResponse($this->controller->contactItemsAction(new Request($query), $args['contactId']));
    }
}
