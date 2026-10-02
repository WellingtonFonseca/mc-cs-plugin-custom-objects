<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Helper;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\Expression\CompositeExpression;
use Doctrine\ORM\EntityManager;
use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use Mautic\LeadBundle\Segment\Query\QueryBuilder as SegmentQueryBuilder;
use Mautic\LeadBundle\Segment\RandomParameterName;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\AbstractMultivalueType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\AbstractTextType;
use MauticPlugin\CustomObjectsBundle\Exception\InvalidArgumentException;
use MauticPlugin\CustomObjectsBundle\Repository\DbalQueryTrait;
use MauticPlugin\CustomObjectsBundle\Segment\Query\UnionQueryContainer;

class QueryFilterHelper
{
    use DbalQueryTrait;

    public function __construct(
        private EntityManager $entityManager,
        private QueryFilterFactory $queryFilterFactory,
        private RandomParameterName $randomParameterNameService
    ) {
    }

    public function createValueQuery(
        string $alias,
        ContactSegmentFilter $segmentFilter,
        bool $filterAlreadyNegated = false
    ): UnionQueryContainer {
        $unionQueryContainer = $this->queryFilterFactory->createQuery($alias, $segmentFilter);
        $this->addCustomFieldValueExpressionFromSegmentFilter($unionQueryContainer, $alias, $segmentFilter, $filterAlreadyNegated);

        return $unionQueryContainer;
    }

    public function createItemNameQueryBuilder(string $queryBuilderAlias): SegmentQueryBuilder
    {
        $queryBuilder = new SegmentQueryBuilder($this->entityManager->getConnection());

        return $this->getBasicItemQueryBuilder($queryBuilder, $queryBuilderAlias);
    }

