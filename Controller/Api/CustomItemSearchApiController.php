<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Exception\InvalidValueException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Helper\ItemFieldFilter;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
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
        private EntityManagerInterface $em
    ) {
        $this->itemFieldFilter = new ItemFieldFilter();
    }

    public function listAction(Request $request, string $objectAlias): JsonResponse
    {
        try {
            $customObject = $this->customObjectModel->fetchEntityByAlias($objectAlias);
        } catch (NotFoundException $e) {
            return new JsonResponse(['errors' => [['message' => $e->getMessage()]]], Response::HTTP_NOT_FOUND);
        }

        $fieldsByAlias = [];
        foreach ($customObject->getCustomFields() as $field) {
            $fieldsByAlias[$field->getAlias()] = $field;
        }

        $qb = $this->em->createQueryBuilder()
            ->select('ci')
            ->from(CustomItem::class, 'ci')
            ->andWhere('ci.customObject = :customObject')
            ->setParameter('customObject', $customObject);

        $index = 0;
        foreach ($request->query->all() as $fieldAlias => $value) {
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
                $this->itemFieldFilter->apply($qb, 'ci', $customField, (string) $value, ItemFieldFilter::TEXT_EQUALS, $index++);
            } catch (InvalidValueException $e) {
                return new JsonResponse(
                    ['errors' => [['message' => "Invalid value for field '{$fieldAlias}': {$e->getMessage()}"]]],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        $items = $qb->getQuery()->getResult();

        // The values of every item in one go; serializeItem() below then finds them already loaded.
        $this->customItemModel->populateCustomFieldsForItems($items);

        return new JsonResponse([
            'total' => count($items),
            'items' => array_map(fn (CustomItem $item) => $this->serializeItem($item), $items),
        ]);
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
