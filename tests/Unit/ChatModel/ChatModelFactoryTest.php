<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit\ChatModel;

use HtmlTranslator\Bundle\ChatModel\ChatModelFactory;
use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\Bundle\Tests\TraceText;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicEffort;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\JsonMode;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

#[CoversClass(ChatModelFactory::class)]
#[CoversClass(InvalidConfigurationException::class)]
final class ChatModelFactoryTest extends TestCase
{
    private const string SECRET_KEY = 'sk-secret-4f9a1c7e2b';

    private const string SECRET_HEADER = 'hdr-secret-8d3e6b0a5c';

    #[Test]
    public function it_maps_openai_to_its_preset(): void
    {
        $config = $this->openAiCompatible('openai', ['api_key' => 'k', 'model' => 'gpt-test']);

        self::assertSame(['openai', 'https://api.openai.com/v1', 'gpt-test', JsonMode::JsonSchema, OpenAiCompatibleConfig::MAX_COMPLETION_TOKENS], $this->describe($config));
    }

    #[Test]
    public function it_passes_the_json_mode_of_openai(): void
    {
        $config = $this->openAiCompatible('openai', ['api_key' => 'k', 'model' => 'gpt-test', 'json_mode' => 'json_object']);

        self::assertSame(JsonMode::JsonObject, $config->jsonMode);
    }

    #[Test]
    public function it_maps_gemini_to_its_preset(): void
    {
        $config = $this->openAiCompatible('gemini', ['api_key' => 'k', 'model' => 'gemini-test']);

        self::assertSame(['gemini', 'https://generativelanguage.googleapis.com/v1beta/openai', 'gemini-test', JsonMode::JsonSchema, OpenAiCompatibleConfig::MAX_TOKENS], $this->describe($config));
    }

    /**
     * The core presets choose the default JSON mode per server (json_schema since core 1.0).
     *
     * @return iterable<string, array{string, string, string, JsonMode}>
     */
    public static function localPresets(): iterable
    {
        yield 'ollama' => ['ollama', 'ollama', 'http://localhost:11434/v1', JsonMode::JsonSchema];
        yield 'vllm' => ['vllm', 'vllm', 'http://localhost:8000/v1', JsonMode::JsonSchema];
        yield 'lm_studio' => ['lm_studio', 'lmstudio', 'http://localhost:1234/v1', JsonMode::JsonSchemaOnly];
    }

