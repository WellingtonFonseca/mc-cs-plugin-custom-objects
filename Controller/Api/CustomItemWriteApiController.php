<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller\Api;

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
 * POST: "id" is optional per entry — omit it to create a new item, pass
 * an existing item's "id" to update it instead.
 *
 * PATCH (same path/body shape): "id" is required on every entry — PATCH
 * only updates, it never creates. An entry missing "id" fails the whole
 * request with a 400 before anything is written.
 *
 * A single call can carry multiple entries in "data" to write several
 * items at once.
 */
class CustomItemWriteApiController extends AbstractController
{
    public function __construct(
        private CustomObjectModel $customObjectModel,
        private CustomItemModel $customItemModel
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
        }

        $items = [];

        foreach ($payload['data'] as $itemData) {
            try {
                $customItem = $this->getCustomItem($customObject, $itemData);
                $customItem = $this->populateCustomItem($customItem, $itemData);
                $customItem->setDefaultValuesForMissingFields();
                $items[]    = $this->customItemModel->save($customItem);
            } catch (NotFoundException $e) {
                return new JsonResponse(['errors' => [['message' => $e->getMessage()]]], Response::HTTP_BAD_REQUEST);
            } catch (InvalidValueException $e) {
                return new JsonResponse(['errors' => [['message' => $e->getMessage()]]], Response::HTTP_BAD_REQUEST);
            }
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
    private function getCustomItem(CustomObject $customObject, array $itemData): CustomItem
    {
        if (empty($itemData['id'])) {
            return new CustomItem($customObject);
        }

        $customItem = $this->customItemModel->fetchEntity((int) $itemData['id']);

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
