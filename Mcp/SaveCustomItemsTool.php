<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Mcp;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\InvalidValueException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;

/**
 * Same rules as POST/PATCH/PUT /api/customobjects/{alias}/items (Controller/Api/CustomItemWriteApiController.php): one transaction for
 * the whole batch, permissions checked per entry. dryRun runs every check and the validation, and saves nothing.
 */
class SaveCustomItemsTool extends AbstractCustomObjectsTool
{
    private const MAX_ITEMS = 100;

    public function __construct(
        private CustomObjectModel $customObjectModel,
        private CustomItemModel $customItemModel,
        private EntityManagerInterface $em,
        private CustomItemPermissionProvider $permissionProvider,
    ) {
    }

    public function name(): string
    {
        return 'custom_objects_save_items';
    }

    /**
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false];
    }

    public function description(): string
    {
        return 'Create and/or update items of one Custom Object, up to 100 per call, all in ONE transaction: if one entry fails, none is saved. "mode": "create" (entries must not have "id"), "update" (every entry needs the "id" of an existing item) or "upsert" (an "id" that exists is updated; no "id", or an unknown one, creates a new item with a new id). Only the given "name" and "attributes" change; fields not mentioned keep their value on update. Never deletes. Permissions are checked per entry like the API. With "dryRun" true (default) it validates everything and saves NOTHING; pass false to save.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'alias' => ['type' => 'string', 'description' => 'Alias of the Custom Object.'],
                'mode'  => ['type' => 'string', 'enum' => ['create', 'update', 'upsert']],
                'items' => [
                    'type'     => 'array',
                    'minItems' => 1,
                    'maxItems' => self::MAX_ITEMS,
                    'items'    => [
                        'type'       => 'object',
                        'properties' => [
                            'id'         => ['type' => 'integer', 'description' => 'Item id (update/upsert).'],
                            'name'       => ['type' => 'string'],
                            'attributes' => ['type' => 'object', 'description' => 'Field alias => value.'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'dryRun' => ['type' => 'boolean', 'default' => true, 'description' => 'true (default): only validate and report. false: save.'],
            ],
            'required'             => ['alias', 'mode', 'items'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'dryRun'  => ['type' => 'boolean'],
                'total'   => ['type' => 'integer'],
                'created' => ['type' => 'integer'],
                'updated' => ['type' => 'integer'],
                'items'   => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['create', 'update']],
                    'id'     => ['type' => ['integer', 'null'], 'description' => 'null for a created item on dryRun.'],
                    'name'   => ['type' => ['string', 'null']],
                ]]],
            ],
            'required' => ['dryRun', 'total', 'created', 'updated', 'items'],
        ];
    }

    public function call(array $args): array
    {
        if ($message = $this->unknownArguments($args, ['alias', 'mode', 'items', 'dryRun'])) {
            return $this->error($message);
        }

        if (!isset($args['alias']) || !is_string($args['alias']) || '' === $args['alias']) {
            return $this->error('"alias" is required.');
        }

        $mode = $args['mode'] ?? null;
        if (!in_array($mode, ['create', 'update', 'upsert'], true)) {
            return $this->error('"mode" must be create, update or upsert.');
        }

        $dryRun = $args['dryRun'] ?? true;
        if (!is_bool($dryRun)) {
            return $this->error('"dryRun" must be a boolean.');
        }

        $entries = $args['items'] ?? null;
        if (!is_array($entries) || [] === $entries || !array_is_list($entries) || count($entries) > self::MAX_ITEMS) {
            return $this->error('"items" must be a list of 1 to '.self::MAX_ITEMS.' objects.');
        }

        foreach ($entries as $position => $entry) {
            if (!is_array($entry)) {
                return $this->error('Each entry in "items" must be an object.');
            }
            if ($message = $this->unknownArguments($entry, ['id', 'name', 'attributes'])) {
                return $this->error('Entry '.$position.': '.$message);
            }
            if (isset($entry['attributes']) && !is_array($entry['attributes'])) {
                return $this->error('Entry '.$position.': "attributes" must be an object.');
            }
            if ('create' === $mode && !empty($entry['id'])) {
                return $this->error('Entry '.$position.': mode "create" never accepts "id". Use "update" or "upsert".');
            }
            if ('update' === $mode && empty($entry['id'])) {
                return $this->error('Entry '.$position.': mode "update" needs the "id" of an existing item. Use "create" or "upsert" to create.');
            }
        }

        try {
            $customObject = $this->customObjectModel->fetchEntityByAlias($args['alias']);
            if ('create' === $mode) {
                $this->permissionProvider->canCreate((int) $customObject->getId());
            } elseif ('update' === $mode) {
                $this->permissionProvider->isGrantedOwnOrOther('edit', (int) $customObject->getId());
            }
        } catch (NotFoundException|ForbiddenException $e) {
            return $this->error($e->getMessage());
        }

        $report = [];
        $this->em->getConnection()->beginTransaction();

        try {
            foreach ($entries as $entry) {
                $isNew      = false;
                $customItem = $this->customItem($customObject, $entry, 'upsert' === $mode, $isNew);
                $this->populate($customItem, $entry);
                $customItem->setDefaultValuesForMissingFields();
                $saved    = $this->customItemModel->save($customItem, $dryRun);
                $report[] = ['action' => $isNew ? 'create' : 'update', 'id' => $saved->getId(), 'name' => $saved->getName()];
            }

            $this->em->getConnection()->commit();
        } catch (NotFoundException|InvalidValueException|ForbiddenException $e) {
            $this->em->getConnection()->rollBack();

            return $this->error($e->getMessage().' Nothing was saved.');
        } catch (RetryableException) {
            $this->em->getConnection()->rollBack();

            return $this->error('The database was busy with another write. Nothing was saved: repeat the whole call.');
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();

            throw $e;
        }

        $created = count(array_filter($report, static fn (array $row) => 'create' === $row['action']));

        return $this->ok([
            'dryRun'  => $dryRun,
            'total'   => count($report),
            'created' => $created,
            'updated' => count($report) - $created,
            'items'   => $report,
        ]);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    private function customItem(\MauticPlugin\CustomObjectsBundle\Entity\CustomObject $customObject, array $entry, bool $upsert, bool &$isNew): CustomItem
    {
        if (!empty($entry['id'])) {
            try {
                $customItem = $this->customItemModel->fetchEntity((int) $entry['id']);
            } catch (NotFoundException $e) {
                if (!$upsert) {
                    throw $e;
                }
                $customItem = null;
            }

            if (null !== $customItem) {
                if ($customItem->getCustomObject()->getId() !== $customObject->getId()) {
                    throw new NotFoundException("Item with ID = {$entry['id']} was not found on Custom Object '{$customObject->getAlias()}'.");
                }
                $this->permissionProvider->canEdit($customItem);

                return $this->customItemModel->populateCustomFields($customItem);
            }
        }

        $this->permissionProvider->canCreate((int) $customObject->getId());
        $isNew = true;

        return new CustomItem($customObject);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @throws NotFoundException
     */
    private function populate(CustomItem $customItem, array $entry): void
    {
        if (!empty($entry['name'])) {
            $customItem->setName((string) $entry['name']);
        }

        foreach ($entry['attributes'] ?? [] as $fieldAlias => $value) {
            try {
                $customItem->findCustomFieldValueForFieldAlias($fieldAlias)->setValue($value);
            } catch (NotFoundException) {
                $customItem->createNewCustomFieldValueByFieldAlias($fieldAlias, $value);
            }
        }
    }
}
