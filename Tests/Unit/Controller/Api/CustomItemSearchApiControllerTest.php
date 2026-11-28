<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Controller\Api;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomItemSearchApiController;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group('area:co-items')]
#[Group('risk:DADOS')]
final class CustomItemSearchApiControllerTest extends TestCase
{
    private CustomObjectModel $customObjectModel;

    private EntityManagerInterface $em;

    private QueryBuilder $queryBuilder;

    private AbstractQuery $query;

    private CustomItemModel $customItemModel;

    private CustomItemPermissionProvider $permissionProvider;

    private CustomItemSearchApiController $controller;

    protected function setUp(): void
    {
        $this->customObjectModel = $this->createMock(CustomObjectModel::class);
        $this->em                = $this->createMock(EntityManagerInterface::class);
        $this->customItemModel   = $this->createMock(CustomItemModel::class);
        $this->permissionProvider = $this->createMock(CustomItemPermissionProvider::class);

        $query = $this->query = $this->createMock(AbstractQuery::class);
        $query->method('getResult')->willReturn([]);
        $query->method('getSingleScalarResult')->willReturn(7);

        $this->queryBuilder = $this->getMockBuilder(QueryBuilder::class)
            ->setConstructorArgs([$this->em])
            ->onlyMethods(['getQuery'])
            ->getMock();
        $this->queryBuilder->method('getQuery')->willReturn($query);
        $this->em->method('createQueryBuilder')->willReturn($this->queryBuilder);

        $this->controller = new CustomItemSearchApiController(
            $this->customObjectModel,
            $this->customItemModel,
            $this->em,
            $this->permissionProvider
        );
    }

    /**
     * @param array<string, string> $fieldTypesByAlias
     */
    private function objectWithFields(array $fieldTypesByAlias): void
    {
        $fields = [];
        $id     = 1;
        foreach ($fieldTypesByAlias as $alias => $type) {
            $field = $this->createMock(CustomField::class);
            $field->method('getAlias')->willReturn($alias);
            $field->method('getType')->willReturn($type);
            $field->method('getId')->willReturn($id++);
            $fields[] = $field;
        }

        $object = $this->createMock(CustomObject::class);
        $object->method('getCustomFields')->willReturn(new \Doctrine\Common\Collections\ArrayCollection($fields));
        $this->customObjectModel->method('fetchEntityByAlias')->willReturn($object);
    }

    /**
     * @return array<string, mixed>
     */
    private function params(): array
    {
        $params = [];
        foreach ($this->queryBuilder->getParameters() as $p) {
            $params[$p->getName()] = $p->getValue();
        }

        return $params;
    }

    public function testUnknownObjectIs404(): void
    {
        $this->customObjectModel->method('fetchEntityByAlias')->willThrowException(new NotFoundException('x'));

        $this->assertSame(404, $this->controller->listAction(new Request(), 'nope')->getStatusCode());
    }

    public function testUnknownFieldIs404(): void
    {
        $this->objectWithFields(['nome1' => 'text']);

        $this->assertSame(404, $this->controller->listAction(new Request(['nada' => 'x']), 'o')->getStatusCode());
    }

    public function testTextStaysAnExactMatch(): void
    {
        $this->objectWithFields(['nome1' => 'text']);

        $response = $this->controller->listAction(new Request(['nome1' => 'Disciplina 01']), 'o');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringNotContainsString('LIKE', $this->queryBuilder->getDQL());
        $this->assertSame('Disciplina 01', $this->params()['sval0']);
    }

