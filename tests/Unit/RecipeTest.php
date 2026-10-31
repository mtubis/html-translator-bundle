<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit;

use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;

/**
 * The Flex recipe drafted for symfony/recipes-contrib (docs/recipe/README.md) stays valid for the bundle.
 */
#[CoversNothing]
final class RecipeTest extends TestCase
{
    private const string RECIPE = __DIR__ . '/../../docs/recipe/mtubis/html-translator-bundle/0.1';

    /** The manifest keys this contrib recipe uses (post-install output comes from post-install.txt). */
    private const array MANIFEST_KEYS = ['bundles', 'copy-from-recipe', 'env'];

    #[Test]
    public function it_uses_only_known_manifest_keys(): void
    {
        $manifest = $this->manifest();

        self::assertSame([], array_diff(array_keys($manifest), self::MANIFEST_KEYS));
        self::assertSame([HtmlTranslatorBundle::class => ['all']], $manifest['bundles'] ?? null);
        self::assertSame(['config/' => '%CONFIG_DIR%/'], $manifest['copy-from-recipe'] ?? null);
    }

    #[Test]
    public function it_sets_every_env_variable_the_configuration_reads(): void
    {
        $env = $this->manifest()['env'] ?? null;
        self::assertIsArray($env);
        // Commented-out examples (e.g. anthropic) are not read.
        $active = (string) preg_replace('/^\s*#.*$/m', '', $this->yaml());
        preg_match_all('/%env\((?:\w+:)?(\w+)\)%/', $active, $matches);

        self::assertSame([], array_diff(array_unique($matches[1]), array_keys($env)));
    }

    #[Test]
    public function it_configures_the_bundle_validly_in_every_environment(): void
    {
        $parsed = Yaml::parse($this->yaml());
        self::assertIsArray($parsed);
        $prod = $parsed['html_translator'] ?? null;
        $test = $parsed['when@test'] ?? null;
        self::assertIsArray($prod);
        self::assertIsArray($test);
        self::assertIsArray($test['html_translator'] ?? null);

        self::assertSame('openai', $this->process([$prod])['default_provider'] ?? null);
        $processed = $this->process([$prod, $test['html_translator']]);
        $providers = $processed['providers'] ?? null;
        self::assertIsArray($providers);
        self::assertSame(['driver' => 'pseudo', 'extra_headers' => [], 'betas' => []], $providers['openai'] ?? null);
    }

    #[Test]
    public function it_ships_a_post_install_note(): void
    {
        self::assertStringContainsString('html-translator:check', (string) file_get_contents(self::RECIPE . '/post-install.txt'));
    }

    /**
     * @param list<array<array-key, mixed>> $configs
     *
     * @return array<array-key, mixed>
     */
    private function process(array $configs): array
    {
        $treeBuilder = new TreeBuilder('html_translator');
        ConfigurationTree::define($treeBuilder->getRootNode());

        return new Processor()->process($treeBuilder->buildTree(), $configs);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function manifest(): array
    {
        $manifest = json_decode((string) file_get_contents(self::RECIPE . '/manifest.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }

    private function yaml(): string
    {
        return (string) file_get_contents(self::RECIPE . '/config/packages/html_translator.yaml');
    }
}
