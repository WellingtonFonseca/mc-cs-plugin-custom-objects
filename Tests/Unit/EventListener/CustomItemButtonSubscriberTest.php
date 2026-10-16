<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Twig\Helper\ButtonHelper;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\EventListener\CustomItemButtonSubscriber;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemRouteProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use MauticPlugin\CustomObjectsBundle\Tests\Unit\ConsecutiveCallsTrait;

class CustomItemButtonSubscriberTest extends TestCase
{
    use ConsecutiveCallsTrait;

    private const OBJECT_ID  = 555;
    private const ITEM_ID    = 222;
    private const CONTACT_ID = 84;

    /**
     * @var MockObject|CustomItemPermissionProvider
     */
    private $permissionProvider;

    /**
     * @var MockObject|CustomItemRouteProvider
     */
    private $routeProvider;

    /**
     * @var MockObject|TranslatorInterface
     */
    private $translator;

    /**
     * @var MockObject|CustomItem
     */
    private $customItem;

    /**
     * @var CustomItemButtonSubscriber
     */
    private $subscrber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permissionProvider = $this->createMock(CustomItemPermissionProvider::class);
        $this->routeProvider      = $this->createMock(CustomItemRouteProvider::class);
        $this->translator         = $this->createMock(TranslatorInterface::class);
        $this->customItem         = $this->createMock(CustomItem::class);
        $this->subscrber          = new CustomItemButtonSubscriber(
            $this->permissionProvider,
            $this->routeProvider,
            $this->translator
        );
    }

    /**
     * The real event: CustomButtonEvent is final in Mautic 7, a double cannot be made for it. It carries ONE location and
     * ignores the buttons of another one, so each location is its own event, as Mautic dispatches it. The providers are
     * then asked once per event: the expectations below are "at least", not "exactly".
     *
     * @param array<string, mixed> $query
     */
    private function buttonEvent(string $route, string $location, array $query = []): CustomButtonEvent
    {
        $request = new Request($query, [], ['_route' => $route, '_route_params' => ['objectId' => self::OBJECT_ID]]);

        return new CustomButtonEvent($location, $request, [], $this->customItem);
    }

    /**
     * @param CustomButtonEvent[] $events
     *
     * @return array<int, array<string, mixed>>
     */
    private function injectInto(array $events): array
    {
        $buttons = [];
        foreach ($events as $event) {
            $this->subscrber->injectViewButtons($event);
            $buttons = array_merge($buttons, array_values($event->getButtons()));
        }

        return $buttons;
    }

    public function testInjectViewButtonsForListRouteOnContactDetailPage(): void
    {
        $this->permissionProvider->expects($this->once())
            ->method('canCreate')
            ->with(self::OBJECT_ID);

        $this->routeProvider->expects($this->once())
            ->method('buildUnlinkRoute')
            ->with(self::ITEM_ID, 'contact', self::CONTACT_ID)
            ->willReturn('generated/route');

        $this->translator->expects($this->once())
            ->method('trans')
            ->with('custom.item.unlink')
            ->willReturn('translated string');

        $this->customItem->expects($this->once())
            ->method('getId')
            ->willReturn(self::ITEM_ID);

        $this->customItem->method('getCustomObject')
            ->willReturn(new CustomObject());

        $buttons = $this->injectInto([
            $this->buttonEvent(CustomItemRouteProvider::ROUTE_LIST, ButtonHelper::LOCATION_LIST_ACTIONS, ['filterEntityId' => self::CONTACT_ID, 'filterEntityType' => 'contact']),
        ]);

        $this->assertEquals([[
                'attr' => [
                    'href'        => '#',
                    'onclick'     => "CustomObjects.unlinkCustomItemFromEntity(this, event, 555, 'contact', 84, 'custom-object-555');",
                    'data-action' => 'generated/route',
                    'data-toggle' => '',
                ],
                'btnText'   => 'translated string',
                'iconClass' => 'fa fa-unlink',
                'priority'  => 500,
            ]], $buttons);
    }

    public function testInjectViewButtonsForListRoute(): void
    {
        $this->customItem->expects($this->any())
            ->method('getId')
            ->willReturn(self::ITEM_ID);

        $this->permissionProvider->expects($this->atLeastOnce())
            ->method('canView')
            ->with($this->customItem);

        $this->routeProvider->expects($this->atLeastOnce())
            ->method('buildViewRoute')
            ->with(self::OBJECT_ID, self::ITEM_ID)
            ->willReturn('generated/view/route');

        $this->permissionProvider->expects($this->atLeastOnce())
            ->method('canDelete')
            ->with($this->customItem);

        $this->routeProvider->expects($this->atLeastOnce())
            ->method('buildDeleteRoute')
            ->with(self::OBJECT_ID, self::ITEM_ID)
            ->willReturn('generated/delete/route');

        $this->permissionProvider->expects($this->atLeastOnce())
            ->method('canClone')
            ->with($this->customItem);

        $this->routeProvider->expects($this->atLeastOnce())
            ->method('buildCloneRoute')
            ->with(self::OBJECT_ID, self::ITEM_ID)
            ->willReturn('generated/clone/route');

        $this->permissionProvider->expects($this->atLeastOnce())
            ->method('canEdit')
            ->with($this->customItem);

        $this->routeProvider->expects($this->atLeastOnce())
            ->method('buildEditRoute')
            ->with(self::OBJECT_ID, self::ITEM_ID)
            ->willReturn('generated/edit/route');

        $this->permissionProvider->expects($this->atLeast(2))
            ->method('canCreate')
            ->with(self::OBJECT_ID);

        $this->routeProvider->expects($this->atLeastOnce())
            ->method('buildNewRoute')
            ->with(self::OBJECT_ID)
            ->willReturn('generated/new/route');

        $this->routeProvider->expects($this->atLeastOnce())
            ->method('buildNewImportRoute')
            ->with(self::OBJECT_ID)
            ->willReturn('generated/new/import/route');

        $this->permissionProvider->expects($this->atLeast(2))
            ->method('canViewAtAll')
            ->with(self::OBJECT_ID);

        $this->routeProvider->expects($this->atLeastOnce())
            ->method('buildListImportRoute')
            ->with(self::OBJECT_ID)
            ->willReturn('generated/list/import/route');

        $this->routeProvider->expects($this->atLeastOnce())
            ->method('buildBatchDeleteRoute')
            ->with(self::OBJECT_ID)
            ->willReturn('generated/batch/delete/route');

        $this->translator->expects($this->atLeast(4))
            ->method('trans')
            ->willReturnCallback($this->consecutiveCalls([
                ['custom.item.delete.confirm'],
                ['mautic.core.form.delete'],
                ['mautic.core.form.cancel'],
                ['mautic.core.form.confirmbatchdelete']
            ], 'translated string'));

        $expected = [[
                'attr' => [
                    'href' => 'generated/view/route',
                ],
                'btnText'   => 'mautic.core.details',
                'iconClass' => 'ri-information-line',
                'priority'  => 600,
            ], [
                'attr' => [
                    'href'                  => 'generated/delete/route',
                    'data-toggle'           => 'confirmation',
                    'data-message'          => 'translated string',
                    'data-confirm-text'     => 'translated string',
                    'data-confirm-callback' => 'executeAction',
                    'data-cancel-text'      => 'translated string',
                    'data-cancel-callback'  => 'dismissConfirmation',
                ],
                'btnText'   => 'mautic.core.form.delete',
                'iconClass' => 'fa fa-fw fa-trash-o text-danger',
                'priority'  => 0,
            ], [
                'attr' => [
                    'href' => 'generated/clone/route',
                ],
                'btnText'   => 'mautic.core.form.clone',
                'iconClass' => 'fa fa-copy',
                'priority'  => 300,
            ], [
                'attr' => [
                    'href' => 'generated/edit/route',
                ],
                'btnText'   => 'mautic.core.form.edit',
                'iconClass' => 'fa fa-pencil-square-o',
                'priority'  => 500,
            ], [
                'attr' => [
                    'href' => 'generated/new/route',
                ],
                'btnText'   => 'mautic.core.form.new',
                'iconClass' => 'fa fa-plus',
                'priority'  => 500,
            ], [
                'attr' => [
                    'href' => 'generated/new/import/route',
                ],
                'btnText'   => 'mautic.lead.import',
                'iconClass' => 'fa fa-upload',
                'priority'  => 350,
            ], [
                'attr' => [
                    'href' => 'generated/list/import/route',
                ],
                'btnText'   => 'mautic.lead.lead.import.index',
                'iconClass' => 'fa fa-history',
                'priority'  => 300,
            ], [
                'attr' => [
                    'href'        => '',
                    'data-method' => 'POST',
                ],
                'btnText'   => 'custom.item.export',
                'iconClass' => 'fa fa-file-text-o',
                'priority'  => 250,
            ], [
                'confirm' => [
                    'message'       => 'translated string',
                    'confirmAction' => 'generated/batch/delete/route',
                    'template'      => 'batchdelete',
                ],
                'btnText'   => 'mautic.core.form.delete',
                'iconClass' => 'fa fa-fw fa-trash-o text-danger',
                'priority'  => 0,
            ]];

        $buttons = $this->injectInto([
            $this->buttonEvent(CustomItemRouteProvider::ROUTE_LIST, ButtonHelper::LOCATION_LIST_ACTIONS),
            $this->buttonEvent(CustomItemRouteProvider::ROUTE_LIST, ButtonHelper::LOCATION_PAGE_ACTIONS),
            $this->buttonEvent(CustomItemRouteProvider::ROUTE_LIST, ButtonHelper::LOCATION_BULK_ACTIONS),
        ]);

        $this->assertEquals($expected, $buttons);
    }

    public function testInjectViewButtonsForListRouteIfForbidden(): void
    {
        $this->customItem->expects($this->any())
            ->method('getId')
            ->willReturn(self::ITEM_ID);

        $this->permissionProvider->expects($this->atLeastOnce())
            ->method('canView')
            ->with($this->customItem)
            ->will($this->throwException(new ForbiddenException('view')));

        $this->routeProvider->expects($this->never())
            ->method('buildViewRoute');

        $this->permissionProvider->expects($this->atLeastOnce())
            ->method('canDelete')
            ->with($this->customItem)
            ->will($this->throwException(new ForbiddenException('delete')));

        $this->routeProvider->expects($this->never())
            ->method('buildDeleteRoute');

        $this->permissionProvider->expects($this->atLeastOnce())
            ->method('canClone')
            ->with($this->customItem)
            ->will($this->throwException(new ForbiddenException('clone')));

        $this->routeProvider->expects($this->never())
            ->method('buildCloneRoute');

        $this->permissionProvider->expects($this->atLeastOnce())
            ->method('canEdit')
            ->with($this->customItem)
            ->will($this->throwException(new ForbiddenException('edit')));

        $this->routeProvider->expects($this->never())
            ->method('buildEditRoute');

        $this->permissionProvider->expects($this->atLeastOnce())
            ->method('canCreate')
            ->with(self::OBJECT_ID)
            ->will($this->throwException(new ForbiddenException('create')));

        $this->routeProvider->expects($this->never())
            ->method('buildNewRoute');

        $this->routeProvider->expects($this->never())
            ->method('buildNewImportRoute');

        $this->routeProvider->expects($this->never())
            ->method('buildListImportRoute');

        $this->routeProvider->expects($this->never())
            ->method('buildBatchDeleteRoute');

        $buttons = $this->injectInto([
            $this->buttonEvent(CustomItemRouteProvider::ROUTE_LIST, ButtonHelper::LOCATION_LIST_ACTIONS),
            $this->buttonEvent(CustomItemRouteProvider::ROUTE_LIST, ButtonHelper::LOCATION_PAGE_ACTIONS),
            $this->buttonEvent(CustomItemRouteProvider::ROUTE_LIST, ButtonHelper::LOCATION_BULK_ACTIONS),
        ]);

        $this->assertSame([], $buttons);
    }

    public function testInjectViewButtonsForViewRoute(): void
    {
        $this->customItem->expects($this->any())
            ->method('getId')
            ->willReturn(self::ITEM_ID);

        $this->permissionProvider->expects($this->once())
            ->method('canDelete')
            ->with($this->customItem);

        $this->routeProvider->expects($this->once())
            ->method('buildDeleteRoute')
            ->with(self::OBJECT_ID, self::ITEM_ID)
            ->willReturn('generated/delete/route');

        $this->permissionProvider->expects($this->once())
            ->method('canClone')
            ->with($this->customItem);

        $this->routeProvider->expects($this->once())
            ->method('buildCloneRoute')
            ->with(self::OBJECT_ID, self::ITEM_ID)
            ->willReturn('generated/clone/route');

        $this->permissionProvider->expects($this->once())
            ->method('canEdit')
            ->with($this->customItem);

        $this->routeProvider->expects($this->once())
            ->method('buildEditRoute')
            ->with(self::OBJECT_ID, self::ITEM_ID)
            ->willReturn('generated/edit/route');

        $this->routeProvider->expects($this->once())
            ->method('buildListRoute')
            ->with(self::OBJECT_ID)
            ->willReturn('generated/list/route');

        $expected = [[
                'attr' => [
                    'href'                  => 'generated/delete/route',
                    'data-toggle'           => 'confirmation',
                    'data-message'          => null,
                    'data-confirm-text'     => null,
                    'data-confirm-callback' => 'executeAction',
                    'data-cancel-text'      => null,
                    'data-cancel-callback'  => 'dismissConfirmation',
                ],
                'btnText'   => 'mautic.core.form.delete',
                'iconClass' => 'fa fa-fw fa-trash-o text-danger',
                'priority'  => 0,
            ], [
                'attr' => [
                    'href' => 'generated/clone/route',
                ],
                'btnText'   => 'mautic.core.form.clone',
                'iconClass' => 'fa fa-copy',
                'priority'  => 300,
            ], [
                'attr' => [
                    'href' => 'generated/edit/route',
                ],
                'btnText'   => 'mautic.core.form.edit',
                'iconClass' => 'fa fa-pencil-square-o',
                'priority'  => 500,
            ], [
                'attr' => [
                    'href' => 'generated/list/route',
                ],
                'btnText'   => 'mautic.core.form.close',
                'iconClass' => 'fa fa-fw fa-remove',
                'priority'  => 400,
            ]];

        $buttons = $this->injectInto([$this->buttonEvent(CustomItemRouteProvider::ROUTE_VIEW, ButtonHelper::LOCATION_PAGE_ACTIONS)]);

        $this->assertEquals($expected, $buttons);
    }
}
