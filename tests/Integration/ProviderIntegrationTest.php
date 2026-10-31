<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Integration;

use HtmlTranslator\Bundle\ChatModel\ChatModelFactory;
use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\DependencyInjection\ProfilerDefinitions;
use HtmlTranslator\Bundle\DependencyInjection\ProviderDefinitions;
use HtmlTranslator\Bundle\DependencyInjection\TranslatorDefinitions;
use HtmlTranslator\Bundle\Drivers;
use HtmlTranslator\Bundle\Html\HtmlRulesFactory;
use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use HtmlTranslator\Bundle\Http\HttpClientFactory;
use HtmlTranslator\Bundle\Tests\Functional\FunctionalTestCase;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\TranslationOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Real APIs through the bundle's wiring. Runs only with `composer test:integration` and the variables of .env.dist;
 * each driver is skipped when its key or model is missing. Costs a few tokens per run.
 */
#[Group('integration')]
#[CoversClass(HtmlTranslatorBundle::class)]
#[CoversClass(ProviderDefinitions::class)]
#[CoversClass(ChatModelFactory::class)]
#[CoversClass(HttpClientFactory::class)]
#[UsesClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
#[UsesClass(HtmlRulesFactory::class)]
#[UsesClass(TranslatorDefinitions::class)]
#[UsesClass(ProfilerDefinitions::class)]
final class ProviderIntegrationTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function drivers(): iterable
    {
        yield 'openai' => ['openai', ['OPENAI_API_KEY', 'HTML_TRANSLATOR_OPENAI_MODEL']];
        yield 'anthropic' => ['anthropic', ['ANTHROPIC_API_KEY', 'HTML_TRANSLATOR_ANTHROPIC_MODEL']];
        yield 'gemini' => ['gemini', ['GEMINI_API_KEY', 'HTML_TRANSLATOR_GEMINI_MODEL']];
    }

    /**
     * @param list<string> $variables
     */
    #[Test]
    #[DataProvider('drivers')]
    public function it_translates_with_the_real_api(string $entry, array $variables): void
    {
        foreach ($variables as $variable) {
            if (!\is_string($_SERVER[$variable] ?? getenv($variable)) || '' === ($_SERVER[$variable] ?? getenv($variable))) {
                self::markTestSkipped(\sprintf('Set %s to run this test.', $variable));
            }
        }

        self::bootKernel(['config' => ['integration.yaml']]);
        $translator = self::getContainer()->get('html_translator.translator.' . $entry);
        self::assertInstanceOf(HtmlTranslator::class, $translator);

        $document = $translator->translate('<p>Hello, world!</p>', Locale::fromString('de'), new TranslationOptions(source: Locale::fromString('en')));

        self::assertTrue($document->isComplete());
        self::assertStringStartsWith('<p>', $document->html());
        self::assertNotSame('<p>Hello, world!</p>', $document->html());
    }
}
