<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Controller\Api;

use Mautic\LeadBundle\Entity\LeadList;
use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomObjectApiController;
use MauticPlugin\CustomObjectsBundle\CustomFieldType\CustomFieldTypeInterface;
use MauticPlugin\CustomObjectsBundle\DTO\CustomObjectDefinitionImportResult;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\DefinitionAlreadyExistsException;
use MauticPlugin\CustomObjectsBundle\Exception\DefinitionImportException;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\InUseException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Helper\CustomObjectDefinitionExporter;
use MauticPlugin\CustomObjectsBundle\Helper\CustomObjectDefinitionImporter;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Repository\CustomItemRepository;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group('area:co-objects')]
#[Group('risk:CRITICO')]
#[Group('risk:DADOS')]
final class CustomObjectApiControllerTest extends TestCase
{
    /** @var CustomObjectDefinitionImporter|MockObject */
    private $importer;

    /** @var CustomObjectModel|MockObject */
    private $model;

    /** @var CustomObjectPermissionProvider|MockObject */
    private $permissionProvider;

    /** @var CustomItemModel|MockObject */
    private $itemModel;

    private CustomObjectApiController $controller;

    protected function setUp(): void
    {
        $this->importer           = $this->createMock(CustomObjectDefinitionImporter::class);
        $this->model              = $this->createMock(CustomObjectModel::class);
        $this->itemModel          = $this->createMock(CustomItemModel::class);
        $this->permissionProvider = $this->createMock(CustomObjectPermissionProvider::class);

        $this->controller = new CustomObjectApiController(
            $this->importer,
            new CustomObjectDefinitionExporter(),
            $this->model,
            $this->itemModel,
            $this->permissionProvider
        );
    }

