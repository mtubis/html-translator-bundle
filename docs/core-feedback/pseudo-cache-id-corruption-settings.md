# PseudoLocalizationProvider::cacheId() should include the corruption settings

> **Status: fixed** in core 0.4.1 (mtubis/html-translator#93); the cache id names the corruption settings. Kept for
> the record.

## Context
`PseudoLocalizationProvider::cacheId()` returns the constant `pseudo-localization` for every instance. Its docblock
says corrupted translations are invalid and so never reach a cache. That holds, but the other direction breaks: a
corrupting provider (`corruptEvery: 1`) that shares a unit cache with a clean one is answered from the clean
provider's entries. It then corrupts nothing, which defeats its purpose of exercising fallbacks and issue handling.

The Symfony bundle shares one cache pool across all provider entries (bundle #4), and so does the Laravel package.
An application with a `pseudo` entry for development and a corrupting entry for fallback tests, e.g.
`providers: { pseudo: { driver: pseudo }, broken: { driver: pseudo, corrupt_every: 1 } }`, gets no issues from
`broken` once `pseudo` has translated the same text. The bundle's own test fixtures disable the cache to avoid this.

Bundle issue: mtubis/html-translator-bundle#4.

## Reproduction (core only)
```php
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\TranslationOptions;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

$cache = new ArrayAdapter();
$options = new TranslationOptions(maxCorrectiveRetries: 0);
new HtmlTranslator(new PseudoLocalizationProvider(), $cache)->translate('<p>Hello <b>world</b></p>', Locale::fromString('pl'), $options);
$document = new HtmlTranslator(new PseudoLocalizationProvider(corruptEvery: 1), $cache)->translate('<p>Hello <b>world</b></p>', Locale::fromString('pl'), $options);

// Prints "cacheHits=1 issues=0": the corrupting provider was never asked.
printf("cacheHits=%d issues=%d\n", $document->stats()->cacheHits, count($document->issues()));
```

## Scope
- `cacheId()` reflects the settings that change the output: e.g. `pseudo-localization` for a clean provider and
  `pseudo-localization+corrupt=<n>[+retries]` for a corrupting one. Alternatively, a corrupting provider does not
  implement caching at all (decided in the change).
- Update the docblock: the reason is about reads, not only writes.

## Acceptance criteria
- [ ] Two providers with different `corruptEvery`/`corruptRetries` have different `cacheId()`s; two clean providers
  keep sharing entries.
- [ ] The reproduction above reports the corrupted unit as an issue (`cacheHits=0`).
- [ ] CHANGELOG entry (a cache id change invalidates existing pseudo entries, which is harmless).

## Out of scope
- Cache ids of the LLM providers (already include model and prompt version).
