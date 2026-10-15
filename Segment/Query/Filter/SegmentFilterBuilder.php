<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Segment\Query\Filter;

use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use Mautic\LeadBundle\Segment\ContactSegmentFilterFactory;

/**
 * Turns one segment filter array into Mautic's ContactSegmentFilter. A thin wrapper because Mautic 7 declares
 * ContactSegmentFilterFactory final, which PHPUnit cannot mock: the classes that need it (and their tests) depend on
 * this one instead.
 */
class SegmentFilterBuilder
{
    public function __construct(private ContactSegmentFilterFactory $factory)
    {
    }

    /**
     * @param array<string, mixed> $segmentFilter
     */
    public function factor(array $segmentFilter): ContactSegmentFilter
    {
        return $this->factory->factorSegmentFilter($segmentFilter);
    }
}
