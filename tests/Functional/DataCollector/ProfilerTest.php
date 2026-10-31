<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional\DataCollector;

use HtmlTranslator\Bundle\ChatModel\ChatModelFactory;
use HtmlTranslator\Bundle\DataCollector\BatchRecord;
use HtmlTranslator\Bundle\DataCollector\CacheableTraceableTranslationProvider;
use HtmlTranslator\Bundle\DataCollector\HtmlTranslatorDataCollector;
use HtmlTranslator\Bundle\DataCollector\TraceableTranslationProvider;
use HtmlTranslator\Bundle\DataCollector\UnitPreview;
use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\DependencyInjection\ProfilerDefinitions;
use HtmlTranslator\Bundle\DependencyInjection\ProviderDefinitions;
use HtmlTranslator\Bundle\DependencyInjection\TranslatorDefinitions;
use HtmlTranslator\Bundle\Drivers;
use HtmlTranslator\Bundle\Html\HtmlRulesFactory;
use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use HtmlTranslator\Bundle\Http\HttpClientFactory;
use HtmlTranslator\Bundle\Tests\Functional\FunctionalTestCase;
use HtmlTranslator\Bundle\Tests\Functional\TestKernel;
use HtmlTranslator\Bundle\TranslatorRegistry;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\Provider\TranslationBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\TerminableInterface;
use Twig\Environment;

#[CoversClass(ProfilerDefinitions::class)]
#[CoversClass(HtmlTranslatorDataCollector::class)]
#[CoversClass(TraceableTranslationProvider::class)]
#[CoversClass(CacheableTraceableTranslationProvider::class)]
#[CoversClass(HtmlTranslatorBundle::class)]
#[UsesClass(BatchRecord::class)]
#[UsesClass(UnitPreview::class)]
#[UsesClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
#[UsesClass(ProviderDefinitions::class)]
#[UsesClass(TranslatorDefinitions::class)]
#[UsesClass(HtmlRulesFactory::class)]
#[UsesClass(HttpClientFactory::class)]
#[UsesClass(ChatModelFactory::class)]
#[UsesClass(TranslatorRegistry::class)]
final class ProfilerTest extends FunctionalTestCase
{
    private const array BUNDLES = [TwigBundle::class, WebProfilerBundle::class];

    #[Test]
    public function it_collects_the_batches_of_a_request(): void
    {
        $collector = $this->profileRequest('/translate');

        $batches = $collector->batches();
        self::assertCount(1, $batches);
        self::assertArrayHasKey(0, $batches);
        $batch = $batches[0];
        self::assertSame('pseudo', $batch->entry);
        // The <script> element is never translated: two units.
        self::assertSame(['auto', 'de', 2, 0, 0], [$batch->source, $batch->target, $batch->units, $batch->corrections, $batch->failures]);
        self::assertArrayHasKey(0, $batch->preview);
        self::assertSame('Hello <g1>world</g1>', $batch->preview[0]->source);
        self::assertSame(['batches' => 1, 'units' => 2, 'corrections' => 0, 'failures' => 0, 'inputTokens' => 0, 'outputTokens' => 0], array_intersect_key($collector->totals(), array_flip(['batches', 'units', 'corrections', 'failures', 'inputTokens', 'outputTokens'])));
    }

    #[Test]
    public function it_shows_the_corrections_of_a_corrupting_provider(): void
    {
        $collector = $this->profileRequest('/translate?provider=broken');

        // The core finds the broken structure and sends corrective retries (2 by default), which stay broken.
        self::assertSame(3, $collector->totals()['batches']);
        self::assertSame([0, 2, 2], array_map(static fn(BatchRecord $batch): int => $batch->corrections, $collector->batches()));
        self::assertSame(['broken'], array_values(array_unique(array_map(static fn(BatchRecord $batch): string => $batch->entry, $collector->batches()))));
    }

    #[Test]
    public function it_keeps_the_unit_cache_working(): void
    {
        self::bootKernel(['config' => ['profiler.yaml', 'web_profiler.yaml'], 'bundles' => self::BUNDLES]);
        $pool = self::scenarioService('cache.html_translator');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        $translator = self::getContainer()->get(HtmlTranslator::class);

        $translator->translate('<p>Hello</p><p>World</p>', Locale::fromString('pl'));
        $second = $translator->translate('<p>Hello</p><p>World</p>', Locale::fromString('pl'));

        self::assertSame(2, $second->stats()->units);
        self::assertSame($second->stats()->units, $second->stats()->cacheHits);
    }

