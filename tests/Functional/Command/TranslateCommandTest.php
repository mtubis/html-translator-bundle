<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional\Command;

use HtmlTranslator\Bundle\ChatModel\ChatModelFactory;
use HtmlTranslator\Bundle\Command\TranslateCommand;
use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\DependencyInjection\ProfilerDefinitions;
use HtmlTranslator\Bundle\DependencyInjection\ProviderDefinitions;
use HtmlTranslator\Bundle\DependencyInjection\TranslatorDefinitions;
use HtmlTranslator\Bundle\Drivers;
use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\Bundle\Html\HtmlRulesFactory;
use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use HtmlTranslator\Bundle\Http\HttpClientFactory;
use HtmlTranslator\Bundle\Options\TranslationOptionsFactory;
use HtmlTranslator\Bundle\Profile\ProfileFactory;
use HtmlTranslator\Bundle\Profile\ProfileRegistry;
use HtmlTranslator\Bundle\Tests\Functional\FunctionalTestCase;
use HtmlTranslator\Bundle\TranslatorRegistry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(TranslateCommand::class)]
#[UsesClass(HtmlTranslatorBundle::class)]
#[UsesClass(HttpClientFactory::class)]
#[UsesClass(ChatModelFactory::class)]
#[UsesClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
#[UsesClass(ProviderDefinitions::class)]
#[UsesClass(TranslatorDefinitions::class)]
#[UsesClass(ProfilerDefinitions::class)]
#[UsesClass(HtmlRulesFactory::class)]
#[UsesClass(TranslatorRegistry::class)]
#[UsesClass(TranslationOptionsFactory::class)]
#[UsesClass(ProfileRegistry::class)]
#[UsesClass(ProfileFactory::class)]
#[UsesClass(InvalidConfigurationException::class)]
final class TranslateCommandTest extends FunctionalTestCase
{
    private const string HTML = '<p>Hello <b>world</b></p>';

    private const string PSEUDO = '<p>[!! Ĥéļļö <b>ŵöŕļð</b> !!]</p>';

    private string $dir;

    private string $cwd;

