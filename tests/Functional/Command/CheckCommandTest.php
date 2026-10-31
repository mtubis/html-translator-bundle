<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional\Command;

use Closure;
use HtmlTranslator\Bundle\ChatModel\ChatModelFactory;
use HtmlTranslator\Bundle\Command\CheckCommand;
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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(CheckCommand::class)]
#[UsesClass(HtmlTranslatorBundle::class)]
#[UsesClass(ConfigurationTree::class)]
#[UsesClass(Drivers::class)]
#[UsesClass(ProviderDefinitions::class)]
#[UsesClass(TranslatorDefinitions::class)]
#[UsesClass(ProfilerDefinitions::class)]
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
        $_ENV['HTML_TRANSLATOR_TEST_NOT_INT'] = $_SERVER['HTML_TRANSLATOR_TEST_NOT_INT'] = 'abc';
        putenv('HTML_TRANSLATOR_TEST_NOT_INT=abc');
    }

    protected function tearDown(): void
    {
        unset($_ENV['HTML_TRANSLATOR_TEST_EMPTY_KEY'], $_SERVER['HTML_TRANSLATOR_TEST_EMPTY_KEY'], $_ENV['HTML_TRANSLATOR_TEST_NOT_INT'], $_SERVER['HTML_TRANSLATOR_TEST_NOT_INT']);
        putenv('HTML_TRANSLATOR_TEST_EMPTY_KEY');
        putenv('HTML_TRANSLATOR_TEST_NOT_INT');
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
        // An env processor error does not stop the run either.
        self::assertStringContainsString('Non-numeric env var "HTML_TRANSLATOR_TEST_NOT_INT"', $output);
        self::assertMatchesRegularExpression('/profiles\.default \.+ OK/', $output);
        self::assertMatchesRegularExpression('/options \.+ OK/', $output);
        self::assertStringContainsString('4 problems found.', $output);
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
        self::assertStringContainsString('html_translator.providers.nope: is not defined; defined: pseudo, empty_key, bad_url, missing_env, not_int', $this->oneLine($tester->getDisplay()));
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
        $output = $this->oneLine($tester->getDisplay());

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('html_translator.profiles.clash.do_not_translate: ', $output);
        self::assertStringContainsString('html_translator.options.source: ', $output);
        // The ping uses the core's default options: a broken options section is counted once.
        self::assertMatchesRegularExpression('/ping pseudo \.+ OK/', $output);
        self::assertStringContainsString('2 problems found.', $output);
    }

    #[Test]
    public function it_never_prints_a_secret_from_a_failing_request(): void
    {
        $tester = $this->execute('check_ping.yaml', ['--ping' => true], static fn(string $method, string $url): MockResponse => match (parse_url($url, \PHP_URL_HOST)) {
            // A proxy echoing the key: the core redacts it.
            'rejected.example' => new MockResponse('{"error": {"message": "Incorrect API key provided: sk-fake-ping-71c2e9d04b"}}', ['http_code' => 401]),
            'unreachable.example' => throw new TransportException('Could not reach https://unreachable.example with sk-fake-ping-71c2e9d04b'),
            default => throw new RuntimeException('Unexpected failure for sk-fake-ping-71c2e9d04b'),
        });
        $output = $this->oneLine($tester->getDisplay());

        self::assertSame(1, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/ping rejected \.+ FAIL/', $output);
        self::assertMatchesRegularExpression('/ping unreachable \.+ FAIL/', $output);
        self::assertMatchesRegularExpression('/ping crashing \.+ FAIL Unexpected RuntimeException/', $output);
        self::assertStringContainsString('3 problems found.', $output);
        self::assertStringNotContainsString('sk-fake-ping-71c2e9d04b', $tester->getDisplay());
    }

    #[Test]
    public function it_escapes_a_provider_name_given_on_the_command_line(): void
    {
        $tester = $this->execute('console.yaml', ['--provider' => ['<error>x']]);

        self::assertStringContainsString('providers.<error>x ', $tester->getDisplay());
    }

    #[Test]
    public function it_checks_a_repeated_provider_name_once(): void
    {
        $tester = $this->execute('console.yaml', ['--provider' => ['pseudo', 'pseudo']]);

        self::assertSame(1, substr_count($tester->getDisplay(), 'providers.pseudo '));
    }

    #[Test]
    public function it_counts_a_single_problem(): void
    {
        $tester = $this->execute('check.yaml', ['--provider' => ['empty_key']]);

        self::assertStringContainsString('1 problem found.', $this->oneLine($tester->getDisplay()));
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
     * @param ?Closure(string, string): MockResponse $responses answers of the scenario's MockHttpClient
     */
    private function execute(string $config, array $options = [], ?Closure $responses = null): CommandTester
    {
        $tester = new CommandTester(new Application(self::bootKernel(['config' => [$config]]))->find('html-translator:check'));
        if ($responses instanceof Closure) {
            $client = self::scenarioService('test.mock_http_client');
            self::assertInstanceOf(MockHttpClient::class, $client);
            $client->setResponseFactory($responses);
        }

        $tester->execute($options, ['decorated' => false]);

        return $tester;
    }

    private function oneLine(string $output): string
    {
        return (string) preg_replace('/\s+/', ' ', $output);
    }
}
