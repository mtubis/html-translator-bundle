<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional\Command;

use HtmlTranslator\Bundle\ChatModel\ChatModelFactory;
use HtmlTranslator\Bundle\Command\CheckCommand;
use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CheckCommand::class)]
#[UsesClass(HtmlTranslatorBundle::class)]
#[UsesClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
#[UsesClass(ProviderDefinitions::class)]
#[UsesClass(TranslatorDefinitions::class)]
#[UsesClass(HtmlRulesFactory::class)]
#[UsesClass(HttpClientFactory::class)]
#[UsesClass(ChatModelFactory::class)]
#[UsesClass(TranslatorRegistry::class)]
#[UsesClass(TranslationOptionsFactory::class)]
#[UsesClass(ProfileRegistry::class)]
#[UsesClass(ProfileFactory::class)]
#[UsesClass(InvalidConfigurationException::class)]
final class CheckCommandTest extends FunctionalTestCase
{
    private const string FAKE_KEY = 'sk-fake-check-5e1f0c93a7';

    #[Override]
    protected function setUp(): void
    {
        // Set but empty: the factory reports it; HTML_TRANSLATOR_TEST_MISSING_KEY stays unset on purpose.
        $_ENV['HTML_TRANSLATOR_TEST_EMPTY_KEY'] = $_SERVER['HTML_TRANSLATOR_TEST_EMPTY_KEY'] = '';
        putenv('HTML_TRANSLATOR_TEST_EMPTY_KEY=');
    }

    protected function tearDown(): void
    {
        unset($_ENV['HTML_TRANSLATOR_TEST_EMPTY_KEY'], $_SERVER['HTML_TRANSLATOR_TEST_EMPTY_KEY']);
        putenv('HTML_TRANSLATOR_TEST_EMPTY_KEY');
        parent::tearDown();
    }

    #[Test]
    public function it_reports_every_broken_entry_in_one_run(): void
    {
        $tester = $this->execute('check.yaml');
        $output = $this->oneLine($tester->getDisplay());

        self::assertSame(1, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/providers\.pseudo \.+ OK/', $output);
        self::assertStringContainsString('html_translator.providers.empty_key.api_key: is empty; the reference configuration reads it from OPENAI_API_KEY', $output);
        self::assertStringContainsString('html_translator.providers.bad_url: The base URL of "bad_url"', $output);
        self::assertStringContainsString('HTML_TRANSLATOR_TEST_MISSING_KEY', $output);
        self::assertMatchesRegularExpression('/profiles\.default \.+ OK/', $output);
        self::assertMatchesRegularExpression('/options \.+ OK/', $output);
        self::assertStringContainsString('3 problems found.', $output);
    }

    #[Test]
    public function it_never_prints_a_secret(): void
    {
        $tester = $this->execute('check.yaml', ['--ping' => true]);

        self::assertStringNotContainsString(self::FAKE_KEY, $tester->getDisplay());
    }

    #[Test]
    public function it_checks_only_the_named_providers(): void
    {
        $tester = $this->execute('check.yaml', ['--provider' => ['pseudo']]);
        $output = $this->oneLine($tester->getDisplay());

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('empty_key', $output);
        self::assertStringContainsString('The configuration is valid.', $output);
    }

    #[Test]
    public function it_reports_an_unknown_provider_name(): void
    {
        $tester = $this->execute('check.yaml', ['--provider' => ['nope']]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('html_translator.providers.nope: is not defined; defined: pseudo, empty_key, bad_url, missing_env', $this->oneLine($tester->getDisplay()));
    }

    #[Test]
    public function it_pings_the_working_providers(): void
    {
        $tester = $this->execute('console.yaml', ['--ping' => true]);
        $output = $this->oneLine($tester->getDisplay());

        self::assertSame(0, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/ping pseudo \.+ OK/', $output);
        self::assertMatchesRegularExpression('/ping broken \.+ OK/', $output);
        self::assertStringContainsString('The configuration is valid.', $output);
    }

    #[Test]
    public function it_reports_broken_profiles_options_and_pings(): void
    {
        $tester = $this->execute('check_profiles.yaml', ['--ping' => true]);
        $output = self::oneLine($tester->getDisplay());

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('html_translator.profiles.clash.do_not_translate: ', $output);
        self::assertStringContainsString('html_translator.options.source: ', $output);
        // The ping builds the options too, so it fails for the same reason.
        self::assertMatchesRegularExpression('/ping pseudo \.+ FAIL/', $output);
        self::assertStringContainsString('3 problems found.', $output);
    }

    #[Test]
    public function it_counts_a_single_problem(): void
    {
        $tester = $this->execute('check.yaml', ['--provider' => ['empty_key']]);

        self::assertStringContainsString('1 problem found.', self::oneLine($tester->getDisplay()));
    }

    #[Test]
    public function it_aligns_the_results_in_a_column(): void
    {
        $display = $this->execute('console.yaml')->getDisplay();

        self::assertStringContainsString("\noptions " . str_repeat('.', 41) . " OK\n", $display);
        self::assertStringStartsWith('providers.pseudo ' . str_repeat('.', 32) . " OK\n", $display);
        self::assertStringContainsString("OK\n\n", $display);
    }

    #[Test]
    public function it_accepts_a_valid_configuration(): void
    {
        $tester = $this->execute('console.yaml');

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('The configuration is valid.', $this->oneLine($tester->getDisplay()));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function execute(string $config, array $options = []): CommandTester
    {
        $tester = new CommandTester(new Application(self::bootKernel(['config' => [$config]]))->find('html-translator:check'));
        $tester->execute($options, ['decorated' => false]);

        return $tester;
    }

    private function oneLine(string $output): string
    {
        return (string) preg_replace('/\s+/', ' ', $output);
    }
}
