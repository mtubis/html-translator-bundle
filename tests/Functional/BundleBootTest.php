<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional;

use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

#[CoversClass(HtmlTranslatorBundle::class)]
final class BundleBootTest extends KernelTestCase
{
    /**
     * @param array{config?: list<string>, bundles?: list<class-string<\Symfony\Component\HttpKernel\Bundle\BundleInterface>>} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['config'] ?? [], $options['bundles'] ?? []);
    }

    #[Test]
    public function it_boots_with_the_bundle_registered(): void
    {
        $kernel = self::bootKernel();

        self::assertArrayHasKey('HtmlTranslatorBundle', $kernel->getBundles());
    }

    #[Test]
    public function it_registers_the_html_translator_extension(): void
    {
        $extension = (new HtmlTranslatorBundle())->getContainerExtension();

        self::assertNotNull($extension);
        self::assertSame('html_translator', $extension->getAlias());
    }

    #[Test]
    public function it_accepts_an_empty_configuration(): void
    {
        $kernel = self::bootKernel(['config' => ['empty.yaml']]);

        self::assertTrue($kernel->getContainer()->has('kernel'));
    }
}
