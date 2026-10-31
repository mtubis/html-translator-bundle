<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit;

use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\Drivers;
use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(HtmlTranslatorBundle::class)]
#[UsesClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
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
        $extension->load([['default_provider' => 'pseudo', 'providers' => ['pseudo' => ['driver' => 'pseudo']]]], $builder);

        $resources = array_map(
            static fn(FileResource $resource): string => $resource->getResource(),
            array_values(array_filter($builder->getResources(), static fn(object $resource): bool => $resource instanceof FileResource)),
        );

        self::assertContains(\dirname(__DIR__, 2) . '/config/services.php', $resources);
    }

    #[Test]
    public function it_validates_the_configuration_with_the_configuration_tree(): void
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = new HtmlTranslatorBundle()->getContainerExtension();
        self::assertNotNull($extension);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The child config "providers" under "html_translator" must be configured');

        $extension->load([], $builder);
    }
}
