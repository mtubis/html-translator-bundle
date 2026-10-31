<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle;

use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\DependencyInjection\ProfilerDefinitions;
use HtmlTranslator\Bundle\DependencyInjection\ProviderDefinitions;
use HtmlTranslator\Bundle\DependencyInjection\TranslatorDefinitions;
use SensitiveParameter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Symfony integration of mtubis/html-translator (ARCHITECTURE §5).
 *
 * The configuration tree (specified in docs/config-reference.yaml) is built by DependencyInjection\ConfigurationTree.
 * Keep this class a thin entry point that delegates to DependencyInjection\* builders.
 */
final class HtmlTranslatorBundle extends AbstractBundle
{
    protected string $extensionAlias = 'html_translator';

    public function configure(DefinitionConfigurator $definition): void
    {
        ConfigurationTree::define($definition->rootNode());
    }

    /**
     * @param array<array-key, mixed> $config the processed configuration
     */
    public function loadExtension(#[SensitiveParameter] array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
        ProviderDefinitions::register($config, $builder);
        TranslatorDefinitions::register($config, $builder);
        ProfilerDefinitions::register($config, $builder);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        TranslatorDefinitions::prependCachePool($builder);
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
