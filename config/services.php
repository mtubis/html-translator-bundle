<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Static services of the bundle: registries, factories, commands. Definitions that depend on the configuration
 * (one per provider entry) are built in DependencyInjection\* and registered by HtmlTranslatorBundle::loadExtension().
 * Services are private by default; public surface is listed in docs/ARCHITECTURE.md §4.
 */
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
            ->private()
            ->autowire()
            ->autoconfigure();
};
