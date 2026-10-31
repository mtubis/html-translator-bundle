<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Symfony integration of mtubis/html-translator (ARCHITECTURE §5).
 *
 * Skeleton: the configuration tree is specified in docs/config-reference.yaml and built in issue "Configuration
 * tree"; definitions per provider entry follow in the issues after it. Keep this class a thin entry point that
 * delegates to DependencyInjection\* builders.
 */
final class HtmlTranslatorBundle extends AbstractBundle
{
    protected string $extensionAlias = 'html_translator';

    public function configure(DefinitionConfigurator $definition): void
    {
        // Intentionally empty until issue "Configuration tree": every key of docs/config-reference.yaml goes here.
        $definition->rootNode();
    }

    /**
     * @param array<array-key, mixed> $config the processed configuration
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
