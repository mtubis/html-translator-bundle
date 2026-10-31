<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\DependencyInjection;

use HtmlTranslator\Bundle\Drivers;
use HtmlTranslator\Profile\Formality;
use HtmlTranslator\Profile\TermPolicy;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicEffort;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\JsonMode;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\Bridge\RetryPolicy;
use InvalidArgumentException;
use SensitiveParameter;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * The configuration tree specified by docs/config-reference.yaml.
 *
 * Validates everything that does not depend on env values (ADR-0004): structure, keys per driver, enums, ranges,
 * required keys and references. Values that may come from %env()% are checked at runtime by the chat-model factory.
 * Enum-valued keys take literal values only: an env placeholder cannot be checked against the allowed values.
 *
 * @internal
 */
final class ConfigurationTree
{
    /** Profile presets; the core offers them as TranslationProfile::default(), ::technical(), ::marketing(), ::legal(). */
    public const array PRESETS = ['default', 'technical', 'marketing', 'legal'];

    /** Config values of FallbackStrategy, which is not a backed enum. */
    public const array FALLBACKS = ['keep_original', 'text_nodes_only', 'throw'];

    /** Array keys that default to [] (prototyped arrays always do), so [] counts as "not set" for the driver check. */
    private const array ARRAY_KEYS = ['betas', 'extra_headers'];

    /** The profile that always exists, as processed from `default: { preset: default }`. */
    private const array DEFAULT_PROFILE = ['preset' => 'default', 'glossary' => [], 'do_not_translate' => []];

    private const string LITERAL_ONLY = ' A literal value: env placeholders are not supported here.';

    public static function define(ArrayNodeDefinition $root): void
    {
        $root
            ->validate()
                ->always(self::checkReferences(...))
            ->end()
            ->children()
                ->scalarNode('default_provider')
                    ->info('The provider entry used where none is named: the service HtmlTranslator\HtmlTranslator.' . self::LITERAL_ONLY)
                    ->defaultValue('openai')
                    ->cannotBeEmpty()
                ->end()
            ->end()
            ->append(self::providers())
            ->append(self::http())
            ->children()
                ->scalarNode('default_profile')
                    ->info('The profile entry used where none is named.' . self::LITERAL_ONLY)
                    ->defaultValue('default')
                    ->cannotBeEmpty()
                ->end()
            ->end()
            ->append(self::profiles())
            ->append(self::options())
            ->append(self::html())
            ->append(self::cache())
            ->append(self::logging())
            ->append(self::profiler());
    }

