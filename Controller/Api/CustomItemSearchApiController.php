<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueDate;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueText;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
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
 * Supports "text" and "date" type fields (the ones actually in use:
 * cursid/cursname/discname/discclass are text, discstart/discend are
 * date). Another field type would need its value entity class added to
 * VALUE_ENTITY_CLASS_BY_TYPE below, following the same pattern.
 */
class CustomItemSearchApiController extends AbstractController
{
    private const VALUE_ENTITY_CLASS_BY_TYPE = [
        'text' => CustomFieldValueText::class,
        'date' => CustomFieldValueDate::class,
    ];

    public function __construct(
        private CustomObjectModel $customObjectModel,
        private CustomItemModel $customItemModel,
        private EntityManagerInterface $em
    ) {
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

        $filters = [];
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

            if (!isset(self::VALUE_ENTITY_CLASS_BY_TYPE[$type])) {
                return new JsonResponse(
                    ['errors' => [['message' => "Filtering by field type '{$type}' (field '{$fieldAlias}') is not supported yet."]]],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $filters[] = [
                'field' => $customField,
                'value' => 'date' === $type ? new \DateTimeImmutable((string) $value) : (string) $value,
                'class' => self::VALUE_ENTITY_CLASS_BY_TYPE[$type],
            ];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('ci')
            ->from(CustomItem::class, 'ci')
            ->andWhere('ci.customObject = :customObject')
            ->setParameter('customObject', $customObject);

        foreach ($filters as $i => $filter) {
            $valueAlias = 'v'.$i;
            $qb->join(
                $filter['class'],
                $valueAlias,
                'WITH',
                "{$valueAlias}.customItem = ci AND {$valueAlias}.customField = :field{$i} AND {$valueAlias}.value = :value{$i}"
            )
                ->setParameter("field{$i}", $filter['field'])
                ->setParameter("value{$i}", $filter['value']);
        }

        $items = $qb->getQuery()->getResult();

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
