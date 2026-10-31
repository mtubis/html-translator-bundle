<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\DataCollector;

use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Provider\CacheableProviderInterface;

/**
 * Records every batch for the profiler (ADR-0006) and keeps the inner provider's cache id, so the unit cache works
 * as without the profiler.
 *
 * @internal
 */
final class CacheableTraceableTranslationProvider implements CacheableProviderInterface
{
    use TracesBatches;

    public function cacheId(): string
    {
        if (!$this->inner instanceof CacheableProviderInterface) {
            // TraceableProviderFactory picks this class only for cacheable providers.
            throw new LogicException(\sprintf('%s is not cacheable.', $this->inner::class));
        }

        return $this->inner->cacheId();
    }
}
