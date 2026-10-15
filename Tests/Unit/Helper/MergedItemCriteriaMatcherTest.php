<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Helper;

use Doctrine\DBAL\Result;
use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use Mautic\LeadBundle\Segment\ContactSegmentFilterCrate;
use Mautic\LeadBundle\Segment\Query\QueryBuilder as SegmentQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Helper\MergedItemCriteriaMatcher;
use MauticPlugin\CustomObjectsBundle\Helper\QueryFilterHelper;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldModel;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\SegmentFilterBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Several criteria of a campaign condition must hold on the SAME item: they go into ONE merged filter, the one the
 * merged segment query (createMergedItemIdsQuery) answers.
 */
class MergedItemCriteriaMatcherTest extends TestCase
{
    private SegmentFilterBuilder&MockObject $factory;

    private QueryFilterHelper&MockObject $helper;

    private CustomFieldModel&MockObject $fieldModel;

    private MergedItemCriteriaMatcher $matcher;

    protected function setUp(): void
    {
        $this->factory    = $this->createMock(SegmentFilterBuilder::class);
        $this->helper     = $this->createMock(QueryFilterHelper::class);
        $this->fieldModel = $this->createMock(CustomFieldModel::class);
        $this->matcher    = new MergedItemCriteriaMatcher($this->factory, $this->helper, $this->fieldModel);
    }

    public function testNoCriteriaMatchesNothing(): void
    {
        $this->factory->expects($this->never())->method('factor');
        $this->helper->expects($this->never())->method('createMergedItemIdsQuery');

        $this->assertNull($this->matcher->findItemId([], 5, 3));
    }

    public function testAllCriteriaGoIntoOneMergedFilterInOrder(): void
    {
        $this->fieldModel->method('fetchEntity')->willReturnCallback(function (int $id): CustomField {
            $field = $this->createMock(CustomField::class);
            $field->method('getId')->willReturn($id);
            $field->method('getType')->willReturn('date');
            $field->method('canHaveMultipleValues')->willReturn(false);

            return $field;
        });

        $crate  = $this->createMock(ContactSegmentFilterCrate::class);
        $filter = [];
        $this->factory->method('factor')->willReturnCallback(function (array $array) use ($crate, &$filter): ContactSegmentFilter {
            $filter[] = $array;
            $mock     = $this->createMock(ContactSegmentFilter::class);
            $mock->contactSegmentFilterCrate = $crate;
            $mock->method('getOperator')->willReturn($array['operator']);
            $mock->method('getParameterValue')->willReturn($array['filter']);
            $mock->method('getField')->willReturn((int) substr($array['field'], 4));
            $mock->method('getType')->willReturn($array['type']);

            return $mock;
        });

        $merged = null;
        $crate->expects($this->once())->method('setMergedProperty')->willReturnCallback(function (array $property) use (&$merged): void {
            $merged = $property;
        });

        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn('42');
        $query = $this->createMock(SegmentQueryBuilder::class);
        $query->method('executeQuery')->willReturn($result);
        $this->helper->expects($this->once())->method('createMergedItemIdsQuery')->with($this->isInstanceOf(ContactSegmentFilter::class), 7)->willReturn($query);

        $itemId = $this->matcher->findItemId([
            ['field' => MergedItemCriteriaMatcher::ITEM_NAME, 'operator' => 'eq', 'value' => 'Disciplina X'],
            ['field' => '33', 'operator' => 'gte', 'value' => '-35 days'],
            ['field' => 34, 'operator' => 'lte', 'value' => '+10 days'],
        ], 7, 3);

        $this->assertSame(42, $itemId);
        $this->assertSame(['cmo_3', 'cmf_33', 'cmf_34'], array_column($filter, 'field'));
        $this->assertSame([3, 33, 34], array_column($merged, 'field'), 'the item name is filtered by the id of its Custom Object');
        $this->assertSame([true, false, false], array_column($merged, 'cmo_filter'));
        $this->assertSame(['eq', 'gte', 'lte'], array_column($merged, 'operator'));
        $this->assertSame(['Disciplina X', '-35 days', '+10 days'], array_column($merged, 'filter_value'));
    }

    public function testNoItemSatisfyingEveryCriterionIsNull(): void
    {
        $crate = $this->createMock(ContactSegmentFilterCrate::class);
        $this->factory->method('factor')->willReturnCallback(function (array $array) use ($crate): ContactSegmentFilter {
            $mock = $this->createMock(ContactSegmentFilter::class);
            $mock->contactSegmentFilterCrate = $crate;

            return $mock;
        });
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(false);
        $query = $this->createMock(SegmentQueryBuilder::class);
        $query->method('executeQuery')->willReturn($result);
        $this->helper->method('createMergedItemIdsQuery')->willReturn($query);

        $this->assertNull($this->matcher->findItemId([['field' => MergedItemCriteriaMatcher::ITEM_NAME, 'operator' => 'eq', 'value' => 'x']], 7, 3));
    }
}
