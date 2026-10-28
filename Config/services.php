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
    ];

    $services->load('MauticPlugin\\CustomObjectsBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->load('MauticPlugin\\CustomObjectsBundle\\Repository\\', '../Repository/*Repository.php');
};
