<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\DependencyInjection;

use HtmlTranslator\Bundle\DataCollector\HtmlTranslatorDataCollector;
use HtmlTranslator\Bundle\DataCollector\TraceableProviderFactory;
use HtmlTranslator\Provider\TranslationProviderInterface;
use SensitiveParameter;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The profiler panel (ADR-0006): a data collector and a traceable decorator per provider service, only in
 * kernel.debug with `profiler.enabled` and WebProfilerBundle. Definitions only; no cost elsewhere.
 *
 * @internal
 */
final class ProfilerDefinitions
{
    public const string COLLECTOR = 'html_translator.data_collector';

    /**
     * @param array<array-key, mixed> $config the processed configuration
     */
    public static function register(#[SensitiveParameter] array $config, ContainerBuilder $builder): void
    {
        if (!self::isEnabled($config, $builder)) {
            return;
        }

        $builder->register(self::COLLECTOR, HtmlTranslatorDataCollector::class)
            ->addTag('data_collector', ['template' => HtmlTranslatorDataCollector::TEMPLATE, 'id' => HtmlTranslatorDataCollector::NAME])
            // Also reset where the profiler (which resets its collectors) is off, e.g. in long-running workers.
            ->addTag('kernel.reset', ['method' => 'reset']);

        $providers = $config['providers'] ?? [];
        foreach (array_keys(\is_array($providers) ? $providers : []) as $name) {
            $id = ProviderDefinitions::PROVIDER_PREFIX . $name;

            // The factory picks the cacheable variant from the actual inner object (an app override or decorator
            // included), so caching is the same as without the profiler.
            $builder->register($id . '.traceable', TranslationProviderInterface::class)
                ->setFactory([TraceableProviderFactory::class, 'decorate'])
                ->setDecoratedService($id)
                ->setArguments([new Reference('.inner'), (string) $name, new Reference(self::COLLECTOR)]);
        }
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private static function isEnabled(#[SensitiveParameter] array $config, ContainerBuilder $builder): bool
    {
        $debug = $builder->hasParameter('kernel.debug') && true === $builder->getParameter('kernel.debug');
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];
        if (!$debug || !\is_array($bundles) || !\in_array(WebProfilerBundle::class, $bundles, true)) {
            return false;
        }

        $profiler = $config['profiler'] ?? [];

        // The default '%kernel.debug%' is not resolved by the configuration tree; resolve it here.
        $enabled = $builder->getParameterBag()->resolveValue(\is_array($profiler) ? $profiler['enabled'] ?? false : false);
        if (!\is_bool($enabled)) {
            // An env placeholder: the panel is wired while the container is compiled, before env values exist.
            throw new InvalidConfigurationException('Invalid configuration for path "html_translator.profiler.enabled": expected true, false or a parameter such as %kernel.debug%; env variables are not supported.');
        }

        return $enabled;
    }
}
