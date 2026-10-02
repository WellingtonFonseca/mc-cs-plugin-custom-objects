<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Helper;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueDate;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueDateTime;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueDecimal;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueInt;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueOption;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueText;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Exception\InvalidValueException;
use MauticPlugin\CustomObjectsBundle\Helper\ItemFieldFilter;
use PHPUnit\Framework\TestCase;

class ItemFieldFilterTest extends TestCase
{
    private ItemFieldFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new ItemFieldFilter();
    }

    /**
     * @return array<string, mixed> DQL and the parameters by name
     */
    private function apply(string $type, string $value, string $textMode = ItemFieldFilter::TEXT_CONTAINS, int $index = 0): array
    {
        $field = $this->createMock(CustomField::class);
        $field->method('getType')->willReturn($type);
        $field->method('getId')->willReturn(77);

        $qb = new QueryBuilder($this->createMock(EntityManagerInterface::class));
        $qb->select('ci')->from(CustomItem::class, 'ci');

        $this->filter->apply($qb, 'ci', $field, $value, $textMode, $index);

        $params = [];
        foreach ($qb->getParameters() as $parameter) {
            $params[$parameter->getName()] = $parameter->getValue();
        }

        return ['dql' => $qb->getDQL(), 'params' => $params];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function textTypes(): array
    {
        return [['text'], ['textarea'], ['email'], ['phone'], ['url'], ['hidden']];
    }

    /**
     * @dataProvider textTypes
     */
    public function testTextFamilyContainsIsCaseInsensitiveOnTheTextTable(string $type): void
    {
        $r = $this->apply($type, 'Disc');

        $this->assertStringContainsString('EXISTS', $r['dql']);
        $this->assertStringContainsString(CustomFieldValueText::class, $r['dql']);
        $this->assertStringContainsString('LOWER(', $r['dql']);
        $this->assertStringContainsString('LIKE', $r['dql']);
        $this->assertSame(77, $r['params']['sfield0']);
        $this->assertSame('%disc%', $r['params']['sval0']);
    }

    public function testContainsEscapesLikeWildcards(): void
    {
        $r = $this->apply('text', '50%_a!');

        $this->assertSame('%50!%!_a!!%', $r['params']['sval0']);
        $this->assertStringContainsString("ESCAPE '!'", $r['dql']);
    }

    public function testTextEqualsModeKeepsTheApiExactMatch(): void
    {
        $r = $this->apply('text', 'Disciplina 01', ItemFieldFilter::TEXT_EQUALS);

        $this->assertStringNotContainsString('LIKE', $r['dql']);
        $this->assertStringContainsString('.value = :sval0', $r['dql']);
        $this->assertSame('Disciplina 01', $r['params']['sval0']);
    }

    public function testSelectAndCountryAreExactEvenInContainsMode(): void
    {
        foreach (['select', 'country', 'radio_group'] as $type) {
            $r = $this->apply($type, 'sim');

            $this->assertStringContainsString(CustomFieldValueText::class, $r['dql']);
            $this->assertStringNotContainsString('LIKE', $r['dql']);
            $this->assertStringContainsString('.value = :sval0', $r['dql']);
            $this->assertSame('sim', $r['params']['sval0']);
        }
    }

    public function testMultiselectChecksTheOptionTable(): void
    {
        foreach (['multiselect', 'checkbox_group'] as $type) {
            $r = $this->apply($type, 'a');

            $this->assertStringContainsString(CustomFieldValueOption::class, $r['dql']);
            $this->assertStringContainsString('.value = :sval0', $r['dql']);
            $this->assertSame('a', $r['params']['sval0']);
        }
    }

    public function testIntEquality(): void
    {
        $r = $this->apply('int', '3');

        $this->assertStringContainsString(CustomFieldValueInt::class, $r['dql']);
        $this->assertStringContainsString('.value = :sval0', $r['dql']);
        $this->assertSame('3', $r['params']['sval0']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function numericCases(): array
    {
        return [
            'eq'         => ['3', '=', '3'],
            'gt'         => ['>3', '>', '3'],
            'gte'        => ['>=3', '>=', '3'],
            'lt'         => ['<3', '<', '3'],
            'lte'        => ['<=3', '<=', '3'],
            'explicit eq'=> ['=3', '=', '3'],
            'negative'   => ['>=-2', '>=', '-2'],
            'comma'      => ['>=0,5', '>=', '0.5'],
            'dot'        => ['0.5', '=', '0.5'],
            'spaces'     => ['>= 0.5', '>=', '0.5'],
        ];
    }

    /**
     * @dataProvider numericCases
     */
    public function testDecimalOperatorsAndSeparators(string $input, string $operator, string $number): void
    {
        $r = $this->apply('decimal', $input);

        $this->assertStringContainsString(CustomFieldValueDecimal::class, $r['dql']);
        $this->assertStringContainsString(".value {$operator} :sval0", $r['dql']);
        $this->assertSame($number, $r['params']['sval0']);
    }

    /**
     * @dataProvider numericCases
     */
    public function testIntAcceptsTheSameOperators(string $input, string $operator, string $number): void
    {
        $r = $this->apply('int', $input);

        $this->assertStringContainsString(".value {$operator} :sval0", $r['dql']);
        $this->assertSame($number, $r['params']['sval0']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidNumbers(): array
    {
        return [['int', 'abc'], ['int', ''], ['decimal', '>=x'], ['decimal', '1,2,3'], ['decimal', '>>1']];
    }

    /**
     * @dataProvider invalidNumbers
     */
    public function testInvalidNumberThrows(string $type, string $value): void
    {
        $this->expectException(InvalidValueException::class);

        $this->apply($type, $value);
    }

    /**
     * @return array<string, array{string, string, string, string|null}>
     */
    public static function dateCases(): array
    {
        // value, condition fragment on the single value column, :sval0, :sval1
        return [
            'br day'         => ['23/08/2026', '>= :sval0', '2026-08-23 00:00:00', '2026-08-24 00:00:00'],
            'iso day'        => ['2026-08-23', '>= :sval0', '2026-08-23 00:00:00', '2026-08-24 00:00:00'],
            'month rollover' => ['2026-08-31', '>= :sval0', '2026-08-31 00:00:00', '2026-09-01 00:00:00'],
            'year rollover'  => ['31/12/2026', '>= :sval0', '2026-12-31 00:00:00', '2027-01-01 00:00:00'],
            'explicit eq'    => ['=2026-08-23', '>= :sval0', '2026-08-23 00:00:00', '2026-08-24 00:00:00'],
        ];
    }

    /**
     * @dataProvider dateCases
     */
    public function testDateEqualityMatchesTheWholeDay(string $input, string $fragment, string $start, string $end): void
    {
        $r = $this->apply('date', $input);

        $this->assertStringContainsString(CustomFieldValueDate::class, $r['dql']);
        $this->assertStringContainsString($fragment, $r['dql']);
        $this->assertStringContainsString('< :svalend0', $r['dql']);
        $this->assertSame($start, $r['params']['sval0']);
        $this->assertSame($end, $r['params']['svalend0']);
        $this->assertStringNotContainsString('DATE(', $r['dql'], 'a range keeps the value index usable');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function dateComparisons(): array
    {
        return [
            'lt'  => ['<2026-09-01', '< :sval0', '2026-09-01 00:00:00'],
            'lte' => ['<=2026-09-01', '< :sval0', '2026-09-02 00:00:00'],
            'gt'  => ['>2026-09-01', '>= :sval0', '2026-09-02 00:00:00'],
            'gte' => ['>=01/09/2026', '>= :sval0', '2026-09-01 00:00:00'],
        ];
    }

    /**
     * @dataProvider dateComparisons
     */
    public function testDateComparisonsWorkOnWholeDays(string $input, string $fragment, string $bound): void
    {
        $r = $this->apply('date', $input);

        $this->assertStringContainsString($fragment, $r['dql']);
        $this->assertSame($bound, $r['params']['sval0']);
        $this->assertArrayNotHasKey('svalend0', $r['params']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDates(): array
    {
        return [['31/02/2026'], ['2026-13-01'], ['abc'], [''], ['2026/08/23'], ['23-08-2026'], ['>']];
    }

    /**
     * @dataProvider invalidDates
     */
    public function testInvalidDateThrows(string $value): void
    {
        $this->expectException(InvalidValueException::class);

        $this->apply('date', $value);
    }

    public function testDateFieldIgnoresATimeOfDay(): void
    {
        $r = $this->apply('date', '2026-09-20T00:00:00');

        $this->assertSame('2026-09-20 00:00:00', $r['params']['sval0']);
        $this->assertSame('2026-09-21 00:00:00', $r['params']['svalend0']);
    }

    public function testDatetimeWithOnlyTheDateMatchesTheWholeDay(): void
    {
        $r = $this->apply('datetime', '23/08/2026');

        $this->assertStringContainsString(CustomFieldValueDateTime::class, $r['dql']);
        $this->assertSame('2026-08-23 00:00:00', $r['params']['sval0']);
        $this->assertSame('2026-08-24 00:00:00', $r['params']['svalend0']);
    }

    public function testDatetimeWithATimeIsAnExactMatch(): void
    {
        foreach (['2026-08-23 10:30', '23/08/2026 10:30', '2026-08-23T10:30:00', '23/08/2026 10:30:00'] as $input) {
            $r = $this->apply('datetime', $input);

            $this->assertStringContainsString('.value = :sval0', $r['dql']);
            $this->assertSame('2026-08-23 10:30:00', $r['params']['sval0']);
            $this->assertArrayNotHasKey('svalend0', $r['params']);
        }
    }

    public function testDatetimeComparisonWithATimeIsDirect(): void
    {
        $r = $this->apply('datetime', '>=2026-08-23 10:30');

        $this->assertStringContainsString('.value >= :sval0', $r['dql']);
        $this->assertSame('2026-08-23 10:30:00', $r['params']['sval0']);
    }

    public function testDatetimeComparisonWithOnlyTheDateUsesDayBounds(): void
    {
        $this->assertSame('2026-08-24 00:00:00', $this->apply('datetime', '>2026-08-23')['params']['sval0']);
        $this->assertSame('2026-08-24 00:00:00', $this->apply('datetime', '<=2026-08-23')['params']['sval0']);
    }

    public function testInvalidTimeThrows(): void
    {
        $this->expectException(InvalidValueException::class);

        $this->apply('datetime', '2026-08-23 25:99');
    }

    public function testUnsupportedTypeThrows(): void
    {
        $this->assertFalse($this->filter->isSupported('unicorn'));
        $this->expectException(\UnexpectedValueException::class);

        $this->apply('unicorn', 'x');
    }

    public function testEveryRegisteredTypeIsSupported(): void
    {
        foreach (['text', 'textarea', 'email', 'phone', 'url', 'hidden', 'select', 'country', 'radio_group', 'int', 'decimal', 'date', 'datetime', 'multiselect', 'checkbox_group'] as $type) {
            $this->assertTrue($this->filter->isSupported($type), $type);
        }
    }

    public function testTwoTermsOnTheSameFieldDoNotShareParameters(): void
    {
        $field = $this->createMock(CustomField::class);
        $field->method('getType')->willReturn('date');
        $field->method('getId')->willReturn(5);

        $qb = new QueryBuilder($this->createMock(EntityManagerInterface::class));
        $qb->select('ci')->from(CustomItem::class, 'ci');

        $this->filter->apply($qb, 'ci', $field, '>=2026-08-01', ItemFieldFilter::TEXT_CONTAINS, 0);
        $this->filter->apply($qb, 'ci', $field, '<2026-09-01', ItemFieldFilter::TEXT_CONTAINS, 1);

        $params = [];
        foreach ($qb->getParameters() as $p) {
            $params[$p->getName()] = $p->getValue();
        }

        $this->assertSame('2026-08-01 00:00:00', $params['sval0']);
        $this->assertSame('2026-09-01 00:00:00', $params['sval1']);
        $this->assertSame(2, substr_count($qb->getDQL(), 'EXISTS'));
    }

    public function testConditionIsCorrelatedToTheRootAliasAndFilteredByFieldId(): void
    {
        $r = $this->apply('text', 'x');

        $this->assertStringContainsString('IDENTITY(', $r['dql']);
        $this->assertStringContainsString('= ci.id', $r['dql']);
        $this->assertStringContainsString(':sfield0', $r['dql']);
    }

    public function testValueNeverAppearsInsideTheDql(): void
    {
        $r = $this->apply('text', "x' OR 1=1 --");

        $this->assertStringNotContainsString('1=1', $r['dql']);
        $this->assertSame("%x' or 1=1 --%", $r['params']['sval0']);
    }
}
