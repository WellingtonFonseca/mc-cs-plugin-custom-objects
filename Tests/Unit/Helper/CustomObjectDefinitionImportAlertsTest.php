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
        $this->assertSame(['type' => 'warning', 'message' => 'custom.object.import.mismatch []', 'items' => ['b (x)', 'e (y)']], $alerts[1]);
    }

    public function testRequiredFieldsAddedToAnObjectWithItemsAreAWarningWithTheirList(): void
    {
        $alerts = $this->alerts->fromResult(new CustomObjectDefinitionImportResult($this->object, false, ['c', 'd'], [], [], ['d']));

        $this->assertSame(['success', 'warning'], array_column($alerts, 'type'));
        $this->assertSame(
            ['type' => 'warning', 'message' => 'custom.object.import.required_with_items {"%name%":"Cursos"}', 'items' => ['d']],
            $alerts[1]
        );
    }

    public function testTheRequiredWarningComesBeforeTheTypeWarning(): void
    {
        $alerts = $this->alerts->fromResult(new CustomObjectDefinitionImportResult($this->object, false, ['c'], [], ['b (x)'], ['c']));

        $this->assertSame(['success', 'warning', 'warning'], array_column($alerts, 'type'));
        $this->assertStringStartsWith('custom.object.import.required_with_items', $alerts[1]['message']);
        $this->assertStringStartsWith('custom.object.import.mismatch', $alerts[2]['message']);
    }

    public function testThePreviewUsesTheFutureTextsAndListsTheFieldsOfANewObject(): void
    {
        $alerts = $this->alerts->fromResult(new CustomObjectDefinitionImportResult($this->object, true, ['a', 'b'], []), true);

        $this->assertSame([['type' => 'success', 'message' => 'custom.object.import.done.preview {"%name%":"Cursos"}', 'items' => ['a', 'b']]], $alerts);
    }

    public function testThePreviewOfAnExistingObjectAndItsWarnings(): void
    {
        $alerts = $this->alerts->fromResult(new CustomObjectDefinitionImportResult($this->object, false, ['c'], ['b'], ['b (x)'], ['c']), true);

        $this->assertSame(
            ['custom.object.import.added.preview', 'custom.object.import.required_with_items.preview', 'custom.object.import.mismatch.preview'],
            array_map(static fn (array $a): string => explode(' ', $a['message'])[0], $alerts)
        );
        $nothing = $this->alerts->fromResult(new CustomObjectDefinitionImportResult($this->object, false, [], ['a']), true);
        $this->assertStringStartsWith('custom.object.import.nothing.preview', $nothing[0]['message']);
    }

    public function testACategoryThatIsCreatedIsAnInfoAfterTheObject(): void
    {
        $applied = $this->alerts->fromResult(new CustomObjectDefinitionImportResult($this->object, true, ['a'], [], [], [], 'Schools'));
        $preview = $this->alerts->fromResult(new CustomObjectDefinitionImportResult($this->object, true, ['a'], [], [], [], 'Schools'), true);

        $this->assertSame(['type' => 'info', 'message' => 'custom.object.import.category {"%name%":"Schools"}', 'items' => []], $applied[1]);
        $this->assertSame('custom.object.import.category.preview {"%name%":"Schools"}', $preview[1]['message']);
    }
}
