<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\DataCollector;

use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Provider\TranslationProviderInterface;
use Throwable;

/**
 * Times a batch on the inner provider and records it, also when it fails.
 *
 * @internal
 */
trait TracesBatches
{
    public function __construct(
        private readonly TranslationProviderInterface $inner,
        private readonly string $entry,
        private readonly HtmlTranslatorDataCollector $collector,
    ) {}

    public function translate(TranslationBatch $batch): BatchResult
    {
        $started = hrtime(true);

        try {
            $result = $this->inner->translate($batch);
        } catch (Throwable $error) {
            $this->collector->record($this->entry, $batch, null, (hrtime(true) - $started) / 1e6, $error);

            throw $error;
        }

        $this->collector->record($this->entry, $batch, $result, (hrtime(true) - $started) / 1e6);

        return $result;
    }
}
