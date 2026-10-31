# 0002. Use only the core's public API; core changes go to the core

- Status: accepted
- Date: 2026-10-31

## Context
Same situation as Laravel ADR-0002: the core is 0.x, and its 1.0 freeze will mark pipeline internals `@internal`. A bundle that reaches into them, or patches around them, breaks on every core refactoring.

## Decision
- The bundle uses the core's public tier only: `HtmlTranslator` constructed with provider, cache, logger, `SkipRules`, `TranslatableAttributes`; `TranslationOptions`, results, profiles, exceptions, provider and chat-model ports, adapters and their configs, `Retrier`/`RetryPolicy`, `PseudoLocalizationProvider`. It never injects `HtmlLoader`, `Segmenter`, `StructureValidator`, `Reassembler`, `Batcher`, `TermChecker` or `PromptBuilder`.
- No edits in `vendor/`, no copies or subclasses of core classes. A needed change is drafted in `docs/core-feedback/` (skill `draft-core-issue`); a temporary workaround is marked `TODO(core#<n>)` and has a test that fails once the core is fixed.
- Composer constraint: the core's current minor (`^0.4`), `^1.0` after the freeze.

## Consequences
Core refactorings do not break the bundle; friction surfaces as core issues before the freeze.
