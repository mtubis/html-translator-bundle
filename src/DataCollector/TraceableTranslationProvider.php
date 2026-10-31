<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\DataCollector;

use HtmlTranslator\Provider\TranslationProviderInterface;

/**
 * Records every batch for the profiler (ADR-0006). For a provider that is not cacheable: it must not become cacheable.
 *
 * @internal
 */
final class TraceableTranslationProvider implements TranslationProviderInterface
{
    use TracesBatches;
}