    #[Override]
    protected function setUp(): void
    {
        $this->dir = __DIR__ . '/../var/console/' . bin2hex(random_bytes(4));
        new Filesystem()->dumpFile($this->dir . '/page.html', self::HTML);
        // Relative paths (and the files a broken command might write) stay in the test directory.
        $this->cwd = (string) getcwd();
        chdir($this->dir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        new Filesystem()->remove($this->dir);
        parent::tearDown();
    }

    #[Test]
    public function it_writes_one_target_to_stdout_and_the_summary_to_stderr(): void
    {
        $tester = $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de']]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(self::PSEUDO, $tester->getDisplay());
        self::assertStringContainsString('de: 1 units, 1 batches, 0 cache hits, 0 retries, 0 issues', $tester->getErrorOutput());
    }

    #[Test]
    public function it_reads_stdin(): void
    {
        $tester = $this->execute(['input' => '-', '--to' => ['de']], stdin: self::HTML);

        // CommandTester ends the input with a line break, which the translation keeps.
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(self::PSEUDO . "\n", $tester->getDisplay());
    }

    #[Test]
    public function it_writes_one_file_per_locale_into_an_output_directory(): void
    {
        $tester = $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de', 'pt_br'], '--output' => $this->dir . '/out/']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringEqualsFile($this->dir . '/out/page.de.html', self::PSEUDO);
        self::assertStringEqualsFile($this->dir . '/out/page.pt-BR.html', self::PSEUDO);
        self::assertStringContainsString('pt-BR → ' . $this->dir . '/out/page.pt-BR.html: 1 units', $tester->getErrorOutput());
    }

    #[Test]
    public function it_names_stdin_output_files_page(): void
    {
        $tester = $this->execute(['input' => '-', '--to' => ['de', 'fr'], '--output' => $this->dir . '/out/'], stdin: self::HTML);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($this->dir . '/out/page.de.html');
        self::assertFileExists($this->dir . '/out/page.fr.html');
    }

    #[Test]
    public function it_fills_a_locale_placeholder_in_the_output_path(): void
    {
        $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de', 'fr'], '--output' => $this->dir . '/{locale}/index.html']);

        self::assertStringEqualsFile($this->dir . '/de/index.html', self::PSEUDO);
        self::assertStringEqualsFile($this->dir . '/fr/index.html', self::PSEUDO);
    }

    #[Test]
    public function it_writes_one_target_to_an_output_file(): void
    {
        $tester = $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de'], '--output' => $this->dir . '/translated.html']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringEqualsFile($this->dir . '/translated.html', self::PSEUDO);
    }

    #[Test]
    public function it_writes_several_targets_next_to_the_input(): void
    {
        $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de', 'fr']]);

        self::assertStringEqualsFile($this->dir . '/page.de.html', self::PSEUDO);
        self::assertStringEqualsFile($this->dir . '/page.fr.html', self::PSEUDO);
    }

    #[Test]
    public function it_shows_a_progress_bar_on_a_terminal(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel(['config' => ['console.yaml']]))->find('html-translator:translate'));
        $tester->execute(['input' => $this->dir . '/page.html', '--to' => ['de']], ['capture_stderr_separately' => true, 'decorated' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(self::PSEUDO, $tester->getDisplay());
        self::assertStringContainsString('1/1 batches', $tester->getErrorOutput());
    }

    #[Test]
    public function it_writes_the_html_raw_even_when_quiet(): void
    {
        new Filesystem()->dumpFile($this->dir . '/styled.html', '<p>Hi <info>there</info></p>');

        $tester = $this->execute(['input' => $this->dir . '/styled.html', '--to' => ['de'], '--quiet' => true]);

        // The console formatter would turn <info> into a style; -q would drop normal output.
        self::assertSame('<p>[!! Ĥí <info>ţĥéŕé</info> !!]</p>', $tester->getDisplay());
    }

    #[Test]
    public function it_writes_into_an_existing_directory_given_without_a_slash(): void
    {
        new Filesystem()->mkdir($this->dir . '/existing');

        $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de', 'fr'], '--output' => $this->dir . '/existing']);

        self::assertStringEqualsFile($this->dir . '/existing/page.de.html', self::PSEUDO);
    }

    #[Test]
    public function it_translates_each_locale_once(): void
    {
        $tester = $this->execute(['input' => $this->dir . '/page.html', '--to' => ['pt_br', 'pt-BR'], '--output' => $this->dir . '/out/']);

        self::assertSame(1, substr_count($tester->getErrorOutput(), 'pt-BR →'));
        self::assertSame(['page.pt-BR.html'], array_values(array_diff((array) scandir($this->dir . '/out'), ['.', '..'])));
    }

    #[Test]
    public function it_checks_every_target_before_writing_anything(): void
    {
        $tester = $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de', 'x!'], '--output' => $this->dir . '/out/']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($this->dir . '/out');
        self::assertStringContainsString('x!', $tester->getErrorOutput());
    }

    #[Test]
    public function it_checks_the_source_locale_before_any_output(): void
    {
        $tester = $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de'], '--from' => 'x!']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
    }

    #[Test]
    public function it_refuses_to_overwrite_the_input(): void
    {
        $tester = $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de'], '--output' => $this->dir . '/page.html']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringEqualsFile($this->dir . '/page.html', self::HTML);
        self::assertStringContainsString(self::squash('The output "' . $this->dir . '/page.html" is the input file; choose another --output.'), self::squash($tester->getErrorOutput()));
    }

    #[Test]
    public function it_reports_an_output_it_cannot_write(): void
    {
        // The parent "directory" is a file.
        $tester = $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de'], '--output' => $this->dir . '/page.html/de.html']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString(self::squash('Cannot write "' . $this->dir . '/page.html/de.html".'), self::squash($tester->getErrorOutput()));
    }

    #[Test]
    public function it_fails_on_an_incomplete_translation(): void
    {
        $tester = $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de'], '--provider' => 'broken']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(self::HTML, $tester->getDisplay());
        self::assertStringContainsString('de is incomplete: 1 issues; run with -v for details.', $tester->getErrorOutput());
    }

    #[Test]
    public function it_lists_the_issues_when_verbose(): void
    {
        $tester = $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de'], '--provider' => 'broken'], verbose: true);

        self::assertMatchesRegularExpression('/^  \w+ u1: .+$/m', $tester->getErrorOutput());
    }

    #[Test]
    public function it_passes_the_fallback_to_the_options(): void
    {
        $tester = $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de'], '--provider' => 'broken', '--fallback' => 'throw']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('[ERROR]', $tester->getErrorOutput());
    }

    #[Test]
    public function it_accepts_a_profile_and_a_source_locale(): void
    {
        $tester = $this->execute(['input' => $this->dir . '/page.html', '--to' => ['de'], '--translation-profile' => 'docs', '--from' => 'en']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(self::PSEUDO, $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidInvocations(): iterable
    {
        yield 'unknown provider' => [['--to' => ['de'], '--provider' => 'nope'], 'html_translator.providers.nope: is not defined; defined: pseudo, broken'];
        yield 'unknown profile' => [['--to' => ['de'], '--translation-profile' => 'nope'], 'html_translator.profiles.nope: is not defined; defined: docs, default'];
        yield 'no target' => [[], 'Give at least one target locale with --to.'];
        yield 'bad fallback' => [['--to' => ['de'], '--fallback' => 'skip'], 'The --fallback option must be one of keep_original, text_nodes_only, throw; got "skip".'];
        yield 'several targets into one file' => [['--to' => ['de', 'fr'], '--output' => 'one.html'], 'Several targets need an output directory or a {locale} placeholder in --output.'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('invalidInvocations')]
    public function it_reports_an_invalid_invocation(array $options, string $message): void
    {
        $tester = $this->execute(['input' => $this->dir . '/page.html'] + $options);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString($this->squash($message), $this->squash($tester->getErrorOutput()));
    }

    #[Test]
    public function it_reports_a_missing_input_file(): void
    {
        $tester = $this->execute(['input' => $this->dir . '/missing.html', '--to' => ['de']]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString($this->squash('Cannot read the input file "' . $this->dir . '/missing.html".'), $this->squash($tester->getErrorOutput()));
    }

    #[Test]
    public function it_needs_an_output_for_several_targets_from_stdin(): void
    {
        $tester = $this->execute(['input' => '-', '--to' => ['de', 'fr']], stdin: self::HTML);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString($this->squash('Several targets from STDIN need --output with a directory or a {locale} placeholder.'), $this->squash($tester->getErrorOutput()));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input, ?string $stdin = null, bool $verbose = false): CommandTester
    {
        $tester = new CommandTester(new Application(self::bootKernel(['config' => ['console.yaml']]))->find('html-translator:translate'));
        if (null !== $stdin) {
            $tester->setInputs([$stdin]);
        }

        $tester->execute($input, ['capture_stderr_separately' => true, 'interactive' => null !== $stdin, 'verbosity' => $verbose ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL]);

        return $tester;
    }

    /**
     * SymfonyStyle wraps long messages, even inside paths (at most 120 columns on 7.4): compare without whitespace.
     */
    private function squash(string $text): string
    {
        return (string) preg_replace('/\s+/', '', str_replace(['[ERROR]', '[WARNING]'], '', $text));
    }
}
