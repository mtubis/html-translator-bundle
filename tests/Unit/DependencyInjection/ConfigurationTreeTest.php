<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit\DependencyInjection;

use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\Drivers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(ConfigurationTree::class)]
#[CoversClass(Drivers::class)]
final class ConfigurationTreeTest extends TestCase
{
    private const array PSEUDO_ONLY = [
        'default_provider' => 'pseudo',
        'providers' => ['pseudo' => ['driver' => 'pseudo']],
    ];

    #[Test]
    public function it_processes_the_reference_configuration(): void
    {
        $reference = Yaml::parseFile(\dirname(__DIR__, 3) . '/docs/config-reference.yaml');
        self::assertIsArray($reference);
        $config = $this->arrayAt($reference, 'html_translator');
        // The kernel resolves %kernel.debug% before processing; %env()% values stay strings, which string nodes accept.
        self::assertSame('%kernel.debug%', self::at($config, 'profiler.enabled'));
        $config['profiler'] = ['enabled' => true];

        $processed = $this->process($config);

        self::assertSame('openai', self::at($processed, 'default_provider'));
        self::assertSame(['openai', 'anthropic', 'gemini', 'ollama', 'pseudo'], array_keys($this->arrayAt($processed, 'providers')));
        self::assertTrue(self::at($processed, 'profiler.enabled'));
    }

    /**
     * The entries the reference shows commented out.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function commentedReferenceExamples(): iterable
    {
        yield 'vllm' => [['providers' => ['vllm' => ['driver' => 'vllm', 'model' => 'meta-llama/Llama-3.1-8B-Instruct', 'base_url' => 'http://localhost:8000/v1', 'api_key' => null]]]];
        yield 'lm_studio' => [['providers' => ['lm_studio' => ['driver' => 'lm_studio', 'model' => 'qwen2.5-7b-instruct', 'base_url' => 'http://localhost:1234/v1']]]];
        yield 'older ollama' => [['providers' => ['ollama' => ['driver' => 'ollama', 'model' => '%env(HTML_TRANSLATOR_OLLAMA_MODEL)%', 'json_mode' => 'json_object']]]];
        yield 'together' => [['providers' => ['together' => [
            'driver' => 'openai_compatible',
            'name' => 'together',
            'base_url' => 'https://api.together.xyz/v1',
            'api_key' => '%env(TOGETHER_API_KEY)%',
            'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
            'json_mode' => 'json_object',
            'max_tokens_field' => 'max_tokens',
            'extra_headers' => [],
        ]]]];
        yield 'docs profile' => [['profiles' => ['docs' => [
            'preset' => 'technical',
            'formality' => 'informal',
            'term_policy' => 'prefer_original_technical_terms',
            'domain' => 'Developer documentation of a PHP framework',
            'glossary' => ['pull request' => 'pull request'],
            'do_not_translate' => ['Symfony', 'Doctrine'],
            'instructions' => null,
        ]]]];
    }

    /**
     * @param array<string, mixed> $example
     */
    #[Test]
    #[DataProvider('commentedReferenceExamples')]
    public function it_processes_the_commented_reference_examples(array $example): void
    {
        $processed = $this->process(array_replace_recursive(self::PSEUDO_ONLY, $example));

        foreach (array_keys($example) as $section) {
            foreach (array_keys($this->arrayAt($example, $section)) as $entry) {
                self::assertArrayHasKey($entry, $this->arrayAt($processed, $section));
            }
        }
    }

    #[Test]
    public function it_applies_the_reference_defaults(): void
    {
        $processed = $this->process(self::PSEUDO_ONLY);

        self::assertSame(['client' => null, 'timeout' => 180.0, 'max_duration' => null, 'retry' => [
            'max_attempts' => 3,
            'base_delay_ms' => 1000,
            'max_delay_ms' => 30000,
            'retry_network_errors' => true,
        ]], self::at($processed, 'http'));
        self::assertSame('default', self::at($processed, 'default_profile'));
        self::assertSame(['default' => ['preset' => 'default', 'glossary' => [], 'do_not_translate' => []]], self::at($processed, 'profiles'));
        self::assertSame(['source' => null, 'fallback' => 'keep_original', 'max_corrective_retries' => 2, 'batch_size' => 20, 'token_budget' => 2000], self::at($processed, 'options'));
        self::assertSame(['skip_elements' => [], 'skip_classes' => [], 'translatable_attributes' => []], self::at($processed, 'html'));
        self::assertSame(['enabled' => true, 'pool' => 'cache.html_translator'], self::at($processed, 'cache'));
        self::assertSame(['enabled' => true], self::at($processed, 'logging'));
        self::assertSame(['enabled' => '%kernel.debug%'], self::at($processed, 'profiler'));
    }

