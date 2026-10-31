# Translator services, named autowiring, profiles, options, cache and logging

## Context
ARCHITECTURE §4 is the specification of the public services — plan exact class names and signatures first and get them approved.

## Scope
- `html_translator.translator.<name>`: `new HtmlTranslator(provider, cache, logger, skipRules: …, translatableAttributes: …)`; `HtmlTranslator\HtmlTranslator` alias → default provider; `registerAliasForArgument()` for `HtmlTranslator $<name>Translator` and the `#[Target('<name>')]` form.
- `TranslatorRegistry` over a service locator of all translators.
- `Profile\ProfileFactory` + `Profile\ProfileRegistry` (profiles built once, at runtime, from processed config).
- `Options\TranslationOptionsFactory::create(...)` — config `options` defaults, profile by entry name or core object (`default_profile` when omitted), source as string/`Locale`/null, other overrides; `fallback` mapping to `FallbackStrategy`.
- `SkipRules` / `TranslatableAttributes` from `html.*`.
- Cache: `prependExtension()` registers pool `cache.html_translator` (adapter `cache.app`) when FrameworkBundle is present; `cache.pool` may name another pool; `cache.enabled: false` → no cache.
- Logging: `monolog.logger` tag with channel `html_translator` when MonologBundle is registered, else `logger`, `logging.enabled: false` → none.

## Acceptance criteria
- [ ] Functional tests (pseudo driver): default alias, named alias, `#[Target]` in a test service, registry names, unknown name errors.
- [ ] Options: every override lands in `TranslationOptions`; unknown profile name → `InvalidConfigurationException` listing known profiles.
- [ ] Cache: second translation of the same HTML has `cacheHits === units`; `enabled: false` → 0 hits; custom pool used.
- [ ] Monolog channel test with MonologBundle in the kernel; without it, the `logger` service.
- [ ] Private internals: a test asserts that only the documented services/aliases are public.
- [ ] `composer check` green.
