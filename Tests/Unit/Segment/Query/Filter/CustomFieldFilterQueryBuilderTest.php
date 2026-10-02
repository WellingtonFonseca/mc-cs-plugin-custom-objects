<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Segment\Query\Filter;

use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomFieldFilterQueryBuilder;

class CustomFieldFilterQueryBuilderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function operators(): iterable
    {
        foreach (['empty', 'neq', 'notLike', '!multiselect', 'notIn', '!between', 'notBetween'] as $operator) {
            yield "{$operator} is built as NOT EXISTS" => [$operator, true];
        }
        foreach (['eq', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'multiselect', 'notEmpty', 'between', 'startsWith', 'endsWith', 'contains'] as $operator) {
            yield "{$operator} is built as EXISTS" => [$operator, false];
        }
    }

    /**
     * A multiselect saved by the segment screen is typed 'select', so core does not
     * turn "not in" into '!multiselect': it stays 'notIn', and it is a negation too.
     *
     * @dataProvider operators
     */
    public function testWhichOperatorsAreNegations(string $operator, bool $negated): void
    {
        $this->assertSame($negated, CustomFieldFilterQueryBuilder::isNegatedOperator($operator));
    }

    public function testGetServiceId(): void
    {
        $this->assertSame('mautic.lead.query.builder.custom_field.value', CustomFieldFilterQueryBuilder::getServiceId());
    }
}
