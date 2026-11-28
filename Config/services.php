<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    // Integration/CustomObjectsIntegration.php is registered in Config/config.php (services.integrations) with core's service ids, as every
    // Mautic Integration is, so autowiring does not load it.
    $excludes = [
        'Integration/CustomObjectsIntegration.php',
        'Provider/SessionProvider.php',
        'Report/ReportColumnsBuilder.php',
        'Serializer/ApiNormalizer.php',
        'Extension/CustomItemListeningExtension.php',
        // implements MauticMcpBundle's AnnotatedToolInterface: registered below only when that plugin is installed
        'Mcp',
    ];

    $services->load('MauticPlugin\\CustomObjectsBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->load('MauticPlugin\\CustomObjectsBundle\\Repository\\', '../Repository/*Repository.php');

    // MCP tools: Custom Objects works without the MCP plugin, so they exist only when its AnnotatedToolInterface does.
    if (interface_exists(\MauticPlugin\MauticMcpBundle\Mcp\Tool\AnnotatedToolInterface::class)) {
        foreach (['ListCustomObjectsTool', 'GetCustomObjectDefinitionTool', 'ApplyCustomObjectDefinitionTool', 'SearchCustomItemsTool', 'GetCustomItemTool', 'SaveCustomItemsTool', 'DeleteCustomItemsTool', 'LinkCustomItemTool', 'UnlinkCustomItemTool', 'ContactCustomItemsTool'] as $tool) {
            $services->set('MauticPlugin\\CustomObjectsBundle\\Mcp\\'.$tool)->tag('mautic_mcp.tool');
        }
    }
};
