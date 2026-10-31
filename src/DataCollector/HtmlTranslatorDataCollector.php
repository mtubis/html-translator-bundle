<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\DataCollector;

use HtmlTranslator\Exception\TranslatorException;
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

    /** Characters of an error message kept for the panel; core messages put the status and vendor error last. */
    public const int ERROR_LENGTH = 500;

    /** Batches kept with all details; later ones (long console runs, workers) only count towards the totals. */
    public const int MAX_BATCHES = 200;

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

        $record = new BatchRecord(
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
            error: $error instanceof Throwable ? $this->describe($error) : null,
            preview: $preview,
        );

        $batches = $this->batches();
        if (\count($batches) < self::MAX_BATCHES) {
            $batches[] = $record;
            $this->data['batches'] = $batches;

            return;
        }

        // Only the numbers of the batches beyond the limit, so memory and the profile stay bounded.
        $this->data['omitted'] = $this->add($this->omitted(), $record);
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
     * Batches beyond MAX_BATCHES, which the panel does not list.
     */
    public function omittedBatches(): int
    {
        return $this->omitted()['batches'];
    }

    /**
     * Over all batches of the request, the omitted ones included.
     *
     * @return array{batches: int, units: int, corrections: int, failures: int, inputTokens: int, outputTokens: int, durationMs: float, errors: int}
     */
    public function totals(): array
    {
        $totals = $this->omitted();
        foreach ($this->batches() as $batch) {
            $totals = $this->add($totals, $batch);
        }

        return $totals;
    }

    /**
     * @param array{batches: int, units: int, corrections: int, failures: int, inputTokens: int, outputTokens: int, durationMs: float, errors: int} $sum
     *
     * @return array{batches: int, units: int, corrections: int, failures: int, inputTokens: int, outputTokens: int, durationMs: float, errors: int}
     */
    private function add(array $sum, BatchRecord $batch): array
    {
        return [
            'batches' => $sum['batches'] + 1,
            'units' => $sum['units'] + $batch->units,
            'corrections' => $sum['corrections'] + $batch->corrections,
            'failures' => $sum['failures'] + $batch->failures + $batch->permanentFailures,
            'inputTokens' => $sum['inputTokens'] + ($batch->inputTokens ?? 0),
            'outputTokens' => $sum['outputTokens'] + ($batch->outputTokens ?? 0),
            'durationMs' => $sum['durationMs'] + $batch->durationMs,
            'errors' => $sum['errors'] + (null === $batch->error ? 0 : 1),
        ];
    }

    /**
     * The totals of the batches beyond MAX_BATCHES (zero while there are none).
     *
     * @return array{batches: int, units: int, corrections: int, failures: int, inputTokens: int, outputTokens: int, durationMs: float, errors: int}
     */
    private function omitted(): array
    {
        $stored = $this->data['omitted'] ?? [];
        $stored = \is_array($stored) ? $stored : [];
        $int = static fn(string $key): int => \is_int($stored[$key] ?? null) ? $stored[$key] : 0;

        return [
            'batches' => $int('batches'),
            'units' => $int('units'),
            'corrections' => $int('corrections'),
            'failures' => $int('failures'),
            'inputTokens' => $int('inputTokens'),
            'outputTokens' => $int('outputTokens'),
            'durationMs' => \is_float($stored['durationMs'] ?? null) ? $stored['durationMs'] : 0.0,
            'errors' => $int('errors'),
        ];
    }

    /**
     * Messages of the core's exceptions are redacted (no keys, no header values); others may carry request details.
     */
    private function describe(Throwable $error): string
    {
        if (!$error instanceof TranslatorException) {
            return $error::class;
        }

        return $error::class . ': ' . $this->truncate($error->getMessage(), self::ERROR_LENGTH);
    }

    private function truncate(string $text, int $length = self::PREVIEW_LENGTH): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }
}
