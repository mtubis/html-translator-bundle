# 0006. Profiler data through a traceable provider

- Status: accepted
- Date: 2026-10-31

## Context
Symfony developers expect a Web Profiler panel: what was translated in this request, how many batches and retries, which units failed, how many tokens (= cost). The natural place would be a decorator around the translator, but the core's `HtmlTranslator` is a `final readonly class` without an interface, so it cannot be decorated. `TranslationProviderInterface` (and `CacheableProviderInterface`) can.

## Decision
- In `kernel.debug` with `profiler.enabled`, each provider service is decorated by `TraceableTranslationProvider`, which records per batch: provider entry, locale pair, unit count, corrections (retries), failures and permanent failures, token usage, duration. It implements `CacheableProviderInterface` and delegates `cacheId()` when the inner provider does, so caching is unchanged; otherwise it implements only `TranslationProviderInterface`.
- `HtmlTranslatorDataCollector` (an `AbstractDataCollector`) shows the batches and totals; unit texts are truncated and escaped, never rendered as HTML.
- Document-level data (issues after fallback, cache hits) is out of reach until the core offers a translator interface: drafted as `docs/core-feedback/translator-interface.md`.

## Consequences
- Useful panel from 0.1 with no core change; no cost outside debug.
- The traceable provider must exactly preserve the inner provider's caching behaviour — covered by a functional test with a cache pool.
