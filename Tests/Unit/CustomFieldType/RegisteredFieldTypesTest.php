<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\CustomFieldType;

/**
 * Guards what a new Custom Field type needs besides its own class: adding
 * one without these breaks the UI, not the code. The "decimal" type first
 * shipped without its Form/Panel template, and the Custom Object form
 * (Save/Close of the modal) threw a Twig LoaderError as soon as a field of
 * that type was on it.
 */
class RegisteredFieldTypesTest extends \PHPUnit\Framework\TestCase
{
    private const BUNDLE_DIR = __DIR__.'/../../..';

    /**
     * @return array<string, array{0: string}>
     */
    public static function registeredTypeKeys(): array
    {
        $config = include self::BUNDLE_DIR.'/Config/config.php';
        $keys   = [];

        $definitions = array_merge(...array_values($config['services']));

        foreach ($definitions as $definition) {
            if (($definition['tag'] ?? null) !== 'custom.field.type') {
                continue;
            }

            $reflection = new \ReflectionClass($definition['class']);
            $key        = $reflection->getProperty('key')->getDefaultValue();
            $keys[$key] = [$key];
        }

        return $keys;
    }

    /**
     * @dataProvider registeredTypeKeys
     */
    public function testTypeHasAFormPanelTemplate(string $key): void
    {
        $this->assertFileExists(
            self::BUNDLE_DIR.'/Resources/views/CustomObject/Form/Panel/'.$key.'.html.twig',
            "Custom Field type '{$key}' has no Form/Panel template."
        );
    }

    /**
     * @dataProvider registeredTypeKeys
     */
    public function testTypeHasATranslatedLabel(string $key): void
    {
        $messages = parse_ini_file(self::BUNDLE_DIR.'/Translations/en_US/messages.ini');

        $this->assertArrayHasKey('custom.field.type.'.$key, $messages, "Custom Field type '{$key}' has no label.");
    }
}
