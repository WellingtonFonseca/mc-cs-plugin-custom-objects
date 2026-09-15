<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller\Api;

use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an API caller delete one of a Custom Object's items directly.
 *
 *   DELETE /api/customobjects/courses/items/{itemId}
 *
 * There is no separate "unlink" call on this API (see
 * EventListener/ApiSubscriber.php — the contact hook only links, and
 * CustomItemModel::unlinkEntity() is not reachable from any API route).
 * Deleting the item removes it, and every xref row that linked it to a
 * contact/company/other item goes with it via the ON DELETE CASCADE on
 * custom_item_xref_contact.custom_item_id (and the equivalent company/
 * custom-item xref tables) — so this only behaves like an "unlink" for a
 * caller whose items are never shared across more than one contact. If an
 * item can be linked to more than one contact, this deletes it for all of
 * them, not just one.
 */
class CustomItemDeleteApiController extends AbstractController
{
    public function __construct(
        private CustomObjectModel $customObjectModel,
        private CustomItemModel $customItemModel,
    ) {
    }

    public function deleteAction(string $objectAlias, int $itemId): JsonResponse
    {
        try {
            $customObject = $this->customObjectModel->fetchEntityByAlias($objectAlias);
        } catch (NotFoundException $e) {
            return new JsonResponse(['errors' => [['message' => $e->getMessage()]]], Response::HTTP_NOT_FOUND);
        }

        try {
            $customItem = $this->customItemModel->fetchEntity($itemId);
        } catch (NotFoundException $e) {
            return new JsonResponse(['errors' => [['message' => $e->getMessage()]]], Response::HTTP_NOT_FOUND);
        }

        if ($customItem->getCustomObject()->getId() !== $customObject->getId()) {
            return new JsonResponse(
                ['errors' => [['message' => "Item with ID = {$itemId} was not found on Custom Object '{$objectAlias}'."]]],
                Response::HTTP_NOT_FOUND
            );
        }

        $this->customItemModel->delete($customItem);

        return new JsonResponse(['id' => $itemId, 'deleted' => true]);
    }
}
