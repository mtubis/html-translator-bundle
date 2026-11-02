# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- `json_mode` for the `gemini`, `ollama`, `vllm` and `lm_studio` drivers, passed to the core preset; unset, the
  preset's default applies. `json_object` brings back the 0.1 behaviour of `gemini`, `ollama` and `vllm` for a server
  without structured outputs.

### Changed
- Requires `mtubis/html-translator` ^1.0 (was ^0.4); see the core's
  [UPGRADE-1.0.md](https://github.com/mtubis/html-translator/blob/v1.0.0/UPGRADE-1.0.md). Core changes that reach
  every application:
  - Behaviour change: the `gemini`, `ollama` and `vllm` drivers send the answer schema (`json_schema`) instead of
    `json_object`; an older server without structured outputs answers HTTP 400 (set `json_mode: json_object`).
  - A batch the model refuses is sent again in halves until the refused units are found (core ADR-0019), which costs
    further calls; token stats also count failed calls that reported usage, so totals may come out higher.
  - A profile `glossary` written as a YAML list instead of a map is rejected with an `InvalidConfigurationException`
    for `html_translator.profiles.<name>.glossary` (it used to become a glossary of the terms "0", "1", …).
- `do_not_translate` terms are passed with `TranslationProfile::withDoNotTranslateTerms()`.

## [0.1.0] - 2026-11-01

First release: configured translators for every core driver, named autowiring, profiles and options, unit cache and
Monolog channel, the console commands `html-translator:translate` and `html-translator:check`, and a Web Profiler panel
(ADR-0001 to ADR-0007; `symfony_ai` is reserved for 0.3, ADR-0008).

### Added
- README with tested snippets (PHP snippets run against an offline kernel, YAML snippets through the configuration tree), a Flex recipe draft for symfony/recipes-contrib (`docs/recipe/`) and `docs/RELEASING.md`.
- Web Profiler panel (ADR-0006): in `kernel.debug` with WebProfilerBundle and `profiler.enabled`, every provider service is decorated by a traceable provider (exactly as cacheable as the inner one, so the unit cache keeps working) and the `html_translator` collector shows each batch (provider, locales, units, corrections, failures, tokens, time, escaped unit previews) with totals in the toolbar. Profiles on disk (`var/cache/dev/profiler`) therefore contain truncated unit texts of the translated pages; provider error messages are kept only for the core's (redacted) exceptions.
- Console: `html-translator:translate <input|-> --to=… [--from] [--provider] [--translation-profile] [--fallback] [--output=file|dir/|path-with-{locale}]` (HTML to STDOUT or files, progress and a summary per locale on STDERR, exit 1 when incomplete) and `html-translator:check [--provider=…] [--ping]` (creates every provider entry and profile and lists all runtime configuration problems at once, never printing secrets). The profile option is `--translation-profile` because FrameworkBundle's console reserves `--profile`.
- Public API (ARCHITECTURE §4): `html_translator.translator.<name>` per provider entry, the `HtmlTranslator\HtmlTranslator` alias for `default_provider`, named autowiring (`#[Target('<name>')]`; on Symfony 7.4 also `HtmlTranslator $<camelName>Translator`, which Symfony 8.1 deprecates), entry names validated so they cannot collide as aliases, `TranslatorRegistry`, `Profile\ProfileRegistry` and `Options\TranslationOptionsFactory`. Translators share the unit cache (`cache.html_translator` on `cache.app` with FrameworkBundle, or the pool named in `cache.pool`), the `html_translator` Monolog channel (or the `logger` service) and the `html.*` rules.
- Provider services per entry: a core chat model and `LlmTranslationProvider` for every LLM driver (openai, gemini, ollama, vllm, lm_studio, openai_compatible, anthropic) and `PseudoLocalizationProvider` for `pseudo`. HTTP goes through FrameworkBundle's `http_client` scoped with `http.timeout`/`http.max_duration` (or the service named in `http.client`, or a client of its own without FrameworkBundle), wrapped as PSR-18; retries through the core `Retrier` configured by `http.retry`. Values from env are checked when a provider is first used; errors name the config path and the env variable of the reference configuration, never a secret.
- Configuration tree for every key of `docs/config-reference.yaml`, validated at compile time: keys and required keys per driver, enums, ranges, `default_provider` and `default_profile` references. The `symfony_ai` driver is reserved for 0.3. A `default` profile always exists.
- Architecture, ADRs 0001–0008 and the configuration specification (`docs/config-reference.yaml`).

[Unreleased]: https://github.com/mtubis/html-translator-bundle/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/mtubis/html-translator-bundle/releases/tag/v0.1.0
