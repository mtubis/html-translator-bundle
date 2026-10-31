<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Html;

use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;

/**
 * The core HTML rules from the `html` section: the core defaults plus the configured additions.
 *
 * @internal used by the html_translator.skip_rules and html_translator.translatable_attributes services
 */
final class HtmlRulesFactory
{
    private const string PREFIX = 'html_translator.html.';

    /**
     * @param array<array-key, mixed> $html the processed `html` section
     *
     * @throws InvalidConfigurationException when the core rejects a name
     */
    public static function skipRules(array $html): SkipRules
    {
        $rules = SkipRules::defaults();

        foreach (self::names($html, 'skip_elements') as $element) {
            $rules = self::guard('skip_elements', static fn(): SkipRules => $rules->withElement($element));
        }

        foreach (self::names($html, 'skip_classes') as $class) {
            $rules = self::guard('skip_classes', static fn(): SkipRules => $rules->withClass($class));
        }

        return $rules;
    }

    /**
     * @param array<array-key, mixed> $html the processed `html` section
     *
     * @throws InvalidConfigurationException when the core rejects a name
     */
    public static function translatableAttributes(array $html): TranslatableAttributes
    {
        $attributes = TranslatableAttributes::defaults();

        foreach (self::names($html, 'translatable_attributes') as $attribute) {
            $attributes = self::guard('translatable_attributes', static fn(): TranslatableAttributes => $attributes->withAttribute($attribute));
        }

        return $attributes;
    }

    /**
     * @param array<array-key, mixed> $html
     *
     * @return list<string>
     */
    private static function names(array $html, string $key): array
    {
        $names = $html[$key] ?? [];

        return array_values(array_map(strval(...), array_filter(\is_array($names) ? $names : [], is_scalar(...))));
    }

    /**
     * @template T
     *
     * @param callable(): T $build
     *
     * @return T
     */
    private static function guard(string $key, callable $build): mixed
    {
        try {
            return $build();
        } catch (InvalidArgumentException $exception) {
            throw InvalidConfigurationException::forPath(self::PREFIX . $key, $exception->getMessage(), $exception);
        }
    }
}
