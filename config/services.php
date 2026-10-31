<?php

declare(strict_types=1);

use HtmlTranslator\Bundle\Command\CheckCommand;
use HtmlTranslator\Bundle\Command\TranslateCommand;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Static services of the bundle: registries, factories, commands. Definitions that depend on the configuration
 * (one per provider entry) are built in DependencyInjection\* and registered by HtmlTranslatorBundle::loadExtension().
 * Services are private by default; public surface is listed in docs/ARCHITECTURE.md §4.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->private()
            ->autowire()
            ->autoconfigure();

    // Console commands (#[AsCommand] makes them lazy); thin layers over the public services.
    $services->set(TranslateCommand::class);
    $services->set(CheckCommand::class);
};
