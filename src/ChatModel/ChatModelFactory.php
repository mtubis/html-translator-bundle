<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\ChatModel;

use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicEffort;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\JsonMode;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * Builds the core chat model of a provider entry when its service is first used (ADR-0004): by then env placeholders
 * are resolved, so the values that may come from env are checked here. Messages name the config path, never a value.
 *
 * @internal used by the html_translator.chat_model.<name> services
 */
final readonly class ChatModelFactory
{
    private const string PREFIX = 'html_translator.providers.';

    /** The env variables the reference configuration reads required values from, named in "is empty" messages. */
    private const array ENV = [
        'openai' => ['api_key' => 'OPENAI_API_KEY', 'model' => 'HTML_TRANSLATOR_OPENAI_MODEL'],
        'gemini' => ['api_key' => 'GEMINI_API_KEY', 'model' => 'HTML_TRANSLATOR_GEMINI_MODEL'],
        'anthropic' => ['api_key' => 'ANTHROPIC_API_KEY', 'model' => 'HTML_TRANSLATOR_ANTHROPIC_MODEL'],
        'ollama' => ['model' => 'HTML_TRANSLATOR_OLLAMA_MODEL'],
    ];

    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private Retrier $retrier,
    ) {}

    /**
     * @param array<array-key, mixed> $entry the processed entry, env values resolved
     *
     * @throws InvalidConfigurationException when a value is empty, of the wrong type or rejected by the core
     */
    public function create(string $name, string $driver, #[SensitiveParameter] array $entry): ChatModelInterface
    {
        $config = $this->config($name, $driver, $entry);

        return $config instanceof AnthropicConfig
            ? new AnthropicChatModel($this->client, $this->requestFactory, $this->streamFactory, $config, $this->retrier)
            : new OpenAiCompatibleChatModel($this->client, $this->requestFactory, $this->streamFactory, $config, $this->retrier);
    }

    /**
     * The core configuration an entry maps to; create() wraps it in its chat model.
     *
     * @param array<array-key, mixed> $entry
     *
     * @throws InvalidConfigurationException when a value is empty, of the wrong type or rejected by the core
     */
    public function config(string $name, string $driver, #[SensitiveParameter] array $entry): OpenAiCompatibleConfig|AnthropicConfig
    {
        // The keys Drivers::requiredKeysOf() lists; each arm reads its own, in the order of the core arguments.
        $required = fn(string $key): string => $this->required($name, $driver, $entry, $key);
        $jsonMode = static fn(JsonMode $default): JsonMode => JsonMode::from(self::literal($entry, 'json_mode') ?? $default->value);

        try {
            return match ($driver) {
                'openai' => OpenAiCompatibleConfig::openAi($required('api_key'), $required('model'), $jsonMode(JsonMode::JsonSchema)),
                'gemini' => OpenAiCompatibleConfig::gemini($required('api_key'), $required('model')),
                'ollama' => null === ($baseUrl = $this->optional($name, $entry, 'base_url'))
                    ? OpenAiCompatibleConfig::ollama($required('model'))
                    : OpenAiCompatibleConfig::ollama($required('model'), $baseUrl),
                'vllm' => null === ($baseUrl = $this->optional($name, $entry, 'base_url'))
                    ? OpenAiCompatibleConfig::vllm($required('model'), apiKey: $this->optional($name, $entry, 'api_key'))
                    : OpenAiCompatibleConfig::vllm($required('model'), $baseUrl, $this->optional($name, $entry, 'api_key')),
                'lm_studio' => null === ($baseUrl = $this->optional($name, $entry, 'base_url'))
                    ? OpenAiCompatibleConfig::lmStudio($required('model'))
                    : OpenAiCompatibleConfig::lmStudio($required('model'), $baseUrl),
                'openai_compatible' => new OpenAiCompatibleConfig(
                    $this->optional($name, $entry, 'name') ?? $name,
                    $required('base_url'),
                    $required('model'),
                    $this->optional($name, $entry, 'api_key'),
                    $jsonMode(JsonMode::JsonObject),
                    self::literal($entry, 'max_tokens_field') ?? OpenAiCompatibleConfig::MAX_TOKENS,
                    $this->headers($name, $entry),
                ),
                'anthropic' => new AnthropicConfig(
                    $required('model'),
                    $required('api_key'),
                    baseUrl: $this->optional($name, $entry, 'base_url') ?? AnthropicConfig::DEFAULT_BASE_URL,
                    apiVersion: $this->optional($name, $entry, 'api_version') ?? AnthropicConfig::DEFAULT_API_VERSION,
                    defaultMaxOutputTokens: \is_int($entry['default_max_output_tokens'] ?? null) ? $entry['default_max_output_tokens'] : AnthropicConfig::DEFAULT_MAX_OUTPUT_TOKENS,
                    effort: null === ($effort = self::literal($entry, 'effort')) ? null : AnthropicEffort::from($effort),
                    refusalFallbacks: true === ($entry['refusal_fallbacks'] ?? false),
                    extraHeaders: $this->headers($name, $entry),
                    betas: $this->betas($name, $entry),
                ),
                default => throw new LogicException(\sprintf('The driver "%s" has no chat model.', $driver)),
            };
        } catch (InvalidArgumentException $exception) {
            // Core messages name settings, never the key or header values (HttpSecrets).
            throw InvalidConfigurationException::forPath(self::PREFIX . $name, $exception->getMessage(), $exception);
        }
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private function required(string $name, string $driver, #[SensitiveParameter] array $entry, string $key): string
    {
        $value = $this->optional($name, $entry, $key);
        if (null !== $value && '' !== trim($value)) {
            return $value;
        }

        $env = self::ENV[$driver][$key] ?? null;

        throw InvalidConfigurationException::forPath(
            self::PREFIX . $name . '.' . $key,
            null === $env ? 'is empty' : 'is empty; the reference configuration reads it from ' . $env,
        );
    }

    /**
     * A string that may come from env; null and '' mean "not set".
     *
     * @param array<array-key, mixed> $entry
     */
    private function optional(string $name, #[SensitiveParameter] array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw InvalidConfigurationException::forPath(self::PREFIX . $name . '.' . $key, 'expected a string, got ' . get_debug_type($value));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $entry
     *
     * @return array<array-key, string>
     */
    private function headers(string $name, #[SensitiveParameter] array $entry): array
    {
        $headers = $entry['extra_headers'] ?? [];
        $result = [];

        foreach (\is_array($headers) ? $headers : [] as $header => $value) {
            if (!\is_string($value)) {
                throw InvalidConfigurationException::forPath(self::PREFIX . $name . '.extra_headers', \sprintf('the value of "%s" must be a string, got %s', $header, get_debug_type($value)));
            }

            $result[$header] = $value;
        }

        return $result;
    }

    /**
     * An enum-like value, a literal checked by the configuration tree.
     *
     * @param array<array-key, mixed> $entry
     */
    private static function literal(#[SensitiveParameter] array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $entry
     *
     * @return list<string>
     */
    private function betas(string $name, #[SensitiveParameter] array $entry): array
    {
        $betas = [];
        foreach (\is_array($entry['betas'] ?? null) ? $entry['betas'] : [] as $beta) {
            if (!\is_string($beta)) {
                throw InvalidConfigurationException::forPath(self::PREFIX . $name . '.betas', 'expected a list of strings, got ' . get_debug_type($beta));
            }

            $betas[] = $beta;
        }

        return $betas;
    }
}
