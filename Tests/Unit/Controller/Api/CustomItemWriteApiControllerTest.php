<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomItemWriteApiController;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\InvalidValueException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group('area:co-items')]
#[Group('risk:CRITICO')]
#[Group('risk:DADOS')]
final class CustomItemWriteApiControllerTest extends TestCase
{
    /** @var CustomObjectModel|MockObject */
    private $customObjectModel;

    /** @var CustomItemModel|MockObject */
    private $customItemModel;

    /** @var Connection|MockObject */
    private $connection;

    /** @var CustomItemPermissionProvider|MockObject */
    private $permissionProvider;

    private CustomItemWriteApiController $controller;

    protected function setUp(): void
    {
        $this->customObjectModel = $this->createMock(CustomObjectModel::class);
        $this->customItemModel   = $this->createMock(CustomItemModel::class);
        $this->connection        = $this->createMock(Connection::class);
        $em                      = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->connection);
        $this->permissionProvider = $this->createMock(CustomItemPermissionProvider::class);
        $this->customObjectModel->method('fetchEntityByAlias')->willReturn(new CustomObject());

        $this->controller = new CustomItemWriteApiController($this->customObjectModel, $this->customItemModel, $em, $this->permissionProvider);
    }

    private function post(string $content = '{"data": [{"name": "item"}]}'): Request
    {
        return Request::create('/api/customobjects/x/items', 'POST', [], [], [], [], $content);
    }

    /**
     * @return mixed[]
     */
    private function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    private function driverException(): DriverException
    {
        return $this->createMock(DriverException::class);
    }

    public function testADeadlockIs503WithRetryAfterAndTheTransactionIsRolledBackOnce(): void
    {
        $this->customItemModel->method('save')->willThrowException(new DeadlockException($this->driverException(), null));
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $response = $this->controller->saveAction($this->post(), 'x');

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $this->assertSame('1', $response->headers->get('Retry-After'));
        $message = $this->body($response)['errors'][0]['message'];
        $this->assertStringContainsString('Nothing was saved', $message);
        $this->assertStringContainsString('repeat', $message);
    }

    public function testALockWaitTimeoutIsTheSame503(): void
    {
        $this->customItemModel->method('save')->willThrowException(new LockWaitTimeoutException($this->driverException(), null));
        $this->connection->expects($this->once())->method('rollBack');

        $response = $this->controller->saveAction($this->post(), 'x');

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $this->assertSame('1', $response->headers->get('Retry-After'));
    }

    public function testAnyOtherErrorRollsBackAndIsThrownAgain(): void
    {
        $this->customItemModel->method('save')->willThrowException(new \RuntimeException('boom'));
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->controller->saveAction($this->post(), 'x');
    }

    public function testAnInvalidValueIsStill400AndRollsBack(): void
    {
        $this->customItemModel->method('save')->willThrowException(new InvalidValueException('The name is too long'));
        $this->connection->expects($this->once())->method('rollBack');

        $response = $this->controller->saveAction($this->post(), 'x');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertNull($response->headers->get('Retry-After'));
        $this->assertSame('The name is too long', $this->body($response)['errors'][0]['message']);
    }

    public function testANotFoundIsStill400AndRollsBack(): void
    {
        $this->customItemModel->method('save')->willThrowException(new NotFoundException('no field'));
        $this->connection->expects($this->once())->method('rollBack');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->controller->saveAction($this->post(), 'x')->getStatusCode());
    }

    public function testASavedBatchIsCommittedOnceAndAnswers200(): void
    {
        $this->customItemModel->method('save')->willReturnCallback(static fn (CustomItem $item): CustomItem => $item);
        $this->customItemModel->method('populateCustomFields')->willReturnArgument(0);
        $this->connection->expects($this->once())->method('commit');
        $this->connection->expects($this->never())->method('rollBack');

        $response = $this->controller->saveAction($this->post('{"data": [{"name": "a"}, {"name": "b"}]}'), 'x');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(2, $this->body($response)['total']);
    }

    public function testNothingIsRolledBackWhenNoTransactionWasOpened(): void
    {
        // a request that is refused before the transaction (an unknown object, a bad body) must not touch the connection
        $this->connection->expects($this->never())->method('beginTransaction');
        $this->connection->expects($this->never())->method('rollBack');

        $response = $this->controller->saveAction($this->post('{"data": []}'), 'x');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testPostWithoutTheCreatePermissionIs403BeforeAnythingIsRead(): void
    {
        $this->permissionProvider->method('canCreate')->willThrowException(new ForbiddenException('create'));
        $this->connection->expects($this->never())->method('beginTransaction');
        $this->customItemModel->expects($this->never())->method('save');

        $response = $this->controller->saveAction($this->post(), 'x');

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertArrayHasKey('errors', $this->body($response));
    }

    public function testPatchWithoutTheEditPermissionIs403BeforeAnythingIsRead(): void
    {
        $this->permissionProvider->expects($this->once())->method('isGrantedOwnOrOther')->with('edit')
            ->willThrowException(new ForbiddenException('edit'));
        $this->connection->expects($this->never())->method('beginTransaction');
        $this->customItemModel->expects($this->never())->method('fetchEntity');
        $this->customItemModel->expects($this->never())->method('save');

        $request = Request::create('/api/customobjects/x/items', 'PATCH', [], [], [], [], '{"data": [{"id": 5, "name": "n"}]}');

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->controller->saveAction($request, 'x')->getStatusCode());
    }

    public function testAnItemTheUserMayNotEditIs403AndTheWholeBatchIsRolledBack(): void
    {
        $this->customItemModel->method('fetchEntity')->willReturn(new CustomItem(new CustomObject()));
        $this->permissionProvider->method('canEdit')->willThrowException(new ForbiddenException('edit'));
        $this->customItemModel->expects($this->never())->method('save');
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $request = Request::create('/api/customobjects/x/items', 'PATCH', [], [], [], [], '{"data": [{"id": 5, "name": "n"}]}');

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->controller->saveAction($request, 'x')->getStatusCode());
    }

    public function testPutThatFallsBackToCreateNeedsTheCreatePermission(): void
    {
        $this->customItemModel->method('fetchEntity')->willThrowException(new NotFoundException('gone'));
        $this->permissionProvider->expects($this->atLeastOnce())->method('canCreate')->willThrowException(new ForbiddenException('create'));
        $this->customItemModel->expects($this->never())->method('save');
        $this->connection->expects($this->once())->method('rollBack');

        $request = Request::create('/api/customobjects/x/items', 'PUT', [], [], [], [], '{"data": [{"id": 999, "name": "n"}]}');

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->controller->saveAction($request, 'x')->getStatusCode());
    }
}
