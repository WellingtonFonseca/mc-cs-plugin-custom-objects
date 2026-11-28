<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Mcp;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomItemLinkApiController;

/**
 * Wraps DELETE /api/customobjects/items/{itemId}/links/{entityType}/{entityId} (Controller/Api/CustomItemLinkApiController.php). dryRun runs the same lookups and permission checks inside a transaction that is rolled back, so nothing is written.
 */
class UnlinkCustomItemTool extends AbstractCustomObjectsTool
{
    public function __construct(private CustomItemLinkApiController $controller, private EntityManagerInterface $em)
    {
    }

    public function name(): string
    {
        return 'custom_objects_unlink_item';
    }

    /**
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true];
    }

    public function description(): string
    {
        return 'DESTRUCTIVE. Remove the link between a Custom Item and a contact or another Custom Item. The item itself is kept, except that for a relationship-object item its linked child item is deleted too. Answers an error (nothing changes) when the item or target does not exist or they are not linked; needs permission to edit the item. With "dryRun" true (default) it changes NOTHING and only checks that the unlink could be made; pass false to unlink.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'itemId'     => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the Custom Item.'],
                'entityType' => ['type' => 'string', 'enum' => ['contact', 'customItem'], 'description' => 'What the item is linked to: a contact or another Custom Item (companies cannot be linked).'],
                'entityId'   => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the contact or of the other Custom Item.'],
                'dryRun'     => ['type' => 'boolean', 'default' => true, 'description' => 'true (default): only check and report. false: unlink.'],
            ],
            'required'             => ['itemId', 'entityType', 'entityId'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'dryRun'     => ['type' => 'boolean'],
                'itemId'     => ['type' => 'integer'],
                'entityType' => ['type' => 'string'],
                'entityId'   => ['type' => 'integer'],
                'linked'     => ['type' => 'boolean', 'description' => 'false: the item is no longer linked (dryRun: would be unlinked).'],
            ],
            'required' => ['dryRun', 'itemId', 'entityType', 'entityId', 'linked'],
        ];
    }

    public function call(array $args): array
    {
        if ($message = $this->unknownArguments($args, ['itemId', 'entityType', 'entityId', 'dryRun'])) {
            return $this->error($message);
        }

        if (!isset($args['itemId']) || !is_int($args['itemId']) || $args['itemId'] < 1) {
            return $this->error('"itemId" must be a positive integer.');
        }

        if (!isset($args['entityType']) || !in_array($args['entityType'], ['contact', 'customItem'], true)) {
            return $this->error('"entityType" must be "contact" or "customItem".');
        }

        if (!isset($args['entityId']) || !is_int($args['entityId']) || $args['entityId'] < 1) {
            return $this->error('"entityId" must be a positive integer.');
        }

        $dryRun = $args['dryRun'] ?? true;
        if (!is_bool($dryRun)) {
            return $this->error('"dryRun" must be a boolean.');
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $response = $this->controller->unlinkAction($args['itemId'], $args['entityType'], $args['entityId']);
            if ($dryRun || $response->getStatusCode() >= 400) {
                $connection->rollBack();
            } else {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        $result = $this->fromApiResponse($response);
        if ($dryRun) {
            $this->em->clear();
            if (!$result['isError']) {
                $result['structuredContent']['dryRun'] = true;
                $result['content'] = [['type' => 'text', 'text' => json_encode($result['structuredContent'])]];
            }
        } elseif (!$result['isError']) {
            $result['structuredContent']['dryRun'] = false;
            $result['content'] = [['type' => 'text', 'text' => json_encode($result['structuredContent'])]];
        }

        return $result;
    }
}