    public function testDateStaysAnEqualityOnTheDay(): void
    {
        $this->objectWithFields(['inicio' => 'date']);

        $this->controller->listAction(new Request(['inicio' => '2026-09-20']), 'o');

        $this->assertSame('2026-09-20 00:00:00', $this->params()['sval0']);
        $this->assertSame('2026-09-21 00:00:00', $this->params()['svalend0']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function newTypes(): array
    {
        return [
            'int'         => ['int', '1'],
            'decimal'     => ['decimal', '1'],
            'select'      => ['select', 'x'],
            'multiselect' => ['multiselect', 'x'],
            'datetime'    => ['datetime', '2026-09-20'],
            'country'     => ['country', 'BR'],
            'url'         => ['url', 'x'],
        ];
    }

    /**
     * @dataProvider newTypes
     */
    public function testNewTypesAreAccepted(string $type, string $value): void
    {
        $this->objectWithFields(['campo' => $type]);

        $response = $this->controller->listAction(new Request(['campo' => $value]), 'o');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('EXISTS', $this->queryBuilder->getDQL());
    }

    public function testComparisonPrefixWorksInTheApiToo(): void
    {
        $this->objectWithFields(['progresso' => 'decimal']);

        $this->controller->listAction(new Request(['progresso' => '>=0.5']), 'o');

        $this->assertStringContainsString('.value >= :sval0', $this->queryBuilder->getDQL());
    }

    public function testInvalidValueIs400(): void
    {
        $this->objectWithFields(['inicio' => 'date']);

        $this->assertSame(400, $this->controller->listAction(new Request(['inicio' => '31/02/2026']), 'o')->getStatusCode());
    }

    public function testUnsupportedTypeIs400(): void
    {
        $this->objectWithFields(['x' => 'unicorn']);

        $this->assertSame(400, $this->controller->listAction(new Request(['x' => '1']), 'o')->getStatusCode());
    }

    public function testWithoutTheViewPermissionIs403AndNothingIsQueried(): void
    {
        $this->objectWithFields(['nome1' => 'text']);
        $this->permissionProvider->expects($this->once())->method('canViewAtAll')
            ->willThrowException(new ForbiddenException('view'));
        $this->em->expects($this->never())->method('createQueryBuilder');

        $response = $this->controller->listAction(new Request(['nome1' => 'x']), 'o');

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testTheOwnerFilterIsAppliedToTheQuery(): void
    {
        $this->objectWithFields(['nome1' => 'text']);
        $this->customItemModel->expects($this->once())->method('applyOwnerFilter')->with($this->queryBuilder);

        $this->assertSame(Response::HTTP_OK, $this->controller->listAction(new Request(), 'o')->getStatusCode());
    }

    public function testListDefaultsToLimit25Offset0AndReportsTheFullTotal(): void
    {
        $this->objectWithFields(['nome1' => 'text']);

        $body = json_decode((string) $this->controller->listAction(new Request(), 'o')->getContent(), true);

        $this->assertSame(25, $this->queryBuilder->getMaxResults());
        $this->assertSame(0, $this->queryBuilder->getFirstResult());
        $this->assertSame(25, $body['limit']);
        $this->assertSame(0, $body['offset']);
        // total is the full match count (7 from the count query), not the size of the page
        $this->assertSame(7, $body['total']);
        $this->assertStringContainsString('ORDER BY', $this->queryBuilder->getDQL());
    }

    /**
     * @return array<string, array{array<string, string>, int, int}>
     */
    public static function pagingInputs(): array
    {
        return [
            'explicit'        => [['limit' => '10', 'offset' => '30'], 10, 30],
            'limit above max' => [['limit' => '5000'], 100, 0],
            'limit zero'      => [['limit' => '0'], 1, 0],
            'limit negative'  => [['limit' => '-4'], 1, 0],
            'offset negative' => [['offset' => '-9'], 25, 0],
        ];
    }

    /**
     * @dataProvider pagingInputs
     *
     * @param array<string, string> $query
     */
    public function testLimitIsClampedTo1To100AndOffsetToZeroOrMore(array $query, int $limit, int $offset): void
    {
        $this->objectWithFields(['nome1' => 'text']);

        $body = json_decode((string) $this->controller->listAction(new Request($query), 'o')->getContent(), true);

        $this->assertSame($limit, $this->queryBuilder->getMaxResults());
        $this->assertSame($offset, $this->queryBuilder->getFirstResult());
        $this->assertSame($limit, $body['limit']);
        $this->assertSame($offset, $body['offset']);
    }

    public function testLimitAndOffsetAreNotTreatedAsFieldFilters(): void
    {
        $this->objectWithFields(['nome1' => 'text']);

        $response = $this->controller->listAction(new Request(['limit' => '5', 'offset' => '2', 'nome1' => 'x']), 'o');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('x', $this->params()['sval0']);
        $this->assertArrayNotHasKey('sval1', $this->params());
    }

    public function testGetWithoutTheViewPermissionIs403AndNothingIsQueried(): void
    {
        $this->objectWithFields([]);
        $this->permissionProvider->method('canViewAtAll')->willThrowException(new ForbiddenException('view'));
        $this->em->expects($this->never())->method('createQueryBuilder');

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->controller->getAction('o', 5)->getStatusCode());
    }

    public function testGetOfAnUnknownObjectIs404(): void
    {
        $this->customObjectModel->method('fetchEntityByAlias')->willThrowException(new NotFoundException('x'));

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->controller->getAction('nope', 5)->getStatusCode());
    }

    public function testGetOfAnItemTheOwnerFilterHidesOrThatDoesNotExistIs404(): void
    {
        $this->objectWithFields([]);
        $this->customItemModel->expects($this->once())->method('applyOwnerFilter')->with($this->queryBuilder);
        $this->query->method('getOneOrNullResult')->willReturn(null);

        $response = $this->controller->getAction('o', 5);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame(5, $this->params()['itemId']);
    }

    public function testGetOfAnItemFromAnotherObjectIs404(): void
    {
        $other = $this->createMock(CustomObject::class);
        $other->method('getId')->willReturn(99);
        $item = $this->createMock(CustomItem::class);
        $item->method('getCustomObject')->willReturn($other);
        $this->query->method('getOneOrNullResult')->willReturn($item);
        $this->customObjectModel->method('fetchEntityByAlias')->willReturn($this->customObjectWithId(1));

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->controller->getAction('o', 5)->getStatusCode());
    }

    public function testGetReturnsTheItemWithItsLinkedContactCount(): void
    {
        $object = $this->customObjectWithId(1);
        $this->customObjectModel->method('fetchEntityByAlias')->willReturn($object);
        $item = $this->createMock(CustomItem::class);
        $item->method('getCustomObject')->willReturn($object);
        $item->method('getId')->willReturn(5);
        $item->method('getName')->willReturn('Item five');
        $item->method('getCustomFieldValues')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $this->query->method('getOneOrNullResult')->willReturn($item);

        $response = $this->controller->getAction('o', 5);
        $body     = json_decode((string) $response->getContent(), true);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(5, $body['id']);
        $this->assertSame('Item five', $body['name']);
        $this->assertArrayHasKey('attributes', $body);
        $this->assertSame(7, $body['linkedContactCount']);
    }

    private function customObjectWithId(int $id): CustomObject
    {
        $object = $this->createMock(CustomObject::class);
        $object->method('getId')->willReturn($id);

        return $object;
    }
}
