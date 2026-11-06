<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an API caller delete one or more of a Custom Object's items directly.
 *
 *   DELETE /api/customobjects/courses/items/{itemId}
 *   DELETE /api/customobjects/courses/items                 {"data": [{"id": 1}, {"id": 2}]}
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
 * them, not just one. Batching many ids in one call only makes it easier to
 * trigger that for many items at once — same caveat, bigger blast radius.
 *
 * The batch route mirrors CustomItemWriteApiController's "data" array and
 * single-DB-transaction behavior: if any id belongs to a different Custom
 * Object, the whole batch is rolled back and nothing already deleted earlier
 * in that same call stays deleted. Both routes answer the same shape:
 * {"total": N, "deleted": [ids], "alreadyGone": [ids that did not exist]}.
 */
class CustomItemDeleteApiController extends AbstractController
{
    public function __construct(
        private CustomObjectModel $customObjectModel,
        private CustomItemModel $customItemModel,
        private EntityManagerInterface $em,
    ) {
    }

    public function deleteAction(string $objectAlias, int $itemId): JsonResponse
    {
        try {
            $customObject = $this->customObjectModel->fetchEntityByAlias($objectAlias);
        } catch (NotFoundException $e) {
            return new JsonResponse(['errors' => [['message' => $e->getMessage()]]], Response::HTTP_NOT_FOUND);
        }

        return $this->deleteItems($customObject, $objectAlias, [$itemId]);
    }

    public function batchDeleteAction(Request $request, string $objectAlias): JsonResponse
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

        $itemIds = [];

        foreach ($payload['data'] as $itemData) {
            if (!is_array($itemData) || empty($itemData['id'])) {
                return new JsonResponse(
                    ['errors' => [['message' => 'Each entry in "data" must have an "id".']]],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // An id that is not a positive integer would become 0 and be reported as "already gone": a typo must not look like a success.
            if (!$this->isPositiveInteger($itemData['id'])) {
                return new JsonResponse(
                    ['errors' => [['message' => 'Each "id" must be a positive integer.']]],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $itemIds[] = (int) $itemData['id'];
        }

        return $this->deleteItems($customObject, $objectAlias, $itemIds);
    }

    /**
     * An id that does not exist is not an error: the caller wanted the item gone and it is gone, so the id is
     * listed in "alreadyGone". An id repeated in the call is deleted once and is never "already gone".
     * An item of ANOTHER Custom Object is a 404 that rolls back the whole call.
     *
     * @param int[] $itemIds
     */
    private function deleteItems(CustomObject $customObject, string $objectAlias, array $itemIds): JsonResponse
    {
        $deletedIds     = [];
        $alreadyGoneIds = [];

        $this->em->getConnection()->beginTransaction();

        try {
            foreach (array_unique($itemIds) as $itemId) {
                try {
                    $customItem = $this->customItemModel->fetchEntity($itemId);
                } catch (NotFoundException) {
                    $alreadyGoneIds[] = $itemId;

                    continue;
                }

                if (!$this->belongsToCustomObject($customItem->getCustomObject(), $customObject)) {
                    throw new NotFoundException("Item with ID = {$itemId} was not found on Custom Object '{$objectAlias}'.");
                }

                $this->customItemModel->delete($customItem);
                $deletedIds[] = $itemId;
            }

            $this->em->getConnection()->commit();
        } catch (NotFoundException $e) {
            $this->em->getConnection()->rollBack();

            return new JsonResponse(['errors' => [['message' => $e->getMessage()]]], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['total' => count($deletedIds), 'deleted' => $deletedIds, 'alreadyGone' => $alreadyGoneIds]);
    }

    private function isPositiveInteger(mixed $id): bool
    {
        if (is_string($id)) {
            return ctype_digit($id) && (int) $id > 0;
        }

        return is_int($id) && $id > 0;
    }

    private function belongsToCustomObject(CustomObject $itemOwner, CustomObject $customObject): bool
    {
        return $itemOwner->getId() === $customObject->getId();
    }
}
