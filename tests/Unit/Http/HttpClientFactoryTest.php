<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit\Http;

use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\Bundle\Http\HttpClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HttpClientFactory::class)]
#[CoversClass(InvalidConfigurationException::class)]
final class HttpClientFactoryTest extends TestCase
{
    #[Test]
    public function it_scopes_the_given_client_with_the_timeouts(): void
    {
        $options = [];
        $base = new MockHttpClient(static function (string $method, string $url, array $requestOptions) use (&$options): MockResponse {
            $options = $requestOptions;

            return new MockResponse('{}');
        });

        HttpClientFactory::create($base, 42.5, 600.0)->request('POST', 'https://api.example/v1/chat/completions')->getContent();

        self::assertSame([42.5, 600.0], [$options['timeout'] ?? null, $options['max_duration'] ?? null]);
    }

    #[Test]
    public function it_leaves_max_duration_unlimited_by_default(): void
    {
        $options = [];
        $base = new MockHttpClient(static function (string $method, string $url, array $requestOptions) use (&$options): MockResponse {
            $options = $requestOptions;

            return new MockResponse('{}');
        });

        HttpClientFactory::create($base, 180.0, null)->request('GET', 'https://api.example/')->getContent();

        self::assertSame(0.0, $options['max_duration'] ?? null);
    }

    #[Test]
    public function it_creates_a_client_without_framework_bundle(): void
    {
        $client = HttpClientFactory::create(null, 180.0, null);

        // A client of Symfony's own (CurlHttpClient, AmpHttpClient or NativeHttpClient), not a scoped one.
        self::assertStringStartsWith('Symfony\\Component\\HttpClient\\', $client::class);
        self::assertNotInstanceOf(MockHttpClient::class, $client);
    }

    /**
     * @return iterable<string, array{float, ?float, string}>
     */
    public static function invalidTimeouts(): iterable
    {
        yield 'timeout 0' => [0.0, null, 'html_translator.http.timeout: must be greater than 0, got 0'];
        yield 'negative timeout' => [-1.5, null, 'html_translator.http.timeout: must be greater than 0, got -1.5'];
        yield 'max_duration 0' => [180.0, 0.0, 'html_translator.http.max_duration: must be greater than 0 or null, got 0'];
    }

    #[Test]
    #[DataProvider('invalidTimeouts')]
    public function it_rejects_a_timeout_that_is_not_positive(float $timeout, ?float $maxDuration, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        HttpClientFactory::create(new MockHttpClient(), $timeout, $maxDuration);
    }
}
