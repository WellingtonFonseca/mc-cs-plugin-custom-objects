<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Helper;

use Mautic\LeadBundle\Segment\ContactSegmentFilterFactory;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldModel;

/**
 * Finds the item of ONE contact that satisfies ALL the given criteria on the SAME item, with the query of the merged
 * segment filter (QueryFilterHelper::createMergedItemIdsQuery). Used outside a segment (the campaign condition), where
 * several criteria in a row would otherwise be answered each by a different item.
 *
 * The filter is built the way ContactSegmentFilterFactory::getSegmentFilters() builds a merged one: every criterion is
 * turned into a segment filter (so a relative date such as "-35 days" is resolved) and its operator, value, field id and
 * type go to the merged property of the last filter.
 */
class MergedItemCriteriaMatcher
{
    /** The "field" of a criterion on the name of the item instead of a Custom Field. */
    public const ITEM_NAME = 'name';

    public function __construct(
        private ContactSegmentFilterFactory $segmentFilterFactory,
        private QueryFilterHelper $queryFilterHelper,
        private CustomFieldModel $customFieldModel
    ) {
    }

    /**
     * @param array<int, array{field: int|string, operator: string, value: mixed}> $criteria field = a Custom Field id, or
     *                                                                                        self::ITEM_NAME for the name of the item
     *
     * @return int|null the id of an item that satisfies every criterion, null when there is none
     *
     * @throws NotFoundException a criterion names a Custom Field that does not exist
     */
    public function findItemId(array $criteria, int $contactId, int $customObjectId): ?int
    {
        if ([] === $criteria) {
            return null;
        }

        $merged = [];
        $last   = null;

        foreach ($criteria as $criterion) {
            $isName = self::ITEM_NAME === $criterion['field'];
            $filter = $isName
                ? $this->itemNameFilterArray($customObjectId, (string) $criterion['operator'], $criterion['value'])
                : $this->segmentFilterArray($this->customFieldModel->fetchEntity((int) $criterion['field']), (string) $criterion['operator'], $criterion['value']);
            $last     = $this->segmentFilterFactory->factorSegmentFilter($filter);
            $merged[] = [
                'operator'     => $last->getOperator(),
                'filter_value' => $last->getParameterValue(),
                // the name of the item is filtered by the id of its Custom Object (the dictionary of a segment does the same)
                'field'        => $isName ? $customObjectId : $last->getField(),
                'type'         => $last->getType(),
                'cmo_filter'   => $isName,
            ];
        }

        $last->contactSegmentFilterCrate->setMergedProperty($merged);

        $itemId = $this->queryFilterHelper->createMergedItemIdsQuery($last, $contactId)->executeQuery()->fetchOne();

        return $itemId ? (int) $itemId : null;
    }

    /**
     * @param mixed $value
     *
     * @return array<string, mixed>
     */
    private function segmentFilterArray(CustomField $customField, string $operator, $value): array
    {
        if ($customField->canHaveMultipleValues() && is_string($value)) {
            $value = [$value];
        }

        return [
            'glue'     => 'and',
            'field'    => 'cmf_'.$customField->getId(),
            'object'   => 'custom_object',
            'type'     => $customField->getType(),
            'filter'   => $value,
            'operator' => $operator,
            'display'  => null,
        ];
    }

    /**
     * @param mixed $value
     *
     * @return array<string, mixed>
     */
    private function itemNameFilterArray(int $customObjectId, string $operator, $value): array
    {
        return [
            'glue'     => 'and',
            'field'    => 'cmo_'.$customObjectId,
            'object'   => 'custom_object',
            'type'     => 'text',
            'filter'   => $value,
            'operator' => $operator,
            'display'  => null,
        ];
    }
}
