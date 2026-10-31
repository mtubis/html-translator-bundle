<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\DataCollector;

use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\TranslationBatch;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The translation batches of a request, recorded by the traceable providers (ADR-0006). Unit texts are kept as
 * truncated plain text; the template escapes them.
 *
 * @internal registered only with the Web Profiler in kernel.debug
 */
final class HtmlTranslatorDataCollector extends AbstractDataCollector
{
    public const string NAME = 'html_translator';

    public const string TEMPLATE = '@HtmlTranslator/Collector/html_translator.html.twig';

    /** Characters of a unit text kept for the panel. */
    public const int PREVIEW_LENGTH = 120;

    public function getName(): string
    {
        return self::NAME;
    }

    public static function getTemplate(): string
    {
        return self::TEMPLATE;
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): void
    {
        // The batches arrive through record() while the request runs.
    }

    public function record(string $entry, TranslationBatch $batch, ?BatchResult $result, float $durationMs, ?Throwable $error = null): void
    {
        $preview = [];
        foreach ($batch->units as $unit) {
            $id = $unit->id->toString();
            $translation = $result?->translations[$id] ?? null;
            $failure = $result?->failures[$id] ?? $result?->permanentFailures[$id] ?? null;
            $preview[] = new UnitPreview(
                $id,
                $this->truncate($unit->text),
                null === $translation ? null : $this->truncate($translation),
                null === $failure ? null : $this->truncate($failure),
            );
        }

        $batches = $this->batches();
        $batches[] = new BatchRecord(
            entry: $entry,
            source: $batch->locales->source?->toString() ?? 'auto',
            target: $batch->locales->target->toString(),
            units: \count($batch->units),
            corrections: \count($batch->corrections),
            failures: \count($result->failures ?? []),
            permanentFailures: \count($result->permanentFailures ?? []),
            inputTokens: $result?->usage?->inputTokens,
            outputTokens: $result?->usage?->outputTokens,
            durationMs: round($durationMs, 1),
            error: $error instanceof Throwable ? $error::class . ': ' . $this->truncate($error->getMessage()) : null,
            preview: $preview,
        );
        $this->data['batches'] = $batches;
    }

    /**
     * @return list<BatchRecord>
     */
    public function batches(): array
    {
        $batches = $this->data['batches'] ?? [];

        return array_values(array_filter(\is_array($batches) ? $batches : [], static fn(mixed $batch): bool => $batch instanceof BatchRecord));
    }

    /**
     * @return array{batches: int, units: int, corrections: int, failures: int, inputTokens: int, outputTokens: int, durationMs: float, errors: int}
     */
    public function totals(): array
    {
        $totals = ['batches' => 0, 'units' => 0, 'corrections' => 0, 'failures' => 0, 'inputTokens' => 0, 'outputTokens' => 0, 'durationMs' => 0.0, 'errors' => 0];
        foreach ($this->batches() as $batch) {
            ++$totals['batches'];
            $totals['units'] += $batch->units;
            $totals['corrections'] += $batch->corrections;
            $totals['failures'] += $batch->failures + $batch->permanentFailures;
            $totals['inputTokens'] += $batch->inputTokens ?? 0;
            $totals['outputTokens'] += $batch->outputTokens ?? 0;
            $totals['durationMs'] += $batch->durationMs;
            $totals['errors'] += null === $batch->error ? 0 : 1;
        }

        return $totals;
    }

    private function truncate(string $text): string
    {
        return mb_strlen($text) > self::PREVIEW_LENGTH ? mb_substr($text, 0, self::PREVIEW_LENGTH - 1) . '…' : $text;
    }
}
