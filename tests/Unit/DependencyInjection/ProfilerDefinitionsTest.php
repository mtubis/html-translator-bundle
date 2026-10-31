<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit\DependencyInjection;

use HtmlTranslator\Bundle\DataCollector\HtmlTranslatorDataCollector;
use HtmlTranslator\Bundle\DataCollector\TraceableProviderFactory;
use HtmlTranslator\Bundle\DependencyInjection\ProfilerDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(ProfilerDefinitions::class)]
final class ProfilerDefinitionsTest extends TestCase
{
    private const array CONFIG = ['providers' => ['pseudo' => ['driver' => 'pseudo']], 'profiler' => ['enabled' => '%kernel.debug%']];

    #[Test]
    public function it_registers_the_collector_and_a_decorator_per_provider(): void
    {
        $builder = $this->builder();

        ProfilerDefinitions::register(self::CONFIG, $builder);

        $collector = $builder->getDefinition(ProfilerDefinitions::COLLECTOR);
        self::assertSame(HtmlTranslatorDataCollector::class, $collector->getClass());
        self::assertSame([['template' => HtmlTranslatorDataCollector::TEMPLATE, 'id' => HtmlTranslatorDataCollector::NAME]], $collector->getTag('data_collector'));
        self::assertSame([['method' => 'reset']], $collector->getTag('kernel.reset'));

        $traceable = $builder->getDefinition('html_translator.provider.pseudo.traceable');
        self::assertSame([TraceableProviderFactory::class, 'decorate'], $traceable->getFactory());
        self::assertSame(['html_translator.provider.pseudo', null, 0], $traceable->getDecoratedService());
        self::assertEquals([new Reference('.inner'), 'pseudo', new Reference(ProfilerDefinitions::COLLECTOR)], $traceable->getArguments());
    }

    /**
     * @return iterable<string, array{bool, list<class-string>, array<string, mixed>}>
     */
    public static function inactiveSetups(): iterable
    {
        yield 'no kernel.debug' => [false, [FrameworkBundle::class, WebProfilerBundle::class], self::CONFIG];
        yield 'no WebProfilerBundle' => [true, [FrameworkBundle::class], self::CONFIG];
        yield 'disabled' => [true, [FrameworkBundle::class, WebProfilerBundle::class], ['profiler' => ['enabled' => false]] + self::CONFIG];
    }

    /**
     * @param list<class-string> $bundles
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('inactiveSetups')]
    public function it_registers_nothing_when_the_panel_is_inactive(bool $debug, array $bundles, array $config): void
    {
        $builder = $this->builder($debug, $bundles);

        ProfilerDefinitions::register($config, $builder);

        self::assertFalse($builder->hasDefinition(ProfilerDefinitions::COLLECTOR));
        self::assertFalse($builder->hasDefinition('html_translator.provider.pseudo.traceable'));
    }

    #[Test]
    public function it_rejects_an_env_variable_for_enabled(): void
    {
        $builder = $this->builder();
        $placeholder = $builder->getParameterBag()->get('env(bool:PROFILE_TRANSLATIONS)');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid configuration for path "html_translator.profiler.enabled": expected true, false or a parameter such as %kernel.debug%; env variables are not supported.');

        ProfilerDefinitions::register(['profiler' => ['enabled' => $placeholder]] + self::CONFIG, $builder);
    }

    /**
     * @param list<class-string> $bundles
     */
    private function builder(bool $debug = true, array $bundles = [FrameworkBundle::class, WebProfilerBundle::class]): ContainerBuilder
    {
        $names = array_map(static fn(string $class): string => substr($class, (int) strrpos($class, '\\') + 1), $bundles);
        $builder = new ContainerBuilder(new EnvPlaceholderParameterBag(['kernel.debug' => $debug, 'kernel.bundles' => array_combine($names, $bundles)]));
        $builder->register('html_translator.provider.pseudo');

        return $builder;
    }
}
