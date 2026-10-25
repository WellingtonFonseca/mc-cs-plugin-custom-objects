<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Provider\FilterOperatorProviderInterface;
use Mautic\LeadBundle\Segment\Query\QueryBuilder as SegmentQueryBuilder;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\TextType;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\EventListener\CampaignSubscriber;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Helper\MergedItemCriteriaMatcher;
use MauticPlugin\CustomObjectsBundle\Helper\QueryFilterHelper;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\ConfigProvider;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\QueryFilterFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use MauticPlugin\CustomObjectsBundle\Tests\Unit\ConsecutiveCallsTrait;

class CampaignSubscriberTest extends TestCase
{
    use ConsecutiveCallsTrait;

    private const OBJECT_ID = 63;

    private const FIELD_ID = 42;

    private const CONTACT_ID = 4;

    private $customFieldModel;
    private $customObjectModel;
    private $customItemModel;
    private $translator;
    private $campaignBuilderEvent;
    private $campaignExecutionEvent;
    private $customObject;
    private $customField;
    private $contact;
    private $configProvider;
    private $queryFilterHelper;
    private $queryFilterFactory;
    private $connection;
    private $queryBuilder;
    private $segmentQueryBuilder;
    private $statement;
    private $result;

    /**
     * @var CampaignSubscriber
     */
    private $campaignSubscriber;

    private MergedItemCriteriaMatcher&MockObject $criteriaMatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customFieldModel       = $this->createMock(CustomFieldModel::class);
        $this->customObjectModel      = $this->createMock(CustomObjectModel::class);
        $this->customItemModel        = $this->createMock(CustomItemModel::class);
        $this->translator             = $this->createMock(TranslatorInterface::class);
        $this->configProvider         = $this->createMock(ConfigProvider::class);
        $this->queryFilterHelper      = $this->createMock(QueryFilterHelper::class);
        $this->queryFilterFactory     = $this->createMock(QueryFilterFactory::class);
        $this->connection             = $this->createMock(Connection::class);
        $this->campaignBuilderEvent   = $this->createMock(CampaignBuilderEvent::class);
        $this->campaignExecutionEvent = $this->createMock(CampaignExecutionEvent::class);
        $this->customObject           = $this->createMock(CustomObject::class);
        $this->customField            = $this->createMock(CustomField::class);
        $this->contact                = $this->createMock(Lead::class);
        $this->queryBuilder           = $this->createMock(QueryBuilder::class);
        $this->segmentQueryBuilder    = $this->createMock(SegmentQueryBuilder::class);
        $this->criteriaMatcher        = $this->createMock(MergedItemCriteriaMatcher::class);
        $this->statement              = $this->createMock(Statement::class);
        $this->result                 = $this->createMock(Result::class);
        $this->campaignSubscriber     = new CampaignSubscriber(
            $this->customFieldModel,
            $this->customObjectModel,
            $this->customItemModel,
            $this->translator,
            $this->configProvider,
            $this->queryFilterHelper,
            $this->queryFilterFactory,
            $this->connection,
            $this->criteriaMatcher
        );

