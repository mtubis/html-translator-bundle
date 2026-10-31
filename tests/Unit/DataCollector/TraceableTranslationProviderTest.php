<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit\DataCollector;

use HtmlTranslator\Bundle\DataCollector\BatchRecord;
use HtmlTranslator\Bundle\DataCollector\CacheableTraceableTranslationProvider;
use HtmlTranslator\Bundle\DataCollector\HtmlTranslatorDataCollector;
use HtmlTranslator\Bundle\DataCollector\TraceableTranslationProvider;
use HtmlTranslator\Bundle\DataCollector\UnitPreview;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\CacheableProviderInterface;
use HtmlTranslator\Provider\Correction;
use HtmlTranslator\Provider\TokenUsage;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Provider\TranslationProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(TraceableTranslationProvider::class)]
#[CoversClass(CacheableTraceableTranslationProvider::class)]
#[CoversClass(HtmlTranslatorDataCollector::class)]
#[CoversClass(BatchRecord::class)]
#[CoversClass(UnitPreview::class)]
final class TraceableTranslationProviderTest extends TestCase
{
    #[Test]
    public function it_records_a_batch_and_returns_the_inner_result(): void
    {
        $collector = new HtmlTranslatorDataCollector();
        $result = new BatchResult(['u1' => 'Hallo'], ['u2' => 'missing'], ['u3' => 'refused'], new TokenUsage(120, 30));
        $traceable = new TraceableTranslationProvider($this->provider($result), 'openai', $collector);

        self::assertSame($result, $traceable->translate($this->batch(Locale::fromString('en'), corrections: ['u1' => new Correction('Hi', '- Keep <g1>.')])));

        $batch = $this->onlyBatch($collector);
        self::assertSame(['openai', 'en', 'de', 3, 1, 1, 1, 120, 30, null], [$batch->entry, $batch->source, $batch->target, $batch->units, $batch->corrections, $batch->failures, $batch->permanentFailures, $batch->inputTokens, $batch->outputTokens, $batch->error]);
        self::assertGreaterThanOrEqual(0.0, $batch->durationMs);
        self::assertEquals([
            new UnitPreview('u1', 'Hello', 'Hallo', null),
            new UnitPreview('u2', 'World', null, 'missing'),
            new UnitPreview('u3', 'Bye', null, 'refused'),
        ], $batch->preview);
    }

    #[Test]
    public function it_records_and_rethrows_a_failing_batch(): void
    {
        $collector = new HtmlTranslatorDataCollector();
        $error = new ProviderException('The provider returned HTTP 429.');
        $traceable = new TraceableTranslationProvider($this->failingProvider($error), 'openai', $collector);

        try {
            $traceable->translate($this->batch());
            self::fail('The error should be rethrown.');
        } catch (ProviderException $exception) {
            self::assertSame($error, $exception);
        }

        $batch = $this->onlyBatch($collector);
        self::assertSame(ProviderException::class . ': The provider returned HTTP 429.', $batch->error);
        self::assertSame(['auto', 0, null], [$batch->source, $batch->failures, $this->firstUnit($batch)->translation]);
        self::assertSame(1, $collector->totals()['errors']);
    }

    #[Test]
    public function it_keeps_the_cacheability_of_the_inner_provider(): void
    {
        $inner = new class implements CacheableProviderInterface {
            public function translate(TranslationBatch $batch): BatchResult
            {
                return new BatchResult([]);
            }

            public function cacheId(): string
            {
                return 'inner-cache-id';
            }
        };

        self::assertSame('inner-cache-id', new CacheableTraceableTranslationProvider($inner, 'p', new HtmlTranslatorDataCollector())->cacheId());
        self::assertFalse(new ReflectionClass(TraceableTranslationProvider::class)->implementsInterface(CacheableProviderInterface::class));
    }

    #[Test]
    public function it_refuses_a_cache_id_for_a_provider_that_has_none(): void
    {
        $this->expectException(LogicException::class);

        new CacheableTraceableTranslationProvider($this->provider(new BatchResult([])), 'p', new HtmlTranslatorDataCollector())->cacheId();
    }

    #[Test]
    public function it_truncates_long_unit_texts(): void
    {
        $collector = new HtmlTranslatorDataCollector();
        $long = str_repeat('ä', 200);
        $batch = new TranslationBatch(LocalePair::of(null, Locale::fromString('de')), [TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, $long)]);

        new TraceableTranslationProvider($this->provider(new BatchResult(['u1' => $long])), 'p', $collector)->translate($batch);

