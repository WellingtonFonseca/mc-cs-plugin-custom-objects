<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\DependencyInjection\Compiler;

use MauticPlugin\CustomObjectsBundle\DependencyInjection\Compiler\CustomFieldTypePass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use MauticPlugin\CustomObjectsBundle\Tests\Unit\ConsecutiveCallsTrait;

class CustomFieldTypePassTest extends \PHPUnit\Framework\TestCase
{
    use ConsecutiveCallsTrait;

    public function testProcess(): void
    {
        $containerBuilder    = $this->createMock(ContainerBuilder::class);
        $definition          = $this->createMock(Definition::class);
        $customFieldTypePass = new CustomFieldTypePass();

        $containerBuilder->expects($this->once())
            ->method('findTaggedServiceIds')
            ->with('custom.field.type')
            ->willReturn(['int.type' => [], 'text.type' => []]);

        $containerBuilder->expects($this->exactly(3))
            ->method('findDefinition')
            ->willReturnCallback($this->consecutiveCalls([
                ['custom_field.type.provider'],
                ['int.type'],
                ['text.type']
            ], [
                $definition
            ], true));

        $definition->expects($this->exactly(2))
            ->method('addMethodCall')
            ->willReturnCallback($this->consecutiveCalls([
                ['addType'],
                ['addType']
            ], null, false, $definition, 'addMethodCall'));

        $customFieldTypePass->process($containerBuilder);
    }
}
