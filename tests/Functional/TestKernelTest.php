<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\MonologBundle\MonologBundle;

// Guards the test infrastructure itself; TestKernel is not production code, so there is nothing to cover.
#[CoversNothing]
final class TestKernelTest extends FunctionalTestCase
{
    #[Test]
    public function it_uses_a_separate_cache_dir_per_config_scenario(): void
    {
        self::assertNotSame(
            new TestKernel()->getCacheDir(),
            new TestKernel(['empty.yaml'])->getCacheDir(),
        );
    }

    #[Test]
    public function it_uses_a_separate_cache_dir_per_bundle_scenario(): void
    {
        self::assertNotSame(
            new TestKernel()->getCacheDir(),
            new TestKernel([], [MonologBundle::class])->getCacheDir(),
        );
    }

    #[Test]
    public function it_keeps_the_generated_config_reference_out_of_the_fixtures(): void
    {
        self::bootKernel();

        self::assertFileDoesNotExist(__DIR__ . '/config/reference.php');
    }
}
