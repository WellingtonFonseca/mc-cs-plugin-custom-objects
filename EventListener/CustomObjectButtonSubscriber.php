<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Twig\Helper\ButtonHelper;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemRouteProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectRouteProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CustomObjectButtonSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CustomObjectPermissionProvider $permissionProvider,
        private CustomObjectRouteProvider $routeProvider,
        private CustomItemPermissionProvider $customItemPermissionProvider,
        private CustomItemRouteProvider $customItemRouteProvider,
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
        switch ($event->getRoute()) {
            case CustomObjectRouteProvider::ROUTE_LIST:
                $this->addEntityButtons($event, ButtonHelper::LOCATION_LIST_ACTIONS);

                try {
                    $event->addButton($this->defineNewButton(), ButtonHelper::LOCATION_PAGE_ACTIONS, $event->getRoute());
                    $event->addButton($this->defineImportDefinitionButton(), ButtonHelper::LOCATION_PAGE_ACTIONS, $event->getRoute());
                } catch (ForbiddenException) {
                }

                break;

            case CustomObjectRouteProvider::ROUTE_VIEW:
                $this->addEntityButtons($event, ButtonHelper::LOCATION_PAGE_ACTIONS);
                $event->addButton($this->defineCloseButton(), ButtonHelper::LOCATION_PAGE_ACTIONS, $event->getRoute());

                $customObject = $event->getItem();

                if ($customObject) {
                    try {
                        $event->addButton($this->defineViewCustomItemsButton($customObject), ButtonHelper::LOCATION_PAGE_ACTIONS, $event->getRoute());
                    } catch (ForbiddenException) {
                    }

                    try {
                        $event->addButton($this->defineCreateNewCustomItemButton($customObject), ButtonHelper::LOCATION_PAGE_ACTIONS, $event->getRoute());
                    } catch (ForbiddenException) {
                    }
                }

                break;
        }
    }

    private function addEntityButtons(CustomButtonEvent $event, string $location): void
    {
        $entity = $event->getItem();
        if ($entity && $entity instanceof CustomObject) {
            // the page itself already is the details: only a row of the list gets the option
            if (ButtonHelper::LOCATION_LIST_ACTIONS === $location) {
                try {
                    $event->addButton($this->defineDetailsButton($entity), $location, $event->getRoute());
                } catch (ForbiddenException) {
                }
            }

            try {
                $event->addButton($this->defineDeleteButton($entity), $location, $event->getRoute());
            } catch (ForbiddenException) {
            }

            try {
                $event->addButton($this->defineCloneButton($entity), $location, $event->getRoute());
            } catch (ForbiddenException) {
            }

            try {
                $event->addButton($this->defineEditButton($entity), $location, $event->getRoute());
            } catch (ForbiddenException) {
            }
        }
    }

    /**
     * The "Detalhes" option of the row's three-dots menu: the page of the object, above Edit.
     *
     * @return mixed[]
     *
     * @throws ForbiddenException
     */
    private function defineDetailsButton(CustomObject $entity): array
    {
        $this->permissionProvider->canView($entity);

        return [
            'attr' => [
                'href' => $this->routeProvider->buildViewRoute($entity->getId()),
            ],
            'btnText'   => 'mautic.core.details',
            'iconClass' => 'ri-information-line',
            'priority'  => 600,
        ];
    }

    /**
     * @return mixed[]
     *
     * @throws ForbiddenException
     */
    private function defineEditButton(CustomObject $entity): array
    {
        $this->permissionProvider->canEdit($entity);

        return [
            'attr' => [
                'href' => $this->routeProvider->buildEditRoute($entity->getId()),
            ],
            'btnText'   => 'mautic.core.form.edit',
            'iconClass' => 'fa fa-pencil-square-o',
            'priority'  => 500,
        ];
    }

    /**
     * @return mixed[]
     */
    private function defineCloseButton(): array
    {
        return [
            'attr' => [
                'href'  => $this->routeProvider->buildListRoute(),
            ],
            'btnText'   => 'mautic.core.form.close',
            'iconClass' => 'fa fa-fw fa-remove',
            'priority'  => 400,
        ];
    }

    /**
     * @return mixed[]
     *
     * @throws ForbiddenException
     */
    private function defineCloneButton(CustomObject $entity): array
    {
        $this->permissionProvider->canClone($entity);

        return [
            'attr' => [
                'href' => $this->routeProvider->buildCloneRoute($entity->getId()),
            ],
            'btnText'   => 'mautic.core.form.clone',
            'iconClass' => 'fa fa-copy',
            'priority'  => 300,
        ];
    }

    /**
     * @return mixed[]
     *
     * @throws ForbiddenException
     */
    private function defineDeleteButton(CustomObject $entity): array
    {
        $this->permissionProvider->canDelete($entity);

        return [
            'attr' => [
                'href'                  => $this->routeProvider->buildDeleteRoute($entity->getId()),
                'data-toggle'           => 'confirmation',
                'data-message'          => $this->translator->trans('custom.object.delete.confirm'),
                'data-confirm-text'     => $this->translator->trans('mautic.core.form.delete'),
                'data-confirm-callback' => 'executeAction',
                'data-cancel-text'      => $this->translator->trans('mautic.core.form.cancel'),
                'data-cancel-callback'  => 'dismissConfirmation',
                // core paints the Delete item of a row menu by this class (red on hover, as in the other lists)
                'class' => 'danger',
            ],
            'btnText'   => 'mautic.core.form.delete',
            // the icon of core's own Delete item: it takes the color of the item (a text-danger icon stays red on the red hover)
            'iconClass' => 'ri-delete-bin-line',
            'priority'  => 0,
        ];
    }

    /**
     * @return mixed[]
     *
     * @throws ForbiddenException
     */
    private function defineNewButton(): array
    {
        $this->permissionProvider->canCreate();

        return [
            'attr' => [
                'href' => $this->routeProvider->buildNewRoute(),
            ],
            'btnText'   => 'mautic.core.form.new',
            'iconClass' => 'fa fa-plus',
            'priority'  => 500,
        ];
    }

    /**
     * The button of the list page that opens the import of the definition of an object made on another instance (a Clone tab's JSON).
     *
     * @return mixed[]
     *
     * @throws ForbiddenException
     */
    private function defineImportDefinitionButton(): array
    {
        $this->permissionProvider->canCreate();

        return [
            'attr' => [
                'href' => $this->routeProvider->buildImportDefinitionRoute(),
            ],
            'btnText'   => 'custom.object.import.button',
            'iconClass' => 'ri-upload-2-line',
            'priority'  => 400,
        ];
    }

    /**
     * @return string[]
     *
     * @throws ForbiddenException
     */
    private function defineViewCustomItemsButton(CustomObject $customObject): array
    {
        $this->customItemPermissionProvider->canViewAtAll($customObject->getId());

        return [
            'attr' => [
                'href' => $this->customItemRouteProvider->buildListRoute($customObject->getId()),
            ],
            'btnText'   => 'custom.items.view.link',
            'iconClass' => 'fa fa-fw fa-list-alt',
            'priority'  => 0,
        ];
    }

    /**
     * @return string[]
     *
     * @throws ForbiddenException
     */
    private function defineCreateNewCustomItemButton(CustomObject $customObject): array
    {
        $this->customItemPermissionProvider->canCreate($customObject->getId());

        return [
            'attr' => [
                'href' => $this->customItemRouteProvider->buildNewRoute($customObject->getId()),
            ],
            'btnText'   => 'custom.item.create.link',
            'iconClass' => 'fa fa-fw fa-plus',
            'priority'  => 0,
        ];
    }
}
