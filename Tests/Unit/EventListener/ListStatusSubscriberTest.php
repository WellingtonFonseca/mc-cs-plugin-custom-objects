<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Twig\Helper\ButtonHelper;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\EventListener\ListStatusSubscriber;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemRouteProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectRouteProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The first item of the row menu of the Custom Objects list: "Desativar" for a published object, "Ativar" for an unpublished one, only for
 * who may edit it.
 */
class ListStatusSubscriberTest extends TestCase
{
    private CustomObjectPermissionProvider $objectPermission;
    private ListStatusSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->objectPermission = $this->createMock(CustomObjectPermissionProvider::class);
        $translator             = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $this->subscriber = new ListStatusSubscriber($this->objectPermission, $translator);
    }

    private function object(bool $published): CustomObject
    {
        $object = new CustomObject();
        $object->setIsPublished($published);

        return $object;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array{0: array<string, mixed>, 1: string, 2: string}>
     */
    private function buttons(string $route, ?object $item, array $query = []): array
    {
        $request = new Request($query);
        $request->attributes->set('_route', $route);
        $event = new CustomButtonEvent(ButtonHelper::LOCATION_LIST_ACTIONS, $request, [], $item);
        $this->subscriber->injectViewButtons($event);

        return array_values($event->getButtons());
    }

    public function testItListensToTheButtonsEvent(): void
    {
        $this->assertSame([CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['injectViewButtons', 0]], ListStatusSubscriber::getSubscribedEvents());
    }

    public function testAPublishedObjectOffersToDeactivateItFirstInTheMenu(): void
    {
        $buttons = $this->buttons(CustomObjectRouteProvider::ROUTE_LIST, $this->object(true));

        $this->assertCount(1, $buttons);
        $this->assertSame('deactivate', $buttons[0]['attr']['data-co-status-action']);
        $this->assertSame('custom.object.status.deactivate', $buttons[0]['btnText']);
        $this->assertSame('ri-close-circle-line', $buttons[0]['iconClass']);
        $this->assertSame(800, $buttons[0]['priority'], 'above Details (600): the first item of the menu');
        // not 'ajax': core would add that and navigate; the script clicks core's icon of the row
        $this->assertSame('co-status', $buttons[0]['attr']['data-toggle']);
        $this->assertSame('javascript:void(0);', $buttons[0]['attr']['href']);
        $this->assertSame('custom.object.status.activate', $buttons[0]['attr']['data-text-activate']);
        $this->assertSame('custom.object.status.deactivate', $buttons[0]['attr']['data-text-deactivate']);
    }

    public function testAnUnpublishedObjectOffersToActivateIt(): void
    {
        $buttons = $this->buttons(CustomObjectRouteProvider::ROUTE_LIST, $this->object(false));

        $this->assertSame('activate', $buttons[0]['attr']['data-co-status-action']);
        $this->assertSame('custom.object.status.activate', $buttons[0]['btnText']);
        $this->assertSame('ri-checkbox-circle-line', $buttons[0]['iconClass']);
    }

    public function testWhoMayNotEditAnObjectGetsNoItem(): void
    {
        $this->objectPermission->method('canEdit')->willThrowException(new ForbiddenException('no'));

        $this->assertSame([], $this->buttons(CustomObjectRouteProvider::ROUTE_LIST, $this->object(true)));
    }

    public function testTheObjectListChecksThePermissionToEditThatObject(): void
    {
        $object = $this->object(true);
        $this->objectPermission->expects($this->once())->method('canEdit')->with($object);

        $this->buttons(CustomObjectRouteProvider::ROUTE_LIST, $object);
    }

    public function testOtherRoutesAndOtherEntitiesAreLeftAlone(): void
    {
        $this->assertSame([], $this->buttons(CustomObjectRouteProvider::ROUTE_VIEW, $this->object(true)));
        $this->assertSame([], $this->buttons(CustomItemRouteProvider::ROUTE_LIST, $this->object(true)), 'the item list has no status item');
        $this->assertSame([], $this->buttons(CustomObjectRouteProvider::ROUTE_LIST, new \stdClass()), 'something that is not an object');
        $this->assertSame([], $this->buttons(CustomObjectRouteProvider::ROUTE_LIST, null));
    }
}
