<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\DataCollector;

use HtmlTranslator\Provider\CacheableProviderInterface;
use HtmlTranslator\Provider\TranslationProviderInterface;

/**
 * Wraps a provider for the profiler, exactly as cacheable as the provider itself: the core skips the unit cache for
 * providers that are not. Decided on the object, so app overrides and other decorators are seen as they are.
 *
 * @internal used by the html_translator.provider.<name>.traceable services
 */
final class TraceableProviderFactory
{
    public static function decorate(TranslationProviderInterface $inner, string $entry, HtmlTranslatorDataCollector $collector): TranslationProviderInterface
    {
        return $inner instanceof CacheableProviderInterface
            ? new CacheableTraceableTranslationProvider($inner, $entry, $collector)
            : new TraceableTranslationProvider($inner, $entry, $collector);
    }
}
