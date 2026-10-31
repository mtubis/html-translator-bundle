<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Profile;

use Closure;
use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Profile\Formality;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\TermPolicy;
use HtmlTranslator\Profile\TranslationProfile;

/**
 * A core TranslationProfile from a processed `profiles.<name>` entry: the preset, then every key that is set.
 *
 * @internal used by ProfileRegistry
 */
final class ProfileFactory
{
    private const string PREFIX = 'html_translator.profiles.';

    /**
     * @param array<array-key, mixed> $settings the processed entry; enum values are checked by the configuration tree
     *
     * @throws InvalidConfigurationException when the core rejects a value
     */
    public static function fromArray(string $name, array $settings): TranslationProfile
    {
        $profile = match ($settings['preset'] ?? 'default') {
            'technical' => TranslationProfile::technical(),
            'marketing' => TranslationProfile::marketing(),
            'legal' => TranslationProfile::legal(),
            default => TranslationProfile::default(),
        };

        $guard = static fn(string $key, Closure $build): TranslationProfile => self::guard($name . '.' . $key, $build);

        if (\is_string($settings['formality'] ?? null)) {
            $profile = $profile->withFormality(Formality::from($settings['formality']));
        }

        if (\is_string($settings['term_policy'] ?? null)) {
            $profile = $profile->withTermPolicy(TermPolicy::from($settings['term_policy']));
        }

        if (\is_string($domain = $settings['domain'] ?? null)) {
            $profile = $guard('domain', static fn(): TranslationProfile => $profile->withDomain($domain));
        }

        $glossary = self::strings($settings['glossary'] ?? []);
        if ([] !== $glossary) {
            $profile = $guard('glossary', static fn(): TranslationProfile => $profile->withGlossary(Glossary::fromArray($glossary)));
        }

        $doNotTranslate = array_values(self::strings($settings['do_not_translate'] ?? []));
        if ([] !== $doNotTranslate) {
            $profile = $guard('do_not_translate', static fn(): TranslationProfile => $profile->withDoNotTranslate(...$doNotTranslate));
        }

        if (\is_string($instructions = $settings['instructions'] ?? null)) {
            $profile = $guard('instructions', static fn(): TranslationProfile => $profile->withInstructions($instructions));
        }

        return $profile;
    }

    /**
     * @param Closure(): TranslationProfile $build
     */
    private static function guard(string $path, Closure $build): TranslationProfile
    {
        try {
            return $build();
        } catch (InvalidArgumentException $exception) {
            throw InvalidConfigurationException::forPath(self::PREFIX . $path, $exception->getMessage(), $exception);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function strings(mixed $values): array
    {
        $strings = [];
        foreach (\is_array($values) ? $values : [] as $key => $value) {
            if (\is_scalar($value)) {
                $strings[(string) $key] = (string) $value;
            }
        }

        return $strings;
    }
}
