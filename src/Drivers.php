<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle;

/**
 * The provider drivers and the keys each accepts (ADR-0004): the single list used by the configuration tree at
 * compile time and by the chat-model factory at runtime. Key names match the Laravel package.
 *
 * @internal
 */
final class Drivers
{
    /** @var list<string> */
    public const array ALL = ['openai', 'gemini', 'ollama', 'vllm', 'lm_studio', 'openai_compatible', 'anthropic', 'pseudo'];

    /** Driver names kept for a later version: name => version. */
    public const array RESERVED = ['symfony_ai' => '0.3'];

    /** Keys an entry may set besides "driver". */
    private const array KEYS = [
        'openai' => ['api_key', 'model', 'json_mode'],
        'gemini' => ['api_key', 'model'],
        'ollama' => ['model', 'base_url'],
        'vllm' => ['model', 'base_url', 'api_key'],
        'lm_studio' => ['model', 'base_url'],
        'openai_compatible' => ['name', 'base_url', 'api_key', 'model', 'json_mode', 'max_tokens_field', 'extra_headers'],
        'anthropic' => ['api_key', 'model', 'effort', 'base_url', 'refusal_fallbacks', 'betas', 'extra_headers', 'api_version', 'default_max_output_tokens'],
        'pseudo' => ['corrupt_every', 'corrupt_retries'],
    ];

    /** Keys the core configs have no default for. */
    private const array REQUIRED = [
        'openai' => ['api_key', 'model'],
        'gemini' => ['api_key', 'model'],
        'ollama' => ['model'],
        'vllm' => ['model'],
        'lm_studio' => ['model'],
        'openai_compatible' => ['base_url', 'model'],
        'anthropic' => ['api_key', 'model'],
        'pseudo' => [],
    ];

    /**
     * @return list<string>
     */
    public static function keysOf(string $driver): array
    {
        return self::KEYS[$driver] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function requiredKeysOf(string $driver): array
    {
        return self::REQUIRED[$driver] ?? [];
    }

    /**
     * Every key some driver accepts, in first-seen order.
     *
     * @return list<string>
     */
    public static function allKeys(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::KEYS))));
    }
}
