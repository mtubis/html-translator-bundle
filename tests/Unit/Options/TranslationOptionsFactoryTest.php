<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit\Options;

use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\Bundle\Options\TranslationOptionsFactory;
use HtmlTranslator\Bundle\Profile\ProfileFactory;
use HtmlTranslator\Bundle\Profile\ProfileRegistry;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Progress;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationOptionsFactory::class)]
#[UsesClass(ProfileRegistry::class)]
#[UsesClass(ProfileFactory::class)]
#[UsesClass(InvalidConfigurationException::class)]
final class TranslationOptionsFactoryTest extends TestCase
{
    private const array DEFAULTS = ['source' => null, 'fallback' => 'keep_original', 'max_corrective_retries' => 2, 'batch_size' => 20, 'token_budget' => 2000];

    private ProfileRegistry $profiles;

    #[Override]
    protected function setUp(): void
    {
        $this->profiles = new ProfileRegistry(['default' => ['preset' => 'default'], 'docs' => ['preset' => 'technical']], 'default');
    }

    #[Test]
    public function it_applies_the_configured_defaults(): void
    {
        $options = $this->factory(source: 'en', fallback: 'throw', maxCorrectiveRetries: 1, batchSize: 5, tokenBudget: 300)->create();

        self::assertSame('en', $options->source?->toString());
        self::assertSame(FallbackStrategy::Throw, $options->fallback);
        self::assertSame([1, 5, 300], [$options->maxCorrectiveRetries, $options->batchSize, $options->tokenBudget]);
        self::assertNull($options->onProgress);
        self::assertSame(TranslationProfile::default()->fingerprint(), $options->profile->fingerprint());
    }

    #[Test]
    public function it_lets_every_override_win(): void
    {
        $onProgress = static function (Progress $progress): void {};
        $profile = TranslationProfile::marketing();

        $options = $this->factory(source: 'en')->create(
            profile: $profile,
            source: 'de',
            fallback: FallbackStrategy::TextNodesOnly,
            maxCorrectiveRetries: 0,
            batchSize: 3,
            tokenBudget: 99,
            onProgress: $onProgress,
        );

        self::assertSame($profile, $options->profile);
        self::assertSame('de', $options->source?->toString());
        self::assertSame(FallbackStrategy::TextNodesOnly, $options->fallback);
        self::assertSame([0, 3, 99], [$options->maxCorrectiveRetries, $options->batchSize, $options->tokenBudget]);
        self::assertSame($onProgress, $options->onProgress);
    }

    #[Test]
    public function it_accepts_a_locale_object_as_source(): void
    {
        $source = Locale::fromString('fr');

        self::assertSame($source, $this->factory()->create(source: $source)->source);
    }

    #[Test]
    public function it_leaves_the_source_to_the_model_by_default(): void
    {
        self::assertNull($this->factory()->create()->source);
    }

    #[Test]
    public function it_finds_a_profile_by_entry_name(): void
    {
        $factory = $this->factory();

        self::assertSame($this->profiles->get('docs'), $factory->create(profile: 'docs')->profile);
        self::assertSame($this->profiles->get('default'), $factory->create()->profile);
    }

    #[Test]
    public function it_uses_the_configured_default_profile(): void
    {
        $this->profiles = new ProfileRegistry(['default' => ['preset' => 'default'], 'docs' => ['preset' => 'technical']], 'docs');

        self::assertSame($this->profiles->get('docs'), new TranslationOptionsFactory($this->profiles, self::DEFAULTS)->create()->profile);
    }

    /**
     * @return iterable<string, array{string, FallbackStrategy}>
     */
    public static function fallbacks(): iterable
    {
        yield 'keep_original' => ['keep_original', FallbackStrategy::KeepOriginal];
        yield 'text_nodes_only' => ['text_nodes_only', FallbackStrategy::TextNodesOnly];
        yield 'throw' => ['throw', FallbackStrategy::Throw];
    }

    #[Test]
    #[DataProvider('fallbacks')]
    public function it_maps_the_configured_fallback(string $name, FallbackStrategy $strategy): void
    {
        self::assertSame($strategy, $this->factory(fallback: $name)->create()->fallback);
    }

    #[Test]
    public function it_maps_every_fallback_the_configuration_allows(): void
    {
        self::assertSame(ConfigurationTree::FALLBACKS, array_keys(TranslationOptionsFactory::FALLBACKS));
    }

    #[Test]
    public function it_lists_the_defined_profiles_for_an_unknown_name(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('html_translator.profiles.legal: is not defined; defined: default, docs');

        $this->factory()->create(profile: 'legal');
    }

    #[Test]
    public function it_reports_an_invalid_configured_source_with_its_path(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('html_translator.options.source: ');

        $this->factory(source: 'not a locale')->create();
    }

    #[Test]
    public function it_leaves_an_invalid_override_to_the_core(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory()->create(batchSize: 0);
    }

    private function factory(?string $source = null, string $fallback = 'keep_original', int $maxCorrectiveRetries = 2, int $batchSize = 20, int $tokenBudget = 2000): TranslationOptionsFactory
    {
        return new TranslationOptionsFactory($this->profiles, [
            'source' => $source,
            'fallback' => $fallback,
            'max_corrective_retries' => $maxCorrectiveRetries,
            'batch_size' => $batchSize,
            'token_budget' => $tokenBudget,
        ]);
    }
}
