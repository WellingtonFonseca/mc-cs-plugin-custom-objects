<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomItemLinkApiController;
use MauticPlugin\CustomObjectsBundle\DTO\TableConfig;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Repository\CustomItemXrefContactRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

#[Group('area:co-items')]
#[Group('risk:CRITICO')]
final class CustomItemLinkApiControllerTest extends TestCase
{
    private CustomItemModel&MockObject $model;

    private EntityManagerInterface&MockObject $em;

    private CustomItemPermissionProvider&MockObject $permissions;

    private CustomItemXrefContactRepository&MockObject $xrefRepository;

    private CorePermissions&MockObject $corePermissions;

    private CustomItemLinkApiController $controller;

    protected function setUp(): void
    {
        $this->model           = $this->createMock(CustomItemModel::class);
        $this->em              = $this->createMock(EntityManagerInterface::class);
        $this->permissions     = $this->createMock(CustomItemPermissionProvider::class);
        $this->xrefRepository  = $this->createMock(CustomItemXrefContactRepository::class);
        $this->corePermissions = $this->createMock(CorePermissions::class);

        $this->controller = new CustomItemLinkApiController(
            $this->model,
            $this->em,
            $this->permissions,
            $this->xrefRepository,
            $this->corePermissions,
        );
    }

    private function item(int $id = 5, bool $relationship = false): CustomItem&MockObject
    {
        $object = $this->createMock(CustomObject::class);
        $object->method('getRelationshipObject')->willReturn($relationship ? $this->createMock(CustomObject::class) : null);
        $item = $this->createMock(CustomItem::class);
        $item->method('getId')->willReturn($id);
        $item->method('getCustomObject')->willReturn($object);

        return $item;
    }

    /** the target (contact / custom item) exists in the database */
    private function targetExists(): void
    {
        $this->em->method('find')->willReturn($this->createMock(Lead::class));
    }

    /** the xref count query answers $count */
    private function linkedCount(int $count): void
    {
        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn($count);
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $this->em->method('createQueryBuilder')->willReturn($qb);
    }

    /** @return array<mixed> */
    private function body(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    public function testLinkOk(): void
    {
        $item = $this->item();
        $this->model->method('fetchEntity')->with(5)->willReturn($item);
        $this->targetExists();
        $this->model->expects($this->once())->method('linkEntity')->with($item, 'contact', 7);

        $response = $this->controller->linkAction(5, 'contact', 7);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['itemId' => 5, 'entityType' => 'contact', 'entityId' => 7, 'linked' => true], $this->body($response));
    }

