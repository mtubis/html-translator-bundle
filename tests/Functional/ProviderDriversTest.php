<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional;

use HtmlTranslator\Bundle\ChatModel\ChatModelFactory;
use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\DependencyInjection\ProviderDefinitions;
use HtmlTranslator\Bundle\DependencyInjection\TranslatorDefinitions;
use HtmlTranslator\Bundle\Drivers;
use HtmlTranslator\Bundle\Html\HtmlRulesFactory;
use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use HtmlTranslator\Bundle\Http\HttpClientFactory;
use HtmlTranslator\Bundle\Tests\Functional\Fixtures\VendorResponses;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\TranslationOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

/**
 * Every LLM driver end to end: config entry → chat model → provider → core translator, against canned vendor bodies.
 */
#[CoversClass(HtmlTranslatorBundle::class)]
#[CoversClass(ProviderDefinitions::class)]
#[CoversClass(ChatModelFactory::class)]
#[CoversClass(HttpClientFactory::class)]
#[UsesClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
#[UsesClass(HtmlRulesFactory::class)]
#[UsesClass(TranslatorDefinitions::class)]
final class ProviderDriversTest extends FunctionalTestCase
{
    /** @var list<array{method: string, url: string, headers: array<string, list<string>>, body: array<array-key, mixed>}> */
    private array $requests = [];

    /**
     * @return iterable<string, array{string, string, array<string, list<string>>, string, string}>
     */
    public static function drivers(): iterable
    {
        // entry, URL, auth headers (name => values), model, JSON mode (response_format or output_config)
        yield 'openai' => ['openai', 'https://api.openai.com/v1/chat/completions', ['authorization' => ['Bearer test-openai-key']], 'gpt-test', 'json_schema'];
        yield 'gemini' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', ['authorization' => ['Bearer test-gemini-key']], 'gemini-test', 'json_object'];
        yield 'ollama' => ['ollama', 'http://localhost:11434/v1/chat/completions', [], 'llama-test', 'json_object'];
        yield 'vllm' => ['vllm', 'http://localhost:8000/v1/chat/completions', ['authorization' => ['Bearer test-vllm-key']], 'vllm-test', 'json_object'];
        yield 'lm_studio' => ['lm_studio', 'http://localhost:1234/v1/chat/completions', [], 'lmstudio-test', 'json_schema'];
        yield 'openai_compatible' => ['together', 'https://api.together.xyz/v1/chat/completions', ['authorization' => ['Bearer test-together-key']], 'together-test', 'json_object'];
        yield 'anthropic' => ['anthropic', 'https://api.anthropic.com/v1/messages', ['x-api-key' => ['test-anthropic-key']], 'claude-test', 'json_schema'];
    }

    /**
     * @param array<string, list<string>> $auth
     */
    #[Test]
    #[DataProvider('drivers')]
    public function it_translates_through_the_driver_of_the_entry(string $entry, string $url, array $auth, string $model, string $jsonMode): void
    {
        $response = 'anthropic' === $entry ? VendorResponses::anthropicSuccess() : VendorResponses::openAiCompatibleSuccess();
        $document = $this->translator($entry, ['drivers.yaml'], $response)->translate('<p>Hello</p>', Locale::fromString('pl'), new TranslationOptions(source: Locale::fromString('en')));

        self::assertSame('<p>Cześć</p>', $document->html());
        self::assertCount(1, $this->requests);
        [$request] = $this->requests;
        self::assertSame('POST', $request['method']);
        self::assertSame($url, $request['url']);
        self::assertSame($model, self::at($request['body'], 'model'));

        self::assertSame($auth, array_intersect_key($request['headers'], ['authorization' => true, 'x-api-key' => true]));

        $format = 'anthropic' === $entry ? self::at($request['body'], 'output_config', 'format') : self::at($request['body'], 'response_format');
        self::assertSame($jsonMode, self::at($format, 'type'));
    }

