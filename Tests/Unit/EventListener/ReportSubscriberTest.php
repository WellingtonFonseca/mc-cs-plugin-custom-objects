<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Mautic\LeadBundle\Model\CompanyReportData;
use Mautic\LeadBundle\Provider\FilterOperatorProviderInterface;
use Mautic\LeadBundle\Report\FieldsBuilder;
use Mautic\ReportBundle\Event\ReportBuilderEvent;
use Mautic\ReportBundle\Event\ReportGeneratorEvent;
use Mautic\ReportBundle\Helper\ReportHelper;
use Mautic\ReportBundle\ReportEvents;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\DateTimeType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\MultiselectType;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\TextType;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\EventListener\ReportSubscriber;
use MauticPlugin\CustomObjectsBundle\Helper\CsvHelper;
use MauticPlugin\CustomObjectsBundle\Repository\CustomObjectRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ReportSubscriberTest extends TestCase
{
    /**
     * @var MockObject|CustomObjectRepository
     */
    private $customObjectRepository;

    /**
     * @var MockObject|FieldsBuilder
     */
    private $fieldsBuilder;

    /**
     * @var MockObject|CompanyReportData
     */
    private $companyReportData;

    /**
     * @var ReportSubscriber
     */
    private $reportSubscriber;

    /**
     * @var MockObject|ReportBuilderEvent
     */
    private $reportBuilderEvent;

    /**
     * @var MockObject|TranslatorInterface
     */
    private $translatorInterface;

    /**
     * @var MockObject|FilterOperatorProviderInterface
     */
    private $filterOperatorProviderInterface;

    /**
     * @var MockObject|CsvHelper
     */
    private $csvHelper;

    /**
     * @var MockObject|ReportGeneratorEvent
     */
    private $reportGeneratorEvent;

    /**
     * @var MockObject|QueryBuilder
     */
    private $queryBuilder;

    /**
     * @var MockObject|Connection
     */
    private $connection;

    /**
     * @var MockObject|EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var ReportHelper
     */
    private $reportHelper;

    protected function setUp(): void
    {
        $this->customObjectRepository          = $this->createMock(CustomObjectRepository::class);
        $this->fieldsBuilder                   = $this->createMock(FieldsBuilder::class);
        $this->companyReportData               = $this->createMock(CompanyReportData::class);
        $this->dispatcher                      = $this->createMock(EventDispatcherInterface::class);
        $this->reportHelper                    = new ReportHelper($this->dispatcher);
        $this->translatorInterface             = $this->createMock(TranslatorInterface::class);
        $this->reportSubscriber                = new ReportSubscriber($this->customObjectRepository, $this->fieldsBuilder, $this->companyReportData, $this->reportHelper, $this->translatorInterface);
        $this->reportBuilderEvent              = $this->createMock(ReportBuilderEvent::class);
        $this->filterOperatorProviderInterface = $this->createMock(FilterOperatorProviderInterface::class);
        $this->csvHelper                       = $this->createMock(CsvHelper::class);
        $this->reportGeneratorEvent            = $this->createMock(ReportGeneratorEvent::class);
        $this->queryBuilder                    = $this->createMock(QueryBuilder::class);
        $this->connection                      = $this->createMock(Connection::class);
    }

    private function getCustomFieldsCollection(int $batch = 1): ArrayCollection
    {
        $label1       = uniqid();
        $customField1 = new CustomField();
        $customField1->setId(1 * $batch);
        $customField1->setLabel($label1);
        $typeObject1 = new TextType($this->translatorInterface, $this->filterOperatorProviderInterface);
        $customField1->setTypeObject($typeObject1);
        $customField1->setType($typeObject1->getKey());

        $label2       = uniqid();
        $customField2 = new CustomField();
        $customField2->setId(2 * $batch);
        $customField2->setLabel($label2);
        $typeObject2 = new MultiselectType($this->translatorInterface, $this->filterOperatorProviderInterface, $this->csvHelper);
        $customField2->setTypeObject($typeObject2);
        $customField2->setType($typeObject2->getKey());

        $label3       = uniqid();
        $customField3 = new CustomField();
        $customField3->setId(3 * $batch);
        $customField3->setLabel($label3);
        $typeObject3 = new DateTimeType($this->translatorInterface, $this->filterOperatorProviderInterface);
        $customField3->setTypeObject($typeObject3);
        $customField3->setType($typeObject3->getKey());

        return new ArrayCollection([
            $customField1,
            $customField2,
            $customField3,
        ]);
    }

    private function getCustomObjectsCollection(): array
    {
        $customObject1 = new CustomObject();
        $customObject1->setId(1);
        $customObject1->setCustomFields($this->getCustomFieldsCollection(1));
        $customObject1->setNameSingular('Custom Object #1');
        $customObject1->setNamePlural('Custom Objects #1');
        $customObject1->setType(CustomObject::TYPE_MASTER);

        $customObject2 = new CustomObject();
        $customObject2->setId(2);
        $customObject2->setCustomFields($this->getCustomFieldsCollection(2));
        $customObject2->setNameSingular('Custom Object #2');
        $customObject2->setNamePlural('Custom Objects #2');
        $customObject2->setType(CustomObject::TYPE_MASTER);

        $customObject3 = new CustomObject();
        $customObject3->setId(3);
        $customObject3->setCustomFields($this->getCustomFieldsCollection(3));
        $customObject3->setNameSingular('Opportunitie');
        $customObject3->setNamePlural('Opportunities');
        $customObject3->setType(CustomObject::TYPE_MASTER);

        $customObject4 = new CustomObject();
        $customObject4->setId(4);
        $customObject4->setCustomFields($this->getCustomFieldsCollection(4));
        $customObject4->setNameSingular('Detail');
        $customObject4->setNamePlural('Details');
        $customObject4->setType(CustomObject::TYPE_RELATIONSHIP);

        $customObject4->setMasterObject($customObject3);

        return [
            $customObject1,
            $customObject2,
            $customObject3,
            $customObject4,
        ];
    }

    public function testThatEventListenersAreSpecified(): void
    {
        $events = ReportSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(ReportEvents::REPORT_ON_BUILD, $events);
        $this->assertArrayHasKey(ReportEvents::REPORT_ON_GENERATE, $events);
        $this->assertContains('onReportBuilder', $events[ReportEvents::REPORT_ON_BUILD]);
        $this->assertContains('onReportGenerate', $events[ReportEvents::REPORT_ON_GENERATE]);
    }

    public function testOnReportBuilderMethod(): void
    {
        $this->customObjectRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($this->getCustomObjectsCollection());

        $this->reportBuilderEvent->expects($this->once())
            ->method('checkContext')
            ->willReturn(true);

        $this->fieldsBuilder->expects($this->once())
            ->method('getLeadFieldsColumns')
            ->willReturn([]);

        $this->companyReportData->expects($this->once())
            ->method('getCompanyData')
            ->willReturn([]);

        $this->reportBuilderEvent->expects($this->exactly(4))
            ->method('addTable');

        $this->translatorInterface->method('trans')
            ->willReturn('Some string'.mt_rand(1, 100));

        $this->reportSubscriber->onReportBuilder($this->reportBuilderEvent);
    }

    public function testTheTablesAreOnePerObjectSortedWithTheChildAfterItsParent(): void
    {
        $this->customObjectRepository->method('findAll')->willReturn(array_reverse($this->getCustomObjectsCollection()));
        $this->reportBuilderEvent->method('checkContext')->willReturn(true);
        $this->fieldsBuilder->method('getLeadFieldsColumns')->willReturn([]);
        $this->companyReportData->method('getCompanyData')->willReturn([]);
        $this->translatorInterface->method('trans')->willReturnArgument(0);

        $tables = [];
        $this->reportBuilderEvent->method('addTable')->willReturnCallback(function (string $context, array $table, string $group) use (&$tables): void {
            $tables[] = [$context, $table['display_name'], $group, array_keys($table['columns'])];
        });

        $this->reportSubscriber->onReportBuilder($this->reportBuilderEvent);

        $this->assertSame(
            [
                [ReportSubscriber::CUSTOM_OBJECTS_CONTEXT_GROUP.'.1', 'Custom Objects #1'],
                [ReportSubscriber::CUSTOM_OBJECTS_CONTEXT_GROUP.'.2', 'Custom Objects #2'],
                [ReportSubscriber::CUSTOM_OBJECTS_CONTEXT_GROUP.'.3', 'Opportunities'],
                [ReportSubscriber::CUSTOM_OBJECTS_CONTEXT_GROUP.'.4', ReportSubscriber::CHILD_CUSTOM_OBJECT_NAME_PREFIX.'Details'],
            ],
            array_map(static fn (array $t): array => [$t[0], $t[1]], $tables)
        );
        $this->assertSame([ReportSubscriber::CUSTOM_OBJECTS_CONTEXT_GROUP, ReportSubscriber::CUSTOM_OBJECTS_CONTEXT_GROUP, ReportSubscriber::CUSTOM_OBJECTS_CONTEXT_GROUP, ReportSubscriber::CUSTOM_OBJECTS_CONTEXT_GROUP], array_column($tables, 2));
    }

    public function testTheColumnsAreTheItemsTheContactsTheCompaniesAndTheParentOnes(): void
    {
        $this->customObjectRepository->method('findAll')->willReturn($this->getCustomObjectsCollection());
        $this->reportBuilderEvent->method('checkContext')->willReturn(true);
        $this->fieldsBuilder->expects($this->once())->method('getLeadFieldsColumns')->with(ReportSubscriber::LEADS_TABLE_ALIAS.'.')
            ->willReturn(['l.email' => ['label' => 'Email']]);
        $this->companyReportData->method('getCompanyData')->willReturn([
            'comp.companyname'           => ['label' => 'Company name'],
            'companies_lead.is_primary' => ['label' => 'Primary'],
        ]);
        $this->translatorInterface->method('trans')->willReturnArgument(0);

        $tables = [];
        $this->reportBuilderEvent->method('addTable')->willReturnCallback(function (string $context, array $table) use (&$tables): void {
            $tables[$context] = $table['columns'];
        });

        $this->reportSubscriber->onReportBuilder($this->reportBuilderEvent);

        $child = $tables[ReportSubscriber::CUSTOM_OBJECTS_CONTEXT_GROUP.'.4'];
        $keys  = array_keys($child);
        $this->assertContains(ReportSubscriber::CUSTOM_ITEM_TABLE_ALIAS.'.name', $keys, 'its own item columns');
        $this->assertContains(ReportSubscriber::PARENT_CUSTOM_ITEM_TABLE_ALIAS.'.name', $keys, 'the parent object item columns');
        $this->assertContains('l.email', $keys, 'the contact columns');
        $this->assertContains('comp.companyname', $keys, 'the company columns');
        $this->assertNotContains('companies_lead.is_primary', $keys, 'the primary flag is not offered');
        $this->assertSame('mautic.lead.contact Email', $child['l.email']['label']);
        $this->assertSame('Company name', $child['comp.companyname']['label'], 'company columns keep their label');
        $this->assertStringStartsWith('Detail ', $child[ReportSubscriber::CUSTOM_ITEM_TABLE_ALIAS.'.name']['label']);
        $this->assertStringStartsWith('Opportunitie ', $child[ReportSubscriber::PARENT_CUSTOM_ITEM_TABLE_ALIAS.'.name']['label']);

        $master = $tables[ReportSubscriber::CUSTOM_OBJECTS_CONTEXT_GROUP.'.1'];
        $this->assertNotContains(ReportSubscriber::PARENT_CUSTOM_ITEM_TABLE_ALIAS.'.name', array_keys($master), 'an object without a parent has no parent columns');
    }

    public function testALabelThatAlreadyStartsWithThePrefixIsNotPrefixedTwice(): void
    {
        $this->customObjectRepository->method('findAll')->willReturn([$this->getCustomObjectsCollection()[0]]);
        $this->reportBuilderEvent->method('checkContext')->willReturn(true);
        $this->fieldsBuilder->method('getLeadFieldsColumns')->willReturn(['l.email' => ['label' => 'mautic.lead.contact Email']]);
        $this->companyReportData->method('getCompanyData')->willReturn([]);
        $this->translatorInterface->method('trans')->willReturnArgument(0);
        $columns = [];
        $this->reportBuilderEvent->method('addTable')->willReturnCallback(function (string $context, array $table) use (&$columns): void {
            $columns = $table['columns'];
        });

        $this->reportSubscriber->onReportBuilder($this->reportBuilderEvent);

        $this->assertSame('mautic.lead.contact Email', $columns['l.email']['label']);
    }

    public function testThatWeDontProcessWrongContexts(): void
    {
        $this->customObjectRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($this->getCustomObjectsCollection());

        $this->reportBuilderEvent->expects($this->once())
            ->method('checkContext')
            ->willReturn(false);

        $this->fieldsBuilder->expects($this->never())
            ->method('getLeadFieldsColumns');

        $this->companyReportData->expects($this->never())
            ->method('getCompanyData');

        $this->reportBuilderEvent->expects($this->never())
            ->method('getStandardColumns');

        $this->reportBuilderEvent->expects($this->never())
            ->method('addTable');

        $this->translatorInterface->method('trans')
            ->willReturn('Some string'.mt_rand(1, 100));

        $this->reportSubscriber->onReportBuilder($this->reportBuilderEvent);
    }

    public function testOnReportGenerateMethod(): void
    {
        $customObjectsCollection = $this->getCustomObjectsCollection();

        $this->customObjectRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($customObjectsCollection);

        $this->reportGeneratorEvent->expects($this->once())
            ->method('checkContext')
            ->willReturn(true);

        $this->reportGeneratorEvent->expects($this->once())
            ->method('getContext')
            ->willReturn('custom.object.1');

        $this->customObjectRepository->expects($this->once())
            ->method('find')
            ->willReturn($customObjectsCollection[0]);

        $this->reportGeneratorEvent->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->expects($this->once())
            ->method('getConnection')
            ->willReturn($this->connection);

        $this->fieldsBuilder->expects($this->once())
            ->method('getLeadFieldsColumns')
            ->willReturn([]);

        $this->companyReportData->expects($this->once())
            ->method('eventHasCompanyColumns')
            ->with($this->reportGeneratorEvent)
            ->willReturn(true);

        $this->reportGeneratorEvent->expects($this->exactly(6))
            ->method('usesColumn')
            ->willReturnOnConsecutiveCalls(true, true, true, true, true, false);

        $this->queryBuilder->expects($this->exactly(7))
            ->method('leftJoin');

        $this->queryBuilder->expects($this->once())
            ->method('andWhere');

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $this->queryBuilder->expects($this->once())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $expressionBuilder->expects($this->once())
            ->method('eq')
            ->willReturn($this->queryBuilder);

        $this->reportSubscriber->onReportGenerate($this->reportGeneratorEvent);
    }

    public function testThatOnReportGenerateMethodDoesntProcessWrongContexts()
    {
        $customObjectsCollection = $this->getCustomObjectsCollection();

        $this->reportGeneratorEvent->expects($this->once())
            ->method('checkContext')
            ->willReturn(false);

        $this->customObjectRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($customObjectsCollection);

        $this->reportGeneratorEvent->expects($this->never())
            ->method('getContext');

        $this->reportSubscriber->onReportGenerate($this->reportGeneratorEvent);
    }

    public function testThatIdDoesntProcessContextsWithEmptyOrNotIntCustomObjectsID()
    {
        $customObjectsCollection = $this->getCustomObjectsCollection();

        $this->reportGeneratorEvent->expects($this->once())
            ->method('checkContext')
            ->willReturn(true);

        $this->customObjectRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($customObjectsCollection);

        $this->reportGeneratorEvent->expects($this->once())
            ->method('getContext')
            ->willReturn('custom.object.');

        $this->expectException(\Exception::class);

        $this->reportSubscriber->onReportGenerate($this->reportGeneratorEvent);
    }

    public function testThatIdDoesntProcessNotExistingCustomObjects()
    {
        $customObjectsCollection = $this->getCustomObjectsCollection();

        $this->reportGeneratorEvent->expects($this->once())
            ->method('checkContext')
            ->willReturn(true);

        $this->customObjectRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($customObjectsCollection);

        $this->reportGeneratorEvent->expects($this->once())
            ->method('getContext')
            ->willReturn('custom.object.1');

        $this->expectException(\Exception::class);

        $this->reportSubscriber->onReportGenerate($this->reportGeneratorEvent);
    }

    public function testThatOnReportGenerateMethodCorrectlyProcessesChildCustomObjects(): void
    {
        $customObjectsCollection = $this->getCustomObjectsCollection();

        $this->customObjectRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($customObjectsCollection);

        $this->reportGeneratorEvent->expects($this->once())
            ->method('checkContext')
            ->willReturn(true);

        $this->reportGeneratorEvent->expects($this->once())
            ->method('getContext')
            ->willReturn('custom.object.1');

        $this->customObjectRepository->expects($this->once())
            ->method('find')
            ->willReturn($customObjectsCollection[3]);

        $this->reportGeneratorEvent->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->expects($this->exactly(2))
            ->method('getConnection')
            ->willReturn($this->connection);

        $this->fieldsBuilder->expects($this->once())
            ->method('getLeadFieldsColumns')
            ->willReturn([]);

        $this->companyReportData->expects($this->once())
            ->method('eventHasCompanyColumns')
            ->with($this->reportGeneratorEvent)
            ->willReturn(true);

        $this->reportGeneratorEvent->expects($this->exactly(10))
            ->method('usesColumn')
            ->willReturnOnConsecutiveCalls(true, true, true, true, true, false, true, true, true, true, true, true, true, true, true, true);

        $this->queryBuilder->expects($this->exactly(12))
            ->method('leftJoin');

        $this->queryBuilder->expects($this->once())
            ->method('andWhere');

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $this->queryBuilder->expects($this->once())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $expressionBuilder->expects($this->once())
            ->method('eq')
            ->willReturn($this->queryBuilder);

        $this->reportSubscriber->onReportGenerate($this->reportGeneratorEvent);
    }

    public function testThatOnReportGenerateMethodDoesntJoinUnnecessaryColumnsWhenProcessesChildCustomObjects(): void
    {
        $customObjectsCollection = $this->getCustomObjectsCollection();

        $this->customObjectRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($customObjectsCollection);

        $this->reportGeneratorEvent->expects($this->once())
            ->method('checkContext')
            ->willReturn(true);

        $this->reportGeneratorEvent->expects($this->once())
            ->method('getContext')
            ->willReturn('custom.object.1');

        $this->customObjectRepository->expects($this->once())
            ->method('find')
            ->willReturn($customObjectsCollection[3]);

        $this->reportGeneratorEvent->expects($this->once())
            ->method('getQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->expects($this->once())
            ->method('getConnection')
            ->willReturn($this->connection);

        $this->fieldsBuilder->expects($this->once())
            ->method('getLeadFieldsColumns')
            ->willReturn([]);

        $this->companyReportData->expects($this->once())
            ->method('eventHasCompanyColumns')
            ->with($this->reportGeneratorEvent)
            ->willReturn(true);

        $this->reportGeneratorEvent->expects($this->exactly(16))
            ->method('usesColumn')
            ->willReturnOnConsecutiveCalls(true, true, true, true, true, false, false, false, false, false, false, false, false, false, false, false);

        $this->queryBuilder->expects($this->exactly(7))
            ->method('leftJoin');

        $this->queryBuilder->expects($this->once())
            ->method('andWhere');

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $this->queryBuilder->expects($this->once())
            ->method('expr')
            ->willReturn($expressionBuilder);

        $expressionBuilder->expects($this->once())
            ->method('eq')
            ->willReturn($this->queryBuilder);

        $this->reportSubscriber->onReportGenerate($this->reportGeneratorEvent);
    }
}
