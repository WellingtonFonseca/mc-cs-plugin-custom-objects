<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller\CustomItem;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Form\Type\DateRangeType;
use Mautic\CoreBundle\Model\AuditLogModel;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemXrefContactModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemRouteProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;

class ViewController extends CommonController
{
    public function viewAction(
        FormFactoryInterface $formFactory,
        CustomItemModel $customItemModel,
        CustomItemXrefContactModel $customItemXrefContactModel,
        AuditLogModel $auditLogModel,
        CustomItemPermissionProvider $permissionProvider,
        CustomItemRouteProvider $routeProvider,
        int $objectId,
        int $itemId
    ): Response {
        $request = $this->getCurrentRequest();

        try {
            $customItem = $customItemModel->fetchEntity($itemId);
            $permissionProvider->canView($customItem);
        } catch (NotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (ForbiddenException $e) {
            return $this->accessDenied(false, $e->getMessage());
        }

        $route         = $routeProvider->buildViewRoute($objectId, $itemId);
        $dateRangeForm = $formFactory->create(
            DateRangeType::class,
            $request->get('daterange', []),
            ['action' => $route]
        );
        $stats = $customItemXrefContactModel->getLinksLineChartData(
            new \DateTime($dateRangeForm->get('date_from')->getData()),
            new \DateTime($dateRangeForm->get('date_to')->getData()),
            $customItem
        );

        $auditLogs = $auditLogModel->getLogForObject('customItem', $itemId, $customItem->getDateAdded(), 10, 'customObjects');

        return $this->delegateView(
            [
                'returnUrl'      => $route,
                'viewParameters' => [
                    'item'          => $customItem,
                    'dateRangeForm' => $dateRangeForm->createView(),
                    'stats'         => $stats,
                    'logs'          => $auditLogs,
                    'contacts'      => $this->forward(
                        'MauticPlugin\CustomObjectsBundle\Controller\CustomItem\ContactListController::listAction',
                        [
                            'objectId'   => $itemId,
                            'page'       => 1,
                            'ignoreAjax' => true,
                        ]
                    )->getContent(),
                ],
                'contentTemplate' => '@CustomObjects/CustomItem/detail.html.twig',
                'passthroughVars' => [
                    'mauticContent' => 'customItem',
                    'activeLink'    => "#mautic_custom_object_{$objectId}",
                    'route'         => $route,
                ],
            ]
        );
    }

    /**
     * GET /s/custom/object/{objectId}/item/details/{itemId}: the body of the modal opened by a click on an item's name
     * in a list: the field values and the contacts linked to the item, nothing else (no chart, no history). Same access
     * rule as the item page; the contacts only show for who may view contacts.
     */
    public function detailsAction(
        CustomItemModel $customItemModel,
        CustomItemPermissionProvider $permissionProvider,
        EntityManagerInterface $em,
        int $objectId,
        int $itemId
    ): Response {
        try {
            $customItem = $customItemModel->fetchEntity($itemId);
            $permissionProvider->canView($customItem);
        } catch (NotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (ForbiddenException $e) {
            return $this->accessDenied(false, $e->getMessage());
        }

        if ($customItem->getCustomObject()->getId() !== $objectId) {
            return $this->notFound();
        }

        $contacts     = [];
        $contactCount = 0;
        if ($this->security->isGranted(['lead:leads:viewown', 'lead:leads:viewother'], 'MATCH_ONE')) {
            $conn         = $em->getConnection();
            $contactCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'custom_item_xref_contact WHERE custom_item_id = ?', [$itemId]);
            $contacts     = $conn->fetchAllAssociative(
                'SELECT l.id, l.firstname, l.lastname, l.email FROM '.MAUTIC_TABLE_PREFIX.'custom_item_xref_contact x'
                .' JOIN '.MAUTIC_TABLE_PREFIX.'leads l ON l.id = x.contact_id WHERE x.custom_item_id = ? ORDER BY x.contact_id LIMIT 50',
                [$itemId]
            );
        }

        return $this->delegateView([
            'viewParameters'  => ['item' => $customItem, 'contacts' => $contacts, 'contactCount' => $contactCount],
            'contentTemplate' => '@CustomObjects/CustomItem/details_modal.html.twig',
            'passthroughVars' => ['route' => false],
        ]);
    }
}