    #[Test]
    public function it_sends_the_extra_headers_of_the_entry(): void
    {
        $this->translator('together', ['drivers.yaml'], VendorResponses::openAiCompatibleSuccess())->translate('<p>Hello</p>', Locale::fromString('pl'));

        self::assertSame(['translations'], $this->requests[0]['headers']['x-team'] ?? null);
        self::assertSame(['functional-test'], $this->requests[0]['headers']['x-run'] ?? null);
    }

    #[Test]
    public function it_retries_a_rate_limited_request(): void
    {
        $document = $this->translator('openai', ['drivers.yaml'], VendorResponses::rateLimited(), VendorResponses::openAiCompatibleSuccess())->translate('<p>Hello</p>', Locale::fromString('pl'));

        self::assertSame('<p>Cześć</p>', $document->html());
        self::assertCount(2, $this->requests);
        // The retry sends the same request again, body included.
        self::assertSame($this->requests[0], $this->requests[1]);
    }

    #[Test]
    public function it_gives_up_after_the_configured_attempts(): void
    {
        $translator = $this->translator('openai', ['drivers.yaml', 'no_retry.yaml'], VendorResponses::rateLimited(), VendorResponses::openAiCompatibleSuccess());

        try {
            // A failing first call is not a unit failure: the provider error reaches the caller.
            $translator->translate('<p>Hello</p>', Locale::fromString('pl'));
            self::fail('The rate limit should surface.');
        } catch (ProviderException $exception) {
            self::assertStringContainsString('429', $exception->getMessage());
            for ($e = $exception; $e instanceof Throwable; $e = $e->getPrevious()) {
                self::assertStringNotContainsString('test-openai-key', $e->getMessage());
            }
        }

        self::assertCount(1, $this->requests);
    }

    #[Test]
    public function it_scopes_the_framework_http_client_by_default(): void
    {
        self::bootKernel(['config' => ['framework_http.yaml']]);
        $translator = self::scenarioService('html_translator.translator.ollama');
        self::assertInstanceOf(HtmlTranslator::class, $translator);

        $document = $translator->translate('<p>Hello</p>', Locale::fromString('pl'));

        $response = self::scenarioService('test.mock_response');
        self::assertInstanceOf(MockResponse::class, $response);
        self::assertSame('<p>Cześć</p>', $document->html());
        self::assertSame('http://localhost:11434/v1/chat/completions', $response->getRequestUrl());
        self::assertSame([42.0, 99.0], [$response->getRequestOptions()['timeout'] ?? null, $response->getRequestOptions()['max_duration'] ?? null]);
    }

    /**
     * Boots the scenario, makes its MockHttpClient answer with the given responses in turn and records each request.
     *
     * @param list<string> $config
     */
    private function translator(string $entry, array $config, MockResponse ...$responses): HtmlTranslator
    {
        $this->requests = [];
        self::bootKernel(['config' => $config]);

        $client = self::scenarioService('test.mock_http_client');
        self::assertInstanceOf(MockHttpClient::class, $client);
        $client->setResponseFactory(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $this->headers($options), 'body' => $this->body($options)];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        $translator = self::scenarioService('html_translator.translator.' . $entry);
        self::assertInstanceOf(HtmlTranslator::class, $translator);

        return $translator;
    }


    /**
     * @param array<array-key, mixed> $options
     *
     * @return array<string, list<string>> lower-case name => values
     */
    private function headers(array $options): array
    {
        $headers = [];
        $normalized = $options['normalized_headers'] ?? [];
        foreach (\is_array($normalized) ? $normalized : [] as $name => $lines) {
            foreach (\is_array($lines) ? $lines : [] as $line) {
                $headers[strtolower((string) $name)][] = \is_string($line) ? trim(explode(':', $line, 2)[1] ?? '') : '';
            }
        }

        return $headers;
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return array<array-key, mixed>
     */
    private function body(array $options): array
    {
        $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '{}', true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($body) ? $body : [];
    }



    private function at(mixed $array, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($array);
            self::assertArrayHasKey($key, $array);
            $array = $array[$key];
        }

        return $array;
    }
}
