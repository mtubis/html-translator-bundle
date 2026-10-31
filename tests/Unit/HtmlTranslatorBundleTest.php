<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit;

use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(HtmlTranslatorBundle::class)]
final class HtmlTranslatorBundleTest extends TestCase
{
    #[Test]
    public function it_loads_the_static_service_definitions(): void
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = new HtmlTranslatorBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([], $builder);

        $resources = array_map(
            static fn(FileResource $resource): string => $resource->getResource(),
            array_values(array_filter($builder->getResources(), static fn(object $resource): bool => $resource instanceof FileResource)),
        );

        self::assertContains(\dirname(__DIR__, 2) . '/config/services.php', $resources);
    }
}
