<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\EventListener;

use Mautic\LeadBundle\Event\LeadListFiltersChoicesEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\CustomObjectsBundle\EventListener\SegmentTextInOperatorSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class SegmentTextInOperatorSubscriberTest extends TestCase
{
    private const TEXT_OPERATORS = ['equals' => '=', 'not equal' => '!=', 'contém' => 'like', 'not like' => '!like', 'começar com' => 'startsWith', 'contains' => 'contains'];

    private function event(array $custom): LeadListFiltersChoicesEvent
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key) => ['mautic.lead.list.form.operator.in' => 'including', 'mautic.lead.list.form.operator.notin' => 'excluding'][$key] ?? $key);

        return new LeadListFiltersChoicesEvent(['custom_object' => $custom], [], $translator);
    }

    private function operators(LeadListFiltersChoicesEvent $event, string $key): array
    {
        return $event->getChoices()['custom_object'][$key]['operators'];
    }

    #[DataProvider('textTypes')]
    public function testATextFieldGetsIncludingAndExcludingAfterNotLike(string $type): void
    {
        $event = $this->event(['cmf_1' => ['properties' => ['type' => $type], 'operators' => self::TEXT_OPERATORS]]);
        (new SegmentTextInOperatorSubscriber())->onGenerateSegmentFilters($event);

        $this->assertSame(
            ['equals' => '=', 'not equal' => '!=', 'contém' => 'like', 'not like' => '!like', 'including' => 'in', 'excluding' => '!in', 'começar com' => 'startsWith', 'contains' => 'contains'],
            $this->operators($event, 'cmf_1')
        );
    }

    public static function textTypes(): array
    {
        return ['text' => ['text'], 'email' => ['email'], 'phone' => ['phone'], 'url' => ['url'], 'textarea' => ['textarea']];
    }

    public function testFieldsThatAreNotTextAreLeftAlone(): void
    {
        $int    = ['=' => '=', 'gt' => 'gt'];
        $select = ['equals' => '=', 'not equal' => '!='];
        $event  = $this->event([
            'cmf_2' => ['properties' => ['type' => 'int'], 'operators' => $int],
            'cmf_3' => ['properties' => ['type' => 'select', 'list' => ['a' => 'A']], 'operators' => $select],
        ]);
        (new SegmentTextInOperatorSubscriber())->onGenerateSegmentFilters($event);

        $this->assertSame($int, $this->operators($event, 'cmf_2'));
        $this->assertSame($select, $this->operators($event, 'cmf_3'));
    }

    public function testTheItemNameFilterGetsItToo(): void
    {
        $event = $this->event(['cmo_5' => ['properties' => ['type' => 'text'], 'operators' => self::TEXT_OPERATORS]]);
        (new SegmentTextInOperatorSubscriber())->onGenerateSegmentFilters($event);

        $this->assertSame('in', $this->operators($event, 'cmo_5')['including']);
    }

    public function testAFieldThatAlreadyHasInIsNotDuplicated(): void
    {
        $ops   = ['equals' => '=', 'including' => 'in', 'excluding' => '!in'];
        $event = $this->event(['cmf_1' => ['properties' => ['type' => 'text'], 'operators' => $ops]]);
        (new SegmentTextInOperatorSubscriber())->onGenerateSegmentFilters($event);

        $this->assertSame($ops, $this->operators($event, 'cmf_1'));
    }

    public function testOnlyTheSegmentFormIsChanged(): void
    {
        $this->assertSame([LeadEvents::LIST_FILTERS_CHOICES_ON_GENERATE => ['onGenerateSegmentFilters', -100]], SegmentTextInOperatorSubscriber::getSubscribedEvents());
    }
}
