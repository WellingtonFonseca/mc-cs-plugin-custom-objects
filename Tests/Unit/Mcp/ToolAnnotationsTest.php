<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Mcp;

use MauticPlugin\CustomObjectsBundle\Mcp\ApplyCustomObjectDefinitionTool;
use MauticPlugin\CustomObjectsBundle\Mcp\GetCustomObjectDefinitionTool;
use MauticPlugin\CustomObjectsBundle\Mcp\ListCustomObjectsTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The MCP client decides what to confirm from these hints: a read tool must say it is read-only,
 * and the add-only apply tool must say it writes but never destroys.
 */
#[Group('area:co-objects')]
#[Group('risk:FLUXO')]
class ToolAnnotationsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(\MauticPlugin\MauticMcpBundle\Mcp\Tool\AnnotatedToolInterface::class)) {
            $this->markTestSkipped('MauticMcpBundle is not installed');
        }
    }

    /**
     * @return iterable<string, array{class-string, string, array<string, bool>}>
     */
    public static function toolProvider(): iterable
    {
        yield 'list is read-only' => [ListCustomObjectsTool::class, 'custom_objects_list', ['readOnlyHint' => true]];
        yield 'get definition is read-only' => [GetCustomObjectDefinitionTool::class, 'custom_objects_get_definition', ['readOnlyHint' => true]];
        yield 'apply definition writes, add-only, idempotent' => [
            ApplyCustomObjectDefinitionTool::class,
            'custom_objects_apply_definition',
            ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
        ];
    }

    /**
     * @param class-string         $class
     * @param array<string, bool>  $expected
     */
    #[DataProvider('toolProvider')]
    public function testToolDeclaresItsAnnotations(string $class, string $name, array $expected): void
    {
        $tool = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        $this->assertInstanceOf(\MauticPlugin\MauticMcpBundle\Mcp\Tool\AnnotatedToolInterface::class, $tool);
        $this->assertSame($name, $tool->name());
        $this->assertSame($expected, $tool->annotations());
    }
}
