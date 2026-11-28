<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItemXrefContact;
use MauticPlugin\CustomObjectsBundle\Exception\InvalidValueException;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Helper\ItemFieldFilter;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an API caller list a Custom Object's items, optionally filtered by
 * one or more of their attribute values (all given filters must match —
 * AND, not OR), without direct database access. Every query string
 * parameter is treated as "<fieldAlias>=<value>". Examples:
 *
 *   GET /api/customobjects/courses/items                    -> every course item
 *   GET /api/customobjects/courses/items?cursid=311-999      -> items with that cursid
 *   GET /api/customobjects/disciplines/items?discclass=3110987_54321&discstart=2026-09-20
 *       -> items matching BOTH filters
 *
 * Every field type can be filtered, through ItemFieldFilter (the same code
 * as the item list search). Text and select-like fields must be EQUAL to the
 * value; dates match the whole day; int/decimal/date/datetime also accept a
 * leading >, >=, < or <= (?progresso=>=0.5); a multiselect matches when the
 * item has that option.
 */
class CustomItemSearchApiController extends AbstractController
{
    private ItemFieldFilter $itemFieldFilter;

    public function __construct(
        private CustomObjectModel $customObjectModel,
        private CustomItemModel $customItemModel,
        private EntityManagerInterface $em,
        private CustomItemPermissionProvider $permissionProvider,
    ) {
        $this->itemFieldFilter = new ItemFieldFilter();
    }

    public function listAction(Request $request, string $objectAlias): JsonResponse
    {
        try {
            $customObject = $this->customObjectModel->fetchEntityByAlias($objectAlias);
            $this->permissionProvider->canViewAtAll((int) $customObject->getId());
        } catch (NotFoundException $e) {
            return new JsonResponse(['errors' => [['message' => $e->getMessage()]]], Response::HTTP_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new JsonResponse(['errors' => [['message' => $e->getMessage()]]], Response::HTTP_FORBIDDEN);
        }

        $fieldsByAlias = [];
        foreach ($customObject->getCustomFields() as $field) {
            $fieldsByAlias[$field->getAlias()] = $field;
        }

        $qb = $this->em->createQueryBuilder()
            ->select(CustomItem::TABLE_ALIAS)
            ->from(CustomItem::class, CustomItem::TABLE_ALIAS)
            ->andWhere(CustomItem::TABLE_ALIAS.'.customObject = :customObject')
            ->setParameter('customObject', $customObject);

        // viewown-only users see only the items they created (same rule as the item list)
        $this->customItemModel->applyOwnerFilter($qb, (int) $customObject->getId());

        $limit  = min(100, max(1, (int) $request->query->get('limit', 25)));
        $offset = max(0, (int) $request->query->get('offset', 0));

        $index = 0;
        foreach ($request->query->all() as $fieldAlias => $value) {
            // limit/offset are reserved for pagination, never field filters
            if (in_array($fieldAlias, ['limit', 'offset'], true)) {
                continue;
            }

            if (!isset($fieldsByAlias[$fieldAlias])) {
                return new JsonResponse(
                    ['errors' => [['message' => "Field '{$fieldAlias}' not found on Custom Object '{$objectAlias}'."]]],
                    Response::HTTP_NOT_FOUND
                );
            }

            /** @var CustomField $customField */
            $customField = $fieldsByAlias[$fieldAlias];
            $type        = $customField->getType();

            if (!$this->itemFieldFilter->isSupported((string) $type)) {
                return new JsonResponse(
                    ['errors' => [['message' => "Filtering by field type '{$type}' (field '{$fieldAlias}') is not supported yet."]]],
                    Response::HTTP_BAD_REQUEST
                );
            }

            try {
                // Same filter as the item list search, except that text must be equal, not just contain the value.
                $this->itemFieldFilter->apply($qb, CustomItem::TABLE_ALIAS, $customField, (string) $value, ItemFieldFilter::TEXT_EQUALS, $index++);
            } catch (InvalidValueException $e) {
                return new JsonResponse(
                    ['errors' => [['message' => "Invalid value for field '{$fieldAlias}': {$e->getMessage()}"]]],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        $total = (int) (clone $qb)
            ->select('COUNT(DISTINCT '.CustomItem::TABLE_ALIAS.'.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb->orderBy(CustomItem::TABLE_ALIAS.'.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        // The values of every item in one go; serializeItem() below then finds them already loaded.
        $this->customItemModel->populateCustomFieldsForItems($items);

        return new JsonResponse([
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
            'items'  => array_map(fn (CustomItem $item) => $this->serializeItem($item), $items),
        ]);
    }

    public function getAction(string $objectAlias, int $itemId): JsonResponse
    {
        try {
            $customObject = $this->customObjectModel->fetchEntityByAlias($objectAlias);
            $this->permissionProvider->canViewAtAll((int) $customObject->getId());
        } catch (NotFoundException $e) {
            return new JsonResponse(['errors' => [['message' => $e->getMessage()]]], Response::HTTP_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new JsonResponse(['errors' => [['message' => $e->getMessage()]]], Response::HTTP_FORBIDDEN);
        }

        $qb = $this->em->createQueryBuilder()
            ->select(CustomItem::TABLE_ALIAS)
            ->from(CustomItem::class, CustomItem::TABLE_ALIAS)
            ->andWhere(CustomItem::TABLE_ALIAS.'.id = :itemId')
            ->andWhere(CustomItem::TABLE_ALIAS.'.customObject = :customObject')
            ->setParameter('itemId', $itemId)
            ->setParameter('customObject', $customObject);

        // viewown-only users see only the items they created
        $this->customItemModel->applyOwnerFilter($qb, (int) $customObject->getId());

        $item = $qb->getQuery()->getOneOrNullResult();

        if (!$item instanceof CustomItem || !$this->belongsToCustomObject($item->getCustomObject(), $customObject)) {
            return new JsonResponse(
                ['errors' => [['message' => "Item with ID = {$itemId} was not found on Custom Object '{$objectAlias}'."]]],
                Response::HTTP_NOT_FOUND
            );
        }

        $linkedContactCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(x.contact)')
            ->from(CustomItemXrefContact::class, 'x')
            ->where('x.customItem = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getSingleScalarResult();

        return new JsonResponse($this->serializeItem($item) + ['linkedContactCount' => $linkedContactCount]);
    }

    private function belongsToCustomObject(\MauticPlugin\CustomObjectsBundle\Entity\CustomObject $itemOwner, \MauticPlugin\CustomObjectsBundle\Entity\CustomObject $customObject): bool
    {
        return $itemOwner->getId() === $customObject->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(CustomItem $item): array
    {
        $this->customItemModel->populateCustomFields($item);

        $attributes = [];
        foreach ($item->getCustomFieldValues() as $fieldValue) {
            $value                                             = $fieldValue->getValue();
            $attributes[$fieldValue->getCustomField()->getAlias()] = $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d')
                : $value;
        }

        return [
            'id'         => $item->getId(),
            'name'       => $item->getName(),
            'attributes' => $attributes,
        ];
    }
}