    private function customObject(string $alias = 'prova', int $id = 7): CustomObject
    {
        $field = new CustomField();
        $field->setAlias('provanome');
        $field->setLabel('Nome');
        $field->setType('text');
        // a field loaded from the database always has its type object (CustomFieldFactory / post-load): the exporter reads it for the options
        $typeObject = $this->createMock(CustomFieldTypeInterface::class);
        $typeObject->method('getSymfonyFormFieldType')->willReturn(TextType::class);
        $field->setTypeObject($typeObject);
        $field->setOrder(0);
        $this->setId($field, 31);

        $object = new CustomObject();
        $object->setAlias($alias);
        $object->setNameSingular('Prova');
        $object->setNamePlural('Provas');
        $object->setType(CustomObject::TYPE_MASTER);
        $object->setIsPublished(false);
        $object->addCustomField($field);
        $this->setId($object, $id);

        return $object;
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    /**
     * @return mixed[]
     */
    private function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    private function postRequest(string $content): Request
    {
        return Request::create('/api/customobjects', 'POST', [], [], [], [], $content);
    }

    public function testCreateAnswers201WithTheIdsOfTheObjectAndItsFields(): void
    {
        $object = $this->customObject();
        $this->importer->expects($this->once())->method('import')
            ->with('{"alias":"prova"}', true, true)
            ->willReturn(new CustomObjectDefinitionImportResult($object, true, ['provanome']));

        $response = $this->controller->createAction($this->postRequest('{"alias":"prova"}'));

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertSame(
            ['id' => 7, 'alias' => 'prova', 'customFields' => [['id' => 31, 'alias' => 'provanome', 'type' => 'text']]],
            $this->body($response)
        );
    }

    public function testCreateChecksThePermissionBeforeAnythingElse(): void
    {
        $this->permissionProvider->method('canCreate')->willThrowException(new ForbiddenException('create'));
        $this->importer->expects($this->never())->method('import');

        $response = $this->controller->createAction($this->postRequest('{"alias":"prova"}'));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertArrayHasKey('errors', $this->body($response));
    }

    public function testCreateAnswers400WithEveryErrorOfTheDefinition(): void
    {
        $this->importer->method('import')->willThrowException(new DefinitionImportException(['first problem', 'second problem']));

        $response = $this->controller->createAction($this->postRequest('{"alias":"prova"}'));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame([['message' => 'first problem'], ['message' => 'second problem']], $this->body($response)['errors']);
    }

    public function testCreateOfAnAliasThatExistsIs409(): void
    {
        // create-only: the importer refuses the alias before it saves anything, so the "add the missing fields" path is never taken
        $this->importer->expects($this->once())->method('import')->with($this->anything(), true, true)
            ->willThrowException(new DefinitionAlreadyExistsException(['A Custom Object with the alias prova already exists.']));

        $response = $this->controller->createAction($this->postRequest('{"alias":"prova","customFields":[]}'));

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertStringContainsString('prova', $this->body($response)['errors'][0]['message']);
    }

    public function testCreateWithABodyThatIsNotJsonIsLeftToTheImporterAs400(): void
    {
        $this->importer->expects($this->once())->method('import')->with('not json', true, true)
            ->willThrowException(new DefinitionImportException(['json']));

        $response = $this->controller->createAction($this->postRequest('not json'));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testViewAnswersTheDefinitionWithTheIds(): void
    {
        $this->model->method('fetchEntityByAlias')->with('prova')->willReturn($this->customObject());

        $response = $this->controller->viewAction('prova');
        $body     = $this->body($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(7, $body['id']);
        $this->assertSame('prova', $body['alias']);
        $this->assertSame('Prova', $body['nameSingular']);
        $this->assertSame('Provas', $body['namePlural']);
        $this->assertSame(CustomObject::TYPE_MASTER, $body['type']);
        $this->assertNull($body['masterObjectAlias']);
        $this->assertFalse($body['isPublished']);
        $this->assertSame(31, $body['customFields'][0]['id']);
        $this->assertSame('provanome', $body['customFields'][0]['alias']);
        $this->assertSame('text', $body['customFields'][0]['type']);
        $this->assertSame('custom-object-definition', $body['format']);
    }

    public function testViewChecksThePermissionOnTheObject(): void
    {
        $object = $this->customObject();
        $this->model->method('fetchEntityByAlias')->willReturn($object);
        $this->permissionProvider->expects($this->once())->method('canView')->with($object)
            ->willThrowException(new ForbiddenException('view'));

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->controller->viewAction('prova')->getStatusCode());
    }

    public function testViewOfAnUnknownAliasIs404(): void
    {
        $this->model->method('fetchEntityByAlias')->willThrowException(new NotFoundException('Custom Object with alias = x was not found'));

        $response = $this->controller->viewAction('x');

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertStringContainsString('was not found', $this->body($response)['errors'][0]['message']);
    }

    public function testDeleteRemovesTheObjectAfterTheChecks(): void
    {
        $object = $this->customObject();
        $this->model->method('fetchEntityByAlias')->willReturn($object);
        $this->permissionProvider->expects($this->once())->method('canDelete')->with($object);
        $this->model->expects($this->once())->method('checkIfTheCustomObjectIsUsedInSegmentFilters')->with($object);
        $this->model->expects($this->once())->method('delete')->with($object);

        $response = $this->controller->deleteAction(new Request(), 'prova');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['alias' => 'prova', 'deleted' => true, 'alreadyGone' => false], $this->body($response));
    }

    public function testDeleteOfAnUnknownAliasIs200AlreadyGone(): void
    {
        $this->model->method('fetchEntityByAlias')->willThrowException(new NotFoundException('no'));
        $this->model->expects($this->never())->method('delete');

        $response = $this->controller->deleteAction(new Request(), 'prova');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['alias' => 'prova', 'deleted' => false, 'alreadyGone' => true], $this->body($response));
    }

