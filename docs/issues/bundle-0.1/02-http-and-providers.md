# HTTP client, chat models and provider services per driver

## Context
ADR-0003 (HTTP), ADR-0004 (runtime validation), core `docs/providers.md` in `vendor/mtubis/html-translator/`. Use the `add-provider-driver` skill.

## Scope
- `html_translator.http_client`: scoped client from FrameworkBundle's `http_client` with `timeout`/`max_duration`, or the service named in `http.client`; wrapped as `Psr18Client` (`html_translator.psr18_client`).
- `html_translator.retrier`: core `Retrier(new RetryPolicy(...))` from `http.retry`.
- `ChatModel\ChatModelFactory` (runtime service): `create(string $name, string $driver, array $entry): ChatModelInterface` — maps every LLM driver to its core config preset/constructor and chat model; validates env-provided values; re-throws core `InvalidArgumentException` as `InvalidConfigurationException` with the path; parameters holding entries are `#[\SensitiveParameter]`.
- Definitions per provider entry: `html_translator.chat_model.<name>` (factory service) and `html_translator.provider.<name>` (`LlmTranslationProvider` or `PseudoLocalizationProvider`, incl. `corrupt_every`).

## Acceptance criteria
- [ ] Unit tests for `ChatModelFactory`: each driver → expected core config values (public properties, never the key); empty/missing env values name the env variable from the reference config; secrets never in messages.
- [ ] Functional test per LLM driver with `MockHttpClient` and canned vendor JSON (copy from the core's adapter fixtures): translator translates `<p>Hello</p>`; the mock sees URL, method, auth header name and JSON body.
- [ ] A 429 then 200 from the mock is retried by the core `Retrier` (with a fake sleeper or `max_delay_ms: 0`).
- [ ] `composer check` green.
