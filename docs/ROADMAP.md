# Roadmap

Each milestone is a GitHub milestone; each bullet is one issue = one Claude Code session = one PR.
Issue bodies are drafted in `docs/issues/<milestone>/` and created with `scripts/create-issues.sh`.

## Bundle 0.1 — configured translators, console and profiler
Released: [v0.1.0](https://github.com/mtubis/html-translator-bundle/releases/tag/v0.1.0).
Goal: `composer require`, set two env vars, inject `HtmlTranslator` and translate; `debug:config`, `html-translator:check`
and a profiler panel show what is going on. Configuration as in [config-reference.yaml](config-reference.yaml).

0. Bootstrap: tooling, `TestKernel`, `composer check` green
1. Configuration tree (`HtmlTranslatorBundle::configure()`): every key of the reference, per-driver keys, references between entries
2. HTTP and providers: scoped client + `Psr18Client`, retrier, `ChatModelFactory` with runtime validation, provider services per driver — end-to-end with `MockHttpClient`
3. Translators, profiles and options: translator services, default alias, named autowiring, `TranslatorRegistry`, `ProfileRegistry`, `TranslationOptionsFactory`, cache pool and Monolog channel
4. Console: `html-translator:translate`, `html-translator:check`
5. Profiler: `TraceableTranslationProvider`, data collector, panel template
6. README, Flex recipe (recipes-contrib), integration tests, release 0.1.0

## Bundle 0.2 — Messenger, events, testing helpers
- Events `HtmlTranslatedEvent` / `HtmlTranslationFailedEvent` (needs the core translator interface, see `docs/core-feedback/translator-interface.md`; otherwise dispatched from the Messenger handler only)
- Messenger: `TranslateHtml` message + handler with a destination contract (`TranslationTarget`), retry strategy and rate limiting per provider (`symfony/rate-limiter`)
- Testing helpers for applications: a recording translator and an assertions trait for `KernelTestCase`
- Doctrine: spike for translating entity fields (Gedmo Translatable, KnpLabs DoctrineBehaviors) as `TranslationTarget`s

## Bundle 0.3 — Symfony AI
- `symfony_ai` driver after Symfony AI 1.0 is stable (ADR-0008)

## Then
- Twig filter (`|translate_html('de')`, cache-only by default) — only if there is demand; translating at render time is expensive.
- EasyAdmin integration (the Symfony counterpart of the Filament plugin): "Translate with AI" action on rich-text fields, built on 0.2.
- Follow core releases (1.0 → `^1.0`), Symfony 8.2 (November 2026) and 8.3/8.4 LTS.
