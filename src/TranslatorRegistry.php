<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle;

use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\HtmlTranslator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The translator of every provider entry by name, for code that picks the provider at runtime.
 */
final readonly class TranslatorRegistry
{
    /**
     * @param ServiceLocator<HtmlTranslator> $translators keyed by entry name
     */
    public function __construct(
        private ServiceLocator $translators,
        private string $defaultName,
    ) {}

    /**
     * @param ?string $name an entry of `providers`; null = `default_provider`
     *
     * @throws InvalidConfigurationException when the entry is not defined
     */
    public function get(?string $name = null): HtmlTranslator
    {
        $name ??= $this->defaultName;

        if (!$this->translators->has($name)) {
            throw InvalidConfigurationException::forPath('html_translator.providers.' . $name, 'is not defined; defined: ' . implode(', ', $this->names()));
        }

        return $this->translators->get($name);
    }

    public function has(string $name): bool
    {
        return $this->translators->has($name);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->translators->getProvidedServices()));
    }

    public function defaultName(): string
    {
        return $this->defaultName;
    }
}