    public function testLinkAlreadyLinkedIsStill200(): void
    {
        $this->model->method('fetchEntity')->willReturn($this->item());
        $this->targetExists();
        $this->model->method('linkEntity')->willThrowException($this->createMock(UniqueConstraintViolationException::class));

        $response = $this->controller->linkAction(5, 'contact', 7);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->body($response)['linked']);
    }

    public function testLinkItemNotFound404(): void
    {
        $this->model->method('fetchEntity')->willThrowException(new NotFoundException('Item 5 not found'));
        $this->model->expects($this->never())->method('linkEntity');

        $this->assertSame(404, $this->controller->linkAction(5, 'contact', 7)->getStatusCode());
    }

    public function testLinkWithoutEditPermission403AndNothingLinked(): void
    {
        $this->model->method('fetchEntity')->willReturn($this->item());
        $this->permissions->method('canEdit')->willThrowException(new ForbiddenException('edit'));
        $this->model->expects($this->never())->method('linkEntity');

        $this->assertSame(403, $this->controller->linkAction(5, 'contact', 7)->getStatusCode());
    }

    public function testLinkUnknownEntityType400(): void
    {
        $this->model->method('fetchEntity')->willReturn($this->item());
        $this->model->expects($this->never())->method('linkEntity');

        $this->assertSame(400, $this->controller->linkAction(5, 'company', 7)->getStatusCode());
    }

    public function testLinkMissingContact404(): void
    {
        $this->model->method('fetchEntity')->willReturn($this->item());
        $this->em->method('find')->willReturn(null);
        $this->model->expects($this->never())->method('linkEntity');

        $this->assertSame(404, $this->controller->linkAction(5, 'contact', 999)->getStatusCode());
    }

    public function testUnlinkOk(): void
    {
        $item = $this->item();
        $this->model->method('fetchEntity')->willReturn($item);
        $this->targetExists();
        $this->linkedCount(1);
        $this->model->expects($this->once())->method('unlinkEntity')->with($item, 'contact', 7);
        $this->model->expects($this->never())->method('delete');

        $response = $this->controller->unlinkAction(5, 'contact', 7);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($this->body($response)['linked']);
    }

    public function testUnlinkNotLinked404AndNothingDeleted(): void
    {
        $item = $this->item(5, true);
        $item->expects($this->never())->method('findChildCustomItem');
        $this->model->method('fetchEntity')->willReturn($item);
        $this->targetExists();
        $this->linkedCount(0);
        $this->model->expects($this->never())->method('delete');
        $this->model->expects($this->never())->method('unlinkEntity');

        $response = $this->controller->unlinkAction(5, 'contact', 7);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('not linked', $this->body($response)['errors'][0]['message']);
    }

    public function testUnlinkRelationshipObjectDeletesChildItem(): void
    {
        $child = $this->createMock(CustomItem::class);
        $item  = $this->item(5, true);
        $item->method('findChildCustomItem')->willReturn($child);
        $this->model->method('fetchEntity')->willReturn($item);
        $this->targetExists();
        $this->linkedCount(1);
        $this->model->expects($this->once())->method('delete')->with($child);
        $this->model->expects($this->once())->method('unlinkEntity');

        $this->assertSame(200, $this->controller->unlinkAction(5, 'customItem', 7)->getStatusCode());
    }

    public function testUnlinkWithoutEditPermission403AndNothingDeleted(): void
    {
        $this->model->method('fetchEntity')->willReturn($this->item(5, true));
        $this->permissions->method('canEdit')->willThrowException(new ForbiddenException('edit'));
        $this->model->expects($this->never())->method('delete');
        $this->model->expects($this->never())->method('unlinkEntity');

        $this->assertSame(403, $this->controller->unlinkAction(5, 'contact', 7)->getStatusCode());
    }

    public function testUnlinkUnknownEntityType400(): void
    {
        $this->model->method('fetchEntity')->willReturn($this->item());
        $this->model->expects($this->never())->method('unlinkEntity');

        $this->assertSame(400, $this->controller->unlinkAction(5, 'company', 7)->getStatusCode());
    }

    private function contact(bool $canView = true): Lead&MockObject
    {
        $contact = $this->createMock(Lead::class);
        $contact->method('getId')->willReturn(7);
        $this->em->method('find')->willReturn($contact);
        $this->corePermissions->method('hasEntityAccess')->willReturn($canView);

        return $contact;
    }

    private function listedItem(int $id, string $name): CustomItem&MockObject
    {
        $item = $this->createMock(CustomItem::class);
        $item->method('getId')->willReturn($id);
        $item->method('getName')->willReturn($name);
        $item->method('getIsPublished')->willReturn(true);

        return $item;
    }

    public function testContactItemsMissingContact404(): void
    {
        $this->em->method('find')->willReturn(null);

        $this->assertSame(404, $this->controller->contactItemsAction(new Request(), 7)->getStatusCode());
    }

    public function testContactItemsWithoutViewPermission403(): void
    {
        $this->contact(false);
        $this->xrefRepository->expects($this->never())->method('getCustomObjectsRelatedToContact');

        $this->assertSame(403, $this->controller->contactItemsAction(new Request(), 7)->getStatusCode());
    }

    public function testContactItemsGroupedByObjectFilteredByAliasAndPermission(): void
    {
        $this->contact();
        $this->xrefRepository->method('getCustomObjectsRelatedToContact')->willReturn([
            ['id' => 1, 'alias' => 'cars'],
            ['id' => 2, 'alias' => 'pets'],
            ['id' => 3, 'alias' => 'secret'],
        ]);
        $this->permissions->method('canViewAtAll')->willReturnCallback(function (int $id): void {
            if (3 === $id) {
                throw new ForbiddenException('view');
            }
        });
        $this->model->method('getTableData')->willReturnCallback(fn (TableConfig $t): array => 1 === $t->getParameter('customObjectId')
            ? [$this->listedItem(10, 'Fusca')]
            : [$this->listedItem(20, 'Rex')]);

        $all = $this->body($this->controller->contactItemsAction(new Request(), 7));
        $this->assertSame(['cars', 'pets'], array_column($all['objects'], 'alias'), 'object without view permission is left out');
        $this->assertSame([['id' => 10, 'name' => 'Fusca', 'isPublished' => true]], $all['objects'][0]['items']);

        $one = $this->body($this->controller->contactItemsAction(new Request(['alias' => 'pets']), 7));
        $this->assertSame(['pets'], array_column($one['objects'], 'alias'));
    }

    public function testContactItemsScopesQueryToTheContactAndCapsLimit(): void
    {
        $this->contact();
        $this->xrefRepository->method('getCustomObjectsRelatedToContact')->willReturn([['id' => 1, 'alias' => 'cars']]);
        $seen = [];
        $this->model->method('getTableData')->willReturnCallback(function (TableConfig $t) use (&$seen): array {
            $seen[] = $t;

            return [$this->listedItem(10, 'Fusca')];
        });

        $body = $this->body($this->controller->contactItemsAction(new Request(['limit' => '5000', 'offset' => '250']), 7));

        $this->assertSame(100, $body['limit']);
        $this->assertSame(200, $body['offset'], 'offset is rounded down to a multiple of limit');
        $this->assertSame(100, $seen[0]->getLimit());
        $this->assertSame(7, $seen[0]->getParameter('filterEntityId'));
        $this->assertSame('contact', $seen[0]->getParameter('filterEntityType'));
        $this->assertSame(1, $seen[0]->getParameter('customObjectId'));

        $this->assertSame(1, $this->body($this->controller->contactItemsAction(new Request(['limit' => '0']), 7))['limit']);
    }

    public function testContactItemsDropsObjectsWithoutItemsOnThePage(): void
    {
        $this->contact();
        $this->xrefRepository->method('getCustomObjectsRelatedToContact')->willReturn([['id' => 1, 'alias' => 'cars']]);
        $this->model->method('getTableData')->willReturn([]);

        $this->assertSame([], $this->body($this->controller->contactItemsAction(new Request(), 7))['objects']);
    }
}