        $this->customObject->method('getId')->willReturn(self::OBJECT_ID);
        $this->customField->method('getId')->willReturn(self::FIELD_ID);
        $this->customField->method('getTypeObject')->willReturn(
            new TextType(
                $this->translator,
                $this->createMock(FilterOperatorProviderInterface::class)
            )
        );
        $this->contact->method('getId')->willReturn(self::CONTACT_ID);
    }

    public function testOnCampaignBuildWhenPluginDisabled(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(false);

        $this->customObjectModel->expects($this->never())
            ->method('fetchAllPublishedEntities');

        $this->campaignSubscriber->onCampaignBuild($this->campaignBuilderEvent);
    }

    public function testOnCampaignBuild(): void
    {
        $customFields = new ArrayCollection();
        $customFields->add(new CustomField());

        $this->customObject->expects(self::once())
            ->method('getCustomFields')
            ->willReturn($customFields);

        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->customObjectModel->expects($this->once())
            ->method('fetchAllPublishedEntities')
            ->willReturn([$this->customObject]);

        $this->campaignBuilderEvent
            ->method('addAction')
            ->willReturnCallback($this->consecutiveCalls([['custom_item.63.linkcontact'], ['custom_item.63.fieldvalue']], null, false, $this->campaignBuilderEvent, 'addAction'));

        $this->campaignSubscriber->onCampaignBuild($this->campaignBuilderEvent);
    }

    public function testOnCampaignBuildNoCustomFieldsDefined(): void
    {
        $customFields = new ArrayCollection();
        $this->customObject->expects(self::once())
            ->method('getCustomFields')
            ->willReturn($customFields);

        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->customObjectModel->expects($this->once())
            ->method('fetchAllPublishedEntities')
            ->willReturn([$this->customObject]);

        $this->campaignBuilderEvent->expects(self::never())
            ->method('addAction')
            ->with('custom_item.63.linkcontact');

        $this->campaignSubscriber->onCampaignBuild($this->campaignBuilderEvent);
    }

    public function testOnCampaignTriggerActionWhenPluginDisabled(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(false);

        $this->campaignExecutionEvent->expects($this->never())
            ->method('getEvent');

        $this->campaignSubscriber->onCampaignTriggerAction($this->campaignExecutionEvent);
    }

    public function testOnCampaignTriggerActionForWrongType(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getEvent')
            ->willReturn(['type' => 'whatever.action']);

        $this->campaignExecutionEvent->expects($this->never())
            ->method('getConfig');

        $this->campaignSubscriber->onCampaignTriggerAction($this->campaignExecutionEvent);
    }

    public function testOnCampaignTriggerActionWhenNoItemsSelected(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getEvent')
            ->willReturn(['type' => 'custom_item.63.linkcontact']);

        $this->campaignExecutionEvent->method('getConfig')
            ->willReturn([]);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getLead')
            ->willReturn($this->contact);

        $this->customItemModel->expects($this->never())
            ->method('linkEntity');

        $this->customItemModel->expects($this->never())
            ->method('unlinkEntity');

        $this->campaignSubscriber->onCampaignTriggerAction($this->campaignExecutionEvent);
    }

    public function testOnCampaignTriggerActionWhenItemsNotFound(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getEvent')
            ->willReturn(['type' => 'custom_item.63.linkcontact']);

        $this->campaignExecutionEvent->method('getConfig')
            ->willReturn([
                'linkCustomItemId'   => '564',
                'unlinkCustomItemId' => '333',
            ]);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getLead')
            ->willReturn($this->contact);

        $this->customItemModel->expects($this->exactly(2))
            ->method('fetchEntity')
            ->willReturnCallback($this->consecutiveCalls([[564], [333]], [
                new NotFoundException(),
                new NotFoundException()
            ], true));

        $this->customItemModel->expects($this->never())
            ->method('linkEntity');

        $this->customItemModel->expects($this->never())
            ->method('unlinkEntity');

        $this->campaignSubscriber->onCampaignTriggerAction($this->campaignExecutionEvent);
    }

    public function testOnCampaignTriggerAction(): void
    {
        $customItem564 = $this->createMock(CustomItem::class);
        $customItem333 = $this->createMock(CustomItem::class);

        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getEvent')
            ->willReturn(['type' => 'custom_item.63.linkcontact']);

        $this->campaignExecutionEvent->method('getConfig')
            ->willReturn([
                'linkCustomItemId'   => '564',
                'unlinkCustomItemId' => '333',
            ]);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getLead')
            ->willReturn($this->contact);

        $this->customItemModel->expects($this->exactly(2))
            ->method('fetchEntity')
            ->willReturnCallback($this->consecutiveCalls([[564], [333]], [$customItem564, $customItem333], true));

        $this->customItemModel->expects($this->once())
            ->method('linkEntity')
            ->with($customItem564, 'contact', self::CONTACT_ID);

        $this->customItemModel->expects($this->once())
            ->method('unlinkEntity')
            ->with($customItem333, 'contact', self::CONTACT_ID);

        $this->campaignSubscriber->onCampaignTriggerAction($this->campaignExecutionEvent);
    }

    public function testOnCampaignTriggerConditionWhenPluginDisabled(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(false);

        $this->campaignExecutionEvent->expects($this->never())
            ->method('getEvent');

        $this->campaignSubscriber->onCampaignTriggerCondition($this->campaignExecutionEvent);
    }

    public function testOnCampaignTriggerConditionForWrongType(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getEvent')
            ->willReturn(['type' => 'whatever.action']);

        $this->campaignExecutionEvent->expects($this->never())
            ->method('getLead');
        $this->campaignExecutionEvent->expects($this->never())
            ->method('getConfig');

        $this->campaignSubscriber->onCampaignTriggerCondition($this->campaignExecutionEvent);
    }

    public function testOnCampaignTriggerConditionWhenContactEmpty(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getEvent')
            ->willReturn(['type' => 'custom_item.63.fieldvalue']);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getLead')
            ->willReturn(null);

        $this->campaignExecutionEvent->expects($this->never())
            ->method('getConfig');

        $this->campaignSubscriber->onCampaignTriggerCondition($this->campaignExecutionEvent);
    }

    public function testOnCampaignTriggerConditionWhenFieldNotFound(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getEvent')
            ->willReturn(['type' => 'custom_item.63.fieldvalue']);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getLead')
            ->willReturn($this->contact);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getConfig')
            ->willReturn(['field' => '432']);

        $this->customFieldModel->expects($this->once())
            ->method('fetchEntity')
            ->will($this->throwException(new NotFoundException()));

        $this->campaignExecutionEvent->expects($this->once())
            ->method('setResult')
            ->with(false);

        $this->campaignSubscriber->onCampaignTriggerCondition($this->campaignExecutionEvent);
    }

    /**
     * More than one criterion (or the name of the item): ONE item must satisfy all of them, asked to the merged query
     * and not answered field by field.
     */
    public function testOnCampaignTriggerConditionWithAdditionalCriteriaUsesTheSameItemMatcher(): void
    {
        $this->configProvider->method('pluginIsEnabled')->willReturn(true);
        $this->campaignExecutionEvent->method('getEvent')->willReturn(['type' => 'custom_item.63.fieldvalue']);
        $this->campaignExecutionEvent->method('getLead')->willReturn($this->contact);
        $this->campaignExecutionEvent->method('getConfig')->willReturn([
            'field'              => 'name',
            'operator'           => 'eq',
            'value'              => 'Disciplina X',
            'additionalCriteria' => [
                ['field' => '33', 'operator' => 'gte', 'value' => '-35 days'],
                ['field' => '34', 'operator' => 'lte', 'value' => '+10 days'],
            ],
        ]);

        $this->customFieldModel->expects($this->never())->method('fetchEntity');
        $this->queryFilterFactory->expects($this->never())->method('configureQueryBuilderFromSegmentFilter');
        $this->criteriaMatcher->expects($this->once())->method('findItemId')->with(
            [
                ['field' => 'name', 'operator' => 'eq', 'value' => 'Disciplina X'],
                ['field' => '33', 'operator' => 'gte', 'value' => '-35 days'],
                ['field' => '34', 'operator' => 'lte', 'value' => '+10 days'],
            ],
            $this->anything(),
            63
        )->willReturn(12);
        $this->campaignExecutionEvent->expects($this->once())->method('setChannel')->with('customItem', 12);
        $this->campaignExecutionEvent->expects($this->once())->method('setResult')->with(true);

        $this->campaignSubscriber->onCampaignTriggerCondition($this->campaignExecutionEvent);
    }

    public function testOnCampaignTriggerConditionWithAdditionalCriteriaIsFalseWhenNoItemSatisfiesThemAll(): void
    {
        $this->configProvider->method('pluginIsEnabled')->willReturn(true);
        $this->campaignExecutionEvent->method('getEvent')->willReturn(['type' => 'custom_item.63.fieldvalue']);
        $this->campaignExecutionEvent->method('getLead')->willReturn($this->contact);
        $this->campaignExecutionEvent->method('getConfig')->willReturn([
            'field' => '32', 'operator' => 'eq', 'value' => 'x', 'additionalCriteria' => [['field' => '33', 'operator' => 'gte', 'value' => '1']],
        ]);
        $this->criteriaMatcher->method('findItemId')->willReturn(null);

        $this->campaignExecutionEvent->expects($this->never())->method('setChannel');
        $this->campaignExecutionEvent->expects($this->once())->method('setResult')->with(false);

        $this->campaignSubscriber->onCampaignTriggerCondition($this->campaignExecutionEvent);
    }

    public function testOnCampaignTriggerConditionOnTheItemNameAloneUsesTheMatcherToo(): void
    {
        $this->configProvider->method('pluginIsEnabled')->willReturn(true);
        $this->campaignExecutionEvent->method('getEvent')->willReturn(['type' => 'custom_item.63.fieldvalue']);
        $this->campaignExecutionEvent->method('getLead')->willReturn($this->contact);
        $this->campaignExecutionEvent->method('getConfig')->willReturn(['field' => 'name', 'operator' => 'eq', 'value' => 'Disciplina X']);

        $this->customFieldModel->expects($this->never())->method('fetchEntity');
        $this->criteriaMatcher->expects($this->once())->method('findItemId')->willReturn(5);
        $this->campaignExecutionEvent->expects($this->once())->method('setResult')->with(true);

        $this->campaignSubscriber->onCampaignTriggerCondition($this->campaignExecutionEvent);
    }

    public function testOnCampaignTriggerConditionWhenItemNotFound(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->connection->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->expects($this->once())
            ->method('execute')
            ->willReturn($this->result);

        $this->queryFilterFactory->expects($this->once())
            ->method('configureQueryBuilderFromSegmentFilter')
            ->willReturn($this->segmentQueryBuilder);

        $this->segmentQueryBuilder->expects($this->once())
            ->method('getParameters')
            ->willReturn([]);

        $this->segmentQueryBuilder->expects($this->once())
            ->method('getParameterTypes')
            ->willReturn([]);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getEvent')
            ->willReturn(['type' => 'custom_item.63.fieldvalue']);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getLead')
            ->willReturn($this->contact);

        $this->campaignExecutionEvent->method('getConfig')
            ->willReturn([
                'field'    => '432',
                'operator' => 'like',
                'value'    => 'value A',
            ]);

        $this->customFieldModel->expects($this->once())
            ->method('fetchEntity')
            ->willReturn($this->customField);

        $this->result->expects($this->once())
            ->method('fetchOne')
            ->willReturn(false);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('setResult')
            ->with(false);

        $this->campaignSubscriber->onCampaignTriggerCondition($this->campaignExecutionEvent);
    }

    public function testOnCampaignTriggerCondition(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->connection->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->expects($this->once())
            ->method('execute')
            ->willReturn($this->result);

        $this->queryFilterFactory->expects($this->once())
            ->method('configureQueryBuilderFromSegmentFilter')
            ->willReturn($this->segmentQueryBuilder);

        $this->segmentQueryBuilder->expects($this->once())
            ->method('getParameters')
            ->willReturn([]);

        $this->segmentQueryBuilder->expects($this->once())
            ->method('getParameterTypes')
            ->willReturn([]);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getEvent')
            ->willReturn(['type' => 'custom_item.63.fieldvalue']);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('getLead')
            ->willReturn($this->contact);

        $this->campaignExecutionEvent->method('getConfig')
            ->willReturn([
                'field'    => '432',
                'operator' => 'like',
                'value'    => 'value A',
            ]);

        $this->customFieldModel->expects($this->once())
            ->method('fetchEntity')
            ->willReturn($this->customField);

        $this->campaignExecutionEvent->expects($this->once())
            ->method('setChannel')
            ->with('customItem', 4344);

        $this->result->expects($this->once())
            ->method('fetchOne')
            ->willReturn('4344');

        $this->campaignExecutionEvent->expects($this->once())
            ->method('setResult')
            ->with(true);

        $this->campaignSubscriber->onCampaignTriggerCondition($this->campaignExecutionEvent);
    }

    public function testTheActionAndTheConditionAreRegisteredForEachObjectWithFields(): void
    {
        $withoutFields = $this->createMock(CustomObject::class);
        $withoutFields->method('getCustomFields')->willReturn(new ArrayCollection());
        $withoutFields->method('getId')->willReturn(11);

        $this->customObject->method('getCustomFields')->willReturn(new ArrayCollection([new CustomField()]));
        $this->customObject->method('getNameSingular')->willReturn('Disciplina');

        $this->configProvider->method('pluginIsEnabled')->willReturn(true);
        $this->customObjectModel->method('fetchAllPublishedEntities')->willReturn([$withoutFields, $this->customObject]);
        $this->translator->method('trans')->willReturnCallback(static fn (string $key, array $params = []): string => $key.'|'.implode(',', $params));

        $actions = $conditions = [];
        $this->campaignBuilderEvent->method('addAction')->willReturnCallback(function (string $key, array $options) use (&$actions): void {
            $actions[$key] = $options;
        });
        $this->campaignBuilderEvent->method('addCondition')->willReturnCallback(function (string $key, array $options) use (&$conditions): void {
            $conditions[$key] = $options;
        });

        $this->campaignSubscriber->onCampaignBuild($this->campaignBuilderEvent);

        $this->assertSame(['custom_item.63.linkcontact'], array_keys($actions), 'the object without fields is skipped, the next one is not');
        $this->assertSame(['custom_item.63.fieldvalue'], array_keys($conditions));
        $this->assertSame('custom.item.events.link.contact|Disciplina', $actions['custom_item.63.linkcontact']['label']);
        $this->assertSame('custom.item.events.link.contact_descr|Disciplina', $actions['custom_item.63.linkcontact']['description']);
        $this->assertSame(['customObjectId' => 63], $actions['custom_item.63.linkcontact']['formTypeOptions']);
        $this->assertSame('custom.item.events.field.value|Disciplina', $conditions['custom_item.63.fieldvalue']['label']);
        $this->assertSame('custom.item.events.field.value_descr|Disciplina', $conditions['custom_item.63.fieldvalue']['description']);
        $this->assertSame(['customObjectId' => 63], $conditions['custom_item.63.fieldvalue']['formTypeOptions']);
    }

    public function testAContactWithoutAnIdFailsTheCondition(): void
    {
        $contact = $this->createMock(Lead::class);
        $contact->method('getId')->willReturn(0);
        $this->configProvider->method('pluginIsEnabled')->willReturn(true);
        $this->campaignExecutionEvent->method('getEvent')->willReturn(['type' => 'custom_item.63.fieldvalue']);
        $this->campaignExecutionEvent->method('getLead')->willReturn($contact);
        $this->campaignExecutionEvent->expects($this->never())->method('getConfig');
        $this->campaignExecutionEvent->expects($this->once())->method('setResult')->with(false);

        $this->campaignSubscriber->onCampaignTriggerCondition($this->campaignExecutionEvent);
    }

    public function testASingleCriterionIsAskedToTheSegmentQueryAndTheItemIsJoinedToIt(): void
    {
        $multi = $this->createMock(CustomField::class);
        $multi->method('getId')->willReturn(42);
        $multi->method('getType')->willReturn('multiselect');
        $multi->method('canHaveMultipleValues')->willReturn(true);

        $this->configProvider->method('pluginIsEnabled')->willReturn(true);
        $this->campaignExecutionEvent->method('getEvent')->willReturn(['type' => 'custom_item.63.fieldvalue']);
        $this->campaignExecutionEvent->method('getLead')->willReturn($this->contact);
        $this->campaignExecutionEvent->method('getConfig')->willReturn(['field' => '42', 'operator' => 'eq', 'value' => 'a']);
        $this->customFieldModel->method('fetchEntity')->willReturn($multi);

        $this->queryFilterFactory->expects($this->once())->method('configureQueryBuilderFromSegmentFilter')
            ->with(
                ['glue' => 'and', 'field' => 'cmf_42', 'object' => 'custom_object', 'type' => 'multiselect', 'filter' => ['a'], 'operator' => 'eq', 'display' => null],
                'q1'
            )
            ->willReturn($this->segmentQueryBuilder);
        $this->segmentQueryBuilder->method('getSQL')->willReturn('INNER SQL');
        $this->segmentQueryBuilder->method('getParameters')->willReturn(['p' => 1]);
        $this->segmentQueryBuilder->method('getParameterTypes')->willReturn(['p' => 2]);
        $this->segmentQueryBuilder->expects($this->once())->method('select')->with('q1_value.custom_item_id');
        $this->queryFilterHelper->expects($this->once())->method('addContactIdRestriction')->with($this->segmentQueryBuilder, 'q1', self::CONTACT_ID);

        $this->connection->method('createQueryBuilder')->willReturn($this->queryBuilder);
        $this->queryBuilder->expects($this->once())->method('select')->with('CustomItem.id');
        $this->queryBuilder->expects($this->once())->method('from')->with('custom_item', 'CustomItem');
        $this->queryBuilder->expects($this->once())->method('innerJoin')->with('CustomItem', '(INNER SQL)', 'q1', 'CustomItem.id = q1.custom_item_id');
        $this->queryBuilder->expects($this->once())->method('setParameters')->with(['p' => 1], ['p' => 2]);
        $this->queryBuilder->method('execute')->willReturn($this->result);
        $this->result->method('fetchOne')->willReturn('9');
        $this->campaignExecutionEvent->expects($this->once())->method('setChannel')->with('customItem', '9');

        $this->campaignSubscriber->onCampaignTriggerCondition($this->campaignExecutionEvent);
    }

    public function testTheValueOfASingleValueFieldIsKeptAsItIs(): void
    {
        $this->configProvider->method('pluginIsEnabled')->willReturn(true);
        $this->campaignExecutionEvent->method('getEvent')->willReturn(['type' => 'custom_item.63.fieldvalue']);
        $this->campaignExecutionEvent->method('getLead')->willReturn($this->contact);
        $this->campaignExecutionEvent->method('getConfig')->willReturn(['field' => '42', 'operator' => 'like', 'value' => 'a']);
        $this->customField->method('canHaveMultipleValues')->willReturn(false);
        $this->customField->method('getType')->willReturn('text');
        $this->customFieldModel->method('fetchEntity')->willReturn($this->customField);
        $this->connection->method('createQueryBuilder')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('execute')->willReturn($this->result);
        $this->segmentQueryBuilder->method('getParameters')->willReturn([]);
        $this->segmentQueryBuilder->method('getParameterTypes')->willReturn([]);

        $this->queryFilterFactory->expects($this->once())->method('configureQueryBuilderFromSegmentFilter')
            ->with($this->callback(static fn (array $filter): bool => 'a' === $filter['filter'] && 'text' === $filter['type']), 'q1')
            ->willReturn($this->segmentQueryBuilder);

        $this->campaignSubscriber->onCampaignTriggerCondition($this->campaignExecutionEvent);
    }

    /**
     * @dataProvider includingExcluding
     *
     * @param array<string, mixed> $config
     */
    public function testIncludingAndExcludingOnATextFieldAreAnsweredByTheMergedQuery(array $config, bool $multipleValues, bool $merged): void
    {
        $field = $this->createMock(CustomField::class);
        $field->method('canHaveMultipleValues')->willReturn($multipleValues);
        $field->method('getType')->willReturn('text');
        $this->configProvider->method('pluginIsEnabled')->willReturn(true);
        $this->campaignExecutionEvent->method('getEvent')->willReturn(['type' => 'custom_item.63.fieldvalue']);
        $this->campaignExecutionEvent->method('getLead')->willReturn($this->contact);
        $this->campaignExecutionEvent->method('getConfig')->willReturn($config + ['value' => 'a']);
        $this->customFieldModel->method('fetchEntity')->willReturn($field);
        $this->connection->method('createQueryBuilder')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('execute')->willReturn($this->result);
        $this->queryFilterFactory->method('configureQueryBuilderFromSegmentFilter')->willReturn($this->segmentQueryBuilder);
        $this->segmentQueryBuilder->method('getParameters')->willReturn([]);
        $this->segmentQueryBuilder->method('getParameterTypes')->willReturn([]);

        $this->criteriaMatcher->expects($merged ? $this->once() : $this->never())->method('findItemId')->willReturn(1);

        $this->campaignSubscriber->onCampaignTriggerCondition($this->campaignExecutionEvent);
    }

    /**
     * @return array<string, array{array<string, mixed>, bool, bool}>
     */
    public static function includingExcluding(): array
    {
        return [
            'in on a text field'          => [['field' => '42', 'operator' => 'in'], false, true],
            '!in on a text field'         => [['field' => '42', 'operator' => '!in'], false, true],
            'in on a multiselect'         => [['field' => '42', 'operator' => 'in'], true, false],
            'eq on a text field'          => [['field' => '42', 'operator' => 'eq'], false, false],
            'in on something not an id'   => [['field' => 'abc', 'operator' => 'in'], false, false],
        ];
    }
}
