<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Segment;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use Mautic\LeadBundle\Segment\ContactSegmentFilterFactory;
use Mautic\LeadBundle\Segment\ContactSegmentFilters;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomFieldFilterQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomItemNameFilterQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomObjectMergedFilterQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Segment\SegmentItemMatcher;
use MauticPlugin\CustomObjectsBundle\Segment\SegmentItemResolver;
use PHPUnit\Framework\TestCase;

/**
 * Covers how SegmentItemResolver combines the segments' Custom Object conditions into the set of items they select. The SQL for
 * each single condition is SegmentItemMatcher's job (it reuses this plugin's own segment query builders) and is mocked here.
 *
 * Fixture: Custom Object #1 'disciplina' with fields #1 nome (text), #2 posicao (int), #3 inicio (date). Another object's
 * field is #9.
 */
class SegmentItemResolverTest extends TestCase
{
    private const OBJECT_ID = 1;

    private ContactSegmentFilterFactory $segmentFilterFactory;

    private SegmentItemMatcher $itemMatcher;

    private SegmentItemResolver $resolver;

    private CustomObject $customObject;

    private Lead $contact;

    protected function setUp(): void
    {
        $this->segmentFilterFactory = $this->createMock(ContactSegmentFilterFactory::class);
        $this->itemMatcher          = $this->createMock(SegmentItemMatcher::class);
        $this->itemMatcher->method('isContactInSegment')->willReturn(true);

        $this->resolver     = new SegmentItemResolver($this->segmentFilterFactory, $this->itemMatcher);
        $this->customObject = $this->buildCustomObject();
        $this->contact      = new Lead();
        $this->contact->setId(1);
    }

    private function buildCustomObject(): CustomObject
    {
        $customObject = $this->createMock(CustomObject::class);
        $customObject->method('getId')->willReturn(self::OBJECT_ID);
        $customObject->method('getCustomFields')->willReturn(new ArrayCollection([
            $this->buildField(1, 'nome', 'text'),
            $this->buildField(2, 'posicao', 'int'),
            $this->buildField(3, 'inicio', 'date'),
        ]));

        return $customObject;
    }

    private function buildField(int $id, string $alias, string $type): CustomField
    {
        $field = $this->createMock(CustomField::class);
        $field->method('getId')->willReturn($id);
        $field->method('getAlias')->willReturn($alias);
        $field->method('getType')->willReturn($type);

        return $field;
    }

    private function fieldFilter(int $fieldId, string $operator = 'eq', string $glue = 'and'): ContactSegmentFilter
    {
        return $this->segmentFilter(CustomFieldFilterQueryBuilder::getServiceId(), $fieldId, $operator, $glue);
    }

    private function itemNameFilter(int $objectId, string $operator = 'eq', string $glue = 'and'): ContactSegmentFilter
    {
        return $this->segmentFilter(CustomItemNameFilterQueryBuilder::getServiceId(), $objectId, $operator, $glue);
    }

    private function mergedFilter(string $glue = 'and'): ContactSegmentFilter
    {
        return $this->segmentFilter(CustomObjectMergedFilterQueryBuilder::getServiceId(), 2, 'lt', $glue);
    }

    /**
     * Declares which merged filters concern the resolved object and which
     * items each of them matches (all of its criteria on the SAME item).
     *
     * @param array<int, array{0: ContactSegmentFilter, 1: bool, 2: int[]}> $map
     */
    private function mergedMatches(array $map): void
    {
        $this->itemMatcher->method('mergedFilterConcernsObject')->willReturnCallback(
            static function (ContactSegmentFilter $filter) use ($map): bool {
                foreach ($map as [$mappedFilter, $concerns]) {
                    if ($mappedFilter === $filter) {
                        return $concerns;
                    }
                }

                return false;
            }
        );
        $this->itemMatcher->method('findMergedItemIds')->willReturnCallback(
            static function (ContactSegmentFilter $filter) use ($map): array {
                foreach ($map as [$mappedFilter, , $ids]) {
                    if ($mappedFilter === $filter) {
                        return $ids;
                    }
                }

                return [];
            }
        );
    }

    private function contactFieldFilter(string $glue = 'and'): ContactSegmentFilter
    {
        return $this->segmentFilter('mautic.lead.query.builder.basic', 'email', 'eq', $glue);
    }

    private function segmentFilter(string $queryType, int|string $field, string $operator, string $glue): ContactSegmentFilter
    {
        $filter = $this->createMock(ContactSegmentFilter::class);
        $filter->method('getQueryType')->willReturn($queryType);
        $filter->method('getField')->willReturn($field);
        $filter->method('getOperator')->willReturn($operator);
        $filter->method('getGlue')->willReturn($glue);

        return $filter;
    }

