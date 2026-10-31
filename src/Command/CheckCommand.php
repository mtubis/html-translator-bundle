<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Command;

use Closure;
use HtmlTranslator\Bundle\Options\TranslationOptionsFactory;
use HtmlTranslator\Bundle\Profile\ProfileRegistry;
use HtmlTranslator\Bundle\TranslatorRegistry;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\TranslatorException;
use HtmlTranslator\TranslationOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException as ContainerRuntimeException;
use Throwable;

/**
 * Creates every provider entry and profile, as the first translation would, and reports all runtime configuration
 * problems at once (ADR-0004). Messages name config paths and env variables, never values.
 */
#[AsCommand(name: 'html-translator:check', description: 'Check the configuration of every provider and profile')]
final class CheckCommand extends Command
{
    private const string PING_HTML = '<p>ok</p>';

    public function __construct(
        private readonly TranslatorRegistry $translators,
        private readonly ProfileRegistry $profiles,
        private readonly TranslationOptionsFactory $options,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('provider', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Check only these provider entries')
            ->addOption('ping', null, InputOption::VALUE_NONE, 'Also translate one short paragraph with each provider (network, costs a few tokens)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $failures = 0;
        $working = [];

        foreach ($this->providerNames($input) as $name) {
            if ($this->check($io, 'providers.' . $name, fn(): mixed => $this->translators->get($name))) {
                $working[] = $name;
            } else {
                ++$failures;
            }
        }

        foreach ($this->profiles->names() as $name) {
            $failures += $this->check($io, 'profiles.' . $name, fn(): mixed => $this->profiles->get($name)) ? 0 : 1;
        }

        $failures += $this->check($io, 'options', fn(): mixed => $this->options->create()) ? 0 : 1;

        if (true === $input->getOption('ping')) {
            foreach ($working as $name) {
                $failures += $this->ping($io, $name) ? 0 : 1;
            }
        }

        $io->newLine();
        if (0 === $failures) {
            $io->success('The configuration is valid.');

            return self::SUCCESS;
        }

        $io->error(\sprintf('%d %s found.', $failures, 1 === $failures ? 'problem' : 'problems'));

        return self::FAILURE;
    }

    /**
     * Any failure is reported and the run goes on, so all problems show up at once.
     *
     * @param Closure(): mixed $check
     */
    private function check(SymfonyStyle $io, string $label, Closure $check): bool
    {
        try {
            $check();
        } catch (TranslatorException|EnvNotFoundException|ContainerRuntimeException $exception) {
            // These name config paths or env variables (also env processors, e.g. invalid JSON), never their values.
            $this->row($io, $label, false);
            $io->writeln('  ' . $exception->getMessage(), OutputInterface::OUTPUT_RAW);

            return false;
        } catch (Throwable $exception) {
            // Only the class: messages of errors the core did not wrap may contain request details.
            $this->row($io, $label, false);
            $io->writeln('  Unexpected ' . $exception::class . '; run html-translator:translate to see the details.', OutputInterface::OUTPUT_RAW);

            return false;
        }

        $this->row($io, $label, true);

        return true;
    }

    /**
     * A real call with the core's default options, so a broken `options` section is not reported once per provider.
     */
    private function ping(SymfonyStyle $io, string $name): bool
    {
        return $this->check($io, 'ping ' . $name, fn(): mixed => $this->translators->get($name)->translate(self::PING_HTML, Locale::fromString('de'), new TranslationOptions()));
    }

    private function row(SymfonyStyle $io, string $label, bool $ok): void
    {
        // The label may hold a --provider value: escape it for the formatter.
        $io->writeln(\sprintf('%s %s %s', OutputFormatter::escape($label), str_repeat('.', max(3, 48 - mb_strlen($label))), $ok ? '<fg=green;options=bold>OK</>' : '<fg=red;options=bold>FAIL</>'));
    }

    /**
     * @return list<string>
     */
    private function providerNames(InputInterface $input): array
    {
        $selected = $input->getOption('provider');
        $selected = \is_array($selected) ? array_values(array_unique(array_filter($selected, \is_string(...)))) : [];

        return [] === $selected ? $this->translators->names() : $selected;
    }
}
