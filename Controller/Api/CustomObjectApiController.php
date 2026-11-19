<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller\Api;

use Mautic\LeadBundle\Entity\LeadList;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\DefinitionAlreadyExistsException;
use MauticPlugin\CustomObjectsBundle\Exception\DefinitionImportException;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\InUseException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Helper\CustomObjectDefinitionExporter;
use MauticPlugin\CustomObjectsBundle\Helper\CustomObjectDefinitionImporter;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Custom Object TYPE over the REST API: POST /api/customobjects, GET and DELETE /api/customobjects/{alias}.
 * The rules, the answers and the traps are in wiki/custom-objects-api.md.
 */
class CustomObjectApiController extends AbstractController
{
    public function __construct(
        private CustomObjectDefinitionImporter $importer,
        private CustomObjectDefinitionExporter $exporter,
        private CustomObjectModel $customObjectModel,
        private CustomObjectPermissionProvider $permissionProvider,
    ) {
    }

    public function createAction(Request $request): JsonResponse
    {
        try {
            $this->permissionProvider->canCreate();
        } catch (ForbiddenException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_FORBIDDEN);
        }

        try {
            // create-only: an alias that exists is refused by the importer before it saves anything (it would add the missing fields)
            $result = $this->importer->import((string) $request->getContent(), true, true);
        } catch (DefinitionAlreadyExistsException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_CONFLICT);
        } catch (DefinitionImportException $e) {
            return new JsonResponse(
                ['errors' => array_map(static fn (string $message): array => ['message' => $message], $e->getErrors())],
                Response::HTTP_BAD_REQUEST
            );
        }

        $customObject = $result->getCustomObject();

        return new JsonResponse([
            'id'           => $customObject->getId(),
            'alias'        => $customObject->getAlias(),
            'customFields' => array_values(array_map(
                static fn (CustomField $field): array => ['id' => $field->getId(), 'alias' => $field->getAlias(), 'type' => $field->getType()],
                $customObject->getCustomFields()->toArray()
            )),
        ], Response::HTTP_CREATED);
    }

    public function viewAction(string $objectAlias): JsonResponse
    {
        try {
            $customObject = $this->customObjectModel->fetchEntityByAlias($objectAlias);
            $this->permissionProvider->canView($customObject);
        } catch (NotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->definitionWithIds($customObject));
    }

    public function deleteAction(string $objectAlias): JsonResponse
    {
        try {
            // before the lookup: "alreadyGone" must not tell a caller without permission which aliases exist
            $this->permissionProvider->canViewAtAll();
            $customObject = $this->customObjectModel->fetchEntityByAlias($objectAlias);
        } catch (ForbiddenException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (NotFoundException) {
            return new JsonResponse(['alias' => $objectAlias, 'deleted' => false, 'alreadyGone' => true]);
        }

        try {
            $this->permissionProvider->canDelete($customObject);
            $child = $customObject->getRelationshipObject();
            if ($child instanceof CustomObject) {
                // the database deletes the child (master_object ON DELETE CASCADE) without the segment check below: the caller deletes it first
                return $this->errorResponse(
                    sprintf('Custom Object "%s" has the child object "%s": delete the child first. Nothing was deleted.', $objectAlias, (string) $child->getAlias()),
                    Response::HTTP_CONFLICT
                );
            }
            $this->customObjectModel->checkIfTheCustomObjectIsUsedInSegmentFilters($customObject);
        } catch (ForbiddenException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (InUseException $e) {
            return $this->inUseResponse($objectAlias, $e);
        }

        $this->customObjectModel->delete($customObject);

        return new JsonResponse(['alias' => $objectAlias, 'deleted' => true, 'alreadyGone' => false]);
    }

    /**
     * The Clone tab JSON plus what a caller needs to point at the rows: the id of the object and of each field, and isPublished.
     *
     * @return mixed[]
     */
    private function definitionWithIds(CustomObject $customObject): array
    {
        $definition = $this->exporter->toArray($customObject);
        $idsByAlias = [];
        foreach ($customObject->getCustomFields() as $field) {
            $idsByAlias[$field->getAlias()] = $field->getId();
        }

        foreach ($definition['customFields'] as $index => $field) {
            $definition['customFields'][$index] = ['id' => $idsByAlias[$field['alias']] ?? null] + $field;
        }

        return ['id' => $customObject->getId(), 'isPublished' => (bool) $customObject->getIsPublished()] + $definition;
    }

    private function inUseResponse(string $objectAlias, InUseException $exception): JsonResponse
    {
        $segments = array_values(array_map(
            static fn (LeadList $segment): array => ['id' => $segment->getId(), 'name' => $segment->getName()],
            $exception->getSegmentList()
        ));
        $names = implode(', ', array_map(static fn (array $segment): string => sprintf('"%s" (%d)', $segment['name'], $segment['id']), $segments));

        return new JsonResponse([
            'errors'   => [['message' => sprintf('Custom Object "%s" is used by the filters of these segments: %s. Nothing was deleted.', $objectAlias, $names)]],
            'segments' => $segments,
        ], Response::HTTP_CONFLICT);
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['errors' => [['message' => $message]]], $status);
    }
}