    #[Test]
    public function it_decorates_every_provider_as_cacheable_as_it_is(): void
    {
        self::bootKernel(['config' => ['profiler.yaml', 'web_profiler.yaml'], 'bundles' => self::BUNDLES]);

        self::assertInstanceOf(CacheableTraceableTranslationProvider::class, self::scenarioService('html_translator.provider.pseudo'));
        self::assertInstanceOf(CacheableTraceableTranslationProvider::class, self::scenarioService('html_translator.provider.ollama'));
    }

    #[Test]
    public function it_stays_out_without_the_web_profiler(): void
    {
        self::bootKernel(['config' => ['profiler.yaml'], 'bundles' => [TwigBundle::class]]);

        $this->assertNotTraced();
    }

    #[Test]
    public function it_stays_out_when_disabled(): void
    {
        self::bootKernel(['config' => ['profiler.yaml', 'web_profiler.yaml', 'profiler_disabled.yaml'], 'bundles' => self::BUNDLES]);

        $this->assertNotTraced();
    }

    #[Test]
    public function it_stays_out_without_kernel_debug(): void
    {
        $kernel = new TestKernel(['profiler.yaml', 'web_profiler.yaml'], self::BUNDLES, debug: false);
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            self::assertFalse($container->has(ProfilerDefinitions::COLLECTOR));
            self::assertInstanceOf(PseudoLocalizationProvider::class, $container->get('html_translator.provider.pseudo'));
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    public function it_renders_the_panel_with_escaped_unit_texts(): void
    {
        $collector = $this->profileRequest('/translate');
        // Core unit texts encode "<" already; a raw one proves the template escapes on its own.
        $script = TranslationUnit::fromText(UnitId::fromString('x1'), UnitKind::Block, '<script>alert(1)</script>');
        $collector->record('pseudo', new TranslationBatch(LocalePair::of(null, Locale::fromString('de')), [$script]), new BatchResult(['x1' => '<script>alert(2)</script>']), 1.0);
        $twig = self::scenarioService('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $template = $twig->load(HtmlTranslatorDataCollector::TEMPLATE);

        $panel = $template->renderBlock('panel', ['collector' => $collector]);
        $toolbar = $template->renderBlock('toolbar', ['collector' => $collector, 'profiler_url' => '/_profiler/x', 'profiler_markup_version' => 3, 'token' => 'x', 'name' => 'html_translator']);

        self::assertStringContainsString('<td>pseudo</td>', $panel);
        self::assertStringNotContainsString('<script>alert', $panel);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $panel);
        self::assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $panel);
        self::assertStringContainsString('batches', $toolbar);
    }

    private function profileRequest(string $uri): HtmlTranslatorDataCollector
    {
        $kernel = self::bootKernel(['config' => ['profiler.yaml', 'web_profiler.yaml'], 'bundles' => self::BUNDLES]);
        $pool = self::scenarioService('cache.html_translator');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();

        $request = Request::create($uri);
        $response = $kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        // The profile is saved on kernel.terminate.
        self::assertInstanceOf(TerminableInterface::class, $kernel);
        $kernel->terminate($request, $response);

        $profiler = self::scenarioService('profiler');
        self::assertInstanceOf(Profiler::class, $profiler);
        $profile = $profiler->loadProfileFromResponse($response);
        self::assertNotNull($profile);
        $collector = $profile->getCollector(HtmlTranslatorDataCollector::NAME);
        self::assertInstanceOf(HtmlTranslatorDataCollector::class, $collector);

        return $collector;
    }

    private function assertNotTraced(): void
    {
        self::assertFalse(self::getContainer()->has(ProfilerDefinitions::COLLECTOR));
        self::assertInstanceOf(PseudoLocalizationProvider::class, self::scenarioService('html_translator.provider.pseudo'));
        self::assertInstanceOf(LlmTranslationProvider::class, self::scenarioService('html_translator.provider.ollama'));
    }
}
