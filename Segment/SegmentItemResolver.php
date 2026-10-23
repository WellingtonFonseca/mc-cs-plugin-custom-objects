<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Segment;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomFieldFilterQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomItemNameFilterQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomObjectMergedFilterQueryBuilder;

/**
 * Which of a contact's Custom Items of one object are the ones the segments "point at": the items that satisfy EVERY
 * condition the segment(s) have on that object. A contact can have many items of the same object; the campaign's source
 * segment(s) say which ones count (the dispatch variables and the campaign wait on a Custom Object date use this).
 *
 * - The filter list is Mautic's own (ContactSegmentFilterFactory::getSegmentFilters()), i.e. after the same rewriting the
 *   segment build applies, and grouped the same way: an 'or' glue starts a new group, conditions inside a group are ANDed.
 * - Inside a group, all conditions on this object must hold for the SAME item (intersection). Groups add their items
 *   together (union), and so do several segments.
 * - Conditions on contact fields or on other objects don't narrow the items; they only decide segment membership, which is
 *   respected by skipping segments the contact isn't currently in.
 * - Operators the segment applies as NOT EXISTS keep every item except the ones matching the positive condition (see
 *   SegmentItemMatcher).
 */
class SegmentItemResolver
{
    /**
     * Operators CustomFieldFilterQueryBuilder applies as NOT EXISTS.
     */
    private const NEGATED_FIELD_OPERATORS = ['empty', 'neq', 'notLike', '!multiselect', 'notIn', '!between', 'notBetween'];

    /**
     * Operators CustomItemNameFilterQueryBuilder applies as NOT EXISTS.
     */
    private const NEGATED_ITEM_NAME_OPERATORS = ['empty', 'neq', 'notLike'];

    public function __construct(
        private SegmentFilterSource $segmentFilterSource,
        private SegmentItemMatcher $itemMatcher,
    ) {
    }

    /**
     * The ids (sorted, distinct) of the contact's items of $customObject that the segments' conditions on that object select,
     * or null when none of the segments the contact is in has a condition on the object (nothing says which items count).
     * An empty list means there is a condition and no item satisfies it.
     *
     * @param iterable<LeadList> $segments
     *
     * @return int[]|null
     */
    public function resolveItemIds(Lead $contact, iterable $segments, CustomObject $customObject): ?array
    {
        $objectId = (int) $customObject->getId();
        $fieldIds = [];

        /** @var CustomField $field */
        foreach ($customObject->getCustomFields() as $field) {
            $fieldIds[] = (int) $field->getId();
        }

        $hasCondition = false;
        $itemIds      = [];

        foreach ($segments as $segment) {
            if (!$this->itemMatcher->isContactInSegment($segment, $contact)) {
                continue;
            }

            foreach ($this->groupByOr($this->segmentFilterSource->filtersFor($segment)) as $group) {
                $groupItemIds = null;

                foreach ($group as $filter) {
                    if (!$this->isConditionOnObject($filter, $objectId, $fieldIds)) {
                        continue;
                    }

                    $conditionItemIds = $this->findItemIds($filter, $contact, $objectId, $fieldIds);
                    $groupItemIds     = null === $groupItemIds ? $conditionItemIds : array_intersect($groupItemIds, $conditionItemIds);
                }

                if (null !== $groupItemIds) {
                    $hasCondition = true;
                    $itemIds      = array_merge($itemIds, $groupItemIds);
                }
            }
        }

        if (!$hasCondition) {
            return null;
        }

        $itemIds = array_values(array_unique($itemIds));
        sort($itemIds);

        return $itemIds;
    }

    /**
     * @return ContactSegmentFilter[][]
     */
    private function groupByOr(iterable $filters): array
    {
        $groups  = [];
        $current = [];

        foreach ($filters as $filter) {
            if ('or' === strtolower((string) $filter->getGlue()) && [] !== $current) {
                $groups[] = $current;
                $current  = [];
            }

            $current[] = $filter;
        }

        if ([] !== $current) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * @param int[] $fieldIds
     */
    private function isConditionOnObject(ContactSegmentFilter $filter, int $objectId, array $fieldIds): bool
    {
        return match ($filter->getQueryType()) {
            CustomFieldFilterQueryBuilder::getServiceId()        => in_array((int) $filter->getField(), $fieldIds, true),
            CustomItemNameFilterQueryBuilder::getServiceId()     => (int) $filter->getField() === $objectId,
            CustomObjectMergedFilterQueryBuilder::getServiceId() => $this->itemMatcher->mergedFilterConcernsObject($filter, $objectId, $fieldIds),
            default                                              => false,
        };
    }

    /**
     * @param int[] $fieldIds
     *
     * @return int[]
     */
    private function findItemIds(ContactSegmentFilter $filter, Lead $contact, int $objectId, array $fieldIds): array
    {
        // Merged filter (custom_object_merge_filter ON): its criteria are applied
        // as written, on the same item, so there is no positive set to negate.
        if (CustomObjectMergedFilterQueryBuilder::getServiceId() === $filter->getQueryType()) {
            return $this->itemMatcher->findMergedItemIds($filter, $contact, $objectId, $fieldIds);
        }

        $positive = $this->itemMatcher->findPositiveItemIds($filter, $contact, $objectId);

        $negatedOperators = CustomItemNameFilterQueryBuilder::getServiceId() === $filter->getQueryType()
            ? self::NEGATED_ITEM_NAME_OPERATORS
            : self::NEGATED_FIELD_OPERATORS;

        if (!in_array($filter->getOperator(), $negatedOperators, true)) {
            return $positive;
        }

        return array_values(array_diff($this->itemMatcher->findAllItemIds($contact, $objectId), $positive));
    }
}