    #[Test]
    #[DataProvider('localPresets')]
    public function it_maps_a_local_server_to_its_preset_defaults(string $driver, string $name, string $baseUrl, JsonMode $jsonMode): void
    {
        foreach ([[], ['base_url' => null], ['base_url' => '']] as $baseUrlEntry) {
            $config = $this->openAiCompatible($driver, ['model' => 'local-model'] + $baseUrlEntry);

            self::assertSame([$name, $baseUrl, 'local-model', $jsonMode, OpenAiCompatibleConfig::MAX_TOKENS], $this->describe($config));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localDrivers(): iterable
    {
        foreach (self::localPresets() as $case => [$driver]) {
            yield $case => [$driver];
        }
    }

    #[Test]
    #[DataProvider('localDrivers')]
    public function it_passes_the_base_url_of_a_local_server(string $driver): void
    {
        $config = $this->openAiCompatible($driver, ['model' => 'local-model', 'base_url' => 'http://gpu-box:9000/v1']);

        self::assertSame('http://gpu-box:9000/v1', $config->baseUrl);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, OpenAiCompatibleConfig}>
     */
    public static function presetsWithAJsonMode(): iterable
    {
        yield 'gemini' => ['gemini', ['api_key' => 'k', 'model' => 'g', 'json_mode' => 'json_object'], OpenAiCompatibleConfig::gemini('k', 'g', JsonMode::JsonObject)];
        yield 'ollama' => ['ollama', ['model' => 'l', 'json_mode' => 'json_object'], OpenAiCompatibleConfig::ollama('l', jsonMode: JsonMode::JsonObject)];
        yield 'ollama with base url' => ['ollama', ['model' => 'l', 'base_url' => 'http://ollama:11434/v1', 'json_mode' => 'none'], OpenAiCompatibleConfig::ollama('l', 'http://ollama:11434/v1', JsonMode::None)];
        yield 'vllm' => ['vllm', ['model' => 'q', 'json_mode' => 'json_object'], OpenAiCompatibleConfig::vllm('q', jsonMode: JsonMode::JsonObject)];
        yield 'vllm with key only' => ['vllm', ['model' => 'q', 'api_key' => 'k'], OpenAiCompatibleConfig::vllm('q', apiKey: 'k')];
        yield 'vllm with everything' => ['vllm', ['model' => 'q', 'base_url' => 'http://gpu:8000/v1', 'api_key' => 'k', 'json_mode' => 'json_object'], OpenAiCompatibleConfig::vllm('q', 'http://gpu:8000/v1', 'k', JsonMode::JsonObject)];
        yield 'lm_studio' => ['lm_studio', ['model' => 'p', 'json_mode' => 'none'], OpenAiCompatibleConfig::lmStudio('p', jsonMode: JsonMode::None)];
        yield 'null json mode keeps the preset default' => ['ollama', ['model' => 'l', 'json_mode' => null], OpenAiCompatibleConfig::ollama('l')];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('presetsWithAJsonMode')]
    public function it_passes_the_json_mode_of_a_preset(string $driver, array $entry, OpenAiCompatibleConfig $expected): void
    {
        $config = $this->openAiCompatible($driver, $entry);

        self::assertSame($this->describe($expected), $this->describe($config));
        self::assertSame($expected->headers(), $config->headers());
    }

    #[Test]
    public function it_maps_openai_compatible_to_the_configuration_constructor(): void
    {
        $config = $this->openAiCompatible('openai_compatible', [
            'name' => 'together',
            'base_url' => 'https://api.together.xyz/v1',
            'api_key' => 'k',
            'model' => 'llama-test',
            'json_mode' => 'json_schema',
            'max_tokens_field' => 'max_completion_tokens',
            'extra_headers' => ['X-Team' => 'translations'],
        ]);

        self::assertSame(['together', 'https://api.together.xyz/v1', 'llama-test', JsonMode::JsonSchema, OpenAiCompatibleConfig::MAX_COMPLETION_TOKENS], $this->describe($config));
    }

    #[Test]
    public function it_defaults_openai_compatible_to_the_entry_name_and_json_object(): void
    {
        $config = $this->factory()->config('my_server', 'openai_compatible', ['base_url' => 'http://llm.internal/v1', 'model' => 'm']);
        self::assertInstanceOf(OpenAiCompatibleConfig::class, $config);

        self::assertSame(['my_server', 'http://llm.internal/v1', 'm', JsonMode::JsonObject, OpenAiCompatibleConfig::MAX_TOKENS], $this->describe($config));
    }

    #[Test]
    public function it_maps_anthropic_to_the_core_defaults(): void
    {
        $config = $this->anthropic(['api_key' => 'k', 'model' => 'claude-test', 'extra_headers' => [], 'betas' => []]);

        self::assertSame('claude-test', $config->model);
        self::assertSame(AnthropicConfig::DEFAULT_BASE_URL, $config->baseUrl);
        self::assertSame(AnthropicConfig::DEFAULT_API_VERSION, $config->apiVersion);
        self::assertSame(AnthropicConfig::DEFAULT_MAX_OUTPUT_TOKENS, $config->defaultMaxOutputTokens);
        self::assertNull($config->effort);
        self::assertFalse($config->refusalFallbacks);
        self::assertSame([], $config->betas);
        self::assertSame('anthropic:claude-test', $config->modelId());
    }

    #[Test]
    public function it_maps_every_anthropic_key(): void
    {
        $config = $this->anthropic([
            'api_key' => 'k',
            'model' => 'claude-test',
            'effort' => 'low',
            'base_url' => 'https://gateway.example/v1',
            'refusal_fallbacks' => true,
            'betas' => ['some-feature-2026-01-01', 'another-feature-2026-02-01'],
            'extra_headers' => ['Authorization' => 'Bearer gateway'],
            'api_version' => '2024-01-01',
            'default_max_output_tokens' => 4096,
        ]);

        self::assertSame('https://gateway.example/v1', $config->baseUrl);
        self::assertSame('2024-01-01', $config->apiVersion);
        self::assertSame(4096, $config->defaultMaxOutputTokens);
        self::assertSame(AnthropicEffort::Low, $config->effort);
        self::assertTrue($config->refusalFallbacks);
        self::assertSame(['some-feature-2026-01-01', 'another-feature-2026-02-01'], $config->betas);
        self::assertSame('anthropic:claude-test+effort=low+betas=another-feature-2026-02-01,some-feature-2026-01-01+fallbacks', $config->modelId());
    }

    #[Test]
    public function it_treats_empty_optional_anthropic_strings_as_not_set(): void
    {
        $config = $this->anthropic(['api_key' => 'k', 'model' => 'm', 'base_url' => '', 'api_version' => '', 'effort' => null]);

        self::assertSame([AnthropicConfig::DEFAULT_BASE_URL, AnthropicConfig::DEFAULT_API_VERSION], [$config->baseUrl, $config->apiVersion]);
    }

    #[Test]
    public function it_creates_the_chat_model_of_the_driver(): void
    {
        $factory = $this->factory();

        $openAi = $factory->create('openai', 'openai', ['api_key' => 'k', 'model' => 'gpt-test']);
        $anthropic = $factory->create('anthropic', 'anthropic', ['api_key' => 'k', 'model' => 'claude-test']);

        self::assertInstanceOf(OpenAiCompatibleChatModel::class, $openAi);
        self::assertSame('openai:gpt-test', $openAi->modelId());
        self::assertInstanceOf(AnthropicChatModel::class, $anthropic);
        self::assertSame('anthropic:claude-test', $anthropic->modelId());
    }

    #[Test]
    public function it_has_no_chat_model_for_the_pseudo_driver(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The driver "pseudo" has no chat model.');

        $this->factory()->config('p', 'pseudo', []);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function emptyRequiredValues(): iterable
    {
        yield 'openai api_key' => ['openai', ['api_key' => '', 'model' => 'm'], 'html_translator.providers.p.api_key: is empty; the reference configuration reads it from OPENAI_API_KEY'];
        yield 'openai model' => ['openai', ['api_key' => 'k', 'model' => ' '], 'html_translator.providers.p.model: is empty; the reference configuration reads it from HTML_TRANSLATOR_OPENAI_MODEL'];
        yield 'gemini api_key' => ['gemini', ['api_key' => '', 'model' => 'm'], 'html_translator.providers.p.api_key: is empty; the reference configuration reads it from GEMINI_API_KEY'];
        yield 'gemini model' => ['gemini', ['api_key' => 'k', 'model' => ''], 'html_translator.providers.p.model: is empty; the reference configuration reads it from HTML_TRANSLATOR_GEMINI_MODEL'];
        yield 'anthropic api_key' => ['anthropic', ['api_key' => '', 'model' => 'm'], 'html_translator.providers.p.api_key: is empty; the reference configuration reads it from ANTHROPIC_API_KEY'];
        yield 'anthropic model' => ['anthropic', ['api_key' => 'k', 'model' => ''], 'html_translator.providers.p.model: is empty; the reference configuration reads it from HTML_TRANSLATOR_ANTHROPIC_MODEL'];
        yield 'ollama model' => ['ollama', ['model' => ''], 'html_translator.providers.p.model: is empty; the reference configuration reads it from HTML_TRANSLATOR_OLLAMA_MODEL'];
        yield 'vllm model' => ['vllm', ['model' => ''], 'html_translator.providers.p.model: is empty'];
        yield 'lm_studio model' => ['lm_studio', ['model' => ''], 'html_translator.providers.p.model: is empty'];
        yield 'openai_compatible base_url' => ['openai_compatible', ['base_url' => '', 'model' => 'm'], 'html_translator.providers.p.base_url: is empty'];
        yield 'openai_compatible model' => ['openai_compatible', ['base_url' => 'http://x', 'model' => ''], 'html_translator.providers.p.model: is empty'];
        yield 'missing model' => ['ollama', [], 'html_translator.providers.p.model: is empty; the reference configuration reads it from HTML_TRANSLATOR_OLLAMA_MODEL'];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('emptyRequiredValues')]
    public function it_names_the_env_variable_of_an_empty_required_value(string $driver, array $entry, string $message): void
    {
        $exception = $this->failure($driver, $entry);

        self::assertSame($message, $exception->getMessage());
    }

    #[Test]
    public function it_names_no_env_variable_for_a_value_it_does_not_know(): void
    {
        self::assertSame('html_translator.providers.p.base_url: is empty', $this->failure('openai_compatible', ['base_url' => '', 'model' => 'm'])->getMessage());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function valuesOfTheWrongType(): iterable
    {
        yield 'api_key' => ['openai', ['api_key' => 42, 'model' => 'm'], 'html_translator.providers.p.api_key: expected a string, got int'];
        yield 'base_url' => ['ollama', ['model' => 'm', 'base_url' => true], 'html_translator.providers.p.base_url: expected a string, got bool'];
        yield 'beta' => ['anthropic', ['api_key' => 'k', 'model' => 'm', 'betas' => [2026]], 'html_translator.providers.p.betas: expected a list of strings, got int'];
        yield 'header value' => ['anthropic', ['api_key' => 'k', 'model' => 'm', 'extra_headers' => ['X-A' => 1]], 'html_translator.providers.p.extra_headers: the value of "X-A" must be a string, got int'];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('valuesOfTheWrongType')]
    public function it_rejects_a_value_of_the_wrong_type(string $driver, array $entry, string $message): void
    {
        self::assertSame($message, $this->failure($driver, $entry)->getMessage());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function entriesTheCoreRejects(): iterable
    {
        yield 'relative base URL' => ['ollama', ['model' => 'm', 'base_url' => 'localhost:11434']];
        yield 'reserved header' => ['openai_compatible', ['base_url' => 'http://x', 'model' => 'm', 'extra_headers' => ['Authorization' => 'Bearer x']]];
        yield 'invalid beta' => ['anthropic', ['api_key' => 'k', 'model' => 'm', 'betas' => ['Not A Beta']]];
        yield 'key with a line break' => ['openai', ['api_key' => "k\nx", 'model' => 'm']];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('entriesTheCoreRejects')]
    public function it_reports_a_core_error_with_the_entry_path(string $driver, array $entry): void
    {
        $exception = $this->failure($driver, $entry);
        $previous = $exception->getPrevious();

        self::assertInstanceOf(InvalidArgumentException::class, $previous);
        self::assertSame('html_translator.providers.p: ' . $previous->getMessage(), $exception->getMessage());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function failingEntriesWithSecrets(): iterable
    {
        yield 'empty model next to a key' => ['openai', ['api_key' => self::SECRET_KEY, 'model' => '']];
        yield 'key with a line break' => ['anthropic', ['api_key' => self::SECRET_KEY . "\n", 'model' => 'm', 'extra_headers' => ['X-Token' => self::SECRET_HEADER]]];
        yield 'header with a line break' => ['openai_compatible', ['base_url' => 'http://x', 'model' => 'm', 'api_key' => self::SECRET_KEY, 'extra_headers' => ['X-Token' => self::SECRET_HEADER . "\r\nX-Evil: 1"]]];
        yield 'beta of the wrong type next to secrets' => ['anthropic', ['api_key' => self::SECRET_KEY, 'model' => 'm', 'betas' => [1], 'extra_headers' => ['X-Token' => self::SECRET_HEADER]]];
        yield 'header of the wrong type next to secrets' => ['anthropic', ['api_key' => self::SECRET_KEY, 'model' => 'm', 'extra_headers' => ['X-Token' => self::SECRET_HEADER, 'X-Other' => 1]]];
        yield 'vllm base URL next to a key and a json mode' => ['vllm', ['model' => 'm', 'api_key' => self::SECRET_KEY, 'base_url' => 'ftp://x', 'json_mode' => 'json_object']];
        yield 'bad base URL next to secrets' => ['anthropic', ['api_key' => self::SECRET_KEY, 'model' => 'm', 'base_url' => 'not a url', 'extra_headers' => ['X-Token' => self::SECRET_HEADER]]];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('failingEntriesWithSecrets')]
    public function it_never_puts_a_secret_into_a_message_or_trace(string $driver, array $entry): void
    {
        // Traces carry arguments where php.ini allows it (php.ini-development does); #[SensitiveParameter] hides them.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            $text = TraceText::of($this->failure($driver, $entry));

            self::assertStringContainsString('SensitiveParameterValue', $text);
            self::assertStringNotContainsString(self::SECRET_KEY, $text);
            self::assertStringNotContainsString(self::SECRET_HEADER, $text);
        } finally {
            ini_set('zend.exception_ignore_args', false === $ignoreArgs ? '1' : $ignoreArgs);
        }
    }

    private function factory(): ChatModelFactory
    {
        $http = new Psr18Client(new MockHttpClient());

        return new ChatModelFactory($http, $http, $http, new Retrier());
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function openAiCompatible(string $driver, array $entry): OpenAiCompatibleConfig
    {
        $config = $this->factory()->config('p', $driver, $entry);
        self::assertInstanceOf(OpenAiCompatibleConfig::class, $config);

        return $config;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function anthropic(array $entry): AnthropicConfig
    {
        $config = $this->factory()->config('p', 'anthropic', $entry);
        self::assertInstanceOf(AnthropicConfig::class, $config);

        return $config;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function failure(string $driver, array $entry): InvalidConfigurationException
    {
        try {
            $this->factory()->config('p', $driver, $entry);
        } catch (InvalidConfigurationException $exception) {
            return $exception;
        }

        self::fail('The entry should be rejected.');
    }

    /**
     * @return array{string, string, string, JsonMode, string}
     */
    private function describe(OpenAiCompatibleConfig $config): array
    {
        return [$config->name, $config->baseUrl, $config->model, $config->jsonMode, $config->maxTokensField];
    }
}
