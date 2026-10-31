# html-translator for Symfony

Translate HTML in your Symfony application with large language models — **without breaking the markup**.

This bundle integrates [`mtubis/html-translator`](https://github.com/mtubis/html-translator) with Symfony: providers
and translation profiles in `config/packages/html_translator.yaml`, one `HtmlTranslator` service per provider with
named autowiring, Symfony HttpClient (visible in the profiler, `MockHttpClient` in tests), cache pools, Monolog, console
commands and a Web Profiler panel.

> **Status:** 0.1 — see the [CHANGELOG](CHANGELOG.md) and the [roadmap](docs/ROADMAP.md). While the version is 0.x, a
> minor version may change the public API; the CHANGELOG says how.

## Requirements

PHP 8.4+, Symfony 7.4 LTS or 8.1+. FrameworkBundle is optional (without it the bundle creates its own HTTP client and
translates uncached); TwigBundle and WebProfilerBundle are only needed for the profiler panel.

## Installation

```bash
composer require mtubis/html-translator-bundle
```

Once the recipe is in [symfony/recipes-contrib](https://github.com/symfony/recipes-contrib), Symfony Flex (after you
accept the contrib recipe) enables the bundle, creates `config/packages/html_translator.yaml` (OpenAI by default, the
offline `pseudo` driver in tests) and adds the variables to `.env`; until then, configure it as described below:

```dotenv
OPENAI_API_KEY=
HTML_TRANSLATOR_OPENAI_MODEL=gpt-4o-mini
```

Without Flex, add `HtmlTranslator\Bundle\HtmlTranslatorBundle::class => ['all' => true]` to `config/bundles.php` and
create the configuration yourself. A minimal one:

```yaml
# config/packages/html_translator.yaml
html_translator:
    default_provider: openai
    providers:
        openai:
            driver: openai
            api_key: '%env(OPENAI_API_KEY)%'
            model: '%env(HTML_TRANSLATOR_OPENAI_MODEL)%'
```

Then check it: `bin/console html-translator:check` creates every provider and profile and lists all problems at once.

## Translating

Inject the core `HtmlTranslator`. The type alone gives the translator of `default_provider`; the result is the core
`TranslatedDocument`.

<!-- runnable: PageTranslator::toGerman -->
```php
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;

final readonly class PageTranslator
{
    public function __construct(
        private HtmlTranslator $translator,
    ) {}

    public function toGerman(string $html): string
    {
        $document = $this->translator->translate($html, Locale::fromString('de'));

        // isComplete() is false when some units kept the original text (see issues()).
        return $document->isComplete() ? $document->html() : $html;
    }
}
```

### Several providers

Every entry of `providers` is a translator service `html_translator.translator.<name>`. Pick one with `#[Target]`, or
at runtime by name with `TranslatorRegistry`:

<!-- runnable: CarefulTranslator::translate -->
```php
use HtmlTranslator\Bundle\TranslatorRegistry;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;
use Symfony\Component\DependencyInjection\Attribute\Target;

final readonly class CarefulTranslator
{
    public function __construct(
        #[Target('anthropic')]
        private HtmlTranslator $anthropic,
        private TranslatorRegistry $translators,
    ) {}

    public function translate(string $html): string
    {
        $cheap = $this->translators->get('openai');   // or ->get() for default_provider; names(), has()

        return $this->anthropic->translate($cheap->translate($html, Locale::fromString('fr'))->html(), Locale::fromString('de'))->html();
    }
}
```

On Symfony 7.4 the argument name works too (`HtmlTranslator $anthropicTranslator`, the entry name in camelCase plus
`Translator`); Symfony 8.1 deprecates autowiring by name alone, so prefer `#[Target]`. Entry names start with a letter
and contain letters, digits, `_` and `-`.

### Profiles and options

Profiles describe *how* to translate: a preset (`default`, `technical`, `marketing`, `legal`) plus formality, term
policy, domain, glossary, terms to keep and extra instructions.

```yaml
html_translator:
    default_profile: default
    profiles:
        docs:
            preset: technical
            formality: informal
            glossary: { 'pull request': 'Pull Request' }
            do_not_translate: [Symfony, Doctrine]
    options:
        source: en               # ~ = the model detects the source language
        fallback: keep_original  # keep_original | text_nodes_only | throw
        batch_size: 20
```

`TranslationOptionsFactory` builds the core `TranslationOptions` from these defaults; every argument overrides one of
them:

<!-- runnable: DocsTranslator::translate -->
```php
use HtmlTranslator\Bundle\Options\TranslationOptionsFactory;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\HtmlTranslator;

final readonly class DocsTranslator
{
    public function __construct(
        private HtmlTranslator $translator,
        private TranslationOptionsFactory $options,
    ) {}

    public function translate(string $html): string
    {
        $options = $this->options->create(
            profile: 'docs',                       // an entry of `profiles`, a core TranslationProfile, or null
            source: 'en',
            fallback: FallbackStrategy::TextNodesOnly,
        );

        return $this->translator->translate($html, Locale::fromString('pl'), $options)->html();
    }
}
```

`Profile\ProfileRegistry` returns the configured profiles as core `TranslationProfile` objects (`get()`, `names()`).

## Providers

| Driver | Core adapter | Base URL | Authentication | Required keys | Reference env |
|---|---|---|---|---|---|
| `openai` | `OpenAiCompatibleConfig::openAi()` (json_schema) | `https://api.openai.com/v1` | Bearer | `api_key`, `model` | `OPENAI_API_KEY`, `HTML_TRANSLATOR_OPENAI_MODEL` |
| `gemini` | `OpenAiCompatibleConfig::gemini()` | Gemini's OpenAI-compatible endpoint | Bearer | `api_key`, `model` | `GEMINI_API_KEY`, `HTML_TRANSLATOR_GEMINI_MODEL` |
| `anthropic` | `AnthropicConfig` | `https://api.anthropic.com/v1` | `x-api-key` | `api_key`, `model` | `ANTHROPIC_API_KEY`, `HTML_TRANSLATOR_ANTHROPIC_MODEL` |
| `ollama` | `OpenAiCompatibleConfig::ollama()` | `http://localhost:11434/v1` (keep the `/v1`) | none | `model` | `HTML_TRANSLATOR_OLLAMA_MODEL` |
| `vllm` | `OpenAiCompatibleConfig::vllm()` | `http://localhost:8000/v1` | optional Bearer | `model` | |
| `lm_studio` | `OpenAiCompatibleConfig::lmStudio()` | `http://localhost:1234/v1` | none | `model` | |
| `openai_compatible` | `new OpenAiCompatibleConfig()` | your server | optional Bearer, `extra_headers` | `base_url`, `model` | |
| `pseudo` | `PseudoLocalizationProvider` | no network | — | — | |
| `symfony_ai` | planned for 0.3 ([ADR-0008](docs/adr/0008-symfony-ai-driver-later.md)) | | | | |

Every key, default and comment is in [docs/config-reference.yaml](docs/config-reference.yaml); `bin/console
config:dump-reference html_translator` prints the same tree. Keys, models, URLs, header values and the timeout may come
from env (`%env(...)%`) and are checked when the provider is first used; enum-like keys are literals and are checked
when the container is compiled.

## HTTP, retries, cache, logging

- **HTTP**: the bundle scopes FrameworkBundle's `http_client` with `http.timeout` (from env:
  `'%env(float:HTML_TRANSLATOR_HTTP_TIMEOUT)%'`) and `http.max_duration`, so requests appear in the profiler; or set
  `http.client` to a service id of your own, which is used as is (the timeouts above are not applied to it). `max_duration: ~` means no limit and overrides a `max_duration` in
  `framework.http_client.default_options`.
