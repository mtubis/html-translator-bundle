<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit;

use HtmlTranslator\Bundle\ChatModel\ChatModelFactory;
use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\DependencyInjection\ProviderDefinitions;
use HtmlTranslator\Bundle\DependencyInjection\TranslatorDefinitions;
use HtmlTranslator\Bundle\Drivers;
use HtmlTranslator\Bundle\Html\HtmlRulesFactory;
use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use HtmlTranslator\Bundle\Http\HttpClientFactory;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

#[CoversClass(HtmlTranslatorBundle::class)]
#[UsesClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
#[UsesClass(HtmlRulesFactory::class)]
#[CoversClass(TranslatorDefinitions::class)]
#[UsesClass(HttpClientFactory::class)]
#[UsesClass(ChatModelFactory::class)]
#[UsesClass(ProviderDefinitions::class)]
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
    public function it_compiles_and_creates_a_provider_without_framework_bundle(): void
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = new HtmlTranslatorBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([['default_provider' => 'ollama', 'providers' => ['ollama' => ['driver' => 'ollama', 'model' => 'llama-test']]]], $builder);
        $builder->getDefinition(ProviderDefinitions::PROVIDER_PREFIX . 'ollama')->setPublic(true);
        $builder->compile();

        self::assertFalse($builder->has('http_client'));
        self::assertFalse($builder->has('cache.html_translator'));
        self::assertInstanceOf(LlmTranslationProvider::class, $this->publicService($builder, 'html_translator.provider.ollama'));
        // Uncached and without a logger: both references are optional.
        self::assertInstanceOf(HtmlTranslator::class, $this->publicService($builder, 'html_translator.translator.ollama'));
    }

    #[Test]
    public function it_requires_a_named_cache_pool_to_exist(): void
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = new HtmlTranslatorBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([['default_provider' => 'pseudo', 'providers' => ['pseudo' => ['driver' => 'pseudo']], 'cache' => ['pool' => 'missing.pool']]], $builder);

        $this->expectException(ServiceNotFoundException::class);
        $this->expectExceptionMessage('missing.pool');

        $builder->compile();
    }

    /**
     * Gets a service by an id PHPStan cannot check (the analysed container has no ollama entry).
     */
    private function publicService(ContainerBuilder $builder, string $id): object
    {
        return $builder->get($id);
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
