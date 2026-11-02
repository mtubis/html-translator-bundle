# Architecture

> Status: living document. Decisions that change this file are recorded as ADRs in [`docs/adr/`](adr/).

## 1. Purpose

`mtubis/html-translator-bundle` makes [`mtubis/html-translator`](https://github.com/mtubis/html-translator) (the
**core**) a first-class citizen of a Symfony application:

1. **Configuration instead of wiring** — providers, profiles, options, cache and logging in
   `config/packages/html_translator.yaml`, validated by the Config component at container compile time.
2. **Dependency injection the Symfony way** — the core `HtmlTranslator` is the service you inject; one service per
   provider entry, with named autowiring (`#[Target('anthropic')]`; on Symfony 7.4 also `HtmlTranslator $anthropicTranslator`).
3. **Symfony infrastructure** — HttpClient (profiler, `MockHttpClient` in tests), cache pools, Monolog channels, the
   console, the Web Profiler; later Messenger and Doctrine.
4. **Parity with the Laravel package** where the concept is the same (drivers, config keys, commands), Symfony idioms
   where the frameworks differ.

## 2. Non-goals

- **No translation logic** (segmentation, prompts, validation, retries, fallbacks): that is the core
  ([ADR-0002](adr/0002-core-public-api-only.md)). Changes the bundle needs there are core issues.
- No facade, no static access, no fluent wrapper around the core API. Symfony code injects services.
- No UI beyond the profiler panel; an EasyAdmin integration would be a separate package.
- No Symfony version without bug or security support ([ADR-0005](adr/0005-supported-versions.md)).

## 3. Ubiquitous language

Core terms (unit, inline tag, profile, term policy, batch, provider, chat model, issue, fallback strategy) keep their
meaning — see the core's ARCHITECTURE §3. Added here, with the same meaning as in the Laravel package:

| Term | Meaning |
|---|---|
| **Provider entry** | A named entry under `html_translator.providers`. |
| **Driver** | The `driver` key of a provider entry: which core adapter and preset it maps to. |
| **Profile entry** | A named entry under `html_translator.profiles`; maps to a core `TranslationProfile`. |
| **Translator service** | `html_translator.translator.<name>`: a core `HtmlTranslator` for one provider entry. |

## 4. Public API

```php
use HtmlTranslator\Bundle\Options\TranslationOptionsFactory;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;
use Symfony\Component\DependencyInjection\Attribute\Target;

final class PageTranslator
{
    public function __construct(
        private HtmlTranslator $translator,                         // default provider
        #[Target('anthropic')] private HtmlTranslator $careful,     // Symfony 7.4 also: HtmlTranslator $anthropicTranslator
        private TranslationOptionsFactory $options,
    ) {}

    public function translate(string $html): string
    {
        $result = $this->careful->translate($html, Locale::fromString('de'), $this->options->create(profile: 'docs', source: 'en'));

        return $result->html();   // core TranslatedDocument: also isComplete(), issues(), stats()
    }
}
```

| Service / class | Purpose |
|---|---|
| `HtmlTranslator\HtmlTranslator` (alias) | Translator of `default_provider`. |
| `html_translator.translator.<name>` + named aliases | Translator of a provider entry. |
| `HtmlTranslator\Bundle\TranslatorRegistry` | Translators by name at runtime (`get(?string)`, `has()`, `names()`), backed by a service locator. |
| `HtmlTranslator\Bundle\Profile\ProfileRegistry` | `TranslationProfile` by entry name. |
| `HtmlTranslator\Bundle\Options\TranslationOptionsFactory` | `create(profile:, source:, fallback:, maxCorrectiveRetries:, batchSize:, tokenBudget:, onProgress:)` — config defaults plus overrides; profile as entry name or core object, source as string or `Locale`. |

```bash
bin/console html-translator:translate page.html --to=de --to=fr --from=en --provider=anthropic --translation-profile=docs --output=translations/
bin/console html-translator:check [--provider=openai] [--ping]
bin/console debug:config html_translator
```

Rules:

- The result is the core `TranslatedDocument`, never wrapped.
- Every bundle exception implements the core's `HtmlTranslator\Exception\TranslatorException`. Configuration errors
  that can only be found at runtime (env values) are `InvalidConfigurationException` naming the config path
  (`html_translator.providers.anthropic.api_key`).
- Translator services (`html_translator.translator.<name>`), the `HtmlTranslator` alias and the registries are public; internal services (`html_translator.chat_model.*`, `.provider.*`,
  factories) are private and may change without notice.

