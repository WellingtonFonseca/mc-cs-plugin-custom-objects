<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\CustomObjectsBundle\CustomItemEvents;
use MauticPlugin\CustomObjectsBundle\DTO\TableConfig;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueInterface;
use MauticPlugin\CustomObjectsBundle\Entity\CustomFieldValueText;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItemXrefInterface;
use MauticPlugin\CustomObjectsBundle\Event\CustomItemEvent;
use MauticPlugin\CustomObjectsBundle\Event\CustomItemListQueryEvent;
use MauticPlugin\CustomObjectsBundle\Event\CustomItemXrefEntityDiscoveryEvent;
use MauticPlugin\CustomObjectsBundle\Event\CustomItemXrefEntityEvent;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldValueModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Repository\CustomItemRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use MauticPlugin\CustomObjectsBundle\Tests\Unit\ConsecutiveCallsTrait;

class CustomItemModelTest extends TestCase
{
    use ConsecutiveCallsTrait;

    private $customItem;

    private $user;

    private $entityManager;

    private $queryBuilder;

    private $dbalQueryBuilder;

    private $statement;

    private $connection;

    private $query;

    private $customItemRepository;

    private $customItemPermissionProvider;

    private $userHelper;

    private $customFieldValueModel;

    private $dispatcher;

    private $validator;

    private $violationList;

    /**
     * @var CustomItemModel
     */
    private $customItemModel;

    /**
     * @var CorePermissions|MockObject
     */
    private $security;

    /**
     * @var UrlGeneratorInterface|MockObject
     */
    private $router;

    /**
     * @var Translator|MockObject
     */
    private $translator;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @var CoreParametersHelper|MockObject
     */
    private $coreParametersHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customItem                   = $this->createMock(CustomItem::class);
        $this->user                         = $this->createMock(User::class);
        $this->entityManager                = $this->createMock(EntityManager::class);
        $this->security                     = $this->createMock(CorePermissions::class);
        $this->router                       = $this->createMock(UrlGeneratorInterface::class);
        $this->translator                   = $this->createMock(Translator::class);
        $this->userHelper                   = $this->createMock(UserHelper::class);
        $this->logger                       = $this->createMock(LoggerInterface::class);
        $this->coreParametersHelper         = $this->createMock(CoreParametersHelper::class);
        $this->queryBuilder                 = $this->createMock(QueryBuilder::class);
        $this->dbalQueryBuilder             = $this->createMock(DbalQueryBuilder::class);
        $this->statement                    = $this->createMock(Statement::class);
        $this->connection                   = $this->createMock(Connection::class);
        $this->query                        = $this->createMock(AbstractQuery::class);
        $this->customItemRepository         = $this->createMock(CustomItemRepository::class);
        $this->customItemPermissionProvider = $this->createMock(CustomItemPermissionProvider::class);
        $this->userHelper                   = $this->createMock(UserHelper::class);
        $this->customFieldValueModel        = $this->createMock(CustomFieldValueModel::class);
        $this->dispatcher                   = $this->createMock(EventDispatcherInterface::class);
        $this->validator                    = $this->createMock(ValidatorInterface::class);
        $this->violationList                = $this->createMock(ConstraintViolationListInterface::class);
        $this->customItemModel              = new CustomItemModel(
            $this->entityManager,
            $this->security,
            $this->dispatcher,
            $this->router,
            $this->translator,
            $this->userHelper,
            $this->logger,
            $this->coreParametersHelper,
            $this->customItemRepository,
            $this->customItemPermissionProvider,
            $this->customFieldValueModel,
            $this->validator
        );

