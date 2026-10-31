<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Profile;

use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\Profile\TranslationProfile;

/**
 * The configured translation profiles by entry name, each built once when it is first used.
 */
final class ProfileRegistry
{
    /** @var array<string, TranslationProfile> */
    private array $built = [];

    /**
     * @param array<array-key, array<array-key, mixed>> $entries the processed `profiles` section (literals, no secrets)
     */
    public function __construct(
        private readonly array $entries,
        private readonly string $defaultName,
    ) {}

    /**
     * @param ?string $name an entry of `profiles`; null = `default_profile`
     *
     * @throws InvalidConfigurationException when the entry is not defined or the core rejects a value
     */
    public function get(?string $name = null): TranslationProfile
    {
        $name ??= $this->defaultName;

        $entry = $this->entries[$name] ?? null;
        if (null === $entry) {
            throw InvalidConfigurationException::forPath('html_translator.profiles.' . $name, 'is not defined; defined: ' . implode(', ', $this->names()));
        }

        return $this->built[$name] ??= ProfileFactory::fromArray($name, $entry);
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->entries);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->entries));
    }

    public function defaultName(): string
    {
        return $this->defaultName;
    }
}
