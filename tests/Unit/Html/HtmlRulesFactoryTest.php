<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit\Html;

use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\Bundle\Html\HtmlRulesFactory;
use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlRulesFactory::class)]
#[UsesClass(InvalidConfigurationException::class)]
final class HtmlRulesFactoryTest extends TestCase
{
    private const array NONE = ['skip_elements' => [], 'skip_classes' => [], 'translatable_attributes' => []];

    #[Test]
    public function it_starts_from_the_core_defaults(): void
    {
        self::assertEquals(SkipRules::defaults(), HtmlRulesFactory::skipRules(self::NONE));
        self::assertEquals(TranslatableAttributes::defaults(), HtmlRulesFactory::translatableAttributes(self::NONE));
    }

    #[Test]
    public function it_adds_the_configured_names(): void
    {
        $html = ['skip_elements' => ['address', 'kbd'], 'skip_classes' => ['brand', 'sku'], 'translatable_attributes' => ['data-tooltip', 'data-label']];

        self::assertEquals(SkipRules::defaults()->withElement('address')->withElement('kbd')->withClass('brand')->withClass('sku'), HtmlRulesFactory::skipRules($html));
        self::assertEquals(TranslatableAttributes::defaults()->withAttribute('data-tooltip')->withAttribute('data-label'), HtmlRulesFactory::translatableAttributes($html));
    }

    /**
     * @return iterable<string, array{array<string, list<string>>, string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'element' => [['skip_elements' => ['<b>']] + self::NONE, 'html_translator.html.skip_elements: '];
        yield 'class' => [['skip_classes' => ['.brand']] + self::NONE, 'html_translator.html.skip_classes: '];
        yield 'attribute' => [['translatable_attributes' => ['data tooltip']] + self::NONE, 'html_translator.html.translatable_attributes: '];
    }

    /**
     * @param array<string, list<string>> $html
     */
    #[Test]
    #[DataProvider('invalidNames')]
    public function it_reports_an_invalid_name_with_its_path(array $html, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        HtmlRulesFactory::skipRules($html);
        HtmlRulesFactory::translatableAttributes($html);
    }
}
