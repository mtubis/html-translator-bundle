<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\DataCollector;

/**
 * A translation batch as the profiler shows it.
 *
 * @internal
 */
final readonly class BatchRecord
{
    /**
     * @param string $source the source locale, or "auto" when the model detects it
     * @param list<UnitPreview> $preview
     */
    public function __construct(
        public string $entry,
        public string $source,
        public string $target,
        public int $units,
        public int $corrections,
        public int $failures,
        public int $permanentFailures,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public float $durationMs,
        public ?string $error,
        public array $preview,
    ) {}
}
