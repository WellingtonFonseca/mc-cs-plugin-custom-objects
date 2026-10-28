<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Mautic\CoreBundle\Helper\ExportHelper;
use Mautic\CoreBundle\ProcessSignal\ProcessSignalService;
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
        'DataPersister/CustomItemDataPersister.php',
    ];

    $services->load('MauticPlugin\\CustomObjectsBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    // Mautic 7 registers its helpers under their class name only; config.php still asks for 'mautic.helper.export'.
    // (An argument with a backslash is taken as a literal by Mautic's ServicePass, so it cannot be named there.)
    if (class_exists(ProcessSignalService::class)) {
        $services->alias('mautic.helper.export', ExportHelper::class);
    }

    $services->load('MauticPlugin\\CustomObjectsBundle\\Repository\\', '../Repository/*Repository.php');
};