    #[Test]
    public function it_keeps_unset_driver_keys_absent(): void
    {
        $processed = $this->process(['providers' => ['openai' => ['driver' => 'openai', 'api_key' => 'k', 'model' => 'm']]]);

        // betas and extra_headers are prototyped arrays and always default to [].
        self::assertSame(['driver' => 'openai', 'api_key' => 'k', 'model' => 'm', 'extra_headers' => [], 'betas' => []], self::at($processed, 'providers.openai'));
    }

    #[Test]
    public function it_adds_the_default_profile_next_to_custom_ones(): void
    {
        $processed = $this->process(self::PSEUDO_ONLY + ['profiles' => ['docs' => ['preset' => 'technical']]]);

        self::assertSame(['docs', 'default'], array_keys($this->arrayAt($processed, 'profiles')));
    }

    #[Test]
    public function it_keeps_a_customised_default_profile(): void
    {
        $processed = $this->process(self::PSEUDO_ONLY + ['profiles' => ['default' => ['preset' => 'legal']]]);

        self::assertSame('legal', self::at($processed, 'profiles.default.preset'));
    }

    #[Test]
    public function it_merges_a_default_profile_from_a_later_file_over_an_earlier_one(): void
    {
        $processed = $this->processAll([
            self::PSEUDO_ONLY + ['profiles' => ['docs' => ['preset' => 'technical']]],
            ['profiles' => ['default' => ['preset' => 'marketing']]],
        ]);

        self::assertSame('marketing', self::at($processed, 'profiles.default.preset'));
    }

    #[Test]
    public function it_keeps_the_name_key_of_an_openai_compatible_entry(): void
    {
        $processed = $this->process(['default_provider' => 'together', 'providers' => ['together' => [
            'driver' => 'openai_compatible',
            'name' => 'Together AI',
            'base_url' => 'https://api.together.xyz/v1',
            'model' => 'm',
            'extra_headers' => ['X-Gateway-Token' => 't'],
        ]]]);

        self::assertSame('Together AI', self::at($processed, 'providers.together.name'));
        self::assertSame(['X-Gateway-Token' => 't'], self::at($processed, 'providers.together.extra_headers'));
    }

    #[Test]
    public function it_keeps_provider_and_profile_names_as_written(): void
    {
        $processed = $this->process([
            'default_provider' => 'my-pseudo',
            'providers' => ['my-pseudo' => ['driver' => 'pseudo']],
            'profiles' => ['legal-de' => ['preset' => 'legal', 'glossary' => ['pull-request' => 'Pull-Request']]],
        ]);

        self::assertSame(['my-pseudo'], array_keys($this->arrayAt($processed, 'providers')));
        self::assertSame(['pull-request' => 'Pull-Request'], self::at($processed, 'profiles.legal-de.glossary'));
    }

    #[Test]
    public function it_reads_a_null_number_as_not_set(): void
    {
        $processed = $this->process([
            'default_provider' => 'a',
            'providers' => [
                'a' => ['driver' => 'anthropic', 'api_key' => 'k', 'model' => 'm', 'default_max_output_tokens' => null],
                'p' => ['driver' => 'pseudo', 'corrupt_every' => null],
            ],
            'http' => ['max_duration' => null],
        ]);

        self::assertArrayNotHasKey('default_max_output_tokens', $this->arrayAt($processed, 'providers.a'));
        self::assertArrayNotHasKey('corrupt_every', $this->arrayAt($processed, 'providers.p'));
        self::assertNull(self::at($processed, 'http.max_duration'));
    }

    #[Test]
    public function it_accepts_the_lower_bounds(): void
    {
        $processed = $this->process([
            'default_provider' => 'a',
            'providers' => ['a' => ['driver' => 'anthropic', 'api_key' => 'k', 'model' => 'm', 'default_max_output_tokens' => 1]],
            'http' => ['retry' => ['max_attempts' => 1, 'base_delay_ms' => 0, 'max_delay_ms' => 0]],
            'options' => ['max_corrective_retries' => 0, 'batch_size' => 1, 'token_budget' => 1],
        ]);

        self::assertSame(1, self::at($processed, 'providers.a.default_max_output_tokens'));
        self::assertSame([1, 0, 0], [self::at($processed, 'http.retry.max_attempts'), self::at($processed, 'http.retry.base_delay_ms'), self::at($processed, 'http.retry.max_delay_ms')]);
        self::assertSame([0, 1, 1], [self::at($processed, 'options.max_corrective_retries'), self::at($processed, 'options.batch_size'), self::at($processed, 'options.token_budget')]);
    }

