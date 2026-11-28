<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Mcp;

use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomItemLinkApiController;
use MauticPlugin\CustomObjectsBundle\Controller\Api\CustomObjectApiController;
use MauticPlugin\CustomObjectsBundle\Mcp\DeleteCustomObjectTool;
use MauticPlugin\CustomObjectsBundle\Mcp\ItemContactsTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * delete_object: destructive tool that must default to dryRun, hand the REST rules (409 segments/child) back as errors.
 * item_contacts: read-only paging passthrough with strict argument checks.
 */
#[Group('area:co-objects')]
#[Group('risk:FLUXO')]
class DeleteAndItemContactsToolsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(\MauticPlugin\MauticMcpBundle\Mcp\Tool\AnnotatedToolInterface::class)) {
            $this->markTestSkipped('MauticMcpBundle is not installed');
        }
    }

    public function testDeleteDefaultsToDryRunAndForwardsTheAlias(): void
    {
        $controller = $this->createMock(CustomObjectApiController::class);
        $controller->expects($this->once())->method('deleteAction')
            ->with($this->callback(fn (Request $r) => '1' === $r->query->get('dryRun')), 'cars')
            ->willReturn(new JsonResponse(['alias' => 'cars', 'dryRun' => true, 'deleted' => false, 'itemCount' => 3, 'wouldDelete' => true]));

        $result = (new DeleteCustomObjectTool($controller))->call(['alias' => 'cars']);

        $this->assertFalse($result['isError']);
        $this->assertSame(3, $result['structuredContent']['itemCount']);
        $this->assertFalse($result['structuredContent']['deleted']);
    }

    public function testDeleteWithDryRunFalseSendsNoDryRunFlag(): void
    {
        $controller = $this->createMock(CustomObjectApiController::class);
        $controller->expects($this->once())->method('deleteAction')
            ->with($this->callback(fn (Request $r) => !$r->query->has('dryRun')), 'cars')
            ->willReturn(new JsonResponse(['alias' => 'cars', 'deleted' => true]));

        $result = (new DeleteCustomObjectTool($controller))->call(['alias' => 'cars', 'dryRun' => false]);

        $this->assertFalse($result['isError']);
        $this->assertTrue($result['structuredContent']['deleted']);
    }

    public function testDeleteSurfacesTheSegmentConflictAsError(): void
    {
        $controller = $this->createMock(CustomObjectApiController::class);
        $controller->method('deleteAction')->willReturn(new JsonResponse(['errors' => [['message' => 'Used by segment "VIPs"']]], 409));

        $result = (new DeleteCustomObjectTool($controller))->call(['alias' => 'cars', 'dryRun' => false]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('VIPs', $result['content'][0]['text']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badDeleteArgs(): iterable
    {
        yield 'no alias' => [[]];
        yield 'empty alias' => [['alias' => '']];
        yield 'numeric alias' => [['alias' => 5]];
        yield 'dryRun not bool' => [['alias' => 'cars', 'dryRun' => 'false']];
        yield 'unknown arg' => [['alias' => 'cars', 'force' => true]];
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('badDeleteArgs')]
    public function testDeleteRejectsBadArgumentsWithoutCallingTheController(array $args): void
    {
        $controller = $this->createMock(CustomObjectApiController::class);
        $controller->expects($this->never())->method('deleteAction');

        $this->assertTrue((new DeleteCustomObjectTool($controller))->call($args)['isError']);
    }

    public function testDeleteAnnotationsAreDestructive(): void
    {
        $annotations = (new DeleteCustomObjectTool($this->createMock(CustomObjectApiController::class)))->annotations();

        $this->assertFalse($annotations['readOnlyHint']);
        $this->assertTrue($annotations['destructiveHint']);
        $this->assertSame('custom_objects_delete_object', (new DeleteCustomObjectTool($this->createMock(CustomObjectApiController::class)))->name());
    }

    public function testItemContactsForwardsPagingAndStaysReadOnly(): void
    {
        $controller = $this->createMock(CustomItemLinkApiController::class);
        $controller->expects($this->once())->method('itemContactsAction')
            ->with($this->callback(fn (Request $r) => 5 === $r->query->get('limit') && 10 === $r->query->get('offset')), 42)
            ->willReturn(new JsonResponse(['itemId' => 42, 'total' => 12, 'limit' => 5, 'offset' => 10, 'contacts' => []]));
        $tool = new ItemContactsTool($controller);

        $result = $tool->call(['itemId' => 42, 'limit' => 5, 'offset' => 10]);

        $this->assertFalse($result['isError']);
        $this->assertSame(12, $result['structuredContent']['total']);
        $this->assertTrue($tool->annotations()['readOnlyHint']);
        $this->assertSame('custom_objects_item_contacts', $tool->name());
    }

    public function testItemContactsSurfacesMissingItemAsError(): void
    {
        $controller = $this->createMock(CustomItemLinkApiController::class);
        $controller->method('itemContactsAction')->willReturn(new JsonResponse(['errors' => [['message' => 'Item not found']]], 404));

        $result = (new ItemContactsTool($controller))->call(['itemId' => 999]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('Item not found', $result['content'][0]['text']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badContactArgs(): iterable
    {
        yield 'no item' => [[]];
        yield 'zero item' => [['itemId' => 0]];
        yield 'string item' => [['itemId' => '7']];
        yield 'limit zero' => [['itemId' => 7, 'limit' => 0]];
        yield 'negative offset' => [['itemId' => 7, 'offset' => -1]];
        yield 'limit not int' => [['itemId' => 7, 'limit' => '5']];
        yield 'unknown arg' => [['itemId' => 7, 'contactId' => 1]];
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('badContactArgs')]
    public function testItemContactsRejectsBadArgumentsWithoutCallingTheController(array $args): void
    {
        $controller = $this->createMock(CustomItemLinkApiController::class);
        $controller->expects($this->never())->method('itemContactsAction');

        $this->assertTrue((new ItemContactsTool($controller))->call($args)['isError']);
    }
}