        $this->entityManager->method('createQueryBuilder')->willReturn($this->queryBuilder);
        $this->entityManager->method('getConnection')->willReturn($this->connection);
        $this->connection->method('createQueryBuilder')->willReturn($this->dbalQueryBuilder);
        $this->queryBuilder->method('getQuery')->willReturn($this->query);
        $this->userHelper->method('getUser')->willReturn($this->user);
        $this->customItem->method('getId')->willReturn(1);
    }

    public function testSaveNew(): void
    {
        $this->user->expects($this->exactly(2))->method('getName')->willReturn('John Doe');
        $this->userHelper->expects($this->once())->method('getUser')->willReturn($this->user);
        $this->customItem->expects($this->exactly(3))->method('isNew')->willReturn(true);
        $this->customItem->expects($this->once())->method('setCreatedBy')->with($this->user);
        $this->customItem->expects($this->once())->method('setCreatedByUser')->with('John Doe');
        $this->customItem->expects($this->once())->method('setDateAdded');
        $this->customItem->expects($this->once())->method('setModifiedBy')->with($this->user);
        $this->customItem->expects($this->once())->method('setModifiedByUser')->with('John Doe');
        $this->customItem->expects($this->once())->method('setDateModified');
        $this->customItem->expects($this->once())->method('updateUniqueHash');
        $this->customItem->expects($this->once())->method('getCustomFieldValues')->willReturn(new ArrayCollection());
        $this->customItem->expects($this->never())->method('recordCustomFieldValueChanges');
        $this->entityManager->expects($this->once())->method('detach')->with($this->customItem);
        $this->entityManager->expects($this->never())->method('persist');
        $this->dispatcher->expects($this->once())->method('dispatch')
            ->willReturnCallback($this->consecutiveCalls([
                [$this->isInstanceOf(CustomItemEvent::class), CustomItemEvents::ON_CUSTOM_ITEM_PRE_SAVE],
                [$this->isInstanceOf(CustomItemEvent::class), CustomItemEvents::ON_CUSTOM_ITEM_POST_SAVE]
            ], null, false, $this->dispatcher, 'dispatch'));
        $this->customItemRepository->expects($this->once())->method('upsert')->with($this->customItem);
        $this->validator->expects($this->once())->method('validate')->with($this->customItem)->willReturn($this->violationList);

        $this->expectException(NotFoundException::class); // since the fetchEntity() method is called on save and there's no customItem in the DB with ID 1
        $this->assertSame($this->customItem, $this->customItemModel->save($this->customItem));
    }

    public function testSaveEdit(): void
    {
        $customFieldValue = $this->createMock(CustomFieldValueText::class);
        $this->user->expects($this->once())->method('getName')->willReturn('John Doe');
        $this->userHelper->expects($this->once())->method('getUser')->willReturn($this->user);
        $this->customItem->expects($this->exactly(4))->method('isNew')->willReturn(false);
        $this->customItem->expects($this->once())->method('setModifiedBy')->with($this->user);
        $this->customItem->expects($this->once())->method('setModifiedByUser')->with('John Doe');
        $this->customItem->expects($this->once())->method('setDateModified');
        $this->customItem->expects($this->once())->method('getCustomFieldValues')->willReturn(new ArrayCollection([$customFieldValue]));
        $this->customItem->expects($this->once())->method('recordCustomFieldValueChanges');
        $this->customFieldValueModel->expects($this->once())->method('save')->with($customFieldValue);
        $this->entityManager->expects($this->once())->method('persist')->with($this->customItem);
        $this->entityManager->expects($this->once())->method('flush');
        $this->entityManager->expects($this->never())->method('detach');
        $this->dispatcher->expects($this->exactly(2))->method('dispatch')
            ->willReturnCallback($this->consecutiveCalls([
                [$this->isInstanceOf(CustomItemEvent::class), CustomItemEvents::ON_CUSTOM_ITEM_PRE_SAVE],
                [$this->isInstanceOf(CustomItemEvent::class), CustomItemEvents::ON_CUSTOM_ITEM_POST_SAVE]
            ], null, false, $this->dispatcher, 'dispatch'));
        $this->validator->expects($this->once())->method('validate')->with($this->customItem)->willReturn($this->violationList);

        $this->assertSame($this->customItem, $this->customItemModel->save($this->customItem));
    }

    public function testDelete(): void
    {
        $this->customItem->expects($this->once())->method('getId')->willReturn(34);
        $this->dispatcher->expects($this->exactly(2))->method('dispatch')
            ->willReturnCallback($this->consecutiveCalls([
                [$this->isInstanceOf(CustomItemEvent::class), CustomItemEvents::ON_CUSTOM_ITEM_PRE_DELETE],
                [$this->isInstanceOf(CustomItemEvent::class), CustomItemEvents::ON_CUSTOM_ITEM_POST_DELETE]
            ], null, false, $this->dispatcher, 'dispatch'));
        $this->entityManager->expects($this->once())->method('remove')->with($this->customItem);
        $this->entityManager->expects($this->once())->method('flush');

        $this->customItemModel->delete($this->customItem);

        $this->assertIsInt($this->customItem->deletedId, 'the id is kept for the listeners of the POST event');
    }

    public function testLinkEntityIfXrefNotFound(): void
    {
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->callback(function (CustomItemXrefEntityDiscoveryEvent $event) {
                    $this->assertSame($this->customItem, $event->getCustomItem());
                    $this->assertSame('contact', $event->getEntityType());
                    $this->assertSame(123, $event->getEntityId());

                    return true;
                }),
                CustomItemEvents::ON_CUSTOM_ITEM_LINK_ENTITY_DISCOVERY
            );

        $this->expectException(\UnexpectedValueException::class);
        $this->customItemModel->linkEntity($this->customItem, 'contact', 123);
    }

    public function testLinkEntity(): void
    {
        $xref = $this->createMock(CustomItemXrefInterface::class);

        $this->dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback($this->consecutiveCalls([
                [
                    $this->callback(function (CustomItemXrefEntityDiscoveryEvent $event) use ($xref) {
                        $this->assertSame($this->customItem, $event->getCustomItem());
                        $this->assertSame('contact', $event->getEntityType());
                        $this->assertSame(123, $event->getEntityId());

                        // Simulate that a subscriber subscribed a Xref entity.
                        $event->setXrefEntity($xref);

                        return true;
                    }),
                    CustomItemEvents::ON_CUSTOM_ITEM_LINK_ENTITY_DISCOVERY,
                ],
                [
                    $this->callback(function (CustomItemXrefEntityEvent $event) use ($xref) {
                        $this->assertSame($xref, $event->getXref());

                        return true;
                    }),
                    CustomItemEvents::ON_CUSTOM_ITEM_LINK_ENTITY,
                ]
            ], null, false, $this->dispatcher, 'dispatch'));

        $this->assertSame($xref, $this->customItemModel->linkEntity($this->customItem, 'contact', 123));
    }

    public function testUninkEntityIfXrefNotFound(): void
    {
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->callback(function (CustomItemXrefEntityDiscoveryEvent $event) {
                    $this->assertSame($this->customItem, $event->getCustomItem());
                    $this->assertSame('contact', $event->getEntityType());
                    $this->assertSame(123, $event->getEntityId());

                    return true;
                }),
                CustomItemEvents::ON_CUSTOM_ITEM_LINK_ENTITY_DISCOVERY
            );

        $this->expectException(\UnexpectedValueException::class);
        $this->customItemModel->unlinkEntity($this->customItem, 'contact', 123);
    }

    public function testUninkEntity(): void
    {
        $xref = $this->createMock(CustomItemXrefInterface::class);

        $this->dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback($this->consecutiveCalls([
                [
                    $this->callback(function (CustomItemXrefEntityDiscoveryEvent $event) use ($xref) {
                        $this->assertSame($this->customItem, $event->getCustomItem());
                        $this->assertSame('contact', $event->getEntityType());
                        $this->assertSame(123, $event->getEntityId());

                        // Simulate that a subscriber subscribed a Xref entity.
                        $event->setXrefEntity($xref);

                        return true;
                    }),
                    CustomItemEvents::ON_CUSTOM_ITEM_LINK_ENTITY_DISCOVERY,
                ],
                [
                    $this->callback(function (CustomItemXrefEntityEvent $event) use ($xref) {
                        $this->assertSame($xref, $event->getXref());

                        return true;
                    }),
                    CustomItemEvents::ON_CUSTOM_ITEM_UNLINK_ENTITY,
                ]
            ], null, false, $this->dispatcher, 'dispatch'));

        $this->assertSame($xref, $this->customItemModel->unlinkEntity($this->customItem, 'contact', 123));
    }

    public function testFetchEntity(): void
    {
        $this->customItemRepository->expects($this->once())
            ->method('getEntity')
            ->willReturn($this->customItem);

        $this->customItem->expects($this->once())
            ->method('getCustomFieldValues')
            ->willReturn(new ArrayCollection([$this->createMock(CustomFieldValueInterface::class)]));

        $this->customItem->expects($this->never())
            ->method('getCustomObject');

        $this->customItemModel->fetchEntity(44);
    }

    public function testFetchEntityIfNotFound(): void
    {
        $this->customItemRepository->expects($this->once())
            ->method('getEntity')
            ->willReturn(null);

        $this->customItem->expects($this->never())
            ->method('getCustomFieldValues');

        $this->customItem->expects($this->never())
            ->method('getCustomObject');

        $this->expectException(NotFoundException::class);

        $this->customItemModel->fetchEntity(44);
    }

    public function testGetTableDataWithoutCustomObjectId(): void
    {
        $tableConfig = new TableConfig(10, 1, 'column');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("customObjectId cannot be empty. It's required for permission management");
        $this->customItemModel->getTableData($tableConfig);
    }

    public function testGetTableData(): void
    {
        $tableConfig = new TableConfig(10, 1, 'column');
        $tableConfig->addParameter('customObjectId', 44);

        $this->customItemPermissionProvider->expects($this->once())
            ->method('isGranted')
            ->will($this->throwException(new ForbiddenException('viewother')));

        $this->user->expects($this->any())
            ->method('getId')
            ->willReturn(22);

        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);

        $this->query->expects($this->once())
            ->method('getResult')
            ->willReturn([]);

        $this->dispatcher->expects($this->once())
            ->method('dispatch');

        $this->customItemModel->getTableData($tableConfig);
    }

    /**
     * A user who may view only his own items sees only the items he created: the restriction has to be a real condition on the
     * creator, in the ORM list and in the DBAL one (it used to be andWhere(alias.createdBy, <id>), which the ORM refuses and the
     * list answered 500).
     */
    public function testAUserWithoutViewOtherOnlySeesTheItemsHeCreatedInTheOrmList(): void
    {
        $realQueryBuilder = new QueryBuilder($this->entityManager);
        $entityManager    = $this->createMock(EntityManager::class);
        $entityManager->method('createQueryBuilder')->willReturn($realQueryBuilder);
        $model = $this->modelWith($entityManager);

        $this->customItemPermissionProvider->method('isGranted')->willThrowException(new ForbiddenException('viewother'));
        $this->user->method('getId')->willReturn(22);
        $this->query->method('getResult')->willReturn([]);
        $tableConfig = new TableConfig(10, 1, 'column');
        $tableConfig->addParameter('customObjectId', 44);

        $this->queryBuilder = $realQueryBuilder;
        $realQueryBuilder->setMaxResults(10);

        $dql = $this->listDql($model, $tableConfig, $realQueryBuilder);

        $this->assertStringContainsString('CustomItem.createdBy = :ownerId', $dql);
        $this->assertSame(22, $realQueryBuilder->getParameter('ownerId')->getValue());
    }

    public function testAUserWithViewOtherSeesTheItemsOfEveryoneInTheOrmList(): void
    {
        $realQueryBuilder = new QueryBuilder($this->entityManager);
        $entityManager    = $this->createMock(EntityManager::class);
        $entityManager->method('createQueryBuilder')->willReturn($realQueryBuilder);
        $model = $this->modelWith($entityManager);
        $this->user->method('getId')->willReturn(22);
        $tableConfig = new TableConfig(10, 1, 'column');
        $tableConfig->addParameter('customObjectId', 44);

        $dql = $this->listDql($model, $tableConfig, $realQueryBuilder);

        $this->assertStringNotContainsString('createdBy', $dql);
        $this->assertNull($realQueryBuilder->getParameter('ownerId'));
    }

    public function testAUserWithoutViewOtherOnlySeesTheItemsHeCreatedInTheDbalList(): void
    {
        $connection = $this->createMock(Connection::class);
        $dbal       = new DbalQueryBuilder($connection);
        $connection->method('createQueryBuilder')->willReturn($dbal);
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $model = $this->modelWith($entityManager);
        $this->customItemPermissionProvider->method('isGranted')->willThrowException(new ForbiddenException('viewother'));
        $this->user->method('getId')->willReturn(22);
        $tableConfig = new TableConfig(10, 1, 'column');
        $tableConfig->addParameter('customObjectId', 44);

        try {
            $model->getArrayTableData($tableConfig);
        } catch (\Throwable) {
            // the connection is a double: only the built query matters
        }

        $where = (string) $dbal->getQueryPart('where');
        $this->assertStringContainsString('CustomItem.custom_object_id = :customObjectId', $where);
        $this->assertStringContainsString('CustomItem.created_by = :ownerId', $where);
        $this->assertSame(['CustomItem.*'], $dbal->getQueryPart('select'));
        $this->assertSame([['table' => 'custom_item', 'alias' => 'CustomItem']], $dbal->getQueryPart('from'));
        $this->assertSame(44, $dbal->getParameters()['customObjectId']);
        $this->assertSame(22, $dbal->getParameters()['ownerId']);
    }

    private function listDql(CustomItemModel $model, TableConfig $tableConfig, QueryBuilder $queryBuilder): string
    {
        $this->query->method('getResult')->willReturn([]);
        $queryBuilder->setMaxResults(10);
        try {
            $model->getTableData($tableConfig);
        } catch (\Throwable) {
            // the query is a double: only the built DQL matters
        }

        return $queryBuilder->getDQL();
    }

    private function modelWith(EntityManager $entityManager): CustomItemModel
    {
        return new CustomItemModel(
            $entityManager,
            $this->security,
            $this->dispatcher,
            $this->router,
            $this->translator,
            $this->userHelper,
            $this->logger,
            $this->coreParametersHelper,
            $this->customItemRepository,
            $this->customItemPermissionProvider,
            $this->customFieldValueModel,
            $this->validator
        );
    }

    public function testGetArrayTableDataWithoutCustomObjectId(): void
    {
        $tableConfig = new TableConfig(10, 1, 'column');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("customObjectId cannot be empty. It's required for permission management");
        $this->customItemModel->getArrayTableData($tableConfig);
    }

    public function testGetArrayTableData(): void
    {
        $tableConfig = new TableConfig(10, 1, 'column');
        $tableConfig->addParameter('customObjectId', 44);

        $this->customItemPermissionProvider->expects($this->never())
            ->method('isGranted');

        // Model situation when the method is called from a command and user is unknown.
        $this->user->expects($this->any())
            ->method('getId')
            ->willReturn(null);

        $this->dbalQueryBuilder->expects($this->once())
            ->method('select')
            ->willReturn(CustomItem::TABLE_ALIAS.'.*');

        $result = $this->createMock(Result::class);

        $result->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $this->dbalQueryBuilder->expects($this->once())
            ->method('execute')
            ->willReturn($result);

        $this->dbalQueryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('customObjectId', 44);

        $this->dispatcher->expects($this->once())
            ->method('dispatch');

        $this->customItemModel->getArrayTableData($tableConfig);
    }

    public function testGetCountForTable(): void
    {
        $expr        = $this->createMock(Expr::class);
        $tableConfig = new TableConfig(10, 2, 'column');
        $tableConfig->addParameter('customObjectId', 44);

        $this->customItemPermissionProvider->expects($this->once())
            ->method('isGranted')
            ->will($this->throwException(new ForbiddenException('viewother')));

        $this->user->expects($this->any())
            ->method('getId')
            ->willReturn(22);

        $this->queryBuilder->expects($this->exactly(2))
            ->method('select')
            ->willReturnCallback($this->consecutiveCalls([
                [CustomItem::TABLE_ALIAS],
                ['the select count expr']
            ], null, false, $this->queryBuilder, 'select'));

        $this->queryBuilder->expects($this->exactly(2))
            ->method('setMaxResults')
            ->willReturnCallback($this->consecutiveCalls([[10], [1]], null, false, $this->queryBuilder, 'setMaxResults'));

        $this->queryBuilder->expects($this->exactly(2))
            ->method('setFirstResult')
            ->willReturnCallback($this->consecutiveCalls([[10], [0]], null, false, $this->queryBuilder, 'setFirstResult'));

        $this->queryBuilder->expects($this->once())
            ->method('resetDQLPart')
            ->with('orderBy');

        $this->queryBuilder->expects($this->once())
            ->method('expr')
            ->willReturn($expr);

        $expr->expects($this->once())
            ->method('countDistinct')
            ->with('CustomItem')
            ->willReturn('the select count expr');

        $this->queryBuilder->expects($this->once())
            ->method('from')
            ->with(CustomItem::class, CustomItem::TABLE_ALIAS);

        $this->queryBuilder->expects($this->once())
            ->method('where')
            ->with(CustomItem::TABLE_ALIAS.'.customObject = :customObjectId');

        $this->queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with(CustomItem::TABLE_ALIAS.'.createdBy = :ownerId');

        $this->queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback($this->consecutiveCalls([
                ['customObjectId', 44],
                ['ownerId', 22],
            ], null, false, $this->queryBuilder, 'setParameter'));

        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);

        $this->query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn(4);

        $this->assertSame(4, $this->customItemModel->getCountForTable($tableConfig));
    }

    public function testGetLookupData(): void
    {
        $tableConfig = new TableConfig(10, 1, 'column');
        $tableConfig->addParameter('customObjectId', 44);
        $tableConfig->addParameter('search', 'Item A');

        $this->queryBuilder->method('expr')->willReturn(new Expr());

        $this->customItemPermissionProvider->expects($this->once())
            ->method('isGranted')
            ->will($this->throwException(new ForbiddenException('viewother')));

        $this->user->expects($this->any())
            ->method('getId')
            ->willReturn(22);

        $this->queryBuilder->expects($this->exactly(4))
            ->method('select')
            ->willReturnCallback($this->consecutiveCalls([
                [CustomItem::TABLE_ALIAS],
                ['IDENTITY(ValueText.customItem)'],
                ['IDENTITY(ValueOption.customItem)'],
                ['CustomItem.name as value, CustomItem.id']
            ], null, false, $this->queryBuilder, 'select'));

        $this->queryBuilder->expects($this->any())
            ->method('andWhere')
            ->willReturnCallback($this->consecutiveCalls([
                ['MATCH (ValueText.value) AGAINST (:search BOOLEAN) > 0'],
                ['MATCH (ValueOption.value) AGAINST (:search BOOLEAN) > 0'],
                [$this->isInstanceOf(\Doctrine\ORM\Query\Expr\Orx::class)],
                [CustomItem::TABLE_ALIAS.'.createdBy = :ownerId'],
            ], null, false, $this->queryBuilder, 'andWhere'));

        $this->queryBuilder->expects($this->exactly(3))
            ->method('setParameter')
            ->willReturnCallback($this->consecutiveCalls([
                ['customObjectId', 44],
                ['search', '(+Item* +A*) >"Item A"'],
                ['ownerId', 22],
            ], null, false, $this->queryBuilder, 'setParameter'));

        $this->queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($this->query);

        $this->query->expects($this->once())
            ->method('getArrayResult')
            ->willReturn([123 => ['id' => 123, 'value' => 'Test Item']]);

        $this->assertSame(
            [['id' => 123, 'value' => 'Test Item (123)']],
            $this->customItemModel->getLookupData($tableConfig)
        );
    }

    public function testTheCountAndTheLookupAskTheirOwnEvents(): void
    {
        $this->entityManager->method('getExpressionBuilder')->willReturn(new Expr());
        $query = $this->createMock(AbstractQuery::class);
        $query->method('getSingleScalarResult')->willReturn(3);
        $query->method('getArrayResult')->willReturn([['id' => 5, 'value' => 'A'], ['id' => 6, 'value' => 'B']]);
        $realQueryBuilder = $this->getMockBuilder(QueryBuilder::class)->setConstructorArgs([$this->entityManager])->onlyMethods(['getQuery'])->getMock();
        $realQueryBuilder->method('getQuery')->willReturn($query);
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('createQueryBuilder')->willReturn($realQueryBuilder);
        $model = $this->modelWith($entityManager);
        $tableConfig = new TableConfig(10, 1, 'column');
        $tableConfig->addParameter('customObjectId', 44);

        $events = [];
        $this->dispatcher->method('dispatch')->willReturnCallback(static function ($event, string $name) use (&$events) {
            $events[] = [get_class($event), $name];

            return $event;
        });

        $this->assertSame(3, $model->getCountForTable($tableConfig));
        $this->assertSame(
            [['id' => 5, 'value' => 'A (5)'], ['id' => 6, 'value' => 'B (6)']],
            $model->getLookupData($tableConfig),
            'every row of the lookup is returned, not only the first'
        );
        $this->assertSame([
            [CustomItemListQueryEvent::class, CustomItemEvents::ON_CUSTOM_ITEM_LIST_ORM_QUERY],
            [CustomItemListQueryEvent::class, CustomItemEvents::ON_CUSTOM_ITEM_LOOKUP_QUERY],
        ], $events);
    }

    public function testFieldValuesAlreadyThereAreNotCreatedAgain(): void
    {
        $item = $this->createMock(CustomItem::class);
        $item->method('getCustomFieldValues')->willReturn(new ArrayCollection([$this->createMock(CustomFieldValueText::class)]));
        $this->customFieldValueModel->expects($this->never())->method('createValuesForItem');
        $item->expects($this->never())->method('createFieldValuesSnapshot');

        $this->assertSame($item, $this->customItemModel->populateCustomFields($item));
    }

    private function field(int $id, string $alias, string $label, string $type = 'text'): CustomField
    {
        $field = $this->createMock(CustomField::class);
        $field->method('getId')->willReturn($id);
        $field->method('getAlias')->willReturn($alias);
        $field->method('getLabel')->willReturn($label);
        $field->method('getType')->willReturn($type);

        return $field;
    }

    /**
     * @param CustomField[] $fields
     */
    private function modelWithFields(array $fields): CustomItemModel
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findBy')->with(['customObject' => 44], ['order' => 'ASC', 'id' => 'ASC'])->willReturn($fields);
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRepository')->with(CustomField::class)->willReturn($repository);

        return $this->modelWith($entityManager);
    }

    public function testASearchWithoutTermsHasNoWarnings(): void
    {
        $this->assertSame([], $this->modelWithFields([])->getSearchWarnings(44, 'just words'));
    }

    public function testAFieldIsFoundByAliasOrByLabelInAnyCaseAndAnAliasWinsOverALabel(): void
    {
        $model = $this->modelWithFields([
            $this->field(1, 'nome', ' Título '),
            $this->field(2, 'inicio', 'nome'),
        ]);

        $this->assertSame([], $model->getSearchWarnings(44, 'TÍTULO:abc'), 'by label, trimmed, with accents');
        $this->assertSame([], $model->getSearchWarnings(44, 'NOME:abc'), 'by alias');
        $this->assertSame([['type' => 'unknown_alias', 'alias' => 'zzz']], $model->getSearchWarnings(44, 'zzz:abc nome:x'));
    }

    public function testAnInvalidValueIsWarnedAboutPerTerm(): void
    {
        $model = $this->modelWithFields([$this->field(1, 'nome', 'Nome'), $this->field(3, 'posicao', 'Posição', 'int')]);

        $this->assertSame(
            [['type' => 'invalid_value', 'alias' => 'posicao']],
            $model->getSearchWarnings(44, 'nome:abc posicao:abc')
        );
    }

    public function testATermOfTheSearchNarrowsTheListToTheItemsOfThatField(): void
    {
        $realQueryBuilder = new QueryBuilder($this->entityManager);
        $repository       = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findBy')->willReturn([$this->field(1, 'nome', 'Nome'), $this->field(3, 'posicao', 'Posição', 'int')]);
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('createQueryBuilder')->willReturn($realQueryBuilder);
        $entityManager->method('getRepository')->willReturn($repository);
        $model = $this->modelWith($entityManager);
        $this->query->method('getResult')->willReturn([]);

        $tableConfig = new TableConfig(10, 1, 'column');
        $tableConfig->addParameter('customObjectId', 44);
        $tableConfig->addParameter('search', 'nome:abc zzz:1 posicao:xyz');

        try {
            $model->getTableData($tableConfig);
        } catch (\Throwable) {
            // the query is a double
        }
        $dql = $realQueryBuilder->getDQL();

        $this->assertSame(1, substr_count($dql, 'EXISTS (SELECT 1 FROM'), 'only the valid term of the known field becomes a condition');
        $this->assertSame(2, substr_count($dql, 'CustomItem.id IS NULL'), 'an unknown alias and an invalid value match nothing');
    }

    public function testPopulateCustomFields(): void
    {
        $this->customItem->expects($this->once())
            ->method('getCustomFieldValues')
            ->willReturn(new ArrayCollection());

        $this->customFieldValueModel->expects($this->once())
            ->method('createValuesForItem')
            ->with($this->customItem);

        $this->customItem->expects($this->once())
            ->method('createFieldValuesSnapshot');

        $this->assertSame(
            $this->customItem,
            $this->customItemModel->populateCustomFields($this->customItem)
        );
    }

    public function testGetPermissionBase(): void
    {
        $this->assertSame('custom_objects:custom_objects', $this->customItemModel->getPermissionBase());
    }
}
