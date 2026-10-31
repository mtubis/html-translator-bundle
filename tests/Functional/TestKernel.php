<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional;

use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Minimal application kernel for functional tests (ADR-0007).
 *
 * Each scenario passes its own config fixtures (tests/Functional/config/*.yaml) and extra bundles; the cache
 * directory is derived from them, so scenarios never share a compiled container.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param list<string> $configFiles paths relative to tests/Functional/config
     * @param list<class-string<BundleInterface>> $extraBundles e.g. MonologBundle, WebProfilerBundle
     */
    public function __construct(
        private readonly array $configFiles = [],
        private readonly array $extraBundles = [],
        string $environment = 'test',
        bool $debug = true,
    ) {
        parent::__construct($environment, $debug);
    }

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new HtmlTranslatorBundle();

        foreach ($this->extraBundles as $bundle) {
            yield new $bundle();
        }
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return __DIR__ . '/var/cache/' . $this->scenarioHash();
    }

    public function getLogDir(): string
    {
        return __DIR__ . '/var/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'test' => true,
            'secret' => 'test',
        ]);

        foreach ($this->configFiles as $file) {
            $loader->load(__DIR__ . '/config/' . $file);
        }
    }

    private function scenarioHash(): string
    {
        return substr(hash('xxh128', serialize([$this->environment, $this->debug, $this->configFiles, $this->extraBundles])), 0, 12);
    }
}