    /**
     * @param ContactSegmentFilter[] $filters
     *
     * @return LeadList[] the segments of the campaign (one)
     */
    private function segmentWith(array $filters): array
    {
        $segmentFilters = new ContactSegmentFilters();
        foreach ($filters as $filter) {
            $segmentFilters->addContactSegmentFilter($filter);
        }

        $list = new LeadList();
        $this->segmentFilterFactory->method('getSegmentFilters')->with($list)->willReturn($segmentFilters);

        return [$list];
    }

    /**
     * Maps each filter to the item ids its condition matches (the
     * "positive" set, before any negation).
     *
     * @param array<int, array{0: ContactSegmentFilter, 1: int[]}> $map
     */
    private function positiveMatches(array $map): void
    {
        $this->itemMatcher->method('findPositiveItemIds')->willReturnCallback(
            static function (ContactSegmentFilter $filter) use ($map): array {
                foreach ($map as [$mappedFilter, $ids]) {
                    if ($mappedFilter === $filter) {
                        return $ids;
                    }
                }

                return [];
            }
        );
    }

    public function testNoSegmentsMeansNothingSaysWhichItemsCount(): void
    {
        $this->assertNull($this->resolver->resolveItemIds($this->contact, [], $this->customObject));
    }

    public function testSegmentWithoutConditionsOnThisObjectSaysNothing(): void
    {
        $segments = $this->segmentWith([$this->contactFieldFilter(), $this->fieldFilter(9)]);

        $this->assertNull($this->resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }

    public function testSegmentTheContactIsNotInIsIgnored(): void
    {
        $segments = $this->segmentWith([$this->fieldFilter(2)]);

        $this->itemMatcher = $this->createMock(SegmentItemMatcher::class);
        $this->itemMatcher->method('isContactInSegment')->willReturn(false);
        $this->itemMatcher->expects($this->never())->method('findPositiveItemIds');
        $resolver = new SegmentItemResolver($this->segmentFilterFactory, $this->itemMatcher);

        $this->assertNull($resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }

    public function testASegmentTheContactIsNotInDoesNotStopTheNextOne(): void
    {
        $other = $this->fieldFilter(2);
        $first = new LeadList();
        $first->setName('not in');
        $second = new LeadList();
        $second->setName('in');
        $this->segmentFilterFactory->method('getSegmentFilters')->willReturnCallback(function (LeadList $list) use ($first, $other) {
            $filters = new ContactSegmentFilters();
            $filters->addContactSegmentFilter($other);

            return $list === $first ? new ContactSegmentFilters() : $filters;
        });
        $this->itemMatcher = $this->createMock(SegmentItemMatcher::class);
        $this->itemMatcher->method('isContactInSegment')->willReturnCallback(static fn (LeadList $list): bool => $list !== $first);
        $this->itemMatcher->method('findPositiveItemIds')->willReturn([3]);
        $resolver = new SegmentItemResolver($this->segmentFilterFactory, $this->itemMatcher);

        $this->assertSame([3], $resolver->resolveItemIds($this->contact, [$first, $second], $this->customObject));
    }

    public function testTheItemsAreUniqueAndInOrderWhateverTheOrderOfTheGroups(): void
    {
        $first    = $this->fieldFilter(2);
        $second   = $this->fieldFilter(2, 'eq', 'or');
        $segments = $this->segmentWith([$first, $second]);

        $this->positiveMatches([[$first, [9, 2, 5]], [$second, [5, 1]]]);

        $this->assertSame([1, 2, 5, 9], $this->resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }

    public function testTheOrGlueIsReadInAnyCase(): void
    {
        $first    = $this->fieldFilter(2);
        $second   = $this->fieldFilter(2, 'eq', 'OR');
        $segments = $this->segmentWith([$first, $second]);

        $this->positiveMatches([[$first, [1]], [$second, [2]]]);

        $this->assertSame([1, 2], $this->resolver->resolveItemIds($this->contact, $segments, $this->customObject), 'two groups added, not one group intersected');
    }

    public function testConditionsJoinedByAndMustAllMatchTheSameItem(): void
    {
        // posicao = 10 AND nome = 'xxxx': item 2 is the only one in both.
        $posicao  = $this->fieldFilter(2);
        $nome     = $this->fieldFilter(1);
        $segments = $this->segmentWith([$posicao, $nome]);

        $this->positiveMatches([[$posicao, [1, 2]], [$nome, [2, 3]]]);

        $this->assertSame([2], $this->resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }

    public function testAndConditionsWithNoCommonItemSelectNoItem(): void
    {
        $posicao  = $this->fieldFilter(2);
        $nome     = $this->fieldFilter(1);
        $segments = $this->segmentWith([$posicao, $nome]);

        $this->positiveMatches([[$posicao, [1]], [$nome, [3]]]);

        $this->assertSame([], $this->resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }

    public function testOrGroupsAddTheirItemsTogether(): void
    {
        // (posicao = 10 AND nome = 'a') OR (posicao = 20)
        $posicao10 = $this->fieldFilter(2);
        $nomeA     = $this->fieldFilter(1);
        $posicao20 = $this->fieldFilter(2, 'eq', 'or');
        $segments  = $this->segmentWith([$posicao10, $nomeA, $posicao20]);

        $this->positiveMatches([[$posicao10, [1, 2]], [$nomeA, [1]], [$posicao20, [4]]]);

        $this->assertSame([1, 4], $this->resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }

    /**
     * The segment screen types a multiselect 'select', so core leaves "not in" as
     * 'notIn' (it only becomes '!multiselect' for the type 'multiselect'). The
     * segment negates it all the same, so the resolver must too.
     */
    public function testNotInOnAMultiselectKeepsTheItemsWithoutTheOptions(): void
    {
        $tipoNotIn = $this->fieldFilter(2, 'notIn');
        $segments  = $this->segmentWith([$tipoNotIn]);

        $this->positiveMatches([[$tipoNotIn, [2]]]); // the items that HAVE the option
        $this->itemMatcher->method('findAllItemIds')->with($this->contact, self::OBJECT_ID)->willReturn([1, 2, 3]);

        $this->assertSame([1, 3], $this->resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }

    public function testNegatedOperatorKeepsTheItemsThatDoNotMatchThePositiveCondition(): void
    {
        // posicao = 10 AND nome != 'x' — mirrors the segment's NOT EXISTS:
        // for 'neq' the matcher returns the items WITH nome = 'x'.
        $posicao  = $this->fieldFilter(2);
        $nomeNeq  = $this->fieldFilter(1, 'neq');
        $segments = $this->segmentWith([$posicao, $nomeNeq]);

        $this->positiveMatches([[$posicao, [1, 2, 3]], [$nomeNeq, [2]]]);
        $this->itemMatcher->method('findAllItemIds')->with($this->contact, self::OBJECT_ID)->willReturn([1, 2, 3, 4]);

        $this->assertSame([1, 3], $this->resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }

    public function testItemNameConditionOnThisObjectIsApplied(): void
    {
        $posicao  = $this->fieldFilter(2);
        $itemName = $this->itemNameFilter(self::OBJECT_ID);
        $segments = $this->segmentWith([$posicao, $itemName]);

        $this->positiveMatches([[$posicao, [1, 2]], [$itemName, [2]]]);

        $this->assertSame([2], $this->resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }

    public function testConditionsOnOtherObjectsAndContactFieldsDoNotNarrowTheItems(): void
    {
        // email = ... AND otherObject.field = ... AND posicao = 10
        $email      = $this->contactFieldFilter();
        $otherField = $this->fieldFilter(9);
        $otherName  = $this->itemNameFilter(99);
        $posicao    = $this->fieldFilter(2);
        $segments   = $this->segmentWith([$email, $otherField, $otherName, $posicao]);

        $this->positiveMatches([[$posicao, [1, 2]]]);

        $this->assertSame([1, 2], $this->resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }

    public function testMergedFilterOnThisObjectUsesTheItemsItMatches(): void
    {
        // custom_object_merge_filter ON: "inicio < hoje AND fim > hoje" arrives as ONE merged filter.
        $merged   = $this->mergedFilter();
        $segments = $this->segmentWith([$merged]);

        $this->mergedMatches([[$merged, true, [2]]]);

        $this->assertSame([2], $this->resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }

    public function testMergedFilterWithNoMatchingItemSelectsNoItem(): void
    {
        $merged   = $this->mergedFilter();
        $segments = $this->segmentWith([$merged]);

        $this->mergedMatches([[$merged, true, []]]);

        $this->assertSame([], $this->resolver->resolveItemIds($this->contact, $segments, $this->customObject), 'there is a condition and no item satisfies it');
    }

    public function testMergedFilterOnAnotherObjectDoesNotNarrowTheItems(): void
    {
        $merged   = $this->mergedFilter();
        $segments = $this->segmentWith([$merged]);

        $this->mergedMatches([[$merged, false, [1, 2]]]);

        $this->assertNull($this->resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }

    public function testMergedFilterIsNarrowedByAnotherAndConditionOnTheSameItem(): void
    {
        $merged   = $this->mergedFilter();
        $nome     = $this->fieldFilter(1);
        $segments = $this->segmentWith([$merged, $nome]);

        $this->mergedMatches([[$merged, true, [1, 2]]]);
        $this->positiveMatches([[$nome, [2, 3]]]);

        $this->assertSame([2], $this->resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }

    public function testMergedFilterOrGroupAddsItsItemsToTheOtherGroup(): void
    {
        $posicao  = $this->fieldFilter(2);
        $merged   = $this->mergedFilter('or');
        $segments = $this->segmentWith([$posicao, $merged]);

        $this->positiveMatches([[$posicao, [1]]]);
        $this->mergedMatches([[$merged, true, [3]]]);

        $this->assertSame([1, 3], $this->resolver->resolveItemIds($this->contact, $segments, $this->customObject));
    }
}