    /**
     * Limit the result to given contact Id, table used is selected by availability
     * CustomFieldValue and CustomItemName are supported.
     *
     * @throws InvalidArgumentException
     */
    public function addContactIdRestriction(SegmentQueryBuilder $queryBuilder, string $queryAlias, int $contactId): void
    {
        if (!$this->hasQueryJoinAlias($queryBuilder, $queryAlias.'_contact')) {
            if (!$this->hasQueryJoinAlias($queryBuilder, $queryAlias.'_value')) {
                throw new InvalidArgumentException('SegmentQueryBuilder contains no usable tables for contact restriction.');
            }
            $tableAlias = $queryAlias.'_contact.contact_id';
        } else {
            $tableAlias = $queryAlias.'_contact.contact_id';
        }
        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq($tableAlias, ':contact_id_'.$contactId)
        );
        $queryBuilder->setParameter('contact_id_'.$contactId, $contactId);
    }

    public function addCustomFieldValueExpressionFromSegmentFilter(
        UnionQueryContainer $unionQueryContainer,
        string $tableAlias,
        ContactSegmentFilter $filter,
        bool $filterAlreadyNegated = false
    ): void {
        $filterValue = $filter->getParameterValue();
        // Only text-like values are stored as '' when unfilled. Comparing a numeric
        // or date column with '' is true for 0 in MySQL, so there only NULL counts.
        $supportsEmptyString = AbstractTextType::TABLE_NAME === $this->queryFilterFactory->getTableNameFromType(
            $this->queryFilterFactory->getCustomFieldTypeById((int) $filter->getField()) ?: (string) $filter->getType()
        );
        // On a custom field, 'notIn' is the "not in" of a multiselect saved as
        // 'select'. It is the same as '!multiselect': the positive IN condition,
        // which the caller negates. Left as 'notIn' the condition was skipped.
        $operator = 'notIn' === $filter->getOperator() ? '!multiselect' : $filter->getOperator();
        foreach ($unionQueryContainer as $segmentQueryBuilder) {
            $valueParameter = $this->randomParameterNameService->generateRandomParameterName();
            $expression     = $this->getCustomValueValueExpression(
                $segmentQueryBuilder,
                $tableAlias,
                $filter,
                $valueParameter,
                $filterAlreadyNegated,
                $filterValue,
                $operator,
                $supportsEmptyString
            );

            $this->addOperatorExpression(
                $segmentQueryBuilder,
                $expression,
                $operator,
                $filterValue,
                $valueParameter
            );
        }
    }

    public function addCustomObjectNameExpression(
        SegmentQueryBuilder $queryBuilder,
        string $tableAlias,
        string $operator,
        ?string $value
    ): void {
        $valueParameter = $this->randomParameterNameService->generateRandomParameterName();
        $expression     = $this->getCustomObjectNameExpression($queryBuilder, $tableAlias, $operator, $valueParameter);
        $this->addOperatorExpression($queryBuilder, $expression, $operator, $value, $valueParameter);
    }

    /**
     * @param CompositeExpression|string            $expression
     * @param array|string|CompositeExpression|null $value
     */
    private function addOperatorExpression(
        SegmentQueryBuilder $segmentQueryBuilder,
        $expression,
        string $operator,
        $value,
        string $valueParameter
    ): void {
        $valueType = null;

        switch ($operator) {
            case 'empty':
            case 'notEmpty':
                break;
            case '!multiselect':
            case 'notIn':
            case 'multiselect':
            case 'in':
                $valueType      = ArrayParameterType::STRING;
                $segmentQueryBuilder->setParameter($valueParameter, $value, $valueType);
                break;
            default:
                $segmentQueryBuilder->setParameter($valueParameter, $value, $valueType);
        }

        switch ($operator) {
            case 'notIn':
                break;
            default:
                $segmentQueryBuilder->andWhere($expression);
                break;
        }
    }

    /**
     * Form the logical expression needed to limit the CustomValue's value for given operator.
     *
     * @param mixed $filterParameterValue
     *
     * @return CompositeExpression|string
     */
    private function getCustomValueValueExpression(
        SegmentQueryBuilder $customQuery,
        string $tableAlias,
        ContactSegmentFilter $filter,
        string $valueParameter,
        bool $alreadyNegated = false,
        $filterParameterValue = null,
        ?string $operator = null,
        ?bool $supportsEmptyString = null
    ) {
        $operator            = $operator ?? $filter->getOperator();
        $supportsEmptyString = $supportsEmptyString ?? $filter->doesColumnSupportEmptyValue();
        if ($alreadyNegated) {
            switch ($operator) {
                case 'empty':
                    $operator = 'notEmpty';
                    break;
                case 'neq':
                    $operator = 'eq';
                    break;
                case '!between':
                case 'notBetween':
                    $operator = 'between';
                    break;
            }
        }

        switch ($operator) {
            case 'empty':
                $expression = $customQuery->expr()->orX(
                    $customQuery->expr()->isNull($tableAlias.'_value.value'),
                );
                if ($supportsEmptyString) {
                    $expression->add(
                        $customQuery->expr()->eq($tableAlias.'_value.value', $customQuery->expr()->literal(''))
                    );
                }
                break;
            case 'notEmpty':
                $expression = $customQuery->expr()->and(
                    $customQuery->expr()->isNotNull($tableAlias.'_value.value'),
                );
                if ($supportsEmptyString) {
                    $expression->add(
                        $customQuery->expr()->neq($tableAlias.'_value.value', $customQuery->expr()->literal(''))
                    );
                }

                break;
            case 'notIn':
            case '!multiselect':
            case 'in':
            case 'multiselect':
                $expression     = $customQuery->expr()->in(
                    $tableAlias.'_value.value',
                    ":{$valueParameter}"
                );

                break;
            case 'neq':
                $expression     = $customQuery->expr()->or(
                    $customQuery->expr()->neq($tableAlias.'_value.value', ":{$valueParameter}"),
                    $customQuery->expr()->isNull($tableAlias.'_value.value')
                );

                break;
            case 'contains':
                $expression = $customQuery->expr()->like($tableAlias.'_value.value', "%:{$valueParameter}%");

                break;
            case 'notLike':
                $expression = $customQuery->expr()->or(
                    $customQuery->expr()->isNull($tableAlias.'_value.value'),
                    $customQuery->expr()->like($tableAlias.'_value.value', ":{$valueParameter}")
                );

                break;
            case 'between':
            case 'notBetween':
                if (is_array($filterParameterValue)) {
                    $expression = $customQuery->expr()->{$operator}(
                        $tableAlias.'_value.value',
                        array_map(function (mixed $val) use ($customQuery): mixed {
                            return is_numeric($val) && intval($val) === $val ?
                                $val : $customQuery->expr()->literal($val);
                        }, array_values($filterParameterValue))
                    );
                    break;
                }
                // no break
            default:
                $expression     = $customQuery->expr()->{$operator}(
                    $tableAlias.'_value.value',
                    ":{$valueParameter}"
                );
        }

        return $expression;
    }

    /**
     * Form the logical expression needed to limit the CustomValue's value for given operator.
     *
     * @return CompositeExpression|string
     */
    private function getCustomObjectNameExpression(
        SegmentQueryBuilder $customQuery,
        string $tableAlias,
        string $operator,
        string $valueParameter
    ) {
        return match ($operator) {
            'empty' => $customQuery->expr()->or(
                $customQuery->expr()->isNull($tableAlias.'_item.name'),
                $customQuery->expr()->eq($tableAlias.'_item.name', $customQuery->expr()->literal(''))
            ),
            'notEmpty' => $customQuery->expr()->and(
                $customQuery->expr()->isNotNull($tableAlias.'_item.name'),
                $customQuery->expr()->neq($tableAlias.'_item.name', $customQuery->expr()->literal(''))
            ),
            'notIn', 'in' => $customQuery->expr()->in(
                $tableAlias.'_item.name',
                ":{$valueParameter}"
            ),
            'neq' => $customQuery->expr()->orX(
                $customQuery->expr()->eq($tableAlias.'_item.name', ':'.$valueParameter),
                $customQuery->expr()->isNull($tableAlias.'_item.name')
            ),
            'notLike' => $customQuery->expr()->or(
                $customQuery->expr()->isNull($tableAlias.'_item.name'),
                $customQuery->expr()->like($tableAlias.'_item.name', ":{$valueParameter}")
            ),
            default => $customQuery->expr()->{$operator}(
                $tableAlias.'_item.name',
                ":{$valueParameter}"
            ),
        };
    }

    /**
     * Get all tables currently registered in the queryBuilder and check is alias is present.
     */
    private function hasQueryJoinAlias(SegmentQueryBuilder $queryBuilder, $alias): bool
    {
        $joins    = array_column($queryBuilder->getQueryParts()['join'], 0);
        $tables   = array_column($joins, 'joinAlias');
        $tables[] = $queryBuilder->getQueryParts()['from'][0]['alias'];

        return in_array($alias, $tables, true);
    }

    /**
     * Get basic query builder with contact reference and item join.
     */
    private function getBasicItemQueryBuilder(SegmentQueryBuilder $queryBuilder, string $alias): SegmentQueryBuilder
    {
        $customFieldQueryBuilder = $queryBuilder->createQueryBuilder();

        $customFieldQueryBuilder
            ->select('*')
            ->from(MAUTIC_TABLE_PREFIX.'custom_item_xref_contact', $alias.'_contact')
            ->leftJoin(
                $alias.'_contact',
                MAUTIC_TABLE_PREFIX.'custom_item',
                $alias.'_item',
                $alias.'_item.id='.$alias.'_contact.custom_item_id'
            );

        return $customFieldQueryBuilder;
    }

    public function createMergeFilterQuery(
        ContactSegmentFilter $segmentFilter,
        string $leadsTableAlias
    ): SegmentQueryBuilder {
        $customItemXrefContactAlias = 'cix';
        $qb                         = new SegmentQueryBuilder($this->entityManager->getConnection());
        $qb->select('1')
           ->from(MAUTIC_TABLE_PREFIX.'custom_item_xref_contact', $customItemXrefContactAlias)
           ->where($qb->expr()->eq($customItemXrefContactAlias.'.contact_id', $leadsTableAlias.'.id'));

        $joinedAlias = [];

        foreach ($segmentFilter->contactSegmentFilterCrate->getMergedProperty() as $filter) {
            $segmentFilterFieldId       = (int) $filter['field'];
            $isCmoFilter                = $filter['cmo_filter'] ?? false;
            // The type saved with a segment filter is the one the segment screen
            // uses ('select' for every choice field, multiselect included), not the
            // one that decides where the value is stored. The real field type does.
            $segmentFilterFieldType     = $isCmoFilter
                ? ($filter['type'] ?: 'text')
                : $this->queryFilterFactory->getCustomFieldTypeById($segmentFilterFieldId);
            $dataTable                  = $this->queryFilterFactory->getTableNameFromType($segmentFilterFieldType);
            $segmentMergedFilter        = $segmentFilter;
            $segmentFilterFieldOperator = (string) $filter['operator'];

            $alias                      = $customItemXrefContactAlias.'_'.$segmentFilterFieldId.'_'.$filter['type'];
            $aliasValue                 = $alias.'_value';
            $cinAlias                   = 'cin_'.$segmentFilterFieldId;
            $cinAliasItem               = $cinAlias.'_item';
            $valueParameter             = $this->randomParameterNameService->generateRandomParameterName();

            if (!$isCmoFilter
                && AbstractMultivalueType::TABLE_NAME === $dataTable
                && $this->addMergeOptionCondition(
                    $qb,
                    $customItemXrefContactAlias,
                    $segmentFilterFieldId,
                    $segmentFilterFieldOperator,
                    $filter['filter_value'],
                    $valueParameter
                )
            ) {
                continue;
            }

            if ($isCmoFilter && !in_array($cinAliasItem, $joinedAlias, true)) {
                $this->joinMergeCustomItem($qb, $customItemXrefContactAlias, $cinAliasItem, $segmentFilterFieldId);
                $joinedAlias[] = $cinAliasItem;
            } elseif (!in_array($aliasValue, $joinedAlias, true)) {
                $this->joinMergeCustomField(
                    $qb,
                    $customItemXrefContactAlias,
                    $dataTable,
                    $aliasValue,
                    $segmentFilterFieldId
                );
                $joinedAlias[] = $aliasValue;
            }

            $this->addOperatorExpression(
                $qb,
                $this->getMergeExpression(
                    $isCmoFilter,
                    $qb,
                    $cinAlias,
                    $alias,
                    $segmentMergedFilter,
                    $valueParameter,
                    $segmentFilterFieldOperator,
                    // Only text-like values are stored as '' when unfilled. Comparing a
                    // numeric or date column with '' is true for 0 in MySQL, so 0 would
                    // read as "empty": those columns are checked for NULL only.
                    AbstractTextType::TABLE_NAME === $dataTable
                ),
                $segmentFilterFieldOperator,
                $filter['filter_value'],
                $valueParameter
            );
        }

        return $qb;
    }

    /**
     * The items of ONE contact that satisfy all the criteria of a merged filter on
     * the same item: createMergeFilterQuery() (the one the segment runs) turned into
     * a query for the item ids. For code outside the plugin that needs to know WHICH
     * items matched (a segment only asks whether one exists), so it does not have to
     * know this query's aliases.
     */
    public function createMergedItemIdsQuery(ContactSegmentFilter $segmentFilter, int $contactId): SegmentQueryBuilder
    {
        $leadAlias = 'merged_items_lead';
        $qb        = $this->createMergeFilterQuery($segmentFilter, $leadAlias);
        $qb->select('DISTINCT cix.custom_item_id')
            ->innerJoin('cix', MAUTIC_TABLE_PREFIX.'leads', $leadAlias, "{$leadAlias}.id = cix.contact_id")
            ->andWhere("{$leadAlias}.id = :merged_items_contact_id")
            ->setParameter('merged_items_contact_id', $contactId);

        return $qb;
    }

    /**
     * A multiselect keeps one row per selected option, so its conditions are
     * checked per item with EXISTS / NOT EXISTS on those rows instead of a join
     * (an item with no option has no row to join, and "not in" must hold for
     * every row, not for some). Each condition gets its own subquery, so two
     * conditions on the same field can both hold on one item.
     *
     * @param mixed $value
     *
     * @return bool false when the operator is not one of these (the caller then
     *              uses the generic join)
     */
    private function addMergeOptionCondition(
        SegmentQueryBuilder $qb,
        string $customItemXrefContactAlias,
        int $fieldId,
        string $operator,
        $value,
        string $valueParameter
    ): bool {
        $optionAlias = "cixo_{$fieldId}_{$valueParameter}";
        $subQuery    = 'SELECT 1 FROM '.MAUTIC_TABLE_PREFIX.AbstractMultivalueType::TABLE_NAME." {$optionAlias}"
            ." WHERE {$optionAlias}.custom_item_id = {$customItemXrefContactAlias}.custom_item_id"
            ." AND {$optionAlias}.custom_field_id = {$fieldId}";

        switch ($operator) {
            case 'in':
            case 'multiselect':
                $qb->andWhere("EXISTS ({$subQuery} AND {$optionAlias}.value IN (:{$valueParameter}))");
                $qb->setParameter($valueParameter, (array) $value, ArrayParameterType::STRING);
                break;
            case 'notIn':
            case '!multiselect':
                $qb->andWhere("NOT EXISTS ({$subQuery} AND {$optionAlias}.value IN (:{$valueParameter}))");
                $qb->setParameter($valueParameter, (array) $value, ArrayParameterType::STRING);
                break;
            case 'empty':
                $qb->andWhere("NOT EXISTS ({$subQuery})");
                break;
            case 'notEmpty':
                $qb->andWhere("EXISTS ({$subQuery})");
                break;
            default:
                return false;
        }

        return true;
    }

    private function joinMergeCustomItem(
        SegmentQueryBuilder $qb,
        string $customItemXrefContactAlias,
        string $cinAliasItem,
        int $segmentFilterFieldId
    ): void {
        $qb->leftJoin(
            $customItemXrefContactAlias,
            MAUTIC_TABLE_PREFIX.'custom_item',
            $cinAliasItem,
            "$customItemXrefContactAlias.custom_item_id = $cinAliasItem.id"
        );
        $qb->andWhere($qb->expr()->eq($cinAliasItem.'.custom_object_id', $segmentFilterFieldId));
    }

    private function joinMergeCustomField(
        SegmentQueryBuilder $qb,
        string $customItemXrefContactAlias,
        string $dataTable,
        string $aliasValue,
        int $segmentFilterFieldId
    ): void {
        $qb->innerJoin(
            $customItemXrefContactAlias,
            MAUTIC_TABLE_PREFIX.$dataTable,
            $aliasValue,
            "$aliasValue.custom_item_id = $customItemXrefContactAlias.custom_item_id AND "
            ."$aliasValue.custom_field_id = $segmentFilterFieldId"
        );
    }

    /**
     * @phpstan-ignore-next-line
     *
     * @return CompositeExpression|string
     */
    private function getMergeExpression(
        bool $isCmoFilter,
        SegmentQueryBuilder $qb,
        string $cinAlias,
        string $alias,
        ContactSegmentFilter $filter,
        string $valueParameter,
        string $criterionOperator,
        bool $valueSupportsEmptyString
    ) {
        $segmentFilterFieldOperator = $criterionOperator;

        // The single-filter builders wrap these operators in NOT EXISTS, so their
        // expressions are the POSITIVE condition. The merged query has no such
        // wrapper (all criteria must hold on the same item), so they are negated
        // here, on the item's own value.
        $column = $isCmoFilter ? $cinAlias.'_item.name' : $alias.'_value.value';
        if ('notLike' === $criterionOperator) {
            return $qb->expr()->or(
                $qb->expr()->isNull($column),
                $qb->expr()->notLike($column, ":{$valueParameter}")
            );
        }
        if ($isCmoFilter && 'neq' === $criterionOperator) {
            return $qb->expr()->or(
                $qb->expr()->neq($column, ":{$valueParameter}"),
                $qb->expr()->isNull($column)
            );
        }

        if ($isCmoFilter) {
            $expression = $this->getCustomObjectNameExpression(
                $qb,
                $cinAlias,
                $segmentFilterFieldOperator,
                $valueParameter
            );
        } else {
            $expression = $this->getCustomValueValueExpression(
                $qb,
                $alias,
                $filter,
                $valueParameter,
                false,
                $filter->getParameterValue(),
                $criterionOperator,
                $valueSupportsEmptyString
            );
        }

        return $expression;
    }
}
