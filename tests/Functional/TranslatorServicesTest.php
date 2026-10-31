<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional;

use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\DependencyInjection\ProviderDefinitions;
use HtmlTranslator\Bundle\DependencyInjection\TranslatorDefinitions;
use HtmlTranslator\Bundle\Drivers;
use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\Bundle\Html\HtmlRulesFactory;
use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use HtmlTranslator\Bundle\Options\TranslationOptionsFactory;
use HtmlTranslator\Bundle\Profile\ProfileFactory;
use HtmlTranslator\Bundle\Profile\ProfileRegistry;
use HtmlTranslator\Bundle\Tests\Functional\Fixtures\TranslatorConsumer;
use HtmlTranslator\Bundle\TranslatorRegistry;
use HtmlTranslator\HtmlTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DependencyInjection\Container;

#[CoversClass(HtmlTranslatorBundle::class)]
#[CoversClass(TranslatorDefinitions::class)]
#[CoversClass(TranslatorRegistry::class)]
#[UsesClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
#[UsesClass(ProviderDefinitions::class)]
#[UsesClass(HtmlRulesFactory::class)]
#[UsesClass(InvalidConfigurationException::class)]
#[UsesClass(ProfileRegistry::class)]
#[UsesClass(ProfileFactory::class)]
#[UsesClass(TranslationOptionsFactory::class)]
final class TranslatorServicesTest extends FunctionalTestCase
{
    #[Test]
    public function it_aliases_the_translator_of_the_default_provider(): void
    {
        self::bootKernel(['config' => ['translators.yaml']]);

        self::assertSame(self::getContainer()->get('html_translator.translator.pseudo'), self::getContainer()->get(HtmlTranslator::class));
        self::assertNotSame(self::getContainer()->get('html_translator.translator.pseudo'), self::scenarioService('html_translator.translator.second'));
    }

    #[Test]
    public function it_autowires_translators_by_argument_name_and_target(): void
    {
        self::bootKernel(['config' => ['translators.yaml']]);
        $consumer = self::scenarioService('test.translator_consumer');
        self::assertInstanceOf(TranslatorConsumer::class, $consumer);

        $second = self::scenarioService('html_translator.translator.second');
        self::assertSame(self::getContainer()->get(HtmlTranslator::class), $consumer->default);
        self::assertSame($second, $consumer->secondTranslator);
        self::assertSame($second, $consumer->byTarget);
    }

    #[Test]
    public function it_finds_translators_by_name_in_the_registry(): void
    {
        self::bootKernel(['config' => ['translators.yaml']]);
        $registry = self::getContainer()->get(TranslatorRegistry::class);

        self::assertSame(['pseudo', 'second'], $registry->names());
        self::assertSame('pseudo', $registry->defaultName());
        self::assertTrue($registry->has('second'));
        self::assertFalse($registry->has('openai'));
        self::assertSame(self::scenarioService('html_translator.translator.second'), $registry->get('second'));
        self::assertSame(self::getContainer()->get(HtmlTranslator::class), $registry->get());
    }

    #[Test]
    public function it_builds_profiles_and_options_from_the_configuration(): void
    {
        self::bootKernel(['config' => ['translators.yaml']]);
        $profiles = self::getContainer()->get(ProfileRegistry::class);
        $options = self::getContainer()->get(TranslationOptionsFactory::class)->create();

        self::assertSame(['docs', 'default'], $profiles->names());
        self::assertSame('docs', $profiles->defaultName());
        self::assertSame($profiles->get('docs'), $options->profile);
        self::assertSame(7, $options->batchSize);
    }

    #[Test]
    public function it_names_the_defined_providers_for_an_unknown_name(): void
    {
        self::bootKernel(['config' => ['translators.yaml']]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('html_translator.providers.openai: is not defined; defined: pseudo, second');

        self::getContainer()->get(TranslatorRegistry::class)->get('openai');
    }

    #[Test]
    public function it_registers_the_documented_public_services_only(): void
    {
        // The real container, not the test one: has() and getServiceIds() see public services only.
        $container = self::bootKernel(['config' => ['translators.yaml']])->getContainer();
        self::assertInstanceOf(Container::class, $container);

        $public = array_values(array_filter(
            $container->getServiceIds(),
            static fn(string $id): bool => str_starts_with($id, 'html_translator.') || str_starts_with($id, 'HtmlTranslator\\'),
        ));
        sort($public);

        self::assertSame([
            TranslationOptionsFactory::class,
            ProfileRegistry::class,
            TranslatorRegistry::class,
            HtmlTranslator::class,
            'html_translator.translator.pseudo',
            'html_translator.translator.second',
        ], $public);
        self::assertFalse($container->has('html_translator.provider.pseudo'));
    }
}
