# html-translator for Symfony

Translate HTML in your Symfony application with large language models — **without breaking the markup**.

This bundle integrates [`mtubis/html-translator`](https://github.com/mtubis/html-translator) with Symfony: providers
and translation profiles in `config/packages/html_translator.yaml`, one `HtmlTranslator` service per provider with
named autowiring, Symfony HttpClient (visible in the profiler, `MockHttpClient` in tests), cache pools, Monolog, console
commands and a Web Profiler panel.

> **Status:** early development (0.x). Nothing is released yet — see the [roadmap](docs/ROADMAP.md).

## Requirements

PHP 8.4+, Symfony 7.4 LTS or 8.1+.

## Installation

```bash
composer require mtubis/html-translator-bundle
```

## Planned usage

```yaml
# config/packages/html_translator.yaml
html_translator:
    default_provider: anthropic
    providers:
        anthropic:
            driver: anthropic
            api_key: '%env(ANTHROPIC_API_KEY)%'
            model: '%env(HTML_TRANSLATOR_ANTHROPIC_MODEL)%'
```

```php
use HtmlTranslator\Bundle\Options\TranslationOptionsFactory;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;

final class PageTranslator
{
    public function __construct(
        private HtmlTranslator $translator,
        private TranslationOptionsFactory $options,
    ) {}

    public function toGerman(string $html): string
    {
        return $this->translator->translate($html, Locale::fromString('de'), $this->options->create(profile: 'docs'))->html();
    }
}
```

The full configuration is specified in [docs/config-reference.yaml](docs/config-reference.yaml). How the translation
itself works — what is sent to the model, fallbacks, profiles, costs — is documented in the
[core library](https://github.com/mtubis/html-translator/blob/main/docs/usage.md). The same features for Laravel:
[html-translator-laravel](https://github.com/mtubis/html-translator-laravel).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Design decisions are recorded in [docs/adr](docs/adr/).

## License

MIT
