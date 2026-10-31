<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional;

use HtmlTranslator\Bundle\ChatModel\ChatModelFactory;
use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\DependencyInjection\ProviderDefinitions;
use HtmlTranslator\Bundle\Drivers;
use HtmlTranslator\Bundle\Exception\InvalidConfigurationException as BundleInvalidConfigurationException;
use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use HtmlTranslator\Bundle\Http\HttpClientFactory;
use HtmlTranslator\Bundle\Tests\TraceText;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Env values are resolved at runtime only (ADR-0004): never while the container is compiled, never in its cache.
 */
#[CoversClass(HtmlTranslatorBundle::class)]
#[CoversClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
#[UsesClass(ChatModelFactory::class)]
#[UsesClass(HttpClientFactory::class)]
#[UsesClass(BundleInvalidConfigurationException::class)]
#[UsesClass(ProviderDefinitions::class)]
final class EnvPlaceholderTest extends FunctionalTestCase
{
    /** The variables tests/Functional/config/env.yaml reads. */
    private const array VARIABLES = [
        'HTML_TRANSLATOR_TEST_NAME',
        'HTML_TRANSLATOR_TEST_BASE_URL',
        'HTML_TRANSLATOR_TEST_API_KEY',
        'HTML_TRANSLATOR_TEST_MODEL',
        'HTML_TRANSLATOR_TEST_HEADER',
        'HTML_TRANSLATOR_TEST_TIMEOUT',
    ];

    /** Secret values: variable => canary. */
    private const array CANARIES = [
        'HTML_TRANSLATOR_TEST_API_KEY' => 'sk-canary-0b5e7c2d9f',
        'HTML_TRANSLATOR_TEST_HEADER' => 'hdr-canary-7a41e8c3b6',
    ];

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $variable) {
            unset($_ENV[$variable], $_SERVER[$variable]);
            putenv($variable);
        }
        parent::tearDown();
    }

    #[Test]
    public function it_compiles_with_none_of_the_env_variables_set(): void
    {
        foreach (self::VARIABLES as $variable) {
            self::assertFalse(getenv($variable), \sprintf('%s must not be set for this test.', $variable));
        }

        $kernel = self::bootKernel(['config' => ['env.yaml']]);

        self::assertTrue($kernel->getContainer()->has('kernel'));
    }

    #[Test]
    public function it_does_not_write_env_secrets_into_the_compiled_container(): void
    {
        // A container compiled by another test would prove nothing: compile this one with the canaries set.
        new Filesystem()->remove(new TestKernel(['env.yaml'])->getCacheDir());
        foreach (self::CANARIES as $variable => $canary) {
            $_ENV[$variable] = $_SERVER[$variable] = $canary;
            putenv($variable . '=' . $canary);
        }

        $kernel = self::bootKernel(['config' => ['env.yaml']]);

        foreach (self::CANARIES as $canary) {
            $files = new Finder()->files()->in($kernel->getCacheDir())->contains($canary);
            self::assertSame([], array_keys(iterator_to_array($files)), $canary);
        }
    }

    #[Test]
    public function it_builds_the_request_from_the_env_values_at_runtime(): void
    {
        $this->setEnv([
            'HTML_TRANSLATOR_TEST_NAME' => 'together',
            'HTML_TRANSLATOR_TEST_BASE_URL' => 'https://llm.example/v1',
            'HTML_TRANSLATOR_TEST_MODEL' => 'env-model',
            'HTML_TRANSLATOR_TEST_TIMEOUT' => '12.5',
        ] + self::CANARIES);
        self::bootKernel(['config' => ['env.yaml', 'env_runtime.yaml']]);

        $document = $this->translator()->translate('<p>Hello</p>', Locale::fromString('pl'));

        $response = self::scenarioService('test.mock_response');
        self::assertInstanceOf(MockResponse::class, $response);
        self::assertSame('<p>Cześć</p>', $document->html());
        self::assertSame('https://llm.example/v1/chat/completions', $response->getRequestUrl());
        self::assertSame(12.5, $response->getRequestOptions()['timeout'] ?? null);
        $headers = $response->getRequestOptions()['normalized_headers'] ?? [];
        self::assertIsArray($headers);
        self::assertSame(['X-Gateway-Token: ' . self::CANARIES['HTML_TRANSLATOR_TEST_HEADER']], $headers['x-gateway-token'] ?? null);
        self::assertSame(['Authorization: Bearer ' . self::CANARIES['HTML_TRANSLATOR_TEST_API_KEY']], $headers['authorization'] ?? null);
    }

    #[Test]
    public function it_reports_an_empty_env_value_with_its_path_on_first_use(): void
    {
        $this->setEnv([
            'HTML_TRANSLATOR_TEST_NAME' => 'together',
            'HTML_TRANSLATOR_TEST_BASE_URL' => 'https://llm.example/v1',
            'HTML_TRANSLATOR_TEST_MODEL' => '',
            'HTML_TRANSLATOR_TEST_TIMEOUT' => '12.5',
        ] + self::CANARIES);
        self::bootKernel(['config' => ['env.yaml']]);
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            $this->translator();
            self::fail('The empty model should be reported.');
        } catch (BundleInvalidConfigurationException $exception) {
            self::assertSame('html_translator.providers.together.model: is empty', $exception->getMessage());
            $text = TraceText::of($exception);
            foreach (self::CANARIES as $canary) {
                self::assertStringNotContainsString($canary, $text);
            }
        } finally {
            ini_set('zend.exception_ignore_args', false === $ignoreArgs ? '1' : $ignoreArgs);
        }
    }

    private function translator(): HtmlTranslator
    {
        $translator = self::scenarioService('test.translator.together');
        self::assertInstanceOf(HtmlTranslator::class, $translator);

        return $translator;
    }

    /**
     * @param array<string, string> $values
     */
    private function setEnv(array $values): void
    {
        foreach ($values as $variable => $value) {
            $_ENV[$variable] = $_SERVER[$variable] = $value;
            putenv($variable . '=' . $value);
        }
    }

    #[Test]
    public function it_rejects_an_enum_key_from_env(): void
    {
        // Symfony checks a placeholder against the allowed values with a stand-in value of the env type.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('is not allowed for path "html_translator.providers.openai.json_mode"');

        self::bootKernel(['config' => ['env_enum.yaml']]);
    }
}
