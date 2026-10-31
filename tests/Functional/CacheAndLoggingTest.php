<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional;

use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\DependencyInjection\ProviderDefinitions;
use HtmlTranslator\Bundle\DependencyInjection\TranslatorDefinitions;
use HtmlTranslator\Bundle\Drivers;
use HtmlTranslator\Bundle\Html\HtmlRulesFactory;
use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use HtmlTranslator\Bundle\Tests\Functional\Fixtures\RecordingLogger;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\TranslatedDocument;
use HtmlTranslator\TranslationOptions;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(HtmlTranslatorBundle::class)]
#[CoversClass(TranslatorDefinitions::class)]
#[UsesClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
#[UsesClass(ProviderDefinitions::class)]
#[UsesClass(HtmlRulesFactory::class)]
final class CacheAndLoggingTest extends FunctionalTestCase
{
    private const string HTML = '<p>Hello</p><p>World</p>';

    #[Test]
    public function it_serves_a_repeated_translation_from_the_unit_cache(): void
    {
        self::bootKernel();
        // cache.app keeps its files in the kernel cache dir, which outlives the test run.
        $pool = self::scenarioService('cache.html_translator');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();

        $first = $this->translate();
        $second = $this->translate();

        self::assertSame(0, $first->stats()->cacheHits);
        self::assertSame(2, $second->stats()->units);
        self::assertSame($second->stats()->units, $second->stats()->cacheHits);
        self::assertSame($first->html(), $second->html());
    }

    #[Test]
    public function it_translates_uncached_when_the_cache_is_disabled(): void
    {
        self::bootKernel(['config' => ['pseudo.yaml', 'cache_disabled.yaml']]);

        $this->translate();

        self::assertSame(0, $this->translate()->stats()->cacheHits);
    }

    #[Test]
    public function it_stores_units_in_the_configured_pool(): void
    {
        self::bootKernel(['config' => ['pseudo.yaml', 'cache_pool.yaml']]);
        $pool = self::scenarioService('test.array_pool');
        self::assertInstanceOf(ArrayAdapter::class, $pool);

        $this->translate();

        self::assertCount(2, $pool->getValues());
        self::assertSame(2, $this->translate()->stats()->cacheHits);
    }

    #[Test]
    public function it_logs_to_the_logger_service_without_monolog(): void
    {
        self::bootKernel(['config' => ['logging.yaml', 'logger_service.yaml']]);

        $this->translate();

        $logger = self::scenarioService('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertNotSame([], $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
    }

    #[Test]
    public function it_logs_nothing_when_logging_is_disabled(): void
    {
        self::bootKernel(['config' => ['logging.yaml', 'logger_service.yaml', 'logging_disabled.yaml']]);

        $this->translate();

        $logger = self::scenarioService('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertSame([], $logger->records);
    }

    #[Test]
    public function it_logs_to_its_own_monolog_channel(): void
    {
        self::bootKernel(['config' => ['logging.yaml', 'monolog.yaml'], 'bundles' => [MonologBundle::class]]);

        $this->translate();

        $handler = self::scenarioService('monolog.handler.test');
        self::assertInstanceOf(TestHandler::class, $handler);
        $channels = array_unique(array_map(static fn(LogRecord $record): string => $record->channel, $handler->getRecords()));
        self::assertSame(['html_translator'], array_values($channels));
    }

    private function translate(): TranslatedDocument
    {
        $translator = self::getContainer()->get(HtmlTranslator::class);

        return $translator->translate(self::HTML, Locale::fromString('pl'), new TranslationOptions(source: Locale::fromString('en'), maxCorrectiveRetries: 0));
    }
}
