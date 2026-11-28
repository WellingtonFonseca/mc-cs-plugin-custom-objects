<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Mcp;

use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomItemLinkApiController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Wraps GET /api/customitems/{itemId}/contacts (Controller/Api/CustomItemLinkApiController.php::itemContactsAction).
 */
class ItemContactsTool extends AbstractCustomObjectsTool
{
    public function __construct(private CustomItemLinkApiController $controller)
    {
    }

    public function name(): string
    {
        return 'custom_objects_item_contacts';
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
        return 'Read-only. List the contacts linked to one Custom Item (id, firstname, lastname, email), ordered by contact id, with the "total" linked. "limit" per page (default 10, max 100), "offset" skips contacts. Writes nothing. Answers an error when the item does not exist or the user may not view the item or contacts.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'itemId' => ['type' => 'integer', 'minimum' => 1],
                'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            ],
            'required'             => ['itemId'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'itemId'   => ['type' => 'integer'],
                'total'    => ['type' => 'integer', 'description' => 'All contacts linked to the item.'],
                'limit'    => ['type' => 'integer'],
                'offset'   => ['type' => 'integer'],
                'contacts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id'        => ['type' => 'integer'],
                    'firstname' => ['type' => ['string', 'null']],
                    'lastname'  => ['type' => ['string', 'null']],
                    'email'     => ['type' => ['string', 'null']],
                ]]],
            ],
            'required' => ['itemId', 'total', 'limit', 'offset', 'contacts'],
        ];
    }

    public function call(array $args): array
    {
        if ($message = $this->unknownArguments($args, ['itemId', 'limit', 'offset'])) {
            return $this->error($message);
        }

        if (!isset($args['itemId']) || !is_int($args['itemId']) || $args['itemId'] < 1) {
            return $this->error('"itemId" must be a positive integer.');
        }

        $query = [];
        foreach (['limit' => 1, 'offset' => 0] as $key => $min) {
            if (isset($args[$key])) {
                if (!is_int($args[$key]) || $args[$key] < $min) {
                    return $this->error('"'.$key.'" must be an integer >= '.$min.'.');
                }
                $query[$key] = $args[$key];
            }
        }

        return $this->fromApiResponse($this->controller->itemContactsAction(new Request($query), $args['itemId']));
    }
}
