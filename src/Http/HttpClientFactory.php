<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Http;

use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The bundle's HTTP client (ADR-0003): FrameworkBundle's http_client scoped with the configured timeouts, so requests
 * appear in the profiler and tests can replace the client; without FrameworkBundle a client of its own.
 *
 * @internal
 */
final class HttpClientFactory
{
    /**
     * @param ?HttpClientInterface $client the "http_client" service; null without FrameworkBundle
     * @param float $timeout html_translator.http.timeout, possibly from env
     * @param ?float $maxDuration html_translator.http.max_duration, possibly from env; null = no limit
     *
     * @throws InvalidConfigurationException when a timeout is not positive
     */
    public static function create(?HttpClientInterface $client, float $timeout, ?float $maxDuration): HttpClientInterface
    {
        if ($timeout <= 0) {
            throw InvalidConfigurationException::forPath('html_translator.http.timeout', \sprintf('must be greater than 0, got %s', $timeout));
        }

        if (null !== $maxDuration && $maxDuration <= 0) {
            throw InvalidConfigurationException::forPath('html_translator.http.max_duration', \sprintf('must be greater than 0 or null, got %s', $maxDuration));
        }

        $options = ['timeout' => $timeout, 'max_duration' => $maxDuration ?? 0];

        return $client?->withOptions($options) ?? HttpClient::create($options);
    }
}