- **Retries** are the core `Retrier`'s, configured by `http.retry` (429, 5xx, network errors, `Retry-After`). Do **not**
  add `retry_failed` to `framework.http_client.default_options`: the bundle's client inherits it and every request
  would be retried twice over ([ADR-0003](docs/adr/0003-symfony-http-client-as-psr18.md)).
- **Cache**: translated units are reused per text, locale pair, profile and model in the pool `cache.html_translator`
  (adapter `cache.app`, registered when FrameworkBundle is present) or in the pool named by `cache.pool`;
  `cache.enabled: false` turns it off.
- **Logging**: issues go to the `html_translator` Monolog channel when MonologBundle is installed, otherwise to the
  `logger` service; `logging.enabled: false` turns it off.

## Console

```bash
bin/console html-translator:translate page.html --to=de --to=fr --from=en --provider=anthropic \
    --translation-profile=docs --output=translations/
cat page.html | bin/console html-translator:translate - --to=de > page.de.html
bin/console html-translator:check [--provider=openai] [--ping]
```

`translate` writes the HTML to STDOUT (one target) or to files (`--output` as a file, a directory, or a path with
`{locale}`; several targets without `--output` go next to the input as `page.<locale>.html`), progress and a summary
per locale to STDERR, and exits with 1 when a document is incomplete. `--fallback` overrides `options.fallback`
(`keep_original`, `text_nodes_only`, `throw`). The profile
option is `--translation-profile`, because FrameworkBundle reserves `--profile` for command profiling. `check` reports
every runtime configuration problem in one run; `--ping` also translates `<p>ok</p>` once per provider (a few tokens).

