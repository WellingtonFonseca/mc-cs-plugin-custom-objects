<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Segment\Query\Filter;

use Doctrine\DBAL\DBALException;
use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use Mautic\LeadBundle\Segment\Query\Filter\BaseFilterQueryBuilder;
use Mautic\LeadBundle\Segment\Query\QueryBuilder;
use Mautic\LeadBundle\Segment\RandomParameterName;
use MauticPlugin\CustomObjectsBundle\Helper\QueryBuilderManipulatorTrait;
use MauticPlugin\CustomObjectsBundle\Helper\QueryFilterHelper;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class CustomItemNameFilterQueryBuilder extends BaseFilterQueryBuilder
{
    use QueryBuilderManipulatorTrait;

    public function __construct(
        RandomParameterName $randomParameterNameService,
        private QueryFilterHelper $filterHelper,
        EventDispatcherInterface $dispatcher
    ) {
        parent::__construct($randomParameterNameService, $dispatcher);
    }

    public static function getServiceId(): string
    {
        return 'mautic.lead.query.builder.custom_item.value';
    }

    /**
     * @throws DBALException
     */
    public function applyQuery(QueryBuilder $queryBuilder, ContactSegmentFilter $filter): QueryBuilder
    {
        $leadsTableAlias = $queryBuilder->getTableAlias(MAUTIC_TABLE_PREFIX.'leads');
        // getField() is 'cmo_<object id>' (SegmentFiltersDictionarySubscriber)
        $customObjectId  = (int) preg_replace('/\D/', '', (string) $filter->getField());

        $tableAlias = 'cin_'.$customObjectId;

        $filterQueryBuilder = $this->filterHelper->createItemNameQueryBuilder($tableAlias);

        $filterQueryBuilder->andWhere(
            $filterQueryBuilder->expr()->eq($tableAlias.'_item.custom_object_id', ':'.$tableAlias.'ObjectId')
        );

        $filterQueryBuilder->setParameter($tableAlias.'ObjectId', (int) $customObjectId);

        $value = $filter->getParameterValue();

        $this->filterHelper->addCustomObjectNameExpression(
            $filterQueryBuilder,
            $tableAlias,
            $filter->getOperator(),
            is_array($value) ? $value : (string) $value
        );

        $filterQueryBuilder->select($tableAlias.'_contact.contact_id as lead_id');
        $filterQueryBuilder->andWhere($leadsTableAlias.'.id = '.$tableAlias.'_contact.contact_id');

        match ($filter->getOperator()) {
            'empty', 'neq', 'notLike', 'notIn' => $queryBuilder->addLogic($queryBuilder->expr()->notExists($filterQueryBuilder->getSQL()), $filter->getGlue()),
            default                   => $queryBuilder->addLogic($queryBuilder->expr()->exists($filterQueryBuilder->getSQL()), $filter->getGlue()),
        };

        $this->copyParams($filterQueryBuilder, $queryBuilder);

        return $queryBuilder;
    }
}
