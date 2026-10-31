<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Documentation;

use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\Tests\Functional\FunctionalTestCase;
use HtmlTranslator\Bundle\TranslatorRegistry;
use HtmlTranslator\HtmlTranslator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs the README, so the documentation cannot rot. A PHP block after `<!-- runnable: Class::method -->` is an
 * application class: it is loaded in its own namespace, built with the container's autowiring rules (#[Target], the
 * type) in a kernel whose providers are all offline, and the method is called with a sample page. Every YAML block
 * with html_translator configuration is processed by the configuration tree.
 */
#[CoversNothing]
final class ReadmeSnippetsTest extends FunctionalTestCase
{
    private const string README = __DIR__ . '/../../README.md';

    private const string HTML = '<h1>Getting started</h1><p>Install the <strong>bundle</strong> first.</p>';

    /** The providers every YAML block is merged with, so a block may show one section only. */
    private const array BASE = ['default_provider' => 'openai', 'providers' => ['openai' => ['driver' => 'pseudo']]];

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function phpSnippets(): iterable
    {
        preg_match_all('/^<!-- runnable: (\w+)::(\w+) -->\n```php\n(.*?)^```$/ms', self::readme(), $matches, \PREG_SET_ORDER);
        foreach ($matches as [, $class, $method, $code]) {
            yield $class . '::' . $method => [$class, $method, $code];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function yamlSnippets(): iterable
    {
        preg_match_all('/^```yaml\n(.*?)^```$/ms', self::readme(), $matches);
        foreach ($matches[1] as $index => $yaml) {
            if (str_contains($yaml, 'html_translator:')) {
                yield \sprintf('#%d %s', $index + 1, strtok($yaml, "\n")) => [$yaml];
            }
        }
    }

    #[Test]
    public function it_finds_a_php_block_after_every_runnable_marker(): void
    {
        $markers = substr_count(self::readme(), '<!-- runnable');

        self::assertSame(3, $markers);
        self::assertCount($markers, iterator_to_array(self::phpSnippets()));
        self::assertCount(3, iterator_to_array(self::yamlSnippets()));
    }

    #[Test]
    #[DataProvider('phpSnippets')]
    public function it_runs_a_php_snippet(string $class, string $method, string $code): void
    {
        $namespace = 'HtmlTranslator\Bundle\Tests\Documentation\Readme\\' . $class;
        $file = (string) tempnam(sys_get_temp_dir(), 'readme-snippet');
        file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . $namespace . ";\n\n" . $code);

        try {
            require_once $file;
        } finally {
            unlink($file);
        }

        self::bootKernel(['config' => ['readme.yaml']]);
        $fqcn = $namespace . '\\' . $class;
        self::assertTrue(class_exists($fqcn));
        $object = new ReflectionClass($fqcn)->newInstanceArgs(array_map($this->resolve(...), new ReflectionClass($fqcn)->getConstructor()?->getParameters() ?? []));

        $result = new ReflectionMethod($object, $method)->invoke($object, self::HTML);

        self::assertIsString($result);
        self::assertStringContainsString('<h1>', $result);
    }

    #[Test]
    #[DataProvider('yamlSnippets')]
    public function it_processes_a_yaml_snippet(string $yaml): void
    {
        $parsed = Yaml::parse($yaml);
        self::assertIsArray($parsed);
        $config = $parsed['html_translator'] ?? $this->section($parsed, 'when@test');
        self::assertIsArray($config);

        $treeBuilder = new TreeBuilder('html_translator');
        ConfigurationTree::define($treeBuilder->getRootNode());
        $processed = new Processor()->process($treeBuilder->buildTree(), [self::BASE, $config]);

        self::assertArrayHasKey('providers', $processed);
    }

    /**
     * Autowiring as the container does it for an application service: #[Target] picks the named translator, any
     * other HtmlTranslator is the default one, and other types are public services of the bundle.
     */
    private function resolve(ReflectionParameter $parameter): object
    {
        $type = $parameter->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        $translators = self::getContainer()->get(TranslatorRegistry::class);

        if (HtmlTranslator::class === $type->getName()) {
            $target = $parameter->getAttributes(Target::class)[0] ?? null;

            return $translators->get($target?->newInstance()->name);
        }

        return self::scenarioService($type->getName());
    }

    /**
     * @param array<array-key, mixed> $parsed
     */
    private function section(array $parsed, string $key): mixed
    {
        $section = $parsed[$key] ?? null;

        return \is_array($section) ? $section['html_translator'] ?? null : null;
    }

    private static function readme(): string
    {
        return (string) file_get_contents(self::README);
    }
}
