<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\Event\MenuEvent;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Menu\MenuHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\EventListener\MenuSubscriber;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\ConfigProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemRouteProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use MauticPlugin\CustomObjectsBundle\Tests\Unit\ConsecutiveCallsTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class MenuSubscriberTest extends TestCase
{
    use ConsecutiveCallsTrait;

    private $customObjectModel;

    private $configProvider;

    private MenuHelper $menuHelper;

    /**
     * @var MenuSubscriber
     */
    private $menuSubscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customObjectModel = $this->createMock(CustomObjectModel::class);
        $this->configProvider    = $this->createMock(ConfigProvider::class);
        // MenuEvent and MenuHelper are final in Mautic 7: the real ones, over mocked dependencies
        $security = $this->createStub(CorePermissions::class);
        $security->method('isGranted')->willReturn(true);
        $this->menuHelper        = new MenuHelper($security, new RequestStack(), $this->createMock(CoreParametersHelper::class), $this->createMock(IntegrationHelper::class));
        $this->menuSubscriber    = new MenuSubscriber($this->customObjectModel, $this->configProvider);
    }

    private function menuEvent(string $type): MenuEvent
    {
        return new MenuEvent($this->menuHelper, $type);
    }

    /**
     * @return array<string, mixed>
     */
    private function children(MenuEvent $event): array
    {
        return $event->getMenuItems()['children'];
    }

    public function testPluginDisabled(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(false);

        $event = $this->menuEvent('main');
        $this->menuSubscriber->onBuildMenu($event);

        $this->assertSame([], $this->children($event));
    }

    public function testTypeNotMain(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->customObjectModel->expects($this->never())
            ->method('getMasterCustomObjects');

        $event = $this->menuEvent('not-main-or-admin');
        $this->menuSubscriber->onBuildMenu($event);

        $this->assertSame([], $this->children($event));
    }

    public function testNoCustomObjects(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->customObjectModel->expects($this->once())
            ->method('getMasterCustomObjects')
            ->willReturn([]);

        $event = $this->menuEvent('main');
        $this->menuSubscriber->onBuildMenu($event);

        $this->assertSame([], $this->children($event));
    }

    public function testSomeCustomObjects(): void
    {
        $customObject1 = $this->createStub(CustomObject::class);
        $customObject1->method('getName')->willReturn('Test Object 1');
        $customObject1->method('getId')->willReturn(333);
        $customObject1->method('getType')->willReturn(CustomObject::TYPE_MASTER);

        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        // Custom Objects of type 'Relationship' are not in getMasterCustomObjects(): they do not appear in the menu
        $this->customObjectModel->expects($this->once())
            ->method('getMasterCustomObjects')
            ->willReturn([$customObject1]);

        $event = $this->menuEvent('main');
        $this->menuSubscriber->onBuildMenu($event);

        $children = $this->children($event);
        $this->assertArrayHasKey('custom.object.title', $children);
        $this->assertSame('mautic_custom_object_list', $children['custom.object.title']['id']);
        $this->assertSame('custom_objects:custom_objects:view', $children['custom.object.title']['access']);

        $entry = $children['custom.object.title']['children']['Test Object 1'] ?? $children['Test Object 1'] ?? null;
        $this->assertNotNull($entry, 'the Custom Object has its own entry');
        $this->assertSame('mautic_custom_object_333', $entry['id']);
        $this->assertSame(CustomItemRouteProvider::ROUTE_LIST, $entry['route']);
        $this->assertSame(['objectId' => 333, 'page' => 1], $entry['routeParameters']);
        $this->assertSame('custom_objects:333:view', $entry['access']);
    }

    public function testAdminMenu(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->customObjectModel->expects($this->never())
            ->method('getMasterCustomObjects');

        $event = $this->menuEvent('admin');
        $this->menuSubscriber->onBuildMenu($event);

        $children = $this->children($event);
        $this->assertArrayHasKey('custom.object.config.menu.title', $children);
        $this->assertSame('custom_objects:custom_objects:view', $children['custom.object.config.menu.title']['access']);
    }
}