        $preview = $this->firstUnit($this->onlyBatch($collector));
        self::assertSame(str_repeat('ä', 119) . '…', $preview->source);
        self::assertSame(str_repeat('ä', 119) . '…', $preview->translation);
    }

    #[Test]
    public function it_keeps_a_text_of_exactly_the_preview_length(): void
    {
        $collector = new HtmlTranslatorDataCollector();
        $text = str_repeat('a', HtmlTranslatorDataCollector::PREVIEW_LENGTH);
        $batch = new TranslationBatch(LocalePair::of(null, Locale::fromString('de')), [TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, $text)]);

        new TraceableTranslationProvider($this->provider(new BatchResult(['u1' => 'x'])), 'p', $collector)->translate($batch);

        self::assertSame($text, $this->firstUnit($this->onlyBatch($collector))->source);
    }

    #[Test]
    public function it_rounds_the_duration_and_adds_it_up(): void
    {
        $collector = new HtmlTranslatorDataCollector();
        $batch = $this->batch();
        $collector->record('p', $batch, new BatchResult([]), 1.26);
        $collector->record('p', $batch, new BatchResult([]), 2.44);

        self::assertSame([1.3, 2.4], array_map(static fn(BatchRecord $record): float => $record->durationMs, $collector->batches()));
        self::assertEqualsWithDelta(3.7, $collector->totals()['durationMs'], 0.0001);
    }

    #[Test]
    public function it_adds_up_corrections_and_errors_of_every_batch(): void
    {
        $collector = new HtmlTranslatorDataCollector();
        $collector->record('p', $this->batch(corrections: ['u1' => Correction::forMissingUnit()]), null, 1.0, new ProviderException('a'));
        $collector->record('p', $this->batch(corrections: ['u2' => Correction::forMissingUnit()]), null, 1.0, new ProviderException('b'));

        self::assertSame([2, 2], [$collector->totals()['corrections'], $collector->totals()['errors']]);
    }

    #[Test]
    public function it_measures_the_inner_call_in_milliseconds(): void
    {
        $collector = new HtmlTranslatorDataCollector();
        $slow = new readonly class implements TranslationProviderInterface {
            public function translate(TranslationBatch $batch): BatchResult
            {
                usleep(20_000);

                return new BatchResult([]);
            }
        };

        new TraceableTranslationProvider($slow, 'p', $collector)->translate($this->batch());

        self::assertGreaterThanOrEqual(19.0, $this->onlyBatch($collector)->durationMs);
        self::assertLessThan(2000.0, $this->onlyBatch($collector)->durationMs);
    }

    #[Test]
    public function it_measures_a_failing_call_in_milliseconds(): void
    {
        $collector = new HtmlTranslatorDataCollector();

        try {
            new TraceableTranslationProvider($this->failingProvider(new ProviderException('x')), 'p', $collector)->translate($this->batch());
        } catch (ProviderException) {
        }

        self::assertGreaterThanOrEqual(0.0, $this->onlyBatch($collector)->durationMs);
        self::assertLessThan(2000.0, $this->onlyBatch($collector)->durationMs);
    }

    #[Test]
    public function it_counts_characters_not_bytes_when_truncating(): void
    {
        $collector = new HtmlTranslatorDataCollector();
        $text = str_repeat('ä', 100);
        $batch = new TranslationBatch(LocalePair::of(null, Locale::fromString('de')), [TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, $text)]);

        new TraceableTranslationProvider($this->provider(new BatchResult(['u1' => $text])), 'p', $collector)->translate($batch);

        self::assertSame($text, $this->firstUnit($this->onlyBatch($collector))->source);
    }

    #[Test]
    public function it_adds_up_the_totals_and_resets(): void
    {
        $collector = new HtmlTranslatorDataCollector();
        $traceable = new TraceableTranslationProvider($this->provider(new BatchResult(['u1' => 'a'], ['u2' => 'x'], ['u3' => 'y'], new TokenUsage(10, 5))), 'p', $collector);
        $traceable->translate($this->batch());
        $traceable->translate($this->batch(corrections: ['u2' => Correction::forMissingUnit()]));
        $collector->collect(new Request(), new Response());

        self::assertSame(['batches' => 2, 'units' => 6, 'corrections' => 1, 'failures' => 4, 'inputTokens' => 20, 'outputTokens' => 10, 'errors' => 0], array_diff_key($collector->totals(), ['durationMs' => true]));
        self::assertSame(0.0, new HtmlTranslatorDataCollector()->totals()['durationMs']);
        $restored = unserialize(serialize($collector));
        self::assertInstanceOf(HtmlTranslatorDataCollector::class, $restored);
        self::assertEquals($collector->batches(), $restored->batches());
        self::assertSame('html_translator', $collector->getName());
        self::assertSame('@HtmlTranslator/Collector/html_translator.html.twig', HtmlTranslatorDataCollector::getTemplate());

        $collector->reset();

        self::assertSame([], $collector->batches());
        self::assertSame(0, $collector->totals()['batches']);
    }

    private function onlyBatch(HtmlTranslatorDataCollector $collector): BatchRecord
    {
        $batches = $collector->batches();
        self::assertCount(1, $batches);
        self::assertArrayHasKey(0, $batches);

        return $batches[0];
    }

    private function firstUnit(BatchRecord $batch): UnitPreview
    {
        self::assertArrayHasKey(0, $batch->preview);

        return $batch->preview[0];
    }

    /**
     * @param array<string, Correction> $corrections
     */
    private function batch(?Locale $source = null, array $corrections = []): TranslationBatch
    {
        $units = [];
        foreach (['u1' => 'Hello', 'u2' => 'World', 'u3' => 'Bye'] as $id => $text) {
            $units[] = TranslationUnit::fromText(UnitId::fromString($id), UnitKind::Block, $text);
        }

        return new TranslationBatch(LocalePair::of($source, Locale::fromString('de')), $units, $corrections);
    }

    private function provider(BatchResult $result): TranslationProviderInterface
    {
        return new readonly class ($result) implements TranslationProviderInterface {
            public function __construct(private BatchResult $result) {}

            public function translate(TranslationBatch $batch): BatchResult
            {
                return $this->result;
            }
        };
    }

    private function failingProvider(RuntimeException $error): TranslationProviderInterface
    {
        return new readonly class ($error) implements TranslationProviderInterface {
            public function __construct(private RuntimeException $error) {}

            public function translate(TranslationBatch $batch): BatchResult
            {
                throw $this->error;
            }
        };
    }
}
