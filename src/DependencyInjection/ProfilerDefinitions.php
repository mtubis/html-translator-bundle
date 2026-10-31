<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\DependencyInjection;

use HtmlTranslator\Bundle\DataCollector\CacheableTraceableTranslationProvider;
use HtmlTranslator\Bundle\DataCollector\HtmlTranslatorDataCollector;
use HtmlTranslator\Bundle\DataCollector\TraceableTranslationProvider;
use HtmlTranslator\Provider\CacheableProviderInterface;
use SensitiveParameter;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
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
            ->addTag('data_collector', ['template' => HtmlTranslatorDataCollector::TEMPLATE, 'id' => HtmlTranslatorDataCollector::NAME]);

        $providers = $config['providers'] ?? [];
        foreach (array_keys(\is_array($providers) ? $providers : []) as $name) {
            $id = ProviderDefinitions::PROVIDER_PREFIX . $name;
            $class = $builder->getDefinition($id)->getClass();

            // Exactly as cacheable as the inner provider: the core skips the cache for providers that are not.
            $traceable = null !== $class && is_subclass_of($class, CacheableProviderInterface::class)
                ? CacheableTraceableTranslationProvider::class
                : TraceableTranslationProvider::class;

            $builder->register($id . '.traceable', $traceable)
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
        return true === $builder->getParameterBag()->resolveValue(\is_array($profiler) ? $profiler['enabled'] ?? false : false);
    }
}
