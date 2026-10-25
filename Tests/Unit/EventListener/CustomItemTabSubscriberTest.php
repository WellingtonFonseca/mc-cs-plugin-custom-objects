<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\Event\CustomContentEvent;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\EventListener\CustomItemTabSubscriber;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemRouteProvider;
use MauticPlugin\CustomObjectsBundle\Provider\SessionProvider;
use MauticPlugin\CustomObjectsBundle\Provider\SessionProviderFactory;
use MauticPlugin\CustomObjectsBundle\Repository\CustomItemRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use MauticPlugin\CustomObjectsBundle\Tests\Unit\ConsecutiveCallsTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class CustomItemTabSubscriberTest extends TestCase
{
    use ConsecutiveCallsTrait;

    /**
     * @var MockObject|CustomObjectModel
     */
    private $customObjectModel;

    /**
     * @var MockObject|CustomItemRepository
     */
    private $customItemRepository;

    /**
     * @var MockObject|TranslatorInterface
     */
    private $translator;

    /**
     * @var MockObject|CustomItemRouteProvider
     */
    private $customItemRouteProvider;

    /**
     * @var CustomContentEvent
     */
    private $customContentEvent;

    /**
     * @var MockObject|CustomItem
     */
    private $customItem;

    /**
     * @var MockObject|CustomObject
     */
    private $customObject;

    /**
     * @var CustomItemTabSubscriber
     */
    private $tabSubscriber;

    /**
     * @var SessionProviderFactory|MockObject
     */
    private $sessionProviderFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customObjectModel       = $this->createMock(CustomObjectModel::class);
        $this->customItemRepository    = $this->createMock(CustomItemRepository::class);
        $this->translator              = $this->createMock(TranslatorInterface::class);
        $this->customItemRouteProvider = $this->createMock(CustomItemRouteProvider::class);
        $this->customObject            = $this->createMock(CustomObject::class);
        $this->customItem              = $this->createMock(CustomItem::class);
        $this->sessionProviderFactory  = $this->createMock(SessionProviderFactory::class);
        $this->tabSubscriber           = new CustomItemTabSubscriber(
            $this->customObjectModel,
            $this->customItemRepository,
            $this->translator,
            $this->customItemRouteProvider,
            $this->sessionProviderFactory
        );
    }

    public function testForTabsContext(): void
    {
        $itemCustomObject = $this->createStub(CustomObject::class);
        $itemCustomObject->method('getId')->willReturn(444);

        $this->customObject->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(555);

        $this->customItem->expects($this->once())
            ->method('getCustomObject')
            ->willReturn($itemCustomObject);

        $this->customObject->expects($this->once())
            ->method('getNamePlural')
            ->willReturn('Object A');

        $this->customContentEvent = new CustomContentEvent('@CustomObjects/CustomItem/detail.html.twig', 'tabs', ['item' => $this->customItem]);

        $this->customObjectModel->expects($this->once())
            ->method('getMasterCustomObjects')
            ->willReturn([$this->customObject]);

        $this->customItemRepository->expects($this->once())
            ->method('countItemsLinkedToAnotherItem')
            ->with($this->customObject, $this->customItem)
            ->willReturn(13);

        $this->tabSubscriber->injectTabs($this->customContentEvent);

        $this->assertEquals([
            [
                'template' => '@CustomObjects/SubscribedEvents/Tab/link.html.twig',
                'vars'     => ['count' => 13, 'title' => 'Object A', 'tabId' => 'custom-object-555'],
            ],
            [
                'template' => '@CustomObjects/SubscribedEvents/Tab/modal.html.twig',
                'vars'     => [],
            ],
        ], $this->customContentEvent->getTemplates());
    }

    /**
     * We don't want to link custom items of the same type together.
     */
    public function testForTabsContextWhenTheCustomItemMatchesCustomObject(): void
    {
        $this->customObject->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(555);

        $this->customItem->expects($this->once())
            ->method('getCustomObject')
            ->willReturn($this->customObject);

        $this->customContentEvent = new CustomContentEvent('@CustomObjects/CustomItem/detail.html.twig', 'tabs', ['item' => $this->customItem]);

        $this->customObjectModel->expects($this->once())
            ->method('getMasterCustomObjects')
            ->willReturn([$this->customObject]);

        $this->customItemRepository->expects($this->never())
            ->method('countItemsLinkedToAnotherItem');

        $this->tabSubscriber->injectTabs($this->customContentEvent);

        $this->assertEquals([['template' => '@CustomObjects/SubscribedEvents/Tab/modal.html.twig', 'vars' => []]], $this->customContentEvent->getTemplates());
    }

    public function testForTabContentsContext(): void
    {
        $otherCustomObject = $this->createStub(CustomObject::class);
        $otherCustomObject->method('getId')->willReturn(111);

        $this->customItem->method('getId')->willReturn(45);
        $this->customItem->method('getCustomObject')
            ->willReturn($otherCustomObject);
        $this->customObject->method('getId')->willReturn(555);

        $this->customContentEvent = new CustomContentEvent('@CustomObjects/CustomItem/detail.html.twig', 'tabs.content', ['item' => $this->customItem]);

        $this->translator->expects($this->once())
            ->method('trans')
            ->with('custom.item.link.existing.modal.header.custom_object')
            ->willReturn('translated modal header');

        $this->customItemRouteProvider->expects($this->once())
            ->method('buildNewRoute')
            ->with(555)
            ->willReturn('new/route');

        $this->customItemRouteProvider->expects($this->exactly(2))
            ->method('buildListRoute')
            ->willReturnCallback($this->consecutiveCalls([
                [555, 1, 'customItem', 45],
                [555, 1, 'customItem', 45, ['lookup' => 1, 'search' => '']]
            ], [
                'search/route',
                'link/route'
            ], true));

        $sessionProvider = $this->createStub(SessionProvider::class);
        $sessionProvider->method('getFilter')->willReturn('Search something');
        $sessionProvider->method('getNamespace')->willReturn('Some namespace');

        $this->sessionProviderFactory->expects($this->once())
            ->method('createItemProvider')
            ->with(555, 'customItem', 45)
            ->willReturn($sessionProvider);

        $this->customObjectModel->expects($this->once())
            ->method('fetchAllPublishedEntities')
            ->willReturn([$this->customObject]);

        $this->tabSubscriber->injectTabs($this->customContentEvent);

        $this->assertEquals([['template' => '@CustomObjects/SubscribedEvents/Tab/content.html.twig', 'vars' => [
                    'customObjectId'    => 555,
                    'currentEntityId'   => 45,
                    'currentEntityType' => 'customItem',
                    'tabId'             => 'custom-object-555',
                    'newRoute'          => 'new/route',
                    'searchId'          => 'list-search-555',
                    'searchValue'       => 'Search something',
                    'searchRoute'       => 'search/route',
                    'linkHeader'        => 'translated modal header',
                    'linkRoute'         => 'link/route',
                    'namespace'         => 'Some namespace',
                ]]], $this->customContentEvent->getTemplates());
    }
}
