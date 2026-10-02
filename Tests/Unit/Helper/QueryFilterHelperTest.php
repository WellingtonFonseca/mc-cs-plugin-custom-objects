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
use MauticPlugin\CustomObjectsBundle\CustomFieldType\DateType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\TextType;
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
     * @param array<int, array<string, mixed>> $criteria merged_property of a merged filter
     */
    private function mergedSql(array $criteria): string
    {
        if (!defined('MAUTIC_TABLE_PREFIX')) {
            define('MAUTIC_TABLE_PREFIX', '');
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $customFieldTypeProvider = new CustomFieldTypeProvider();
        foreach ([DateType::class, TextType::class] as $typeClass) {
            $customFieldTypeProvider->addType(new $typeClass(
                $this->createMock(TranslatorInterface::class),
                $this->createMock(FilterOperatorProviderInterface::class)
            ));
        }

        $queryFilterHelper = new QueryFilterHelper(
            $entityManager,
            new QueryFilterFactory(
                $entityManager,
                $customFieldTypeProvider,
                $this->createMock(CustomFieldRepository::class),
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
