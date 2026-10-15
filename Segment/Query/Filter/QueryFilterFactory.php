<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Segment\Query\Filter;

use Mautic\LeadBundle\Segment\Query\QueryBuilder as SegmentQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Exception\InvalidSegmentFilterException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Helper\QueryFilterHelper;
use MauticPlugin\CustomObjectsBundle\Segment\Query\UnionQueryContainer;

class QueryFilterFactory
{
    public function __construct(
        private SegmentFilterBuilder $segmentFilterBuilder,
        private QueryFilterHelper $queryFilterHelper
    ) {
    }

    /**
     * @return UnionQueryContainer|SegmentQueryBuilder
     *
     * @throws InvalidSegmentFilterException
     * @throws NotFoundException
     * @throws Exception
     */
    public function configureQueryBuilderFromSegmentFilter(array $segmentFilter, string $queryAlias)
    {
        $isItemName    = str_starts_with((string) ($segmentFilter['field'] ?? ''), 'cmo_');
        $segmentFilter = $this->segmentFilterBuilder->factor($segmentFilter);

        if ($segmentFilter->getTable() !== MAUTIC_TABLE_PREFIX.'custom_objects') {
            throw new InvalidSegmentFilterException("{$segmentFilter->getTable()} filter table cannot be processed.");
        }

        $type = $segmentFilter->getQueryType();

        // With the merge filter ON core hands every Custom Object criterion to the merged builder. A condition outside a
        // segment (campaign, dynamic content, token) asks about ONE criterion, so it is answered by the single-criterion
        // query that matches what the criterion is: the item name (cmo_<object id>) or a field value (cmf_<field id>).
        if (CustomObjectMergedFilterQueryBuilder::getServiceId() === $type) {
            $type = $isItemName ? CustomItemNameFilterQueryBuilder::getServiceId() : CustomFieldFilterQueryBuilder::getServiceId();
        }

        if (CustomFieldFilterQueryBuilder::getServiceId() === $type) {
            $queryBuilder = $this->queryFilterHelper->createValueQuery(
                $queryAlias,
                $segmentFilter
            );
        } elseif (CustomItemNameFilterQueryBuilder::getServiceId() === $type) {
            $queryBuilder = $this->queryFilterHelper->createItemNameQueryBuilder($queryAlias);

            $this->queryFilterHelper->addCustomObjectNameExpression(
                $queryBuilder,
                $queryAlias,
                $segmentFilter->getOperator(),
                $segmentFilter->getParameterValue()
            );
        } else {
            throw new InvalidSegmentFilterException("{$type} filter query type cannot be processed.");
        }

        return $queryBuilder;
    }
}
