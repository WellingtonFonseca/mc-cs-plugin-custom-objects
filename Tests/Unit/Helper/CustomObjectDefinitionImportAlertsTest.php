<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Helper;

use MauticPlugin\CustomObjectsBundle\DTO\CustomObjectDefinitionImportResult;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Helper\CustomObjectDefinitionImportAlerts;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class CustomObjectDefinitionImportAlertsTest extends TestCase
{
    private CustomObjectDefinitionImportAlerts $alerts;

    private CustomObject $object;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id.' '.json_encode($params));
        $this->alerts = new CustomObjectDefinitionImportAlerts($translator);
        $this->object = new CustomObject();
        $this->object->setNameSingular('Curso');
        $this->object->setNamePlural('Cursos');
    }

    public function testACreatedObjectIsASuccess(): void
    {
        $alerts = $this->alerts->fromResult(new CustomObjectDefinitionImportResult($this->object, true, ['a', 'b'], []));

        $this->assertSame([['type' => 'success', 'message' => 'custom.object.import.done {"%name%":"Cursos"}', 'items' => []]], $alerts);
    }

    public function testAddedFieldsAreASuccessWithTheirAliasesAsItemsAndTheKeptOnesAreNotMentioned(): void
    {
        $alerts = $this->alerts->fromResult(new CustomObjectDefinitionImportResult($this->object, false, ['c', 'd'], ['a']));

        $this->assertSame([
            ['type' => 'success', 'message' => 'custom.object.import.added {"%name%":"Cursos"}', 'items' => ['c', 'd']],
        ], $alerts);
    }

    public function testNothingToAddIsAnInfo(): void
    {
        $alerts = $this->alerts->fromResult(new CustomObjectDefinitionImportResult($this->object, false, [], ['a']));

        $this->assertCount(1, $alerts);
        $this->assertSame('info', $alerts[0]['type']);
        $this->assertStringStartsWith('custom.object.import.nothing', $alerts[0]['message']);
    }

    public function testADifferentTypeIsAWarning(): void
    {
        $alerts = $this->alerts->fromResult(new CustomObjectDefinitionImportResult($this->object, false, ['c'], ['b'], ['b (x)', 'e (y)']));

        $this->assertSame(['success', 'warning'], array_column($alerts, 'type'));
        $this->assertSame('custom.object.import.mismatch {"%fields%":"b (x); e (y)"}', $alerts[1]['message']);
    }
}
