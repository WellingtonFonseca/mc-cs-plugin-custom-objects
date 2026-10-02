<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Helper;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Mautic\LeadBundle\Provider\FilterOperatorProviderInterface;
use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use Mautic\LeadBundle\Segment\ContactSegmentFilterCrate;
use Mautic\LeadBundle\Segment\Query\Expression\ExpressionBuilder;
use Mautic\LeadBundle\Segment\Query\QueryBuilder;
use Mautic\LeadBundle\Segment\RandomParameterName;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\CheckboxGroupType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\CountryType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\DateTimeType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\DecimalType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\EmailType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\HiddenType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\IntType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\PhoneType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\RadioGroupType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\TextareaType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\UrlType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\CustomFieldTypeInterface;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\DateType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\MultiselectType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\SelectType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\TextType;
use MauticPlugin\CustomObjectsBundle\Helper\CsvHelper;
use MauticPlugin\CustomObjectsBundle\Helper\QueryFilterFactory;
use MauticPlugin\CustomObjectsBundle\Helper\QueryFilterHelper;
use MauticPlugin\CustomObjectsBundle\Provider\CustomFieldTypeProvider;
use MauticPlugin\CustomObjectsBundle\Repository\CustomFieldRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class QueryFilterHelperTest extends TestCase
{
    /**
     * @var QueryFilterHelper
     */
    private $queryFilterHelper;

    /**
     * @var QueryBuilder|MockObject
     */
    private $queryBuilder;

    /**
     * @var ExpressionBuilder|MockObject
     */
    private $expressionBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager
            ->method('getConnection')
            ->willReturn($this->createMock(Connection::class));

        $this->queryFilterHelper = new QueryFilterHelper(
            $entityManager,
            new QueryFilterFactory(
                $entityManager,
                new CustomFieldTypeProvider(),
                $this->createMock(CustomFieldRepository::class),
                new QueryFilterFactory\Calculator(),
                1
            ),
            new RandomParameterName()
        );
        $this->queryBuilder      = $this->createMock(QueryBuilder::class);
        $this->expressionBuilder = $this->createMock(ExpressionBuilder::class);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testAddCustomObjectNameExpression(): void
    {
        $this->queryBuilder
            ->expects($this->any())
            ->method('expr')
            ->willReturn($this->expressionBuilder);

        $this->expressionBuilder
            ->expects($this->any())
            ->method('eq')
            ->willReturn($this->expressionBuilder);

        $this->queryBuilder
            ->expects($this->any())
            ->method('andWhere')
            ->with($this->expressionBuilder);

        $this->queryBuilder
            ->expects($this->any())
            ->method('setParameter')
            ->with('par0', 'acquia', null);

        $this->queryFilterHelper
            ->addCustomObjectNameExpression($this->queryBuilder, 'test', 'eq', 'acquia');
    }

    public function testAddCustomObjectNameExpressionWithErrorForIntegerValue(): void
    {
        $this->expectException(\TypeError::class);
        $this->queryBuilder
            ->expects($this->any())
            ->method('expr')
            ->willReturn($this->expressionBuilder);

        $this->expressionBuilder
            ->expects($this->any())
            ->method('eq')
            ->willReturn($this->expressionBuilder);

        $this->queryBuilder
            ->expects($this->any())
            ->method('andWhere')
            ->with($this->expressionBuilder);

        $this->queryBuilder
            ->expects($this->any())
            ->method('setParameter')
            ->with('test_value_value', 10, null);

        $this->queryFilterHelper
            ->addCustomObjectNameExpression($this->queryBuilder, 'test', 'eq', 10);
    }

    /**
     * Each criterion of a merged filter must keep its own operator, even though
     * the merged filter itself reports a single one.
     */
    public function testCreateMergeFilterQueryKeepsOperatorOfEachCriterion(): void
    {
        $sql = $this->mergedSql([
            ['operator' => 'lt', 'filter_value' => '2026-10-01', 'field' => '13', 'type' => 'date', 'cmo_filter' => false],
            ['operator' => 'gt', 'filter_value' => '2026-10-01', 'field' => '12', 'type' => 'date', 'cmo_filter' => false],
        ]);

        $this->assertMatchesRegularExpression('/cix_13_date_value\.value < :/', $sql);
        $this->assertMatchesRegularExpression('/cix_12_date_value\.value > :/', $sql);
    }

    /**
     * The merged query has no NOT EXISTS around it (unlike the single-filter
     * builders), so a negated operator must be built as the real negation on
     * the item's own value, not as its positive condition.
     */
    public function testMergedNotLikeOnAFieldNegatesTheValue(): void
    {
        $sql = $this->mergedSql([
            ['operator' => 'notLike', 'filter_value' => '%(C)%', 'field' => '14', 'type' => 'text', 'cmo_filter' => false],
        ]);

        $this->assertMatchesRegularExpression('/\(\(cix_14_text_value\.value IS NULL\) OR \(cix_14_text_value\.value NOT LIKE :par\d+\)\)/', $sql);
    }

    public function testMergedNotEqualOnAnItemNameNegatesTheName(): void
    {
        $sql = $this->mergedSql([
            ['operator' => 'neq', 'filter_value' => 'Disciplina 1', 'field' => '1', 'type' => 'text', 'cmo_filter' => true],
        ]);

        $this->assertMatchesRegularExpression('/\(\(cin_1_item\.name <> :par\d+\) OR \(cin_1_item\.name IS NULL\)\)/', $sql);
    }

    public function testMergedNotLikeOnAnItemNameNegatesTheName(): void
    {
        $sql = $this->mergedSql([
            ['operator' => 'notLike', 'filter_value' => '%(C)%', 'field' => '1', 'type' => 'text', 'cmo_filter' => true],
        ]);

        $this->assertMatchesRegularExpression('/\(\(cin_1_item\.name IS NULL\) OR \(cin_1_item\.name NOT LIKE :par\d+\)\)/', $sql);
    }

    /**
     * Operators that already work in the merged query must stay as they are.
     */
    public function testMergedNotEqualOnAFieldKeepsItsExpression(): void
    {
        $sql = $this->mergedSql([
            ['operator' => 'neq', 'filter_value' => 'x', 'field' => '14', 'type' => 'text', 'cmo_filter' => false],
        ]);

        $this->assertMatchesRegularExpression('/\(\(cix_14_text_value\.value <> :par\d+\) OR \(cix_14_text_value\.value IS NULL\)\)/', $sql);
    }

    /**
     * The value table comes from the real field type (multiselect values live in
     * custom_field_value_option), not from the 'select' type the segment saved.
     */
    public function testMergedInOnAMultiselectReadsTheOptionTable(): void
    {
        $sql = $this->mergedSql([
            ['operator' => 'in', 'filter_value' => ['pos'], 'field' => '15', 'type' => 'select', 'cmo_filter' => false],
        ]);

        $this->assertStringNotContainsString('custom_field_value_text', $sql);
        $this->assertMatchesRegularExpression('/AND \(EXISTS \(SELECT 1 FROM custom_field_value_option (\w+) WHERE \1\.custom_item_id = cix\.custom_item_id AND \1\.custom_field_id = 15 AND \1\.value IN \(:par\w+\)\)\)/', $sql);
    }

    public function testMergedNotInOnAMultiselectIsNotExists(): void
    {
        $sql = $this->mergedSql([
            ['operator' => 'notIn', 'filter_value' => ['pos'], 'field' => '15', 'type' => 'select', 'cmo_filter' => false],
        ]);

        $this->assertMatchesRegularExpression('/AND \(NOT EXISTS \(SELECT 1 FROM custom_field_value_option (\w+) WHERE \1\.custom_item_id = cix\.custom_item_id AND \1\.custom_field_id = 15 AND \1\.value IN \(:par\w+\)\)\)/', $sql);
    }

    public function testMergedEmptyOnAMultiselectIsNotExistsOfAnyOption(): void
    {
        $sql = $this->mergedSql([
            ['operator' => 'empty', 'filter_value' => null, 'field' => '15', 'type' => 'select', 'cmo_filter' => false],
        ]);

        $this->assertMatchesRegularExpression('/AND \(NOT EXISTS \(SELECT 1 FROM custom_field_value_option (\w+) WHERE \1\.custom_item_id = cix\.custom_item_id AND \1\.custom_field_id = 15\)\)/', $sql);
    }

    public function testMergedNotEmptyOnAMultiselectIsExistsOfAnyOption(): void
    {
        $sql = $this->mergedSql([
            ['operator' => 'notEmpty', 'filter_value' => null, 'field' => '15', 'type' => 'select', 'cmo_filter' => false],
        ]);

        $this->assertMatchesRegularExpression('/AND \(EXISTS \(SELECT 1 FROM custom_field_value_option (\w+) WHERE \1\.custom_item_id = cix\.custom_item_id AND \1\.custom_field_id = 15\)\)/', $sql);
    }

    /**
     * Two conditions on the same multiselect are each their own EXISTS on the
     * item, so "has pos" AND "has grad" can both hold on one item.
     */
    public function testMergedTwoConditionsOnTheSameMultiselectAreIndependent(): void
    {
        $sql = $this->mergedSql([
            ['operator' => 'in', 'filter_value' => ['pos'], 'field' => '15', 'type' => 'select', 'cmo_filter' => false],
            ['operator' => 'in', 'filter_value' => ['grad'], 'field' => '15', 'type' => 'select', 'cmo_filter' => false],
        ]);

        $this->assertSame(2, substr_count($sql, 'EXISTS (SELECT 1 FROM custom_field_value_option'));
        $this->assertSame(2, preg_match_all('/custom_field_value_option (\w+) WHERE/', $sql, $aliases));
        $this->assertNotSame($aliases[1][0], $aliases[1][1]);
    }

    private const FIELD_TYPE_CLASSES = [
        CheckboxGroupType::class, CountryType::class, DateTimeType::class, DateType::class, DecimalType::class,
        EmailType::class, HiddenType::class, IntType::class, MultiselectType::class, PhoneType::class,
        RadioGroupType::class, SelectType::class, TextType::class, TextareaType::class, UrlType::class,
    ];

    /**
     * Builds a field type with a mock for every constructor argument.
     */
    private function createFieldType(string $typeClass): CustomFieldTypeInterface
    {
        $arguments = [];
        foreach ((new \ReflectionClass($typeClass))->getConstructor()->getParameters() as $parameter) {
            $arguments[] = $this->createMock($parameter->getType()->getName());
        }

        return new $typeClass(...$arguments);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function fieldTypeTables(): iterable
    {
        $text   = 'custom_field_value_text';
        $option = 'custom_field_value_option';
        $tables = [
            CheckboxGroupType::class => $option, MultiselectType::class => $option,
            DateType::class          => 'custom_field_value_date',
            DateTimeType::class      => 'custom_field_value_datetime',
            DecimalType::class       => 'custom_field_value_decimal',
            IntType::class           => 'custom_field_value_int',
            CountryType::class       => $text, EmailType::class => $text, HiddenType::class => $text,
            PhoneType::class         => $text, RadioGroupType::class => $text, SelectType::class => $text,
            TextType::class          => $text, TextareaType::class => $text, UrlType::class => $text,
        ];

        foreach ($tables as $typeClass => $table) {
            yield substr($typeClass, strrpos($typeClass, '\\') + 1) => [$typeClass, $table];
        }
    }

    /**
     * Whatever type the segment filter carries, the value is read from the table
     * of the REAL field type, for every field type of the plugin.
     *
     * @dataProvider fieldTypeTables
     */
    public function testMergedReadsTheValueTableOfTheRealFieldType(string $typeClass, string $expectedTable): void
    {
        $this->assertContains($typeClass, self::FIELD_TYPE_CLASSES);
        $key = $this->createFieldType($typeClass)->getKey();

        $sql = $this->mergedSql(
            [['operator' => 'notEmpty', 'filter_value' => null, 'field' => '20', 'type' => 'select', 'cmo_filter' => false]],
            [20 => $key]
        );

        if ('custom_field_value_option' === $expectedTable) {
            $this->assertStringContainsString('FROM custom_field_value_option cixo_20_', $sql);
            $this->assertStringNotContainsString('INNER JOIN', $sql);
        } else {
            $this->assertStringContainsString("INNER JOIN {$expectedTable} cix_20_select_value", $sql);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $criteria merged_property of a merged filter
    /**
     * @param array<int, array<string, mixed>> $criteria   merged_property of a merged filter
     * @param array<int, string>               $fieldTypes real field type key by field id (adds to the defaults)
     */
    private function mergedSql(array $criteria, array $fieldTypes = []): string
    {
        if (!defined('MAUTIC_TABLE_PREFIX')) {
            define('MAUTIC_TABLE_PREFIX', '');
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getConnection')->willReturn($connection);

        // Real field type by id: 12/13 date, 14 text, 15 multiselect (the segment
        // filter itself carries the UI type, 'select' for every choice field).
        $fieldTypes            = $fieldTypes + [12 => 'date', 13 => 'date', 14 => 'text', 15 => 'multiselect'];
        $customFieldRepository = $this->createMock(CustomFieldRepository::class);
        $customFieldRepository->method('getCustomFieldTypeById')
            ->willReturnCallback(static fn (int $id): string => $fieldTypes[$id] ?? 'text');

        $customFieldTypeProvider = new CustomFieldTypeProvider();
        foreach (self::FIELD_TYPE_CLASSES as $typeClass) {
            $customFieldTypeProvider->addType($this->createFieldType($typeClass));
        }

        $queryFilterHelper = new QueryFilterHelper(
            $entityManager,
            new QueryFilterFactory(
                $entityManager,
                $customFieldTypeProvider,
                $customFieldRepository,
                new QueryFilterFactory\Calculator(),
                1
            ),
            new RandomParameterName()
        );

        $crate = new ContactSegmentFilterCrate([
            'glue'            => 'and',
            'field'           => 'cmf_13',
            'object'          => 'custom_object',
            'type'            => 'date',
            'operator'        => 'custom_operator',
            'merged_property' => $criteria,
        ]);

        $segmentFilter = $this->createMock(ContactSegmentFilter::class);
        $segmentFilter->contactSegmentFilterCrate = $crate;
        // The merged filter reports one operator for the whole group (the last criterion's).
        $segmentFilter->method('getOperator')->willReturn('gt');
        $segmentFilter->method('getParameterValue')->willReturn('2026-10-01');

        return $queryFilterHelper->createMergeFilterQuery($segmentFilter, 'l')->getSQL();
    }
}
