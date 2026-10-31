<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional;

use LogicException;
use Override;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Boots a TestKernel per scenario: self::bootKernel(['config' => ['pseudo.yaml'], 'bundles' => [MonologBundle::class]]).
 *
 * The parent signature takes a loose array, so the options are checked here instead of in a narrower PHPDoc shape.
 */
abstract class FunctionalTestCase extends KernelTestCase
{
    /**
     * @param array<array-key, mixed> $options "config": fixture files in tests/Functional/config; "bundles": extra bundle classes
     */
    #[Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel(self::configFiles($options), self::extraBundles($options));
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return list<string>
     */
    private static function configFiles(array $options): array
    {
        $files = [];

        foreach ((array) ($options['config'] ?? []) as $file) {
            if (!\is_string($file)) {
                throw new LogicException('Option "config" must list fixture file names.');
            }

            $files[] = $file;
        }

        return $files;
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return list<class-string<BundleInterface>>
     */
    private static function extraBundles(array $options): array
    {
        $bundles = [];

        foreach ((array) ($options['bundles'] ?? []) as $bundle) {
            if (!\is_string($bundle) || !is_subclass_of($bundle, BundleInterface::class)) {
                throw new LogicException('Option "bundles" must list bundle class names.');
            }

            $bundles[] = $bundle;
        }

        return $bundles;
    }
}
