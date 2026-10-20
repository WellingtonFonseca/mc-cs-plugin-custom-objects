<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\EventListener;

use Mautic\LeadBundle\Event\LeadListFiltersChoicesEvent;
use Mautic\LeadBundle\LeadEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Offers "including" / "excluding" (in / !in) on the text fields of a Custom Object in the segment filters, so several
 * values fit in ONE criterion (A|B|C) instead of one criterion per value joined by OR. Core offers them only on lists.
 * Runs after SegmentFiltersChoicesGenerateSubscriber, which builds the cmf_ / cmo_ choices.
 */
class SegmentTextInOperatorSubscriber implements EventSubscriberInterface
{
    public const TEXT_TYPES = ['text', 'email', 'phone', 'url', 'textarea'];

    /**
     * @return mixed[]
     */
    public static function getSubscribedEvents(): array
    {
        return [LeadEvents::LIST_FILTERS_CHOICES_ON_GENERATE => ['onGenerateSegmentFilters', -100]];
    }

    public function onGenerateSegmentFilters(LeadListFiltersChoicesEvent $event): void
    {
        foreach ($event->getChoices()['custom_object'] ?? [] as $key => $config) {
            $operators = $config['operators'] ?? [];

            if (!in_array($config['properties']['type'] ?? null, self::TEXT_TYPES, true) || in_array('in', $operators, true)) {
                continue;
            }

            $translator = $event->getTranslator();
            $withIn     = [];
            foreach ($operators as $label => $operator) {
                $withIn[$label] = $operator;
                if ('!like' === $operator) {
                    $withIn[$translator->trans('mautic.lead.list.form.operator.in')]    = 'in';
                    $withIn[$translator->trans('mautic.lead.list.form.operator.notin')] = '!in';
                }
            }

            $config['operators'] = $withIn;
            $event->setChoice('custom_object', (string) $key, $config);
        }
    }
}
