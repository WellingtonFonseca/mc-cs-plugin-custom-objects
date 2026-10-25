<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Segment\Query\Filter;

use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use Mautic\LeadBundle\Segment\Query\QueryBuilder as SegmentQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Exception\InvalidSegmentFilterException;
use MauticPlugin\CustomObjectsBundle\Helper\QueryFilterHelper;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomFieldFilterQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomItemNameFilterQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomObjectMergedFilterQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\QueryFilterFactory;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\SegmentFilterBuilder;
use MauticPlugin\CustomObjectsBundle\Segment\Query\UnionQueryContainer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class QueryFilterFactoryTest extends TestCase
{
    /**
     * @var SegmentFilterBuilder|MockObject
     */
    private $contactSegmentFilterFactory;

    /**
     * @var QueryFilterHelper|MockObject
     */
    private $queryFilterHelper;

    /**
     * @var ContactSegmentFilter|MockObject
     */
    private $contactSegmentFilter;

    /**
     * @var SegmentQueryBuilder|MockObject
     */
    private $segmentQueryBuilder;

    /**
     * @var UnionQueryContainer|MockObject
     */
    private $unionQueryContainer;

    /**
     * @var QueryFilterFactory
     */
    private $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contactSegmentFilterFactory = $this->createMock(SegmentFilterBuilder::class);
        $this->queryFilterHelper           = $this->createMock(QueryFilterHelper::class);
        $this->contactSegmentFilter        = $this->createMock(ContactSegmentFilter::class);
        $this->segmentQueryBuilder         = $this->createStub(SegmentQueryBuilder::class);
        $this->unionQueryContainer         = $this->createStub(UnionQueryContainer::class);
        $this->factory                     = new QueryFilterFactory(
            $this->contactSegmentFilterFactory,
            $this->queryFilterHelper
        );
    }

    public function testConfigureQueryBuilderFromSegmentFilterWithNotCustomObjectFilter(): void
    {
        $queryAlias    = 'filter_0';
        $segmentFilter = ['not relevant'];

        $this->contactSegmentFilterFactory->expects($this->once())
            ->method('factor')
            ->with($segmentFilter)
            ->willReturn($this->contactSegmentFilter);

        $this->contactSegmentFilter->expects($this->exactly(2))
            ->method('getTable')
            ->willReturn('unicorn');

        $this->contactSegmentFilter->expects($this->never())
            ->method('getQueryType');

        $this->expectException(InvalidSegmentFilterException::class);

        $this->factory->configureQueryBuilderFromSegmentFilter($segmentFilter, $queryAlias);
    }

    public function testConfigureQueryBuilderFromSegmentFilterForCustomField(): void
    {
        $fieldId       = 1;
        $fieldType     = 'text';
        $queryAlias    = 'filter_0';
        $segmentFilter = [
            'glue'     => 'and',
            'field'    => 'cmf_'.$fieldId,
            'object'   => 'custom_object',
            'type'     => $fieldType,
            'filter'   => '23',
            'display'  => null,
            'operator' => '=',
        ];

        $this->contactSegmentFilterFactory->expects($this->once())
            ->method('factor')
            ->with($segmentFilter)
            ->willReturn($this->contactSegmentFilter);

        $this->contactSegmentFilter->expects($this->once())
            ->method('getTable')
            ->willReturn(MAUTIC_TABLE_PREFIX.'custom_objects');

        $this->contactSegmentFilter->expects($this->once())
            ->method('getQueryType')
            ->willReturn(CustomFieldFilterQueryBuilder::getServiceId());

        $this->queryFilterHelper->expects($this->once())
            ->method('createValueQuery')
            ->with(
                $queryAlias,
                $this->contactSegmentFilter
            )
            ->willReturn($this->unionQueryContainer);

        $this->assertSame(
            $this->unionQueryContainer,
            $this->factory->configureQueryBuilderFromSegmentFilter($segmentFilter, $queryAlias)
        );
    }

    public function testConfigureQueryBuilderFromSegmentFilterForCustomItem(): void
    {
        $fieldId       = 1;
        $fieldType     = 'text';
        $operator      = '=';
        $value         = '23';
        $queryAlias    = 'filter_0';
        $segmentFilter = [
            'glue'     => 'and',
            'field'    => 'cmf_'.$fieldId,
            'object'   => 'custom_object',
            'type'     => $fieldType,
            'filter'   => $value,
            'display'  => null,
            'operator' => $operator,
        ];

        $this->contactSegmentFilterFactory->expects($this->once())
            ->method('factor')
            ->with($segmentFilter)
            ->willReturn($this->contactSegmentFilter);

        $this->contactSegmentFilter->expects($this->once())
            ->method('getTable')
            ->willReturn(MAUTIC_TABLE_PREFIX.'custom_objects');

        $this->contactSegmentFilter->expects($this->once())
            ->method('getQueryType')
            ->willReturn(CustomItemNameFilterQueryBuilder::getServiceId());

        $this->contactSegmentFilter->expects($this->once())
            ->method('getOperator')
            ->willReturn($operator);

        $this->contactSegmentFilter->expects($this->once())
            ->method('getParameterValue')
            ->willReturn($value);

        $this->queryFilterHelper->expects($this->once())
            ->method('createItemNameQueryBuilder')
            ->with($queryAlias)
            ->willReturn($this->segmentQueryBuilder);

        $this->queryFilterHelper->expects($this->once())
            ->method('addCustomObjectNameExpression')
            ->with(
                $this->segmentQueryBuilder,
                $queryAlias,
                $operator,
                $value
            );

        $this->assertSame(
            $this->segmentQueryBuilder,
            $this->factory->configureQueryBuilderFromSegmentFilter($segmentFilter, $queryAlias)
        );
    }

    /**
     * With the merge filter ON core gives every Custom Object criterion the merged query type. A campaign condition (and a
     * token / dynamic content) asks about one criterion, so a field value gets the single value query.
     */
    public function testMergedQueryTypeOfAFieldValueIsAnsweredByTheValueQuery(): void
    {
        $segmentFilter = ['glue' => 'and', 'field' => 'cmf_1', 'object' => 'custom_object', 'type' => 'date', 'filter' => 'today', 'display' => null, 'operator' => '='];

        $this->contactSegmentFilterFactory->method('factor')->willReturn($this->contactSegmentFilter);
        $this->contactSegmentFilter->method('getTable')->willReturn(MAUTIC_TABLE_PREFIX.'custom_objects');
        $this->contactSegmentFilter->method('getQueryType')->willReturn(CustomObjectMergedFilterQueryBuilder::getServiceId());
        $this->queryFilterHelper->expects($this->once())->method('createValueQuery')->with('filter_0', $this->contactSegmentFilter)->willReturn($this->unionQueryContainer);
        $this->queryFilterHelper->expects($this->never())->method('createItemNameQueryBuilder');

        $this->assertSame($this->unionQueryContainer, $this->factory->configureQueryBuilderFromSegmentFilter($segmentFilter, 'filter_0'));
    }

    public function testMergedQueryTypeOfAnItemNameIsAnsweredByTheItemNameQuery(): void
    {
        $segmentFilter = ['glue' => 'and', 'field' => 'cmo_1', 'object' => 'custom_object', 'type' => 'text', 'filter' => 'x', 'display' => null, 'operator' => '='];

        $this->contactSegmentFilterFactory->method('factor')->willReturn($this->contactSegmentFilter);
        $this->contactSegmentFilter->method('getTable')->willReturn(MAUTIC_TABLE_PREFIX.'custom_objects');
        $this->contactSegmentFilter->method('getQueryType')->willReturn(CustomObjectMergedFilterQueryBuilder::getServiceId());
        $this->contactSegmentFilter->method('getOperator')->willReturn('=');
        $this->contactSegmentFilter->method('getParameterValue')->willReturn('x');
        $this->queryFilterHelper->expects($this->once())->method('createItemNameQueryBuilder')->with('filter_0')->willReturn($this->segmentQueryBuilder);
        $this->queryFilterHelper->expects($this->never())->method('createValueQuery');

        $this->assertSame($this->segmentQueryBuilder, $this->factory->configureQueryBuilderFromSegmentFilter($segmentFilter, 'filter_0'));
    }

    public function testConfigureQueryBuilderFromSegmentFilterForInvalidType(): void
    {
        $queryAlias    = 'filter_0';
        $segmentFilter = [
            'glue'     => 'and',
            'field'    => 'cmf_1',
            'object'   => 'custom_object',
            'type'     => 'text',
            'filter'   => '23',
            'display'  => null,
            'operator' => '=',
        ];

        $this->contactSegmentFilterFactory->expects($this->once())
            ->method('factor')
            ->with($segmentFilter)
            ->willReturn($this->contactSegmentFilter);

        $this->contactSegmentFilter->expects($this->once())
            ->method('getTable')
            ->willReturn(MAUTIC_TABLE_PREFIX.'custom_objects');

        $this->contactSegmentFilter->expects($this->once())
            ->method('getQueryType')
            ->willReturn('unicorn');

        $this->expectException(InvalidSegmentFilterException::class);
        $this->factory->configureQueryBuilderFromSegmentFilter($segmentFilter, $queryAlias);
    }
}