    private static function providers(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('providers');
        $node
            ->info(
                'Named providers. "driver" picks the core adapter: openai, gemini, ollama, vllm, lm_studio (OpenAI-compatible '
                . 'presets), openai_compatible (any other OpenAI-compatible server), anthropic, symfony_ai (planned, ADR-0008), '
                . 'pseudo (no network; dev, test). Each entry becomes the service "html_translator.translator.<name>" and the '
                . 'autowiring alias "HtmlTranslator $<name>Translator" (or #[Target(\'<name>\')]).',
            )
            ->isRequired()
            ->requiresAtLeastOneElement()
            // Not "name": openai_compatible has a "name" key, which would become the entry key.
            ->useAttributeAsKey('provider')
            ->normalizeKeys(false)
            ->arrayPrototype()
                // A later file (e.g. when@test) replaces an entry as a whole, so it can switch the driver.
                ->performNoDeepMerging()
                ->validate()
                    ->always(self::checkProviderEntry(...))
                ->end()
                ->children()
                    ->enumNode('driver')
                        ->info('Picks the core adapter; the other keys depend on it.' . self::LITERAL_ONLY)
                        ->values([...Drivers::ALL, ...array_keys(Drivers::RESERVED)])
                        ->isRequired()
                    ->end()
                    ->scalarNode('api_key')
                        ->info('openai, gemini, anthropic (required); vllm, openai_compatible. Use %env()% or Symfony secrets: a literal ends up in the compiled container.')
                    ->end()
                    ->scalarNode('model')
                        ->info('Required by every driver but pseudo, e.g. \'%env(HTML_TRANSLATOR_OPENAI_MODEL)%\'.')
                    ->end()
                    ->scalarNode('base_url')
                        ->info('API root without the endpoint. ollama, vllm, lm_studio, anthropic (~ = the driver\'s default); openai_compatible (required).')
                    ->end()
                    ->scalarNode('name')
                        ->info('openai_compatible: the name in model ids and error messages; ~ = the entry name.')
                    ->end()
                    ->enumNode('json_mode')
                        ->info('openai (default json_schema), openai_compatible (default json_object).' . self::LITERAL_ONLY)
                        ->values(array_column(JsonMode::cases(), 'value'))
                    ->end()
                    ->enumNode('max_tokens_field')
                        ->info('openai_compatible: the request field of the output limit (default max_tokens).' . self::LITERAL_ONLY)
                        ->values([OpenAiCompatibleConfig::MAX_TOKENS, OpenAiCompatibleConfig::MAX_COMPLETION_TOKENS])
                    ->end()
                    ->arrayNode('extra_headers')
                        ->info('openai_compatible, anthropic: extra request headers (name: value), e.g. a gateway token; treated as secret like the key.')
                        ->useAttributeAsKey('header')
                        ->normalizeKeys(false)
                        ->scalarPrototype()->end()
                    ->end()
                    ->enumNode('effort')
                        ->info('anthropic: output_config.effort; ~ = the model\'s default.' . self::LITERAL_ONLY)
                        ->values([null, ...array_column(AnthropicEffort::cases(), 'value')])
                    ->end()
                    ->booleanNode('refusal_fallbacks')
                        ->info('anthropic: let the API answer a refused request with a fallback model (Claude API only; default false).')
                    ->end()
                    ->arrayNode('betas')
                        ->info('anthropic: further anthropic-beta values, e.g. "some-feature-2026-01-01".')
                        ->scalarPrototype()->end()
                    ->end()
                    ->scalarNode('api_version')
                        ->info('anthropic: the anthropic-version header; ~ = the adapter\'s default.')
                    ->end()
                    ->integerNode('default_max_output_tokens')
                        ->info('anthropic: max_tokens when a request sets no limit; ~ = the adapter\'s default.')
                        ->min(1)
                        ->beforeNormalization()->ifNull()->thenUnset()->end()
                    ->end()
                    ->integerNode('corrupt_every')
                        ->info('pseudo: 0 = never (default); n >= 1: break every n-th unit, to exercise fallbacks.')
                        ->min(0)
                        ->beforeNormalization()->ifNull()->thenUnset()->end()
                    ->end()
                    ->booleanNode('corrupt_retries')
                        ->info('pseudo: true keeps corrupted units broken in corrective retries too (default false).')
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private static function http(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('http');
        $node
            ->info(
                'HTTP: a scoped client of FrameworkBundle\'s http_client, wrapped as PSR-18 (ADR-0003). Retries are the '
                . 'core\'s; do not add retry_failed to this client.',
            )
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('client')
                    ->info('Service id of an HttpClientInterface; ~ = a scoped client the bundle creates.')
                    ->defaultNull()
                ->end()
                ->floatNode('timeout')
                    ->info('Idle timeout in seconds; translation batches can take a while. From env: \'%env(float:HTML_TRANSLATOR_HTTP_TIMEOUT)%\'.')
                    ->defaultValue(180.0)
                    // No min(): it may come from env, so it is checked when the HTTP client is created (ADR-0004);
                    // symfony/config < 7.4.9 also range-checked placeholders (with 0) and rejected every env timeout.
                ->end()
                ->floatNode('max_duration')
                    ->info('Maximum duration of a request in seconds; ~ = no limit.')
                    ->defaultNull()
                    // Numeric nodes reject null; "~" means "not set", which the default covers.
                    ->beforeNormalization()->ifNull()->thenUnset()->end()
                    // No min(), as for timeout: an env value is checked when the HTTP client is created.
                ->end()
                ->arrayNode('retry')
                    ->info('The core RetryPolicy.')
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->always(self::checkRetryDelays(...))
                    ->end()
                    ->children()
                        ->integerNode('max_attempts')->info('Calls in total, the first included; 1 disables retries.')->defaultValue(3)->min(1)->max(RetryPolicy::MAX_ATTEMPTS)->end()
                        ->integerNode('base_delay_ms')->info('Wait before the first retry, doubling per retry (with jitter).')->defaultValue(1000)->min(0)->max(RetryPolicy::MAX_DELAY_MILLISECONDS)->end()
                        ->integerNode('max_delay_ms')->info('Upper bound of a single wait, also for Retry-After.')->defaultValue(30000)->min(0)->max(RetryPolicy::MAX_DELAY_MILLISECONDS)->end()
                        ->booleanNode('retry_network_errors')->info('Retry network errors (no response at all).')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private static function profiles(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('profiles');
        $node
            ->info(
                'Named translation profiles. "preset" is the starting point; the other keys override it. A "default" '
                . 'profile (preset: default) always exists.',
            )
            ->useAttributeAsKey('profile')
            ->normalizeKeys(false)
            ->defaultValue(['default' => self::DEFAULT_PROFILE])
            ->validate()
                ->always(static fn(array $profiles): array => $profiles + ['default' => self::DEFAULT_PROFILE])
            ->end()
            ->arrayPrototype()
                ->children()
                    ->enumNode('preset')
                        ->info(implode(' | ', self::PRESETS) . '.' . self::LITERAL_ONLY)
                        ->values(self::PRESETS)
                        ->defaultValue('default')
                    ->end()
                    ->enumNode('formality')
                        ->info(implode(' | ', array_column(Formality::cases(), 'value')) . '; ~ = the preset\'s.' . self::LITERAL_ONLY)
                        ->values(array_column(Formality::cases(), 'value'))
                    ->end()
                    ->enumNode('term_policy')
                        ->info(implode(' | ', array_column(TermPolicy::cases(), 'value')) . '; ~ = the preset\'s.' . self::LITERAL_ONLY)
                        ->values(array_column(TermPolicy::cases(), 'value'))
                    ->end()
                    ->scalarNode('domain')
                        ->info('e.g. "Developer documentation of a PHP framework"; ~ = the preset\'s.')
                    ->end()
                    ->arrayNode('glossary')
                        ->info('Fixed translations: source term => translation.')
                        ->useAttributeAsKey('term')
                        ->normalizeKeys(false)
                        ->scalarPrototype()->end()
                    ->end()
                    ->arrayNode('do_not_translate')
                        ->info('Terms kept exactly as written, e.g. [Symfony, Doctrine].')
                        ->scalarPrototype()->end()
                    ->end()
                    ->scalarNode('instructions')
                        ->info('Extra instructions for the model; ~ = the preset\'s.')
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private static function options(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('options');
        $node
            ->info('Defaults of TranslationOptions built by HtmlTranslator\Bundle\Options\TranslationOptionsFactory.')
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('source')
                    ->info('Source locale, e.g. "en"; ~ = the model detects the source language.')
                    ->defaultNull()
                ->end()
                ->enumNode('fallback')
                    ->info(implode(' | ', self::FALLBACKS) . '.' . self::LITERAL_ONLY)
                    ->values(self::FALLBACKS)
                    ->defaultValue('keep_original')
                ->end()
                ->integerNode('max_corrective_retries')->info('How often a unit failing validation is sent again with feedback.')->defaultValue(2)->min(0)->end()
                ->integerNode('batch_size')->info('Upper bound of units per provider call.')->defaultValue(20)->min(1)->end()
                ->integerNode('token_budget')->info('Approximate tokens of unit texts per provider call, not counting the instructions.')->defaultValue(2000)->min(1)->end()
            ->end();

        return $node;
    }

    private static function html(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('html');
        $node
            ->info('Additions to the core defaults of what is (not) translated.')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('skip_elements')->info('e.g. [address]')->scalarPrototype()->end()->end()
                ->arrayNode('skip_classes')->info('e.g. [brand]')->scalarPrototype()->end()->end()
                ->arrayNode('translatable_attributes')->info('e.g. [data-tooltip]')->scalarPrototype()->end()->end()
            ->end();

        return $node;
    }

    private static function cache(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('cache');
        $node
            ->info(
                'Unit cache: a PSR-6 pool. The bundle registers "cache.html_translator" (adapter: cache.app) unless "pool" '
                . 'names another pool. Translated units are reused per text, locale pair, profile and model.',
            )
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->info('Reuse translated units across requests.')->defaultTrue()->end()
                ->scalarNode('pool')->info('Service id of a PSR-6 pool.')->defaultValue('cache.html_translator')->cannotBeEmpty()->end()
            ->end();

        return $node;
    }

    private static function logging(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('logging');
        $node
            ->info('Logging: the "html_translator" Monolog channel when MonologBundle is installed, otherwise the "logger" service.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->info('Give the translators a logger.')->defaultTrue()->end()
            ->end();

        return $node;
    }

    private static function profiler(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('profiler');
        $node
            ->info('Profiler panel (kernel.debug only): batches, units, retries, failures and tokens per request (ADR-0006).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->info('Collect data for the profiler panel; needs WebProfilerBundle.')->defaultValue('%kernel.debug%')->end()
            ->end();

        return $node;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private static function checkProviderEntry(#[SensitiveParameter] array $entry): array
    {
        $driver = $entry['driver'] ?? null;
        if (!\is_string($driver)) {
            // Unreachable: "driver" is a required enum node, checked before this closure runs.
            return $entry;
        }

        if (isset(Drivers::RESERVED[$driver])) {
            throw new InvalidArgumentException(\sprintf('the "%s" driver is planned for version %s and not available yet', $driver, Drivers::RESERVED[$driver]));
        }

        $allowed = Drivers::keysOf($driver);

        foreach ($entry as $key => $value) {
            // "~" means "not set", like the [] that betas and extra_headers default to.
            if ('driver' === $key || \in_array($key, $allowed, true) || null === $value || ([] === $value && \in_array($key, self::ARRAY_KEYS, true))) {
                continue;
            }

            throw new InvalidArgumentException(\sprintf('"%s" is not supported by the "%s" driver; supported keys: %s', $key, $driver, implode(', ', $allowed)));
        }

        foreach (Drivers::requiredKeysOf($driver) as $key) {
            if (!isset($entry[$key])) {
                throw new InvalidArgumentException(\sprintf('"%s" is required by the "%s" driver', $key, $driver));
            }
        }

        // The core's PseudoLocalizationProvider rule, so the mistake fails at cache:clear.
        if (true === ($entry['corrupt_retries'] ?? false) && ($entry['corrupt_every'] ?? 0) < 1) {
            throw new InvalidArgumentException('"corrupt_retries" needs "corrupt_every" of at least 1');
        }

        return $entry;
    }

    /**
     * @param array{max_attempts: int, base_delay_ms: int, max_delay_ms: int, retry_network_errors: bool} $retry
     *
     * @return array{max_attempts: int, base_delay_ms: int, max_delay_ms: int, retry_network_errors: bool}
     */
    private static function checkRetryDelays(array $retry): array
    {
        if ($retry['base_delay_ms'] > $retry['max_delay_ms']) {
            throw new InvalidArgumentException(\sprintf('"base_delay_ms" (%d) must not be greater than "max_delay_ms" (%d)', $retry['base_delay_ms'], $retry['max_delay_ms']));
        }

        return $retry;
    }

    /**
     * @param array{default_provider: string, providers: array<string, mixed>, default_profile: string, profiles: array<string, mixed>} $config
     *
     * @return array{default_provider: string, providers: array<string, mixed>, default_profile: string, profiles: array<string, mixed>}
     */
    private static function checkReferences(#[SensitiveParameter] array $config): array
    {
        foreach (['provider' => 'providers', 'profile' => 'profiles'] as $kind => $section) {
            $name = $config['default_' . $kind];
            if (!\array_key_exists($name, $config[$section])) {
                throw new InvalidArgumentException(\sprintf('"default_%s" is "%s", which is not a configured %s; configured: %s', $kind, $name, $kind, implode(', ', array_keys($config[$section]))));
            }
        }

        return $config;
    }
}
