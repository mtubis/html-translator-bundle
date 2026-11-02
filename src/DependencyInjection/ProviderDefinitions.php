<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\DependencyInjection;

use HtmlTranslator\Bundle\ChatModel\ChatModelFactory;
use HtmlTranslator\Bundle\Http\HttpClientFactory;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\Bridge\RetryPolicy;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Definitions of the HTTP stack and of one chat model and provider per provider entry (ARCHITECTURE §5).
 *
 * Builds definitions only (ADR-0004): core objects are created by the container at runtime, and entries are passed
 * with their env placeholders, so ChatModelFactory sees the resolved values and secrets never reach the compiled
 * container. All services are private.
 *
 * @internal
 */
final class ProviderDefinitions
{
    public const string HTTP_CLIENT = 'html_translator.http_client';

    public const string PSR18_CLIENT = 'html_translator.psr18_client';

    public const string RETRIER = 'html_translator.retrier';

    public const string CHAT_MODEL_FACTORY = 'html_translator.chat_model_factory';

    public const string CHAT_MODEL_PREFIX = 'html_translator.chat_model.';

    public const string PROVIDER_PREFIX = 'html_translator.provider.';

    /**
     * @param array<array-key, mixed> $config the processed configuration
     */
    public static function register(#[SensitiveParameter] array $config, ContainerBuilder $builder): void
    {
        $http = self::section($config, 'http');
        self::registerHttpClient($http, $builder);
        self::registerRetrier(self::section($http, 'retry'), $builder);

        $builder->register(self::PSR18_CLIENT, Psr18Client::class)
            ->setArguments([new Reference(self::HTTP_CLIENT)]);

        $builder->register(self::CHAT_MODEL_FACTORY, ChatModelFactory::class)
            ->setArguments([
                new Reference(self::PSR18_CLIENT),
                new Reference(self::PSR18_CLIENT),
                new Reference(self::PSR18_CLIENT),
                new Reference(self::RETRIER),
            ]);

        foreach (self::section($config, 'providers') as $name => $entry) {
            if (\is_array($entry)) {
                self::registerProvider((string) $name, $entry, $builder);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $http
     */
    private static function registerHttpClient(array $http, ContainerBuilder $builder): void
    {
        if (\is_string($http['client'] ?? null)) {
            // The application configured this client; it is used as is.
            $builder->setAlias(self::HTTP_CLIENT, $http['client']);

            return;
        }

        $builder->register(self::HTTP_CLIENT, HttpClientInterface::class)
            ->setFactory([HttpClientFactory::class, 'create'])
            ->setArguments([
                new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $http['timeout'] ?? null,
                $http['max_duration'] ?? null,
            ]);
    }

    /**
     * @param array<array-key, mixed> $retry
     */
    private static function registerRetrier(array $retry, ContainerBuilder $builder): void
    {
        $policy = new Definition(RetryPolicy::class, [
            '$maxAttempts' => $retry['max_attempts'] ?? null,
            '$baseDelayMilliseconds' => $retry['base_delay_ms'] ?? null,
            '$maxDelayMilliseconds' => $retry['max_delay_ms'] ?? null,
            '$retryNetworkErrors' => $retry['retry_network_errors'] ?? null,
        ]);

        $builder->register(self::RETRIER, Retrier::class)->setArguments([$policy]);
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private static function registerProvider(string $name, #[SensitiveParameter] array $entry, ContainerBuilder $builder): void
    {
        $driver = $entry['driver'] ?? null;

        if ('pseudo' === $driver) {
            $builder->register(self::PROVIDER_PREFIX . $name, PseudoLocalizationProvider::class)
                ->setArguments(['$corruptEvery' => $entry['corrupt_every'] ?? 0, '$corruptRetries' => $entry['corrupt_retries'] ?? false]);

            return;
        }

        $builder->register(self::CHAT_MODEL_PREFIX . $name, ChatModelInterface::class)
            ->setFactory([new Reference(self::CHAT_MODEL_FACTORY), 'create'])
            ->setArguments([$name, $driver, $entry]);

        $builder->register(self::PROVIDER_PREFIX . $name, LlmTranslationProvider::class)
            ->setArguments([new Reference(self::CHAT_MODEL_PREFIX . $name)]);
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
