<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Twig\Helper\AssetsHelper;
use MauticPlugin\CustomObjectsBundle\EventListener\AssetsSubscriber;
use MauticPlugin\CustomObjectsBundle\Provider\ConfigProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;

#[Group('area:co-objects')]
#[Group('area:co-items')]
#[Group('area:plugin-settings')]
#[Group('risk:INFRA')]
#[Group('risk:UI')]
class AssetsSubscriberTest extends \PHPUnit\Framework\TestCase
{
    private $packages;

    private $coreParametersHelper;
    private $assetsHelper;

    private $configProvider;

    private $requestEvent;

    private $request;

    private $assetsSubscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packages             = $this->createMock(Packages::class);
        $this->coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $this->assetsHelper         = new AssetsHelper(
            $this->packages,
            $this->coreParametersHelper
        );
        $this->configProvider       = $this->createMock(ConfigProvider::class);
        $this->requestEvent         = $this->createMock(RequestEvent::class);
        $this->request              = $this->createMock(Request::class);
        $this->assetsSubscriber     = new AssetsSubscriber($this->assetsHelper, $this->configProvider);
    }

    public function testPluginDisabled(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(false);

        $this->requestEvent->expects($this->never())
            ->method('isMainRequest');

        $this->assetsSubscriber->loadAssets($this->requestEvent);
    }

    public function testPluginEnabledOnPublicPage(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->requestEvent->expects($this->once())
            ->method('isMainRequest')
            ->willReturn(true);

        $this->requestEvent->expects($this->once())
            ->method('getRequest')
            ->willReturn($this->request);

        $this->request->expects($this->once())
            ->method('getPathInfo')
            ->willReturn('/email/unsubscribe/5c9f4105548a6783784018');

        $this->assetsSubscriber->loadAssets($this->requestEvent);
    }

    public function testTheAboutScriptIsLoadedBeforeTheChangeLogScript(): void
    {
        $this->configProvider->method('pluginIsEnabled')->willReturn(true);
        $this->requestEvent->method('isMainRequest')->willReturn(true);
        $this->requestEvent->method('getRequest')->willReturn($this->request);
        $this->request->method('getPathInfo')->willReturn('/s/dashboard');
        // AssetsHelper is final: the real one runs and its output is read. Core hands it the paths helper by a setter (TwigPass).
        $paths = $this->createMock(PathsHelper::class);
        $paths->method('getSystemPath')->willReturn('');
        $this->assetsHelper->setPathsHelper($paths);
        $this->packages->method('getUrl')->willReturnArgument(0);

        $this->assetsSubscriber->loadAssets($this->requestEvent);

        $head = $this->assetsHelper->getHeadDeclarations();
        foreach (['custom-objects.js', 'co-form.js', 'plugin-about-tab.js', 'plugin-changelog-tab.js', 'list-status.js', 'custom-objects.css'] as $asset) {
            $this->assertStringContainsString('Assets/'.('css' === substr($asset, -3) ? 'css' : 'js').'/'.$asset, $head);
        }
        $this->assertLessThan(strpos($head, 'plugin-changelog-tab.js'), strpos($head, 'plugin-about-tab.js'));
    }

    public function testOnlyAPathThatStartsWithSLoadsTheAssets(): void
    {
        $this->configProvider->method('pluginIsEnabled')->willReturn(true);
        $this->requestEvent->method('isMainRequest')->willReturn(true);
        $this->requestEvent->method('getRequest')->willReturn($this->request);
        $this->request->method('getPathInfo')->willReturn('/api/s/dashboard');
        $paths = $this->createMock(PathsHelper::class);
        $paths->method('getSystemPath')->willReturn('');
        $this->assetsHelper->setPathsHelper($paths);
        $this->packages->method('getUrl')->willReturnArgument(0);

        $this->assetsSubscriber->loadAssets($this->requestEvent);

        $this->assertSame('', $this->assetsHelper->getHeadDeclarations());
    }

    public function testTheModalThatHoldsTheAboutBlockIsWidenedTo780px(): void
    {
        $css = file_get_contents(__DIR__.'/../../../Assets/css/custom-objects.css');

        $this->assertMatchesRegularExpression('/\.modal-dialog:has\(\.customobjects-about-block\)\s*\{\s*width: 780px;/', $css);
    }

    public function testPluginEnabled(): void
    {
        $this->configProvider->expects($this->once())
            ->method('pluginIsEnabled')
            ->willReturn(true);

        $this->requestEvent->expects($this->once())
            ->method('isMainRequest')
            ->willReturn(true);

        $this->requestEvent->expects($this->once())
            ->method('getRequest')
            ->willReturn($this->request);

        $this->request->expects($this->once())
            ->method('getPathInfo')
            ->willReturn('/s/dashboard');

        $this->assetsSubscriber->loadAssets($this->requestEvent);
    }
}