    #[Test]
    public function it_replaces_a_provider_entry_from_a_later_file(): void
    {
        $processed = $this->processAll([
            ['providers' => ['openai' => ['driver' => 'openai', 'api_key' => 'k', 'model' => 'm']]],
            ['providers' => ['openai' => ['driver' => 'pseudo']]],
        ]);

        self::assertSame(['driver' => 'pseudo', 'extra_headers' => [], 'betas' => []], self::at($processed, 'providers.openai'));
    }

    #[Test]
    public function it_reads_null_as_not_set_on_keys_of_other_drivers(): void
    {
        $processed = $this->process(['default_provider' => 'g', 'providers' => ['g' => ['driver' => 'gemini', 'api_key' => 'k', 'model' => 'm', 'base_url' => null]]]);

        self::assertNull(self::at($processed, 'providers.g.base_url'));
    }

    #[Test]
    public function it_names_rejected_secrets_by_key_only(): void
    {
        foreach ([['api_key' => 'sk-literal-123'], ['extra_headers' => ['X-Token' => 'hdr-secret-456']]] as $secret) {
            try {
                $this->process(['default_provider' => 'o', 'providers' => ['o' => ['driver' => 'ollama', 'model' => 'm'] + $secret]]);
                self::fail('The entry should be rejected.');
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString(\sprintf('"%s" is not supported by the "ollama" driver', array_key_first($secret)), $exception->getMessage());
                self::assertStringNotContainsString('sk-literal-123', $exception->getMessage());
                self::assertStringNotContainsString('hdr-secret-456', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function it_keeps_entries_out_of_the_stack_trace_of_its_checks(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            $this->process(['default_provider' => 'o', 'providers' => ['o' => ['driver' => 'ollama', 'model' => 'm', 'api_key' => 'sk-literal-123']]]);
            self::fail('The entry should be rejected.');
        } catch (InvalidConfigurationException $exception) {
            $frames = array_values(array_filter(
                $exception->getPrevious()?->getTrace() ?? [],
                static fn(array $frame): bool => ConfigurationTree::class === ($frame['class'] ?? null),
            ));

            self::assertNotSame([], $frames);
            foreach ($frames as $frame) {
                self::assertContainsOnlyInstancesOf(SensitiveParameterValue::class, $frame['args'] ?? []);
            }
        } finally {
            ini_set('zend.exception_ignore_args', false === $ignoreArgs ? '1' : $ignoreArgs);
        }
    }

    #[Test]
    public function it_accepts_a_float_timeout(): void
    {
        self::assertSame(2.5, self::at($this->process(self::PSEUDO_ONLY + ['http' => ['timeout' => 2.5]]), 'http.timeout'));
    }

    #[Test]
    public function it_accepts_corrupt_retries_with_a_corruption_interval(): void
    {
        $processed = $this->process(['default_provider' => 'p', 'providers' => ['p' => ['driver' => 'pseudo', 'corrupt_every' => 2, 'corrupt_retries' => true]]]);

        self::assertSame(['driver' => 'pseudo', 'corrupt_every' => 2, 'corrupt_retries' => true, 'extra_headers' => [], 'betas' => []], self::at($processed, 'providers.p'));
    }

    #[Test]
    public function it_accepts_equal_retry_delays(): void
    {
        $processed = $this->process(self::PSEUDO_ONLY + ['http' => ['retry' => ['base_delay_ms' => 500, 'max_delay_ms' => 500]]]);

        self::assertSame([500, 500], [self::at($processed, 'http.retry.base_delay_ms'), self::at($processed, 'http.retry.max_delay_ms')]);
    }

    #[Test]
    public function it_accepts_the_retry_policy_limits(): void
    {
        $processed = $this->process(self::PSEUDO_ONLY + ['http' => ['retry' => ['max_attempts' => 10, 'base_delay_ms' => 0, 'max_delay_ms' => 3_600_000]]]);

        self::assertSame([10, 0, 3_600_000], [self::at($processed, 'http.retry.max_attempts'), self::at($processed, 'http.retry.base_delay_ms'), self::at($processed, 'http.retry.max_delay_ms')]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidProviders(): iterable
    {
        yield 'unknown driver' => [['driver' => 'openia'], 'The value "openia" is not allowed for path "html_translator.providers.p.driver"'];
        yield 'missing driver' => [['model' => 'm'], 'The child config "driver" under "html_translator.providers.p" must be configured'];
        yield 'reserved symfony_ai' => [['driver' => 'symfony_ai'], 'Invalid configuration for path "html_translator.providers.p": the "symfony_ai" driver is planned for version 0.3 and not available yet'];
        yield 'unknown key with suggestion' => [['driver' => 'openai', 'api_key' => 'k', 'modle' => 'm'], 'Unrecognized option "modle" under "html_translator.providers.p". Did you mean "model"?'];
        yield 'betas on openai' => [['driver' => 'openai', 'api_key' => 'k', 'model' => 'm', 'betas' => ['x']], 'Invalid configuration for path "html_translator.providers.p": "betas" is not supported by the "openai" driver; supported keys: api_key, model, json_mode'];
        yield 'base_url on gemini' => [['driver' => 'gemini', 'api_key' => 'k', 'model' => 'm', 'base_url' => 'http://x'], 'Invalid configuration for path "html_translator.providers.p": "base_url" is not supported by the "gemini" driver; supported keys: api_key, model'];
        yield 'extra_headers on ollama' => [['driver' => 'ollama', 'model' => 'm', 'extra_headers' => ['X-A' => 'b']], '"extra_headers" is not supported by the "ollama" driver'];
        yield 'pseudo keys on openai' => [['driver' => 'openai', 'api_key' => 'k', 'model' => 'm', 'corrupt_every' => 2], '"corrupt_every" is not supported by the "openai" driver'];
        yield 'refusal_fallbacks false on openai' => [['driver' => 'openai', 'api_key' => 'k', 'model' => 'm', 'refusal_fallbacks' => false], '"refusal_fallbacks" is not supported by the "openai" driver'];
        yield 'model on pseudo' => [['driver' => 'pseudo', 'model' => 'm'], 'Invalid configuration for path "html_translator.providers.p": "model" is not supported by the "pseudo" driver; supported keys: corrupt_every, corrupt_retries'];
        yield 'bad json_mode' => [['driver' => 'openai', 'api_key' => 'k', 'model' => 'm', 'json_mode' => 'schema'], 'The value "schema" is not allowed for path "html_translator.providers.p.json_mode"'];
        yield 'bad json_mode on ollama' => [['driver' => 'ollama', 'model' => 'm', 'json_mode' => 'json'], 'The value "json" is not allowed for path "html_translator.providers.p.json_mode"'];
        yield 'json_mode on anthropic' => [['driver' => 'anthropic', 'api_key' => 'k', 'model' => 'm', 'json_mode' => 'json_object'], '"json_mode" is not supported by the "anthropic" driver'];
        yield 'bad effort' => [['driver' => 'anthropic', 'api_key' => 'k', 'model' => 'm', 'effort' => 'extreme'], 'The value "extreme" is not allowed for path "html_translator.providers.p.effort"'];
        yield 'bad max_tokens_field' => [['driver' => 'openai_compatible', 'base_url' => 'http://x', 'model' => 'm', 'max_tokens_field' => 'max_output_tokens'], 'The value "max_output_tokens" is not allowed for path "html_translator.providers.p.max_tokens_field"'];
        yield 'betas not a list of strings' => [['driver' => 'anthropic', 'api_key' => 'k', 'model' => 'm', 'betas' => [['x']]], 'html_translator.providers.p.betas'];
        yield 'default_max_output_tokens 0' => [['driver' => 'anthropic', 'api_key' => 'k', 'model' => 'm', 'default_max_output_tokens' => 0], 'The value 0 is too small for path "html_translator.providers.p.default_max_output_tokens"'];
        yield 'refusal_fallbacks not a bool' => [['driver' => 'anthropic', 'api_key' => 'k', 'model' => 'm', 'refusal_fallbacks' => 'yes'], 'html_translator.providers.p.refusal_fallbacks'];
        yield 'negative corrupt_every' => [['driver' => 'pseudo', 'corrupt_every' => -1], 'The value -1 is too small for path "html_translator.providers.p.corrupt_every"'];
        yield 'corrupt_retries without interval' => [['driver' => 'pseudo', 'corrupt_retries' => true], 'Invalid configuration for path "html_translator.providers.p": "corrupt_retries" needs "corrupt_every" of at least 1'];
        yield 'corrupt_retries with interval 0' => [['driver' => 'pseudo', 'corrupt_every' => 0, 'corrupt_retries' => true], '"corrupt_retries" needs "corrupt_every" of at least 1'];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('invalidProviders')]
    public function it_rejects_an_invalid_provider_entry(array $entry, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process(['default_provider' => 'p', 'providers' => ['p' => $entry]]);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function entriesWithoutARequiredKey(): iterable
    {
        yield 'openai api_key' => ['openai', ['model' => 'm'], 'api_key'];
        yield 'openai model' => ['openai', ['api_key' => 'k'], 'model'];
        yield 'gemini api_key' => ['gemini', ['model' => 'm'], 'api_key'];
        yield 'gemini model' => ['gemini', ['api_key' => 'k'], 'model'];
        yield 'anthropic api_key' => ['anthropic', ['model' => 'm'], 'api_key'];
        yield 'anthropic model' => ['anthropic', ['api_key' => 'k'], 'model'];
        yield 'ollama model' => ['ollama', [], 'model'];
        yield 'vllm model' => ['vllm', [], 'model'];
        yield 'lm_studio model' => ['lm_studio', [], 'model'];
        yield 'openai_compatible base_url' => ['openai_compatible', ['model' => 'm'], 'base_url'];
        yield 'openai_compatible model' => ['openai_compatible', ['base_url' => 'http://x'], 'model'];
    }

    /**
     * @param array<string, string> $keys
     */
    #[Test]
    #[DataProvider('entriesWithoutARequiredKey')]
    public function it_rejects_an_entry_without_a_required_key(string $driver, array $keys, string $missing): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(\sprintf('Invalid configuration for path "html_translator.providers.p": "%s" is required by the "%s" driver', $missing, $driver));

        $this->process(['default_provider' => 'p', 'providers' => ['p' => ['driver' => $driver] + $keys]]);
    }

    #[Test]
    public function it_treats_a_null_required_key_as_missing(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"model" is required by the "ollama" driver');

        $this->process(['default_provider' => 'p', 'providers' => ['p' => ['driver' => 'ollama', 'model' => null]]]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSections(): iterable
    {
        yield 'timeout string' => [['http' => ['timeout' => 'long']], 'Invalid type for path "html_translator.http.timeout"'];
        yield 'empty http client' => [['http' => ['client' => '']], 'Invalid configuration for path "html_translator.http.client": a service id or ~, got an empty string'];
        yield 'bundle http client as client' => [['http' => ['client' => 'html_translator.http_client']], 'Invalid configuration for path "html_translator.http.client": "html_translator.http_client" is a service the bundle builds on this client; name the client itself'];
        yield 'bundle psr18 client as client' => [['http' => ['client' => 'html_translator.psr18_client']], '"html_translator.psr18_client" is a service the bundle builds on this client'];
        yield 'max_attempts 0' => [['http' => ['retry' => ['max_attempts' => 0]]], 'The value 0 is too small for path "html_translator.http.retry.max_attempts"'];
        yield 'max_attempts 11' => [['http' => ['retry' => ['max_attempts' => 11]]], 'The value 11 is too big for path "html_translator.http.retry.max_attempts"'];
        yield 'negative base delay' => [['http' => ['retry' => ['base_delay_ms' => -1]]], 'The value -1 is too small for path "html_translator.http.retry.base_delay_ms"'];
        yield 'negative max delay' => [['http' => ['retry' => ['base_delay_ms' => 0, 'max_delay_ms' => -1]]], 'The value -1 is too small for path "html_translator.http.retry.max_delay_ms"'];
        yield 'max delay too big' => [['http' => ['retry' => ['max_delay_ms' => 3_600_001]]], 'The value 3600001 is too big for path "html_translator.http.retry.max_delay_ms"'];
        yield 'base above max delay' => [['http' => ['retry' => ['base_delay_ms' => 5000, 'max_delay_ms' => 1000]]], 'Invalid configuration for path "html_translator.http.retry": "base_delay_ms" (5000) must not be greater than "max_delay_ms" (1000)'];
        yield 'bad preset' => [['profiles' => ['docs' => ['preset' => 'casual']]], 'The value "casual" is not allowed for path "html_translator.profiles.docs.preset"'];
        yield 'bad formality' => [['profiles' => ['docs' => ['formality' => 'polite']]], 'The value "polite" is not allowed for path "html_translator.profiles.docs.formality"'];
        yield 'bad term_policy' => [['profiles' => ['docs' => ['term_policy' => 'keep']]], 'The value "keep" is not allowed for path "html_translator.profiles.docs.term_policy"'];
        yield 'unknown profile key' => [['profiles' => ['docs' => ['glosary' => []]]], 'Unrecognized option "glosary" under "html_translator.profiles.docs". Did you mean "glossary"?'];
        yield 'bad fallback' => [['options' => ['fallback' => 'skip']], 'The value "skip" is not allowed for path "html_translator.options.fallback"'];
        yield 'negative corrective retries' => [['options' => ['max_corrective_retries' => -1]], 'The value -1 is too small for path "html_translator.options.max_corrective_retries"'];
        yield 'batch_size 0' => [['options' => ['batch_size' => 0]], 'The value 0 is too small for path "html_translator.options.batch_size"'];
        yield 'token_budget 0' => [['options' => ['token_budget' => 0]], 'The value 0 is too small for path "html_translator.options.token_budget"'];
        yield 'skip_elements not a list' => [['html' => ['skip_elements' => 'address']], 'html_translator.html.skip_elements'];
        yield 'cache enabled not a bool' => [['cache' => ['enabled' => 'yes']], 'Invalid type for path "html_translator.cache.enabled"'];
        yield 'unknown root key' => [['provider' => []], 'Unrecognized option "provider" under "html_translator". Did you mean "providers"'];
        yield 'provider name starting with a digit' => [['default_provider' => '1st', 'providers' => ['1st' => ['driver' => 'pseudo']]], 'Invalid configuration for path "html_translator.providers": the entry name "1st" must start with a letter and contain only letters, digits, "_" and "-"'];
        yield 'provider name with a dot' => [['default_provider' => 'a.b', 'providers' => ['a.b' => ['driver' => 'pseudo']]], 'the entry name "a.b" must start with a letter'];
        yield 'provider names autowiring alike' => [['default_provider' => 'lm_studio', 'providers' => ['lm_studio' => ['driver' => 'pseudo'], 'lmStudio' => ['driver' => 'pseudo']]], 'Invalid configuration for path "html_translator.providers": the entries "lm_studio" and "lmStudio" both autowire as $lmStudioTranslator; rename one of them'];
        yield 'unknown default_provider' => [['default_provider' => 'openai'], 'Invalid configuration for path "html_translator": "default_provider" is "openai", which is not a configured provider; configured: pseudo'];
        yield 'unknown default_profile' => [['default_profile' => 'docs'], 'Invalid configuration for path "html_translator": "default_profile" is "docs", which is not a configured profile; configured: default'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[Test]
    #[DataProvider('invalidSections')]
    public function it_rejects_an_invalid_value(array $override, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process($override + self::PSEUDO_ONLY);
    }

    #[Test]
    public function it_requires_providers(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The child config "providers" under "html_translator" must be configured');

        $this->process([]);
    }

    #[Test]
    public function it_requires_at_least_one_provider(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The path "html_translator.providers" should have at least 1 element(s) defined');

        $this->process(['providers' => []]);
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function process(array $config): array
    {
        return $this->processAll([$config]);
    }

    /**
     * @param list<array<array-key, mixed>> $configs
     *
     * @return array<array-key, mixed>
     */
    private function processAll(array $configs): array
    {
        $treeBuilder = new TreeBuilder('html_translator');
        ConfigurationTree::define($treeBuilder->getRootNode());

        return new Processor()->process($treeBuilder->buildTree(), $configs);
    }

    /**
     * @param array<array-key, mixed> $array
     *
     * @return array<array-key, mixed>
     */
    private function arrayAt(array $array, string $path): array
    {
        $value = self::at($array, $path);
        self::assertIsArray($value, \sprintf('"%s" is not an array.', $path));

        return $value;
    }

    /**
     * @param array<array-key, mixed> $array
     * @param string $path dot-separated keys, e.g. "http.retry.max_attempts"
     */
    private function at(array $array, string $path): mixed
    {
        $value = $array;
        foreach (explode('.', $path) as $key) {
            self::assertIsArray($value, \sprintf('No array above "%s" in "%s".', $key, $path));
            self::assertArrayHasKey($key, $value, \sprintf('Missing "%s".', $path));
            $value = $value[$key];
        }

        return $value;
    }
}