## 5. Container wiring

```
config/packages/html_translator.yaml
        │  Config component: types, enums, required keys, per-driver keys (compile time)
        ▼
 HtmlTranslatorBundle::loadExtension()            (AbstractBundle; prependExtension() registers cache.html_translator)
        │  registers definitions per provider entry:
        ▼
 html_translator.http_client      scoped HttpClient (timeout, max_duration) ─► Psr18Client (PSR-18 + PSR-17)
 html_translator.retrier          core Retrier(RetryPolicy)
 html_translator.chat_model.<n>   ChatModelFactory::create(<name>, <driver>, <entry with env placeholders>)   (runtime)
 html_translator.provider.<n>     LlmTranslationProvider(chat model) | PseudoLocalizationProvider
     └── (debug) .traceable       (Cacheable)TraceableTranslationProvider, as cacheable as the inner one ─► HtmlTranslatorDataCollector
 html_translator.translator.<n>   new HtmlTranslator(provider, cache pool, logger, SkipRules, TranslatableAttributes)
 TranslatorRegistry · ProfileRegistry · TranslationOptionsFactory · console commands
```

Rules:

- Everything that does not depend on env values is validated in the configuration tree, so `cache:clear` fails with
  a clear message. Values that can be `%env()%` (keys, models, URLs) are validated by `ChatModelFactory` at runtime
  ([ADR-0004](adr/0004-validation-at-compile-time-and-runtime.md)).
- `loadExtension()` only builds definitions; it never instantiates core objects. Core objects are created by factory
  services at runtime.
- Only the core's public API tier is used ([ADR-0002](adr/0002-core-public-api-only.md)).
- Optional integrations are wired only when their package is present (`class_exists` / `ContainerBuilder::willBeAvailable()`):
  Monolog channel, Web Profiler, later Messenger and Symfony AI.

## 6. Package layout

```
config/
  services.php                 static services (registries, factories, commands)
src/
  HtmlTranslatorBundle.php     AbstractBundle: configure(), loadExtension(), prependExtension()
  DependencyInjection/         definition builders per concern (providers, translators, cache, profiler) — no logic in the bundle class beyond delegation
  ChatModel/ChatModelFactory.php
  Profile/                     ProfileFactory, ProfileRegistry
  Options/                     TranslationOptionsFactory
  TranslatorRegistry.php
  Command/                     TranslateCommand, CheckCommand
  DataCollector/               TraceableTranslationProvider, HtmlTranslatorDataCollector
  Exception/                   InvalidConfigurationException
templates/Collector/html_translator.html.twig
tests/
  Unit/         configuration tree (Processor), factories — no kernel
  Functional/   TestKernel (MicroKernelTrait) per scenario: container wiring, aliases, commands, profiler, MockHttpClient end-to-end
  Integration/  real APIs, group "integration"
```

## 7. Testing strategy

| Layer | What | Runs |
|---|---|---|
| Unit | Configuration tree via `Processor` (valid configs, every invalid value, per-driver keys), factories | always |
| Functional | Booted `TestKernel` with config fixtures: services and aliases exist, named autowiring works, cache pool and logger injected, commands, profiler collector | always |
| HTTP | `MockHttpClient` with canned vendor JSON (from the core's fixtures) behind the real core adapters: config → service → HTTP request → `TranslatedDocument` | always |
| Integration | One real call per driver, `#[Group('integration')]` | manual / nightly |
| Mutation | Infection on `src/` | CI |

## 8. Relationship to the core and the Laravel package

- Core constraint is `^1.0`: the core is under its backward compatibility promise (core ADR-0018), so a core minor
  needs no bundle release. A new core feature is used only after raising the lower bound (e.g. `^1.1`).
- Config keys, driver names and command names match the Laravel package (`api_key`, `json_mode`, `refusal_fallbacks`,
  `html-translator:check`), so docs and users move between frameworks easily. Symfony naming (snake_case YAML, service
  ids, `#[Target]`) wins where the frameworks differ.
- Friction with the core is drafted in `docs/core-feedback/` (skill `draft-core-issue`). Since the 1.0 freeze the core
  only adds in a minor, so a draft names an additive change or waits for 2.0. Open draft relevant here: a translator
  interface for decoration (`docs/core-feedback/translator-interface.md`). Public `UntrustedText::quote()` shipped in
  core 1.0; the bundle's messages name config paths and never quote values, so it does not use it.
