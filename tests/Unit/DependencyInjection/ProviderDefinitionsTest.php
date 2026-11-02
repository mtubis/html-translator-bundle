<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit\DependencyInjection;

use HtmlTranslator\Bundle\ChatModel\ChatModelFactory;
use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\DependencyInjection\ProviderDefinitions;
use HtmlTranslator\Bundle\Drivers;
use HtmlTranslator\Bundle\Http\HttpClientFactory;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\Bridge\RetryPolicy;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\Psr18Client;

#[CoversClass(ProviderDefinitions::class)]
#[UsesClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
final class ProviderDefinitionsTest extends TestCase
{
    #[Test]
    public function it_defines_the_http_stack_from_the_http_section(): void
    {
        $builder = $this->register(['http' => ['timeout' => 30, 'max_duration' => 300, 'retry' => ['max_attempts' => 5, 'base_delay_ms' => 10, 'max_delay_ms' => 20, 'retry_network_errors' => false]]]);

        $http = $builder->getDefinition('html_translator.http_client');
        self::assertSame([HttpClientFactory::class, 'create'], $http->getFactory());
        $base = $http->getArgument(0);
        self::assertInstanceOf(Reference::class, $base);
        self::assertSame('http_client', (string) $base);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $base->getInvalidBehavior());
        self::assertSame([30, 300], [$http->getArgument(1), $http->getArgument(2)]);

        self::assertSame(Psr18Client::class, $builder->getDefinition('html_translator.psr18_client')->getClass());
        self::assertEquals([new Reference('html_translator.http_client')], $builder->getDefinition('html_translator.psr18_client')->getArguments());

        $retrier = $builder->getDefinition('html_translator.retrier');
        self::assertSame(Retrier::class, $retrier->getClass());
        $policy = $retrier->getArgument(0);
        self::assertInstanceOf(Definition::class, $policy);
        self::assertSame(RetryPolicy::class, $policy->getClass());
        self::assertSame(['$maxAttempts' => 5, '$baseDelayMilliseconds' => 10, '$maxDelayMilliseconds' => 20, '$retryNetworkErrors' => false], $policy->getArguments());

        $factory = $builder->getDefinition('html_translator.chat_model_factory');
        self::assertSame(ChatModelFactory::class, $factory->getClass());
        self::assertEquals([
            new Reference('html_translator.psr18_client'),
            new Reference('html_translator.psr18_client'),
            new Reference('html_translator.psr18_client'),
            new Reference('html_translator.retrier'),
        ], $factory->getArguments());
    }

    #[Test]
    public function it_uses_the_configured_http_client_as_is(): void
    {
        $builder = $this->register(['http' => ['client' => 'app.llm_http_client']]);

        self::assertFalse($builder->hasDefinition('html_translator.http_client'));
        self::assertSame('app.llm_http_client', (string) $builder->getAlias('html_translator.http_client'));
    }

    #[Test]
    public function it_defines_a_chat_model_and_a_provider_per_llm_entry(): void
    {
        $builder = $this->register(['default_provider' => 'cheap', 'providers' => ['cheap' => ['driver' => 'openai', 'api_key' => '%env(OPENAI_API_KEY)%', 'model' => 'gpt-test']]]);

        $chatModel = $builder->getDefinition('html_translator.chat_model.cheap');
        self::assertSame(ChatModelInterface::class, $chatModel->getClass());
        self::assertEquals([new Reference('html_translator.chat_model_factory'), 'create'], $chatModel->getFactory());
        self::assertSame('cheap', $chatModel->getArgument(0));
        self::assertSame('openai', $chatModel->getArgument(1));
        // The entry is passed as written: env placeholders are resolved when the service is created (ADR-0004).
        self::assertSame(['driver' => 'openai', 'api_key' => '%env(OPENAI_API_KEY)%', 'model' => 'gpt-test', 'extra_headers' => [], 'betas' => []], $chatModel->getArgument(2));
        self::assertFalse($chatModel->isPublic());

        $provider = $builder->getDefinition('html_translator.provider.cheap');
        self::assertSame(LlmTranslationProvider::class, $provider->getClass());
        self::assertEquals([new Reference('html_translator.chat_model.cheap')], $provider->getArguments());
        self::assertFalse($provider->isPublic());
    }

    #[Test]
    public function it_defines_a_pseudo_provider_without_a_chat_model(): void
    {
        $builder = $this->register(['providers' => ['pseudo' => ['driver' => 'pseudo', 'corrupt_every' => 3, 'corrupt_retries' => true], 'plain' => ['driver' => 'pseudo']]]);

        self::assertFalse($builder->hasDefinition('html_translator.chat_model.pseudo'));
        $provider = $builder->getDefinition('html_translator.provider.pseudo');
        self::assertSame(PseudoLocalizationProvider::class, $provider->getClass());
        self::assertSame(['$corruptEvery' => 3, '$corruptRetries' => true], $provider->getArguments());
        self::assertSame(['$corruptEvery' => 0, '$corruptRetries' => false], $builder->getDefinition('html_translator.provider.plain')->getArguments());
    }

    /**
     * @param array<string, mixed> $override
     */
    private function register(array $override): ContainerBuilder
    {
        $treeBuilder = new TreeBuilder('html_translator');
        ConfigurationTree::define($treeBuilder->getRootNode());
        $config = new Processor()->process($treeBuilder->buildTree(), [$override + ['default_provider' => 'pseudo', 'providers' => ['pseudo' => ['driver' => 'pseudo']]]]);

        $builder = new ContainerBuilder();
        ProviderDefinitions::register($config, $builder);

        return $builder;
    }
}
