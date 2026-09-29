<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomItemDeleteApiController;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CustomItemDeleteApiControllerTest extends TestCase
{
    private CustomObjectModel $customObjectModel;

    private CustomItemModel $customItemModel;

    private EntityManagerInterface $em;

    private Connection $connection;

    private CustomItemDeleteApiController $controller;

    protected function setUp(): void
    {
        $this->customObjectModel = $this->createMock(CustomObjectModel::class);
        $this->customItemModel   = $this->createMock(CustomItemModel::class);
        $this->em                = $this->createMock(EntityManagerInterface::class);
        $this->connection        = $this->createMock(Connection::class);

        $this->em->method('getConnection')->willReturn($this->connection);

        $this->controller = new CustomItemDeleteApiController(
            $this->customObjectModel,
            $this->customItemModel,
            $this->em
        );
    }

    private function customObject(int $id): CustomObject
    {
        $customObject = $this->createMock(CustomObject::class);
        $customObject->method('getId')->willReturn($id);

        return $customObject;
    }

    private function customItem(int $id, CustomObject $owner): CustomItem
    {
        $customItem = $this->createMock(CustomItem::class);
        $customItem->method('getId')->willReturn($id);
        $customItem->method('getCustomObject')->willReturn($owner);

        return $customItem;
    }

    public function testBatchDeleteReturns404WhenObjectAliasNotFound(): void
    {
        $this->customObjectModel->method('fetchEntityByAlias')
            ->willThrowException(new NotFoundException("Custom Object with alias = 'documents' was not found"));

        $this->connection->expects($this->never())->method('beginTransaction');

        $request  = new Request([], [], [], [], [], [], json_encode(['data' => [['id' => 1]]]));
        $response = $this->controller->batchDeleteAction($request, 'documents');

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testBatchDeleteReturns400WhenDataMissing(): void
    {
        $this->customObjectModel->method('fetchEntityByAlias')->willReturn($this->customObject(1));

        $this->connection->expects($this->never())->method('beginTransaction');

        $request  = new Request([], [], [], [], [], [], json_encode(['data' => []]));
        $response = $this->controller->batchDeleteAction($request, 'documents');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testBatchDeleteReturns400WhenEntryMissingId(): void
    {
        $this->customObjectModel->method('fetchEntityByAlias')->willReturn($this->customObject(1));

        $this->connection->expects($this->never())->method('beginTransaction');
        $this->customItemModel->expects($this->never())->method('delete');

        $request  = new Request([], [], [], [], [], [], json_encode(['data' => [['id' => 1], ['name' => 'no id here']]]));
        $response = $this->controller->batchDeleteAction($request, 'documents');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testBatchDeleteRollsBackWholeBatchWhenOneIdNotFound(): void
    {
        $customObject = $this->customObject(1);
        $item1        = $this->customItem(1, $customObject);

        $this->customObjectModel->method('fetchEntityByAlias')->willReturn($customObject);

        $this->customItemModel->method('fetchEntity')
            ->willReturnCallback(function (int $id) use ($item1) {
                if (1 === $id) {
                    return $item1;
                }

                throw new NotFoundException("Custom Item with ID = {$id} was not found");
            });

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->customItemModel->expects($this->once())->method('delete')->with($item1);

        $request  = new Request([], [], [], [], [], [], json_encode(['data' => [['id' => 1], ['id' => 999]]]));
        $response = $this->controller->batchDeleteAction($request, 'documents');

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testBatchDeleteReturns404WhenItemBelongsToDifferentCustomObject(): void
    {
        $customObject      = $this->customObject(1);
        $otherCustomObject = $this->customObject(2);
        $foreignItem       = $this->customItem(5, $otherCustomObject);

        $this->customObjectModel->method('fetchEntityByAlias')->willReturn($customObject);
        $this->customItemModel->method('fetchEntity')->willReturn($foreignItem);

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('rollBack');
        $this->customItemModel->expects($this->never())->method('delete');

        $request  = new Request([], [], [], [], [], [], json_encode(['data' => [['id' => 5]]]));
        $response = $this->controller->batchDeleteAction($request, 'documents');

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testBatchDeleteSucceedsAndCommitsForAllItems(): void
    {
        $customObject = $this->customObject(1);
        $item1        = $this->customItem(1, $customObject);
        $item2        = $this->customItem(2, $customObject);

        $this->customObjectModel->method('fetchEntityByAlias')->willReturn($customObject);

        $this->customItemModel->method('fetchEntity')
            ->willReturnMap([
                [1, $item1],
                [2, $item2],
            ]);

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');
        $this->connection->expects($this->never())->method('rollBack');

        $this->customItemModel->expects($this->exactly(2))->method('delete');

        $request  = new Request([], [], [], [], [], [], json_encode(['data' => [['id' => 1], ['id' => 2]]]));
        $response = $this->controller->batchDeleteAction($request, 'documents');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true);
        $this->assertSame(2, $content['total']);
        $this->assertSame([1, 2], $content['deleted']);
    }
}
