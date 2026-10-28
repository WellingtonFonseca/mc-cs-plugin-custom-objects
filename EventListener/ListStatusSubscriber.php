<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Twig\Helper\ButtonHelper;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectRouteProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The status of a row of the Custom Objects list (Assets/js/list-status.js): the first item of the three-dots menu, "Desativar" for a
 * published object and "Ativar" for an unpublished one, only for who may edit it. The item clicks core's toggle icon of the row (hidden),
 * so core sends the request and checks the permission as it always did; the badge of the Status column follows that icon.
 */
class ListStatusSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CustomObjectPermissionProvider $permissionProvider,
        private TranslatorInterface $translator
    ) {
    }

    /**
     * @return mixed[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['injectViewButtons', 0],
        ];
    }

    public function injectViewButtons(CustomButtonEvent $event): void
    {
        $entity = $event->getItem();

        if (CustomObjectRouteProvider::ROUTE_LIST !== $event->getRoute() || !$entity instanceof CustomObject) {
            return;
        }

        try {
            $this->permissionProvider->canEdit($entity);
        } catch (ForbiddenException) {
            return;
        }

        $event->addButton($this->defineButton($entity->isPublished()), ButtonHelper::LOCATION_LIST_ACTIONS, $event->getRoute());
    }

    /**
     * @return mixed[]
     */
    private function defineButton(bool $published): array
    {
        return [
            'attr' => [
                // not 'ajax': core would add that and navigate; the script clicks core's icon of the row
                'data-toggle'           => 'co-status',
                'data-co-status-action' => $published ? 'deactivate' : 'activate',
                'data-text-activate'    => $this->translator->trans('custom.object.status.activate'),
                'data-text-deactivate'  => $this->translator->trans('custom.object.status.deactivate'),
                'href'                  => 'javascript:void(0);',
            ],
            'btnText'   => $published ? 'custom.object.status.deactivate' : 'custom.object.status.activate',
            'iconClass' => $published ? 'ri-close-circle-line' : 'ri-checkbox-circle-line',
            'priority'  => 800,
        ];
    }
}
