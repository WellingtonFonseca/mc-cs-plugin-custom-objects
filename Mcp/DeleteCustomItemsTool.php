<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Mcp;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;

/**
 * Same rules as DELETE /api/customobjects/{alias}/items (Controller/Api/CustomItemDeleteApiController.php). dryRun runs the same lookups
 * and permission checks and deletes nothing.
 */
class DeleteCustomItemsTool extends AbstractCustomObjectsTool
{
    private const MAX_IDS = 100;

    public function __construct(
        private CustomObjectModel $customObjectModel,
        private CustomItemModel $customItemModel,
        private EntityManagerInterface $em,
        private CustomItemPermissionProvider $permissionProvider,
    ) {
    }

    public function name(): string
    {
        return 'custom_objects_delete_items';
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
        return 'DESTRUCTIVE. Permanently delete items of one Custom Object by id, up to 100 per call, in ONE transaction: if one id belongs to another Custom Object or may not be deleted by the user, nothing is deleted. The item is deleted for every contact linked to it. An id that does not exist is not an error: it is listed in "alreadyGone". With "dryRun" true (default) it deletes NOTHING and lists what would be deleted; pass false to delete. Use custom_objects_get_item first to check an item and its linked contact count.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'alias'  => ['type' => 'string', 'description' => 'Alias of the Custom Object.'],
                'ids'    => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_IDS, 'items' => ['type' => 'integer', 'minimum' => 1]],
                'dryRun' => ['type' => 'boolean', 'default' => true, 'description' => 'true (default): only report. false: delete.'],
            ],
            'required'             => ['alias', 'ids'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'dryRun'      => ['type' => 'boolean'],
                'total'       => ['type' => 'integer', 'description' => 'Items deleted (dryRun: that would be deleted).'],
                'deleted'     => ['type' => 'array', 'items' => ['type' => 'integer']],
                'alreadyGone' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Ids that do not exist.'],
            ],
            'required' => ['dryRun', 'total', 'deleted', 'alreadyGone'],
        ];
    }

    public function call(array $args): array
    {
        if ($message = $this->unknownArguments($args, ['alias', 'ids', 'dryRun'])) {
            return $this->error($message);
        }

        if (!isset($args['alias']) || !is_string($args['alias']) || '' === $args['alias']) {
            return $this->error('"alias" is required.');
        }

        $dryRun = $args['dryRun'] ?? true;
        if (!is_bool($dryRun)) {
            return $this->error('"dryRun" must be a boolean.');
        }

        $ids = $args['ids'] ?? null;
        if (!is_array($ids) || [] === $ids || !array_is_list($ids) || count($ids) > self::MAX_IDS) {
            return $this->error('"ids" must be a list of 1 to '.self::MAX_IDS.' item ids.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                return $this->error('Each id must be a positive integer.');
            }
        }

        try {
            $customObject = $this->customObjectModel->fetchEntityByAlias($args['alias']);
            // before any item lookup: "alreadyGone" must not tell a user without the permission which ids exist
            $this->permissionProvider->isGrantedOwnOrOther('delete', (int) $customObject->getId());
        } catch (NotFoundException|ForbiddenException $e) {
            return $this->error($e->getMessage());
        }

        $deleted     = [];
        $alreadyGone = [];
        $this->em->getConnection()->beginTransaction();

        try {
            foreach (array_unique($ids) as $id) {
                try {
                    $customItem = $this->customItemModel->fetchEntity($id);
                } catch (NotFoundException) {
                    $alreadyGone[] = $id;

                    continue;
                }

                if ($customItem->getCustomObject()->getId() !== $customObject->getId()) {
                    throw new NotFoundException("Item with ID = {$id} was not found on Custom Object '{$args['alias']}'.");
                }

                $this->permissionProvider->canDelete($customItem);
                if (!$dryRun) {
                    $this->customItemModel->delete($customItem);
                }
                $deleted[] = $id;
            }

            $this->em->getConnection()->commit();
        } catch (NotFoundException|ForbiddenException $e) {
            $this->em->getConnection()->rollBack();

            return $this->error($e->getMessage().' Nothing was deleted.');
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();

            throw $e;
        }

        return $this->ok(['dryRun' => $dryRun, 'total' => count($deleted), 'deleted' => $deleted, 'alreadyGone' => $alreadyGone]);
    }
}
