<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\DependencyInjection;

use HtmlTranslator\Bundle\Html\HtmlRulesFactory;
use HtmlTranslator\Bundle\Options\TranslationOptionsFactory;
use HtmlTranslator\Bundle\Profile\ProfileRegistry;
use HtmlTranslator\Bundle\TranslatorRegistry;
use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;
use HtmlTranslator\HtmlTranslator;
use SensitiveParameter;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The public API (ARCHITECTURE §4): a translator per provider entry with its autowiring aliases, the registries, the
 * options factory, and the cache, logger and HTML rules the translators share.
 *
 * Builds definitions only (ADR-0004); profiles and HTML rules are built by runtime factories.
 *
 * @internal
 */
final class TranslatorDefinitions
{
    public const string TRANSLATOR_PREFIX = 'html_translator.translator.';

    public const string CACHE_POOL = 'cache.html_translator';

    public const string LOG_CHANNEL = 'html_translator';

    private const string SKIP_RULES = 'html_translator.skip_rules';

    private const string TRANSLATABLE_ATTRIBUTES = 'html_translator.translatable_attributes';

    /**
     * @param array<array-key, mixed> $config the processed configuration
     */
    public static function register(#[SensitiveParameter] array $config, ContainerBuilder $builder): void
    {
        $html = self::section($config, 'html');
        $builder->register(self::SKIP_RULES, SkipRules::class)
            ->setFactory([HtmlRulesFactory::class, 'skipRules'])
            ->setArguments([$html]);
        $builder->register(self::TRANSLATABLE_ATTRIBUTES, TranslatableAttributes::class)
            ->setFactory([HtmlRulesFactory::class, 'translatableAttributes'])
            ->setArguments([$html]);

        $cache = self::cache(self::section($config, 'cache'));
        $logger = true === (self::section($config, 'logging')['enabled'] ?? true)
            ? new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)
            : null;
        $monolog = $logger instanceof Reference && self::hasMonolog($builder);

        $translators = [];
        foreach (array_keys(self::section($config, 'providers')) as $name) {
            $name = (string) $name;
            $id = self::TRANSLATOR_PREFIX . $name;
            $definition = $builder->register($id, HtmlTranslator::class)
                ->setPublic(true)
                ->setArguments([
                    '$provider' => new Reference(ProviderDefinitions::PROVIDER_PREFIX . $name),
                    '$cache' => $cache,
                    '$logger' => $logger,
                    '$skipRules' => new Reference(self::SKIP_RULES),
                    '$translatableAttributes' => new Reference(self::TRANSLATABLE_ATTRIBUTES),
                ]);

            if ($monolog) {
                $definition->addTag('monolog.logger', ['channel' => self::LOG_CHANNEL]);
            }

            // HtmlTranslator $<name>Translator, and #[Target('<name>')] through the ".HtmlTranslator $<name>" alias.
            $builder->registerAliasForArgument($id, HtmlTranslator::class, $name . 'Translator', $name);
            $translators[$name] = new Reference($id);
        }

        // The configuration tree requires default_provider and checks that it names an entry.
        $defaultProvider = self::string($config, 'default_provider', 'default');
        $builder->setAlias(HtmlTranslator::class, self::TRANSLATOR_PREFIX . $defaultProvider)->setPublic(true);

        $builder->register(TranslatorRegistry::class, TranslatorRegistry::class)
            ->setPublic(true)
            ->setArguments([new ServiceLocatorArgument($translators), $defaultProvider]);

        $builder->register(ProfileRegistry::class, ProfileRegistry::class)
            ->setPublic(true)
            ->setArguments([self::section($config, 'profiles'), self::string($config, 'default_profile', 'default')]);

        $builder->register(TranslationOptionsFactory::class, TranslationOptionsFactory::class)
            ->setPublic(true)
            ->setArguments([new Reference(ProfileRegistry::class), self::section($config, 'options')]);
    }

    /**
     * Prepends the default pool to FrameworkBundle's configuration, so the bundle works without cache config.
     */
    public static function prependCachePool(ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('framework')) {
            $builder->prependExtensionConfig('framework', ['cache' => ['pools' => [self::CACHE_POOL => ['adapter' => 'cache.app']]]]);
        }
    }

    /**
     * @param array<array-key, mixed> $cache
     */
    private static function cache(array $cache): ?Reference
    {
        if (false === ($cache['enabled'] ?? true)) {
            return null;
        }

        $pool = \is_string($cache['pool'] ?? null) ? $cache['pool'] : self::CACHE_POOL;

        // The default pool exists only with FrameworkBundle: without it, translate uncached. A named pool must exist.
        return self::CACHE_POOL === $pool
            ? new Reference($pool, ContainerInterface::NULL_ON_INVALID_REFERENCE)
            : new Reference($pool);
    }

    private static function hasMonolog(ContainerBuilder $builder): bool
    {
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];

        return \is_array($bundles) && \in_array(MonologBundle::class, $bundles, true);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private static function string(#[SensitiveParameter] array $config, string $key, string $default): string
    {
        return \is_string($config[$key] ?? null) ? $config[$key] : $default;
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function section(#[SensitiveParameter] array $config, string $key): array
    {
        $section = $config[$key] ?? [];

        return \is_array($section) ? $section : [];
    }
}