    public function testDeleteOfAnUnknownAliasWithoutPermissionIs403NotAlreadyGone(): void
    {
        $this->permissionProvider->method('canViewAtAll')->willThrowException(new ForbiddenException('view'));
        // the lookup must not even happen: "alreadyGone" would tell who has no permission which aliases exist
        $this->model->expects($this->never())->method('fetchEntityByAlias');
        $this->model->expects($this->never())->method('delete');

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->controller->deleteAction(new Request(), 'nobody')->getStatusCode());
    }

    public function testDeleteOfAMasterThatHasAChildIs409NamingTheChild(): void
    {
        $child = $this->customObject('filho', 8);
        $master = $this->customObject('mestre', 9);
        $master->setRelationshipObject($child);
        $this->model->method('fetchEntityByAlias')->willReturn($master);
        // the database would delete the child by cascade, skipping the segment check: nothing may be checked as "free" nor deleted
        $this->model->expects($this->never())->method('checkIfTheCustomObjectIsUsedInSegmentFilters');
        $this->model->expects($this->never())->method('delete');

        $response = $this->controller->deleteAction(new Request(), 'mestre');

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertStringContainsString('"filho"', $this->body($response)['errors'][0]['message']);
    }

    public function testDeleteWithoutPermissionIs403AndDeletesNothing(): void
    {
        $this->model->method('fetchEntityByAlias')->willReturn($this->customObject());
        $this->permissionProvider->method('canDelete')->willThrowException(new ForbiddenException('delete'));
        $this->model->expects($this->never())->method('delete');

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->controller->deleteAction(new Request(), 'prova')->getStatusCode());
    }

    public function testDeleteOfAnObjectUsedByASegmentIs409ListingTheSegments(): void
    {
        $segment = new LeadList();
        $segment->setName('Alunos');
        $this->setId($segment, 12);
        $exception = new InUseException();
        $exception->setSegmentList([$segment]);

        $this->model->method('fetchEntityByAlias')->willReturn($this->customObject());
        $this->model->method('checkIfTheCustomObjectIsUsedInSegmentFilters')->willThrowException($exception);
        $this->model->expects($this->never())->method('delete');

        $response = $this->controller->deleteAction(new Request(), 'prova');
        $body     = $this->body($response);

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame([['id' => 12, 'name' => 'Alunos']], $body['segments']);
        $this->assertStringContainsString('Alunos', $body['errors'][0]['message']);
    }

    private function dryRunRequest(): Request
    {
        return new Request(['dryRun' => '1']);
    }

    public function testDeleteDryRunDeletesNothingAndAnswersTheCount(): void
    {
        $object = $this->customObject();
        $this->model->method('fetchEntityByAlias')->willReturn($object);
        $repository = $this->createMock(CustomItemRepository::class);
        $repository->expects($this->once())->method('getItemCount')->with(7)->willReturn(42);
        $this->itemModel->method('getRepository')->willReturn($repository);
        $this->permissionProvider->expects($this->once())->method('canDelete')->with($object);
        $this->model->expects($this->once())->method('checkIfTheCustomObjectIsUsedInSegmentFilters')->with($object);
        $this->model->expects($this->never())->method('delete');

        $response = $this->controller->deleteAction($this->dryRunRequest(), 'prova');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(
            ['alias' => 'prova', 'dryRun' => true, 'deleted' => false, 'itemCount' => 42, 'wouldDelete' => true],
            $this->body($response)
        );
    }

    public function testDeleteDryRunWithoutPermissionIs403(): void
    {
        $this->model->method('fetchEntityByAlias')->willReturn($this->customObject());
        $this->permissionProvider->method('canDelete')->willThrowException(new ForbiddenException('delete'));
        $this->model->expects($this->never())->method('delete');
        $this->itemModel->expects($this->never())->method('getRepository');

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->controller->deleteAction($this->dryRunRequest(), 'prova')->getStatusCode());
    }

    public function testDeleteDryRunOfAMasterWithAChildIs409(): void
    {
        $master = $this->customObject('mestre', 9);
        $master->setRelationshipObject($this->customObject('filho', 8));
        $this->model->method('fetchEntityByAlias')->willReturn($master);
        $this->model->expects($this->never())->method('delete');

        $response = $this->controller->deleteAction($this->dryRunRequest(), 'mestre');

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertArrayNotHasKey('dryRun', $this->body($response));
    }

    public function testDeleteDryRunOfAnObjectUsedByASegmentIs409(): void
    {
        $exception = new InUseException();
        $exception->setSegmentList([]);
        $this->model->method('fetchEntityByAlias')->willReturn($this->customObject());
        $this->model->method('checkIfTheCustomObjectIsUsedInSegmentFilters')->willThrowException($exception);
        $this->model->expects($this->never())->method('delete');

        $this->assertSame(Response::HTTP_CONFLICT, $this->controller->deleteAction($this->dryRunRequest(), 'prova')->getStatusCode());
    }

    public function testDeleteWithDryRunZeroStillDeletes(): void
    {
        $this->model->method('fetchEntityByAlias')->willReturn($this->customObject());
        $this->model->expects($this->once())->method('delete');

        $response = $this->controller->deleteAction(new Request(['dryRun' => '0']), 'prova');

        $this->assertTrue($this->body($response)['deleted']);
    }
}
