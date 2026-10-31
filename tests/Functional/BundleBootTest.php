<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional;

use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\DependencyInjection\ProviderDefinitions;
use HtmlTranslator\Bundle\Drivers;
use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

#[CoversClass(HtmlTranslatorBundle::class)]
#[UsesClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
#[UsesClass(ProviderDefinitions::class)]
final class BundleBootTest extends FunctionalTestCase
{
    #[Test]
    public function it_boots_with_the_bundle_registered(): void
    {
        $kernel = self::bootKernel();

        self::assertArrayHasKey('HtmlTranslatorBundle', $kernel->getBundles());
    }

    #[Test]
    public function it_registers_the_html_translator_extension(): void
    {
        $extension = self::bootKernel()->getBundle('HtmlTranslatorBundle')->getContainerExtension();

        self::assertNotNull($extension);
        self::assertSame('html_translator', $extension->getAlias());
    }

    #[Test]
    public function it_rejects_a_configuration_without_providers(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The child config "providers" under "html_translator" must be configured');

        self::bootKernel(['config' => ['empty.yaml']]);
    }
}
