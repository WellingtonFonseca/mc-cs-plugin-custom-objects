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
use MauticPlugin\CustomObjectsBundle\Exception\InvalidValueException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
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

    private CustomItemWriteApiController $controller;

    protected function setUp(): void
    {
        $this->customObjectModel = $this->createMock(CustomObjectModel::class);
        $this->customItemModel   = $this->createMock(CustomItemModel::class);
        $this->connection        = $this->createMock(Connection::class);
        $em                      = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->connection);
        $this->customObjectModel->method('fetchEntityByAlias')->willReturn(new CustomObject());

        $this->controller = new CustomItemWriteApiController($this->customObjectModel, $this->customItemModel, $em);
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
}
