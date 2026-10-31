# Profiler panel: traceable provider and data collector

## Context
ADR-0006. The panel shows batch-level data only; document-level data needs the core change drafted in `docs/core-feedback/translator-interface.md`.

## Scope
- `DataCollector\TraceableTranslationProvider` decorating `html_translator.provider.<name>` when `profiler.enabled` (default `%kernel.debug%`) and WebProfilerBundle is present. Two classes or one with a cacheable variant, so that `CacheableProviderInterface` is implemented exactly when the inner provider implements it.
- `HtmlTranslatorDataCollector` (`AbstractDataCollector`, `reset()` between requests): per batch — provider entry, locales, units, corrections, failures, permanent failures, input/output tokens, duration; totals in the toolbar (batches, tokens, failures).
- `templates/Collector/html_translator.html.twig`: toolbar item + panel; unit texts truncated, escaped, never `|raw`.

## Acceptance criteria
- [ ] Functional test: request through the test kernel with profiler enabled → collector has the batches and totals.
- [ ] Caching unchanged with the decorator (the cache-hit test of the "Translator services…" issue passes with profiler enabled).
- [ ] Not registered when `kernel.debug` is false or WebProfilerBundle is missing.
- [ ] Template renders in a test (Twig available) and escapes `<script>` in unit text.
- [ ] `composer check` green.
