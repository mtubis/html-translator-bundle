<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit\Profile;

use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\Bundle\Profile\ProfileFactory;
use HtmlTranslator\Profile\Formality;
use HtmlTranslator\Profile\GlossaryEntry;
use HtmlTranslator\Profile\TermPolicy;
use HtmlTranslator\Profile\TranslationProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfileFactory::class)]
#[UsesClass(InvalidConfigurationException::class)]
final class ProfileFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, TranslationProfile}>
     */
    public static function presets(): iterable
    {
        yield 'default' => ['default', TranslationProfile::default()];
        yield 'technical' => ['technical', TranslationProfile::technical()];
        yield 'marketing' => ['marketing', TranslationProfile::marketing()];
        yield 'legal' => ['legal', TranslationProfile::legal()];
    }

    #[Test]
    #[DataProvider('presets')]
    public function it_starts_from_the_preset(string $preset, TranslationProfile $expected): void
    {
        $profile = ProfileFactory::fromArray('p', ['preset' => $preset, 'glossary' => [], 'do_not_translate' => []]);

        self::assertSame($expected->fingerprint(), $profile->fingerprint());
    }

    #[Test]
    public function it_overrides_the_preset_with_every_key(): void
    {
        $profile = ProfileFactory::fromArray('docs', [
            'preset' => 'technical',
            'formality' => 'informal',
            'term_policy' => 'keep_original',
            'domain' => 'PHP framework documentation',
            'glossary' => ['pull request' => 'Pull Request', 'merge' => 'zusammenführen'],
            'do_not_translate' => ['Symfony', 'Doctrine'],
            'instructions' => 'Use the informal "du".',
        ]);

        self::assertSame(Formality::Informal, $profile->formality);
        self::assertSame(TermPolicy::KeepOriginal, $profile->termPolicy);
        self::assertSame('PHP framework documentation', $profile->domain);
        self::assertSame(
            [['pull request', 'Pull Request'], ['merge', 'zusammenführen']],
            array_map(static fn(GlossaryEntry $entry): array => [$entry->source, $entry->target], $profile->glossary->entries()),
        );
        self::assertSame(['Symfony', 'Doctrine'], $profile->doNotTranslate);
        self::assertSame('Use the informal "du".', $profile->instructions);
    }

    #[Test]
    public function it_keeps_the_preset_values_that_are_not_set(): void
    {
        $profile = ProfileFactory::fromArray('p', ['preset' => 'legal', 'domain' => null, 'instructions' => null, 'glossary' => [], 'do_not_translate' => []]);

        self::assertSame(TranslationProfile::legal()->fingerprint(), $profile->fingerprint());
    }

    #[Test]
    public function it_defaults_to_the_default_preset(): void
    {
        self::assertSame(TranslationProfile::default()->fingerprint(), ProfileFactory::fromArray('p', [])->fingerprint());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function entriesTheCoreRejects(): iterable
    {
        yield 'term in glossary and do_not_translate' => [['glossary' => ['Symfony' => 'Symfony'], 'do_not_translate' => ['Symfony']], 'html_translator.profiles.p.do_not_translate: '];
        yield 'domain with a line break' => [['domain' => "a\nb"], 'html_translator.profiles.p.domain: '];
        yield 'blank instructions' => [['instructions' => ' '], 'html_translator.profiles.p.instructions: '];
        yield 'blank glossary term' => [['glossary' => [' ' => 'x']], 'html_translator.profiles.p.glossary: '];
        // A YAML list instead of a map ("glossary: [pull request, branch]"); core 1.0 rejects it.
        yield 'glossary as a list' => [['glossary' => ['pull request', 'branch']], 'html_translator.profiles.p.glossary: terms must map source terms to target terms, got a list.'];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('entriesTheCoreRejects')]
    public function it_reports_a_core_error_with_the_key_path(array $entry, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        ProfileFactory::fromArray('p', $entry);
    }
}
