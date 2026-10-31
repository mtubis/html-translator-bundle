<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Options;

use Closure;
use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\Bundle\Profile\ProfileRegistry;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Progress;
use HtmlTranslator\TranslationOptions;

/**
 * Core TranslationOptions from the `options` defaults plus per-call overrides.
 *
 * Overrides come from code, so an invalid one fails with the core's own exception; an invalid configured value is
 * reported with its config path.
 */
final readonly class TranslationOptionsFactory
{
    /**
     * FallbackStrategy is a pure enum: the configuration names (ConfigurationTree::FALLBACKS) are mapped explicitly.
     *
     * @internal public for the translate command and the test that keeps it in step with the configuration tree
     */
    public const array FALLBACKS = [
        'keep_original' => FallbackStrategy::KeepOriginal,
        'text_nodes_only' => FallbackStrategy::TextNodesOnly,
        'throw' => FallbackStrategy::Throw,
    ];

    /**
     * @param array{source: ?string, fallback: string, max_corrective_retries: int, batch_size: int, token_budget: int} $defaults the processed `options` section
     */
    public function __construct(
        private ProfileRegistry $profiles,
        private array $defaults,
    ) {}

    /**
     * @param string|TranslationProfile|null $profile an entry of `profiles`, a core profile, or null for `default_profile`
     * @param Locale|string|null $source null = `options.source` (null there: the model detects the language)
     * @param (Closure(Progress): void)|null $onProgress called after each batch
     *
     * @throws InvalidConfigurationException when the profile is not defined or a configured value is invalid
     * @throws InvalidArgumentException when an override is invalid
     */
    public function create(
        string|TranslationProfile|null $profile = null,
        Locale|string|null $source = null,
        ?FallbackStrategy $fallback = null,
        ?int $maxCorrectiveRetries = null,
        ?int $batchSize = null,
        ?int $tokenBudget = null,
        ?Closure $onProgress = null,
    ): TranslationOptions {
        return new TranslationOptions(
            source: \is_string($source) ? Locale::fromString($source) : ($source ?? $this->configuredSource()),
            fallback: $fallback ?? self::FALLBACKS[$this->defaults['fallback']] ?? FallbackStrategy::KeepOriginal,
            maxCorrectiveRetries: $maxCorrectiveRetries ?? $this->defaults['max_corrective_retries'],
            batchSize: $batchSize ?? $this->defaults['batch_size'],
            onProgress: $onProgress,
            profile: $profile instanceof TranslationProfile ? $profile : $this->profiles->get($profile),
            tokenBudget: $tokenBudget ?? $this->defaults['token_budget'],
        );
    }

    private function configuredSource(): ?Locale
    {
        $source = $this->defaults['source'];
        if (null === $source) {
            return null;
        }

        try {
            return Locale::fromString($source);
        } catch (InvalidArgumentException $exception) {
            throw InvalidConfigurationException::forPath('html_translator.options.source', $exception->getMessage(), $exception);
        }
    }
}
