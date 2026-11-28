<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller\Api;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\CustomObjectsBundle\DTO\TableConfig;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItemXrefContact;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItemXrefCustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Repository\CustomItemXrefContactRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST twin of the UI link/unlink buttons (CustomItem\LinkController / UnlinkController) plus the list of
 * the items linked to a contact.
 *
 *   POST   /api/customobjects/items/{itemId}/links/{entityType}/{entityId}   link; already linked = 200 as well
 *   DELETE /api/customobjects/items/{itemId}/links/{entityType}/{entityId}   unlink
 *   GET    /api/contacts/{contactId}/customitems?alias=&limit=&offset=       items of the contact, grouped by object
 *
 * {entityType} is whatever CustomItemModel::linkEntity() can discover ("contact", "customItem"); another value is a 400.
 */
class CustomItemLinkApiController extends AbstractController
{
    private const MAX_LIMIT = 100;

    /** entityType => entity class a link may point to (the types CustomItemModel::linkEntity() discovers) */
    private const LINKABLE = ['contact' => Lead::class, 'customItem' => CustomItem::class];

    public function __construct(
        private CustomItemModel $customItemModel,
        private EntityManagerInterface $em,
        private CustomItemPermissionProvider $permissionProvider,
        private CustomItemXrefContactRepository $xrefContactRepository,
        private CorePermissions $corePermissions,
    ) {
    }

    public function linkAction(int $itemId, string $entityType, int $entityId): JsonResponse
    {
        try {
            $customItem = $this->customItemModel->fetchEntity($itemId);
            $this->permissionProvider->canEdit($customItem);
            $this->assertTargetExists($entityType, $entityId);
            // an existing xref is loaded by the discovery and not persisted again: linking twice is a no-op
            $this->customItemModel->linkEntity($customItem, $entityType, $entityId);
        } catch (UniqueConstraintViolationException) {
            // same as the UI LinkController: already linked is not an error
        } catch (NotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (\UnexpectedValueException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['itemId' => $itemId, 'entityType' => $entityType, 'entityId' => $entityId, 'linked' => true]);
    }

    public function unlinkAction(int $itemId, string $entityType, int $entityId): JsonResponse
    {
        try {
            $customItem = $this->customItemModel->fetchEntity($itemId);
            $this->permissionProvider->canEdit($customItem);
            // validate before anything is deleted: the child item goes only when the unlink will succeed
            $this->assertTargetExists($entityType, $entityId);
            $this->assertLinked($customItem, $entityType, $entityId);

            if ($customItem->getCustomObject()->getRelationshipObject()) {
                try {
                    $childCustomItem = $customItem->findChildCustomItem();
                } catch (NotFoundException) {
                }

                if (isset($childCustomItem)) {
                    $this->customItemModel->delete($childCustomItem);
                }
            }

            $this->customItemModel->unlinkEntity($customItem, $entityType, $entityId);
        } catch (NotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (\UnexpectedValueException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['itemId' => $itemId, 'entityType' => $entityType, 'entityId' => $entityId, 'linked' => false]);
    }

    /**
     * One entry per Custom Object the contact has items in; "limit" (default 10, max 100) caps the items per object
     * and "offset" is rounded down to a multiple of "limit" (page based); the response echoes the offset used. Objects the caller may not view are left out.
     */
    public function contactItemsAction(Request $request, int $contactId): JsonResponse
    {
        $contact = $this->em->find(Lead::class, $contactId);

        if (!$contact instanceof Lead) {
            return $this->errorResponse("Contact with ID = {$contactId} was not found.", Response::HTTP_NOT_FOUND);
        }

        if (!$this->corePermissions->hasEntityAccess('lead:leads:viewown', 'lead:leads:viewother', $contact->getPermissionUser())) {
            return $this->errorResponse('You do not have permission to view this contact.', Response::HTTP_FORBIDDEN);
        }

        $alias  = $request->query->get('alias');
        $limit  = min(self::MAX_LIMIT, max(1, (int) $request->query->get('limit', 10)));
        $offset = max(0, (int) $request->query->get('offset', 0));
        $page   = intdiv($offset, $limit) + 1;

        $objects = $this->xrefContactRepository->getCustomObjectsRelatedToContact(
            $contact,
            new TableConfig(self::MAX_LIMIT, 1, CustomObject::TABLE_ALIAS.'.dateAdded', 'DESC')
        );

        $result = [];

        foreach ($objects as $object) {
            if (is_string($alias) && '' !== $alias && $object['alias'] !== $alias) {
                continue;
            }

            try {
                $this->permissionProvider->canViewAtAll((int) $object['id']);
            } catch (ForbiddenException) {
                continue;
            }

            $tableConfig = new TableConfig($limit, $page, CustomItem::TABLE_ALIAS.'.dateAdded', 'DESC');
            $tableConfig->addParameter('customObjectId', $object['id']);
            $tableConfig->addParameter('filterEntityId', $contact->getId());
            $tableConfig->addParameter('filterEntityType', 'contact');

            $items = [];

            /** @var CustomItem $item */
            foreach ($this->customItemModel->getTableData($tableConfig) as $item) {
                $items[] = ['id' => $item->getId(), 'name' => $item->getName(), 'isPublished' => $item->getIsPublished()];
            }

            if ($items) {
                $result[] = ['id' => $object['id'], 'alias' => $object['alias'], 'items' => $items];
            }
        }

        return new JsonResponse(['contactId' => $contactId, 'limit' => $limit, 'offset' => ($page - 1) * $limit, 'objects' => $result]);
    }

    /**
     * @throws \UnexpectedValueException unknown entityType
     * @throws NotFoundException          the contact / custom item to link does not exist
     */
    private function assertTargetExists(string $entityType, int $entityId): void
    {
        if (!isset(self::LINKABLE[$entityType])) {
            throw new \UnexpectedValueException("Entity {$entityType} cannot be linked to a custom item.");
        }

        if (null === $this->em->find(self::LINKABLE[$entityType], $entityId)) {
            throw new NotFoundException("Entity {$entityType} with ID = {$entityId} was not found.");
        }
    }

    private function assertLinked(CustomItem $customItem, string $entityType, int $entityId): void
    {
        $queryBuilder = $this->em->createQueryBuilder();

        if ('contact' === $entityType) {
            $queryBuilder->select('COUNT(x.customItem)')
                ->from(CustomItemXrefContact::class, 'x')
                ->where('x.customItem = :item')->andWhere('x.contact = :target');
            $queryBuilder->setParameter('item', $customItem->getId())->setParameter('target', $entityId);
        } else {
            $queryBuilder->select('COUNT(x.customItemLower)')
                ->from(CustomItemXrefCustomItem::class, 'x')
                ->where('x.customItemLower = :lower')->andWhere('x.customItemHigher = :higher');
            $queryBuilder->setParameter('lower', min($customItem->getId(), $entityId))->setParameter('higher', max($customItem->getId(), $entityId));
        }

        if (0 === (int) $queryBuilder->getQuery()->getSingleScalarResult()) {
            throw new NotFoundException("Custom item {$customItem->getId()} is not linked to {$entityType} {$entityId}.");
        }
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['errors' => [['message' => $message]]], $status);
    }
}