## Profiler

In `kernel.debug` with WebProfilerBundle (and `profiler.enabled`, default `%kernel.debug%`) the panel shows every batch
sent to a provider: provider entry, locales, units, corrections, failures, tokens, time and the unit texts
([ADR-0006](docs/adr/0006-profiler-through-a-traceable-provider.md)). Cache hits and issues after fallback are not
shown: they are decided by the core translator, which the bundle cannot observe. Profiles on disk
(`var/cache/dev/profiler`) therefore contain truncated texts of the translated pages.

## Testing your application

Use the offline `pseudo` driver in tests: it "translates" to accented text, so you can assert on structure without a
network or a key.

```yaml
# config/packages/html_translator.yaml
when@test:
    html_translator:
        default_provider: pseudo
        providers:
            pseudo:
                driver: pseudo
```

An entry in a later file or a `when@<env>` block replaces the earlier one as a whole, so `when@test` can also switch a
single entry, e.g. `providers: { openai: { driver: pseudo } }` (what the recipe does). `corrupt_every: 1` (with `corrupt_retries: true`) breaks every unit, to
test how your code handles incomplete documents. To test the real request, keep the driver (remove the recipe's `when@test`
override for that entry), give it a dummy key in `.env.test` (an empty key fails before any request) and answer with
Symfony's `MockHttpClient`: set `framework.http_client.mock_response_factory`, or point `http.client` at a
`MockHttpClient` service.

## Errors and secrets

Problems the configuration tree can see fail at `cache:clear` with Symfony's path-based messages. Values that come
from env are checked on first use and raise `HtmlTranslator\Bundle\Exception\InvalidConfigurationException`, which
implements the core `TranslatorException` and names the config path, e.g.
`html_translator.providers.openai.api_key: is empty; the reference configuration reads it from OPENAI_API_KEY`.
A variable that is not defined at all raises Symfony's `EnvNotFoundException` instead. Messages never quote API keys
or header values.

Keep API keys in env variables or [Symfony secrets](https://symfony.com/doc/current/configuration/secrets.html)
(`bin/console secrets:set OPENAI_API_KEY`): a literal key in YAML ends up in the compiled container.

## More

- How the translation itself works — what is sent to the model, fallbacks, profiles, costs: the
  [core documentation](https://github.com/mtubis/html-translator/blob/main/docs/usage.md).
- The same features for Laravel: [html-translator-laravel](https://github.com/mtubis/html-translator-laravel).
- Design decisions: [docs/adr](docs/adr/); architecture: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md); releases follow [docs/RELEASING.md](docs/RELEASING.md).

## License

MIT
