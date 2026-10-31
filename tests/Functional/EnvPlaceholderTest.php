<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional;

use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\Drivers;
use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Env values are resolved at runtime only (ADR-0004): never while the container is compiled, never in its cache.
 */
#[CoversClass(HtmlTranslatorBundle::class)]
#[CoversClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
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
        foreach (array_keys(self::CANARIES) as $variable) {
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
    public function it_rejects_an_enum_key_from_env(): void
    {
        // Symfony checks a placeholder against the allowed values with a stand-in value of the env type.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('is not allowed for path "html_translator.providers.openai.json_mode"');

        self::bootKernel(['config' => ['env_enum.yaml']]);
    }
}
