<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\InvalidValueException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an API caller create/update one or more of a Custom Object's items
 * directly, without going through the Contact API's customObjects hook
 * (EventListener/ApiSubscriber.php) and without linking the item to a
 * contact. Body shape mirrors the "data" array already used there:
 *
 *   POST /api/customobjects/courses/items
 *   {"data": [{"name": "311-999 - nome do curso", "attributes": {"cursid": "311-999", "cursname": "nome do curso"}}]}
 *
 * POST creates only — "id" must be absent on every entry, present = 400.
 * PATCH (same path/body shape) updates only — "id" is required on every
 * entry; absent, or present but not found, = 400. Neither verb ever falls
 * back to the other's behavior for a mismatched entry.
 *
 * PUT (same path/body shape) is the one exception: it upserts by "id",
 * matching Mautic core's own PUT convention on its entity edit routes
 * (ApiBundle/Controller/CommonApiController.php::editEntityAction) — "id"
 * present and found = update; "id" present but not found, or absent
 * entirely = create (a fresh id is assigned, the requested one is not
 * reused). Added on request for API callers who expect that standard
 * Mautic upsert convention and don't want to look up existence first —
 * POST/PATCH stay strict on purpose (see git history) for callers who do.
 *
 * A single call can carry multiple entries in "data" to write several
 * items at once, wrapped in one DB transaction — if any entry fails
 * (missing "id" where required, "id" not found, an unknown field alias,
 * a validation error like a missing required field, etc.), everything
 * already written earlier in that same batch is rolled back too. Only a
 * whole-request failure is atomic this way; a successful response commits
 * every entry in "data" together.
 */
class CustomItemWriteApiController extends AbstractController
{
    public function __construct(
        private CustomObjectModel $customObjectModel,
        private CustomItemModel $customItemModel,
        private EntityManagerInterface $em
    ) {
    }

    public function saveAction(Request $request, string $objectAlias): JsonResponse
    {
        try {
            $customObject = $this->customObjectModel->fetchEntityByAlias($objectAlias);
        } catch (NotFoundException $e) {
            return new JsonResponse(['errors' => [['message' => $e->getMessage()]]], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode((string) $request->getContent(), true);

        if (!is_array($payload) || empty($payload['data']) || !is_array($payload['data'])) {
            return new JsonResponse(
                ['errors' => [['message' => 'Request body must contain a "data" array of items.']]],
                Response::HTTP_BAD_REQUEST
            );
        }

        $isPatch = $request->isMethod('PATCH');
        $isPost  = $request->isMethod('POST');
        $isPut   = $request->isMethod('PUT');

        foreach ($payload['data'] as $itemData) {
            if (!is_array($itemData)) {
                return new JsonResponse(
                    ['errors' => [['message' => 'Each entry in "data" must be an object.']]],
                    Response::HTTP_BAD_REQUEST
                );
            }

            if ($isPatch && empty($itemData['id'])) {
                return new JsonResponse(
                    ['errors' => [['message' => 'PATCH requires "id" on every entry in "data" — it only updates existing items, it never creates one. Use POST to create.']]],
                    Response::HTTP_BAD_REQUEST
                );
            }

            if ($isPost && !empty($itemData['id'])) {
                return new JsonResponse(
                    ['errors' => [['message' => 'POST never accepts "id" — it only creates new items, it never updates one. Use PATCH to update.']]],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        $items = [];

        $this->em->getConnection()->beginTransaction();

        try {
            foreach ($payload['data'] as $itemData) {
                $customItem = $this->getCustomItem($customObject, $itemData, $isPut);
                $customItem = $this->populateCustomItem($customItem, $itemData);
                $customItem->setDefaultValuesForMissingFields();
                $items[]    = $this->customItemModel->save($customItem);
            }

            $this->em->getConnection()->commit();
        } catch (NotFoundException|InvalidValueException $e) {
            $this->em->getConnection()->rollBack();

            return new JsonResponse(['errors' => [['message' => $e->getMessage()]]], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'total' => count($items),
            'items' => array_map(fn (CustomItem $item) => $this->serializeItem($item), $items),
        ]);
    }

    /**
     * @param mixed[] $itemData
     *
     * @throws NotFoundException
     */
    private function getCustomItem(CustomObject $customObject, array $itemData, bool $isPut = false): CustomItem
    {
        if (empty($itemData['id'])) {
            return new CustomItem($customObject);
        }

        try {
            $customItem = $this->customItemModel->fetchEntity((int) $itemData['id']);
        } catch (NotFoundException $e) {
            if ($isPut) {
                // PUT upserts: an id that doesn't exist falls back to create, matching
                // Mautic core's own PUT convention (see class docblock).
                return new CustomItem($customObject);
            }

            throw $e;
        }

        return $this->customItemModel->populateCustomFields($customItem);
    }

    /**
     * @param mixed[] $itemData
     *
     * @throws NotFoundException
     */
    private function populateCustomItem(CustomItem $customItem, array $itemData): CustomItem
    {
        if (!empty($itemData['name'])) {
            $customItem->setName($itemData['name']);
        }

        if (!empty($itemData['attributes']) && is_array($itemData['attributes'])) {
            foreach ($itemData['attributes'] as $fieldAlias => $value) {
                try {
                    $customFieldValue = $customItem->findCustomFieldValueForFieldAlias($fieldAlias);
                    $customFieldValue->setValue($value);
                } catch (NotFoundException) {
                    $customItem->createNewCustomFieldValueByFieldAlias($fieldAlias, $value);
                }
            }
        }

        return $customItem;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(CustomItem $item): array
    {
        $this->customItemModel->populateCustomFields($item);

        $attributes = [];
        foreach ($item->getCustomFieldValues() as $fieldValue) {
            $value                                                 = $fieldValue->getValue();
            $attributes[$fieldValue->getCustomField()->getAlias()] = $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d')
                : $value;
        }

        return [
            'id'         => $item->getId(),
            'name'       => $item->getName(),
            'attributes' => $attributes,
        ];
    }
}
