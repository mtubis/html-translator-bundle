# An interface for HtmlTranslator, so integrations can decorate and replace it

## Context
`HtmlTranslator` is a `final readonly class` without an interface. Both framework integrations need to put something
in front of it:

- **Symfony bundle**: the Web Profiler panel and (0.2) events need document-level data — issues after fallback, cache
  hits, `isComplete()` — which only `translate()` sees. Today the bundle can decorate only the provider (bundle
  ADR-0006), so the panel shows batches but not the final issues.
- **Laravel package**: `HtmlTranslation::fake()` and the planned `HtmlTranslated` event wrap the manager instead
  (Laravel ADR-0006, contract `TranslatesHtml`), because a translator cannot be swapped in the container.
- **Applications**: code that injects `HtmlTranslator` cannot replace it with a test double; users must build a real
  translator around `PseudoLocalizationProvider`.

Before the 1.0 freeze this is cheap to add; afterwards a new interface method is a BC break (core issue "Public API
review and the backward compatibility promise", open question on interfaces).

Bundle issue: mtubis/html-translator-bundle#<n> (profiler).

## Scope
- `HtmlTranslatorInterface` (name to be decided in the ADR) with exactly
  `translate(string $html, Locale $target, ?TranslationOptions $options = null): TranslatedDocument`;
  `HtmlTranslator` implements it.
- Docs: "inject the interface" in `docs/usage.md`; the BC promise says the interface gets no new methods in 1.x.
- Consider a `Testing\` double (e.g. a recording translator) or document `new HtmlTranslator(new PseudoLocalizationProvider())`
  as the test double.

## Acceptance criteria
- [ ] ADR accepted (interface name, BC rules); `HtmlTranslator` implements the interface.
- [ ] A decorator in a test can wrap `HtmlTranslator` through the interface and see the `TranslatedDocument`.
- [ ] API snapshot updated; `composer check` green.

## Out of scope
Changing `translate()`'s signature or result.
