<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Translations;

use PHPUnit\Framework\TestCase;

class ItemSearchTranslationsTest extends TestCase
{
    private const KEYS = [
        'custom.item.search.hint',
        'custom.item.search.warning.unknown_alias',
        'custom.item.search.warning.invalid_value',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['en_US' => ['en_US'], 'pt_BR' => ['pt_BR']];
    }

    /**
     * @dataProvider locales
     */
    public function testWarningsKeepTheAliasPlaceholder(string $locale): void
    {
        $lines = file(__DIR__.'/../../../Translations/'.$locale.'/messages.ini', FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $found = [];
        foreach ($lines as $line) {
            foreach (self::KEYS as $key) {
                if (str_starts_with($line, $key.'=')) {
                    $found[$key] = $line;
                }
            }
        }

        $this->assertSame(self::KEYS, array_keys($found), "{$locale} lacks a key");
        $this->assertStringContainsString('%alias%', $found['custom.item.search.warning.unknown_alias']);
        $this->assertStringContainsString('%alias%', $found['custom.item.search.warning.invalid_value']);
    }
}
