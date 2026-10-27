<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Twig\Helper\ButtonHelper;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\EventListener\CustomObjectButtonSubscriber;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemRouteProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectRouteProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use MauticPlugin\CustomObjectsBundle\Tests\Unit\ConsecutiveCallsTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class CustomObjectButtonSubscriberTest extends \PHPUnit\Framework\TestCase
{
    use ConsecutiveCallsTrait;

    private $itemPermissionProvider;
    private $objectPermissionProvider;
    private $objectRouteProvider;
    private $itemRouteProvider;
    private $translator;
    private $customObject;

    /**
     * @var CustomObjectButtonSubscriber
     */
    private $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->itemPermissionProvider    = $this->createMock(CustomItemPermissionProvider::class);
        $this->objectPermissionProvider  = $this->createMock(CustomObjectPermissionProvider::class);
        $this->objectRouteProvider       = $this->createMock(CustomObjectRouteProvider::class);
        $this->itemRouteProvider         = $this->createMock(CustomItemRouteProvider::class);
        $this->translator                = $this->createMock(TranslatorInterface::class);
        $this->customObject              = $this->createMock(CustomObject::class);
        $this->subscriber                = new CustomObjectButtonSubscriber(
            $this->objectPermissionProvider,
            $this->objectRouteProvider,
            $this->itemPermissionProvider,
            $this->itemRouteProvider,
            $this->translator
        );
    }

    /**
     * The real event: CustomButtonEvent is final in Mautic 7, a double cannot be made for it. It carries ONE location and
     * ignores the buttons of another one, so each location is its own event, as Mautic dispatches it.
     */
    private function buttonEvent(string $route, string $location): CustomButtonEvent
    {
        return new CustomButtonEvent($location, new Request([], [], ['_route' => $route]), [], $this->customObject);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buttons(CustomButtonEvent $event): array
    {
        return array_values($event->getButtons());
    }

    public function testInjectViewButtonsForListRoute(): void
    {
        $this->customObject->expects($this->any())
            ->method('getId')
            ->willReturn(555);

        $this->objectPermissionProvider->expects($this->exactly(2))
            ->method('canView')
            ->with($this->customObject);
        $this->objectRouteProvider->expects($this->exactly(2))
            ->method('buildViewRoute')
            ->with(555)
            ->willReturn('generated/view/route');

        $this->objectPermissionProvider->expects($this->exactly(2))
            ->method('canDelete')
            ->with($this->customObject);

        $this->objectRouteProvider->expects($this->exactly(2))
            ->method('buildDeleteRoute')
            ->with(555)
            ->willReturn('generated/delete/route');

        $this->objectPermissionProvider->expects($this->exactly(2))
            ->method('canClone')
            ->with($this->customObject);

        $this->objectRouteProvider->expects($this->exactly(2))
            ->method('buildCloneRoute')
            ->with(555)
            ->willReturn('generated/clone/route');

        $this->objectPermissionProvider->expects($this->exactly(2))
            ->method('canEdit')
            ->with($this->customObject);

        $this->objectRouteProvider->expects($this->exactly(2))
            ->method('buildEditRoute')
            ->with(555)
            ->willReturn('generated/edit/route');

        // the New button and the import of a definition ask the same permission, on each of the two locations
        $this->objectPermissionProvider->expects($this->exactly(4))
            ->method('canCreate');

        $this->objectRouteProvider->expects($this->exactly(2))
            ->method('buildNewRoute')
            ->willReturn('generated/new/route');

        $this->objectRouteProvider->expects($this->exactly(2))
            ->method('buildImportDefinitionRoute')
            ->willReturn('generated/import/route');

        $this->translator->expects($this->exactly(6))
            ->method('trans')
            ->willReturnCallback($this->consecutiveCalls([
                ['custom.object.delete.confirm'],
                ['mautic.core.form.delete'],
                ['mautic.core.form.cancel'],
                ['custom.object.delete.confirm'],
                ['mautic.core.form.delete'],
                ['mautic.core.form.cancel']
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
                    'id'                    => 'delete',
                    'class'                 => 'danger',
                ],
                'btnText'   => 'mautic.core.form.delete',
                'iconClass' => 'ri-delete-bin-line',
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
                    'href' => 'generated/import/route',
                ],
                'btnText'   => 'custom.object.import.button',
                'iconClass' => 'ri-upload-2-line',
                'priority'  => 400,
            ]];

        $list = $this->buttonEvent(CustomObjectRouteProvider::ROUTE_LIST, ButtonHelper::LOCATION_LIST_ACTIONS);
        $page = $this->buttonEvent(CustomObjectRouteProvider::ROUTE_LIST, ButtonHelper::LOCATION_PAGE_ACTIONS);
        $this->subscriber->injectViewButtons($list);
        $this->subscriber->injectViewButtons($page);

        $this->assertEquals($expected, array_merge($this->buttons($list), $this->buttons($page)));
    }

    public function testInjectViewButtonsForListRouteWithoutPermissions(): void
    {
        $this->customObject->expects($this->any())
            ->method('getId')
            ->willReturn(555);

        $this->objectPermissionProvider->expects($this->exactly(2))
            ->method('canView')
            ->with($this->customObject)
            ->will($this->throwException(new ForbiddenException('view')));

        $this->objectPermissionProvider->expects($this->exactly(2))
            ->method('canDelete')
            ->with($this->customObject)
            ->will($this->throwException(new ForbiddenException('delete')));

        $this->objectPermissionProvider->expects($this->exactly(2))
            ->method('canClone')
            ->with($this->customObject)
            ->will($this->throwException(new ForbiddenException('clone')));

        $this->objectPermissionProvider->expects($this->exactly(2))
            ->method('canEdit')
            ->with($this->customObject)
            ->will($this->throwException(new ForbiddenException('edit')));

        $this->objectPermissionProvider->expects($this->exactly(2))
            ->method('canCreate')
            ->will($this->throwException(new ForbiddenException('create')));

        $list = $this->buttonEvent(CustomObjectRouteProvider::ROUTE_LIST, ButtonHelper::LOCATION_LIST_ACTIONS);
        $page = $this->buttonEvent(CustomObjectRouteProvider::ROUTE_LIST, ButtonHelper::LOCATION_PAGE_ACTIONS);
        $this->subscriber->injectViewButtons($list);
        $this->subscriber->injectViewButtons($page);

        $this->assertSame([], array_merge($this->buttons($list), $this->buttons($page)));
    }

    public function testInjectViewButtonsForViewRoute(): void
    {
        $this->customObject->expects($this->any())
            ->method('getId')
            ->willReturn(555);

        $this->objectPermissionProvider->expects($this->once())
            ->method('canDelete')
            ->with($this->customObject);

        $this->objectRouteProvider->expects($this->once())
            ->method('buildDeleteRoute')
            ->with(555)
            ->willReturn('generated/delete/route');

        $this->objectPermissionProvider->expects($this->once())
            ->method('canClone')
            ->with($this->customObject);

        $this->objectRouteProvider->expects($this->once())
            ->method('buildCloneRoute')
            ->with(555)
            ->willReturn('generated/clone/route');

        $this->objectPermissionProvider->expects($this->once())
            ->method('canEdit')
            ->with($this->customObject);

        $this->objectRouteProvider->expects($this->once())
            ->method('buildEditRoute')
            ->with(555)
            ->willReturn('generated/edit/route');

        $this->objectRouteProvider->expects($this->once())
            ->method('buildListRoute')
            ->willReturn('generated/list/route');

        $this->itemPermissionProvider->expects($this->once())
            ->method('canViewAtAll')
            ->with(555);

        $this->itemRouteProvider->expects($this->once())
            ->method('buildListRoute')
            ->willReturn('generated/item/list/route');

        $this->itemPermissionProvider->expects($this->once())
            ->method('canCreate')
            ->with(555);

        $this->itemRouteProvider->expects($this->once())
            ->method('buildNewRoute')
            ->willReturn('generated/item/new/route');

        $expected = [[
                'attr' => [
                    'href'                  => 'generated/delete/route',
                    'data-toggle'           => 'confirmation',
                    'data-message'          => null,
                    'data-confirm-text'     => null,
                    'data-confirm-callback' => 'executeAction',
                    'data-cancel-text'      => null,
                    'data-cancel-callback'  => 'dismissConfirmation',
                    'id'                    => 'delete',
                    'class'                 => 'danger',
                ],
                'btnText'   => 'mautic.core.form.delete',
                'iconClass' => 'ri-delete-bin-line',
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
            ], [
                'attr' => [
                    'href' => 'generated/item/list/route',
                ],
                'btnText'   => 'custom.items.view.link',
                'iconClass' => 'fa fa-fw fa-list-alt',
                'priority'  => 0,
            ], [
                'attr' => [
                    'href' => 'generated/item/new/route',
                ],
                'btnText'   => 'custom.item.create.link',
                'iconClass' => 'fa fa-fw fa-plus',
                'priority'  => 0,
            ]];

        $event = $this->buttonEvent(CustomObjectRouteProvider::ROUTE_VIEW, ButtonHelper::LOCATION_PAGE_ACTIONS);
        $this->subscriber->injectViewButtons($event);

        $this->assertEquals($expected, $this->buttons($event));
    }

    public function testInjectViewButtonsForViewRouteWithoutPermissions(): void
    {
        $this->customObject->expects($this->any())
            ->method('getId')
            ->willReturn(555);

        $this->objectPermissionProvider->expects($this->once())
            ->method('canDelete')
            ->with($this->customObject)
            ->will($this->throwException(new ForbiddenException('create')));

        $this->objectPermissionProvider->expects($this->once())
            ->method('canClone')
            ->with($this->customObject)
            ->will($this->throwException(new ForbiddenException('create')));

        $this->objectPermissionProvider->expects($this->once())
            ->method('canEdit')
            ->with($this->customObject)
            ->will($this->throwException(new ForbiddenException('create')));

        $this->itemPermissionProvider->expects($this->once())
            ->method('canViewAtAll')
            ->with(555)
            ->will($this->throwException(new ForbiddenException('create')));

        $this->itemPermissionProvider->expects($this->once())
            ->method('canCreate')
            ->with(555)
            ->will($this->throwException(new ForbiddenException('create')));

        $this->objectRouteProvider->expects($this->once())
            ->method('buildListRoute')
            ->willReturn('generated/list/route');

        $event = $this->buttonEvent(CustomObjectRouteProvider::ROUTE_VIEW, ButtonHelper::LOCATION_PAGE_ACTIONS);
        $this->subscriber->injectViewButtons($event);

        $this->assertEquals([[
                'attr' => [
                    'href' => 'generated/list/route',
                ],
                'btnText'   => 'mautic.core.form.close',
                'iconClass' => 'fa fa-fw fa-remove',
                'priority'  => 400,
            ]], $this->buttons($event));
    }
}
