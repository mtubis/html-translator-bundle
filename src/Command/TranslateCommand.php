<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Command;

use Closure;
use HtmlTranslator\Bundle\Options\TranslationOptionsFactory;
use HtmlTranslator\Bundle\TranslatorRegistry;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\TranslatorException;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Progress;
use HtmlTranslator\TranslatedDocument;
use HtmlTranslator\TranslationOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Translates an HTML file (or STDIN) into one or more locales. Translated HTML goes to STDOUT or files; progress, the
 * summary and errors go to STDERR, so the HTML can be piped.
 */
#[AsCommand(name: 'html-translator:translate', description: 'Translate an HTML file into one or more locales')]
final class TranslateCommand extends Command
{
    public function __construct(
        private readonly TranslatorRegistry $translators,
        private readonly TranslationOptionsFactory $options,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::REQUIRED, 'An HTML file, or - to read STDIN')
            ->addOption('to', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target locale, e.g. --to=de; repeat for several')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Source locale; the model detects it when omitted')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'A provider entry of html_translator.providers; default: default_provider')
            // Not --profile (the Laravel name): FrameworkBundle's console reserves it for command profiling.
            ->addOption('translation-profile', null, InputOption::VALUE_REQUIRED, 'A profile entry of html_translator.profiles; default: default_profile')
            ->addOption('fallback', null, InputOption::VALUE_REQUIRED, implode(', ', array_keys(TranslationOptionsFactory::FALLBACKS)))
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output file, a directory (ending with /) or a path with {locale}; default: STDOUT for one target, page.{locale}.html next to the input for several');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = new SymfonyStyle($input, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
        $targets = $this->targets($input);
        $source = $this->string($input, 'input') ?? '-';
        $destination = $this->string($input, 'output');
        $fallback = $this->string($input, 'fallback');
        $several = \count($targets) > 1;
        $multi = null !== $destination && (str_contains($destination, '{locale}') || $this->isDirectory($destination));

        $problem = match (true) {
            [] === $targets => 'Give at least one target locale with --to.',
            null !== $fallback && !\array_key_exists($fallback, TranslationOptionsFactory::FALLBACKS) => \sprintf('The --fallback option must be one of %s; got "%s".', implode(', ', array_keys(TranslationOptionsFactory::FALLBACKS)), $fallback),
            $several && null === $destination && '-' === $source => 'Several targets from STDIN need --output with a directory or a {locale} placeholder.',
            $several && null !== $destination && !$multi => 'Several targets need an output directory or a {locale} placeholder in --output.',
            default => null,
        };
        $html = null === $problem ? $this->read($input, $source) : null;
        if (null === $html) {
            $errors->error($problem ?? \sprintf('Cannot read the input file "%s".', $source));

            return self::FAILURE;
        }

        try {
            $translator = $this->translators->get($this->string($input, 'provider'));
            $options = fn(?Closure $onProgress = null): TranslationOptions => $this->options->create(
                profile: $this->string($input, 'translation-profile'),
                source: $this->string($input, 'from'),
                fallback: null === $fallback ? null : TranslationOptionsFactory::FALLBACKS[$fallback],
                onProgress: $onProgress,
            );
            // Fails early, before any output, when the profile or source is invalid.
            $options();

            $complete = true;
            foreach ($targets as $target) {
                $locale = Locale::fromString($target);
                $document = $this->translate($translator, $html, $locale, $options, $errors);
                $complete = $document->isComplete() && $complete;
                $path = $this->path($destination, $source, $locale, $several);

                if (null === $path) {
                    // Raw and at every verbosity: the formatter would change the HTML, and -q would drop the result.
                    $output->write($document->html(), false, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
                } elseif (!$this->write($path, $document->html())) {
                    $errors->error(\sprintf('Cannot write "%s".', $path));

                    return self::FAILURE;
                }

                $this->summarize($errors, $locale, $document, $path);
            }
        } catch (TranslatorException $exception) {
            $errors->error($exception->getMessage());

            return self::FAILURE;
        }

        return $complete ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param Closure(?Closure=): TranslationOptions $options
     */
    private function translate(HtmlTranslator $translator, string $html, Locale $target, Closure $options, SymfonyStyle $errors): TranslatedDocument
    {
        // A progress bar only on a terminal: elsewhere (pipes, CI logs) it cannot be cleared and garbles the summary.
        if (!$errors->isDecorated()) {
            return $translator->translate($html, $target, $options());
        }

        $bar = $errors->createProgressBar();
        $bar->setFormat(' %current%/%max% batches [%bar%] ' . $target->toString());

        try {
            return $translator->translate($html, $target, $options(static function (Progress $progress) use ($bar): void {
                $bar->setMaxSteps($progress->batchesTotal);
                $bar->setProgress($progress->batchesDone);
            }));
        } finally {
            $bar->clear();
        }
    }

    private function summarize(SymfonyStyle $errors, Locale $target, TranslatedDocument $document, ?string $path): void
    {
        $stats = $document->stats();
        $tokens = null === $stats->inputTokens && null === $stats->outputTokens
            ? ''
            : \sprintf(', %s → %s tokens', number_format($stats->inputTokens ?? 0), number_format($stats->outputTokens ?? 0));

        $errors->writeln(\sprintf(
            '%s%s: %d units, %d batches, %d cache hits, %d retries%s, %d issues',
            $target->toString(),
            null === $path ? '' : ' → ' . $path,
            $stats->units,
            $stats->batches,
            $stats->cacheHits,
            $stats->retries,
            $tokens,
            \count($document->issues()),
        ), OutputInterface::OUTPUT_RAW);

        if ($errors->isVerbose()) {
            foreach ($document->issues() as $issue) {
                $errors->writeln(\sprintf('  %s %s: %s', $issue->type->name, $issue->unitId->toString(), $issue->message), OutputInterface::OUTPUT_RAW);
            }
        }

        if (!$document->isComplete()) {
            $errors->warning(\sprintf('%s is incomplete: %d issues; run with -v for details.', $target->toString(), \count($document->issues())));
        }
    }

    /**
     * Null: STDOUT.
     */
    private function path(?string $destination, string $source, Locale $target, bool $several): ?string
    {
        $basename = '-' === $source ? 'page.html' : basename($source);
        $info = pathinfo($basename);
        $file = $info['filename'] . '.' . $target->toString() . (isset($info['extension']) ? '.' . $info['extension'] : '');

        return match (true) {
            null === $destination => $several ? \dirname($source) . '/' . $file : null,
            str_contains($destination, '{locale}') => str_replace('{locale}', $target->toString(), $destination),
            $this->isDirectory($destination) => rtrim($destination, '/') . '/' . $file,
            default => $destination,
        };
    }

    private function isDirectory(string $path): bool
    {
        return str_ends_with($path, '/') || is_dir($path);
    }

    private function write(string $path, string $html): bool
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return false;
        }

        return false !== @file_put_contents($path, $html);
    }

    private function read(InputInterface $input, string $source): ?string
    {
        if ('-' !== $source) {
            $html = is_file($source) ? @file_get_contents($source) : false;

            return false === $html ? null : $html;
        }

        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $html = stream_get_contents($stream ?? \STDIN);

        return false === $html ? null : $html;
    }

    /**
     * @return list<string>
     */
    private function targets(InputInterface $input): array
    {
        $targets = $input->getOption('to');

        return \is_array($targets) ? array_values(array_filter($targets, static fn(mixed $target): bool => \is_string($target) && '' !== $target)) : [];
    }

    private function string(InputInterface $input, string $name): ?string
    {
        $value = $input->hasArgument($name) ? $input->getArgument($name) : $input->getOption($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
