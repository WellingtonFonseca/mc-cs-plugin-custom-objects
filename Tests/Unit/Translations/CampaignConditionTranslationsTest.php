<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Translations;

use PHPUnit\Framework\TestCase;

/**
 * Everything the campaign builder shows for the Custom Object events (the list of events, and the condition form) has to
 * follow the system language: each text is a translation key with an entry in en_US AND pt_BR. A key only in en_US shows
 * in English in a Portuguese system.
 */
class CampaignConditionTranslationsTest extends TestCase
{
    private const KEYS = [
        'custom.item.events.link.contact',
        'custom.item.events.link.contact_descr',
        'custom.item.events.field.value',
        'custom.item.events.field.value_descr',
        'custom.item.field',
        'custom.item.operator',
        'custom.item.field.value',
        'custom.item.criterion.name',
        'custom.item.criterion.add',
        'custom.item.criterion.remove',
        'custom.item.criterion.same_item_help',
    ];

    /**
     * @return array<string, string>
     */
    private function load(string $locale): array
    {
        $found = [];
        foreach (file(__DIR__.'/../../../Translations/'.$locale.'/messages.ini', FILE_IGNORE_NEW_LINES) as $line) {
            if (1 === preg_match('/^([\w.\-]+)="(.*)"\s*$/', $line, $match)) {
                $found[$match[1]] = $match[2];
            }
        }

        return $found;
    }

    /**
     * @dataProvider locales
     */
    public function testEveryKeyHasAnEntryInTheLocale(string $locale): void
    {
        $messages = $this->load($locale);

        foreach (self::KEYS as $key) {
            $this->assertArrayHasKey($key, $messages, "{$key} is missing in {$locale}");
            $this->assertNotSame('', trim($messages[$key]), "{$key} is empty in {$locale}");
        }
    }

    public function testThePortugueseTextIsNotTheEnglishOne(): void
    {
        $en = $this->load('en_US');
        $pt = $this->load('pt_BR');

        foreach (self::KEYS as $key) {
            $this->assertNotSame($en[$key], $pt[$key], "{$key} was not translated");
        }
    }

    /**
     * @dataProvider locales
     */
    public function testThePlaceholderOfTheObjectNameSurvivesTheTranslation(string $locale): void
    {
        $messages = $this->load($locale);

        foreach (['custom.item.events.link.contact', 'custom.item.events.link.contact_descr', 'custom.item.events.field.value', 'custom.item.events.field.value_descr'] as $key) {
            $this->assertStringContainsString('%customObject%', $messages[$key], "{$key} lost %customObject% in {$locale}");
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['en_US' => ['en_US'], 'pt_BR' => ['pt_BR']];
    }
}
