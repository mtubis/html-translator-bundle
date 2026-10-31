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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Makes every change of the configuration tree visible in review. After an intended change, regenerate the snapshot
 * with UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter ConfigDumpReferenceTest and commit it.
 */
#[CoversClass(HtmlTranslatorBundle::class)]
#[CoversClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
#[UsesClass(ProviderDefinitions::class)]
final class ConfigDumpReferenceTest extends FunctionalTestCase
{
    private const string SNAPSHOT = __DIR__ . '/snapshots/config_reference.yaml';

    #[Test]
    public function it_dumps_the_reference_of_the_committed_snapshot(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('config:dump-reference'));
        $tester->execute(['name' => 'html_translator']);
        $tester->assertCommandIsSuccessful();
        $dump = $tester->getDisplay(true);

        if ('1' === getenv('UPDATE_SNAPSHOTS')) {
            file_put_contents(self::SNAPSHOT, $dump);
        }

        self::assertStringEqualsFile(self::SNAPSHOT, $dump);
    }
}
