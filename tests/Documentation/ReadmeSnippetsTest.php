<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Documentation;

use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\Tests\Functional\FunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs the README, so the documentation cannot rot.
 *
 * - A PHP block after `<!-- runnable: Class::method -->` is an application class: it is registered as an autowired
 *   service of a kernel whose providers are all offline (tests/Functional/config/readme.yaml), fetched from the
 *   container and the method is called with a sample page.
 * - Every YAML block with html_translator configuration (also under when@test) goes through the configuration tree.
 * - Every option of the html-translator commands shown in a bash block exists.
 */
#[CoversNothing]
final class ReadmeSnippetsTest extends FunctionalTestCase
{
    private const string README = __DIR__ . '/../../README.md';

    private const string NAMESPACE = 'HtmlTranslator\Bundle\Tests\Documentation\Readme';

    private const string SNIPPETS = __DIR__ . '/../Functional/var/readme-snippets';

    private const string HTML = '<h1>Getting started</h1><p>Install the <strong>bundle</strong> first.</p>';

    /** The providers every YAML block is merged with, so a block may show one section only. */
    private const array BASE = ['default_provider' => 'openai', 'providers' => ['openai' => ['driver' => 'pseudo']]];

    #[Override]
    public static function setUpBeforeClass(): void
    {
        // The container finds the classes through the resource directory, and PHP through these files.
        new Filesystem()->remove(self::SNIPPETS);
        foreach (self::phpSnippets() as [$class, , $code]) {
            $file = self::SNIPPETS . '/' . $class . '.php';
            new Filesystem()->dumpFile($file, "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . self::NAMESPACE . ";\n\n" . $code);
            if (!class_exists(self::NAMESPACE . '\\' . $class, false)) {
                require $file;
            }
        }
    }

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
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function yamlSnippets(): iterable
    {
        preg_match_all('/^```yaml\n(.*?)^```$/ms', self::readme(), $matches);
        foreach ($matches[1] as $index => $yaml) {
            $parsed = Yaml::parse($yaml);
            if (!\is_array($parsed)) {
                continue;
            }

            $test = $parsed['when@test'] ?? null;
            foreach (['' => $parsed['html_translator'] ?? null, ' when@test' => \is_array($test) ? $test['html_translator'] ?? null : null] as $scope => $config) {
                if (\is_array($config)) {
                    yield \sprintf('#%d%s', $index + 1, $scope) => [$config];
                }
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commandOptions(): iterable
    {
        preg_match_all('/^```bash\n(.*?)^```$/ms', self::readme(), $blocks);
        // Joined continuation lines, then every "html-translator:<name> …" call with its long options.
        preg_match_all('/html-translator:([\w-]+)([^\n|>]*)/', str_replace("\\\n", ' ', implode("\n", $blocks[1])), $calls, \PREG_SET_ORDER);
        $cases = [];
        foreach ($calls as [, $command, $arguments]) {
            preg_match_all('/--([\w-]+)/', $arguments, $options);
            foreach ($options[1] as $option) {
                $cases[$command . ' --' . $option] = ['html-translator:' . $command, $option];
            }
        }

        yield from $cases;
    }

    #[Test]
    public function it_marks_every_php_block_as_runnable(): void
    {
        $readme = self::readme();

        self::assertSame(substr_count($readme, "\n```php\n"), substr_count($readme, '<!-- runnable'));
        self::assertCount(substr_count($readme, '<!-- runnable'), iterator_to_array(self::phpSnippets()));
        self::assertNotSame([], iterator_to_array(self::yamlSnippets()));
        self::assertNotSame([], iterator_to_array(self::commandOptions()));
    }

    #[Test]
    #[DataProvider('phpSnippets')]
    public function it_runs_a_php_snippet(string $class, string $method, string $code): void
    {
        self::bootKernel(['config' => ['readme.yaml']]);
        $service = self::scenarioService(self::NAMESPACE . '\\' . $class);

        $result = new ReflectionMethod($service, $method)->invoke($service, self::HTML);

        self::assertIsString($result);
        self::assertStringContainsString('<h1>', $result);
        // The pseudo driver changes every text: the original back would mean the translation failed.
        self::assertNotSame(self::HTML, $result);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[Test]
    #[DataProvider('yamlSnippets')]
    public function it_processes_a_yaml_snippet(array $config): void
    {
        $treeBuilder = new TreeBuilder('html_translator');
        ConfigurationTree::define($treeBuilder->getRootNode());
        $processed = new Processor()->process($treeBuilder->buildTree(), [self::BASE, $config]);

        self::assertArrayHasKey('providers', $processed);
    }

    #[Test]
    #[DataProvider('commandOptions')]
    public function it_shows_only_options_the_commands_have(string $command, string $option): void
    {
        $definition = new Application(self::bootKernel())->find($command)->getDefinition();

        self::assertTrue($definition->hasOption($option), \sprintf('%s has no --%s.', $command, $option));
    }

    private static function readme(): string
    {
        return (string) file_get_contents(self::README);
    }
}
