<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Config;

use MauticPlugin\CustomObjectsBundle\Provider\ConfigProvider;
use PHPUnit\Framework\TestCase;

class ConfigDefaultsTest extends TestCase
{
    /**
     * Segment filters on Custom Object fields only need to match on the same item
     * when the merge filter is on, so the plugin must ship with it enabled.
     */
    public function testMergedFiltersAreEnabledByDefault(): void
    {
        if (!defined('MAUTIC_TABLE_PREFIX')) {
            define('MAUTIC_TABLE_PREFIX', '');
        }

        $parameters = (include __DIR__.'/../../../Config/config.php')['parameters'];

        $this->assertTrue($parameters['custom_object_merge_filter']);
        $this->assertSame(0, $parameters[ConfigProvider::CONFIG_PARAM_ITEM_VALUE_TO_CONTACT_RELATION_LIMIT]);
    }
}
