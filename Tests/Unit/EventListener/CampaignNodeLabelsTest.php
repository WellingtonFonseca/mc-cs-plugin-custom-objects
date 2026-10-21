<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\EventListener\CampaignSubscriber;
use MauticPlugin\CustomObjectsBundle\Helper\MergedItemCriteriaMatcher;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\ConfigProvider;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\QueryFilterFactory;
use MauticPlugin\CustomObjectsBundle\Helper\QueryFilterHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The node of a Custom Object event in the campaign builder is named by the translation of `mautic.campaign.<type>` (core), and the
 * type holds the id of the object: the plugin adds those translations, one pair per object, to the catalogue in use.
 */
class CampaignNodeLabelsTest extends TestCase
{
    private function subscriber(TranslatorInterface $translator, CustomObjectModel $objects): CampaignSubscriber
    {
        $config = $this->createMock(ConfigProvider::class);
        $config->method('pluginIsEnabled')->willReturn(true);

        return new CampaignSubscriber(
            $this->createMock(CustomFieldModel::class),
            $objects,
            $this->createMock(CustomItemModel::class),
            $translator,
            $config,
            $this->createMock(QueryFilterHelper::class),
            $this->createMock(QueryFilterFactory::class),
            $this->createMock(Connection::class),
            $this->createMock(MergedItemCriteriaMatcher::class)
        );
    }

    private function object(int $id, string $name, int $fields): CustomObject
    {
        $object = $this->createMock(CustomObject::class);
        $object->method('getId')->willReturn($id);
        $object->method('getNameSingular')->willReturn($name);
        $object->method('getCustomFields')->willReturn(new ArrayCollection(array_fill(0, $fields, new CustomField())));

        return $object;
    }

    private function bagTranslator(MessageCatalogue $catalogue): TranslatorInterface
    {
        $translator = $this->createMockForIntersectionOfInterfaces([TranslatorInterface::class, TranslatorBagInterface::class, LocaleAwareInterface::class]);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $p = []) => strtr($id.' '.($p['%customObject%'] ?? ''), []));
        $translator->method('getLocale')->willReturn('pt_BR');
        $translator->method('getCatalogue')->with('pt_BR')->willReturn($catalogue);

        return $translator;
    }

    public function testEachObjectGetsTheTranslationsOfItsTwoEvents(): void
    {
        $catalogue = new MessageCatalogue('pt_BR');
        $objects   = $this->createMock(CustomObjectModel::class);
        $objects->method('fetchAllPublishedEntities')->willReturn([$this->object(3, 'disciplina', 2), $this->object(8, 'curso', 1)]);

        $this->subscriber($this->bagTranslator($catalogue), $objects)->onCampaignBuild($this->createMock(CampaignBuilderEvent::class));

        foreach ([3 => 'disciplina', 8 => 'curso'] as $id => $name) {
            $this->assertTrue($catalogue->has("mautic.campaign.custom_item.{$id}.fieldvalue"));
            $this->assertTrue($catalogue->has("mautic.campaign.custom_item.{$id}.linkcontact"));
            $this->assertStringContainsString($name, $catalogue->get("mautic.campaign.custom_item.{$id}.fieldvalue"));
            $this->assertStringContainsString($name, $catalogue->get("mautic.campaign.custom_item.{$id}.linkcontact"));
        }
    }

    public function testAnObjectWithoutFieldsGetsNoTranslation(): void
    {
        $catalogue = new MessageCatalogue('pt_BR');
        $objects   = $this->createMock(CustomObjectModel::class);
        $objects->method('fetchAllPublishedEntities')->willReturn([$this->object(5, 'vazio', 0)]);

        $this->subscriber($this->bagTranslator($catalogue), $objects)->onCampaignBuild($this->createMock(CampaignBuilderEvent::class));

        $this->assertFalse($catalogue->has('mautic.campaign.custom_item.5.fieldvalue'));
    }

    public function testATranslatorWithoutACatalogueIsLeftAlone(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('x');
        $objects = $this->createMock(CustomObjectModel::class);
        $objects->method('fetchAllPublishedEntities')->willReturn([$this->object(3, 'disciplina', 1)]);

        $event = $this->createMock(CampaignBuilderEvent::class);
        $event->expects($this->once())->method('addAction');
        $event->expects($this->once())->method('addCondition');
        $this->subscriber($translator, $objects)->onCampaignBuild($event);
    }
}
