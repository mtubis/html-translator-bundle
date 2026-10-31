---
name: add-provider-driver
description: Use when adding or changing a provider driver of html_translator.providers (openai, anthropic, ollama, openai_compatible, symfony_ai, …) or when the core adds a chat model adapter or preset the bundle should expose.
---

# Add or change a provider driver

A driver maps a config entry to a core chat model or provider. The bundle never implements vendor HTTP itself (ADR-0002): a new vendor adapter is a core issue first (core skill `add-translation-provider`).

1. Read the core adapter and config class in `vendor/mtubis/html-translator/src/Provider/Llm/Bridge/` and the section in `vendor/mtubis/html-translator/docs/providers.md`. List every parameter, type and default.
2. Check the Laravel package's driver of the same name (its `config/html-translator.php` and `Config/ChatModelFactory`): use the **same key names** and the same env variable names.
3. Specify the entry in `docs/config-reference.yaml` (keys, defaults, comment per non-obvious key) and in `Drivers` (allowed/required keys).
4. Tests first:
   - `tests/Unit/DependencyInjection/ConfigurationTest.php`: the entry processes; unknown keys, wrong enum values and missing required keys fail with the path.
   - `tests/Unit/ChatModel/ChatModelFactoryTest.php`: entry → expected core config values; empty env-provided values name the env variable; no secret in any message.
5. Implement: configuration tree node + `Drivers`, definition building in `DependencyInjection\`, runtime mapping in `ChatModelFactory` (re-throw core `InvalidArgumentException` as `InvalidConfigurationException` with the path).
6. Functional test with `MockHttpClient` and canned vendor JSON (shape from the core's `tests/Unit/Provider/Llm/Bridge/*/Fixtures/success.json`): the translator of the entry translates `<p>Hello</p>`; assert URL, method, auth header *name*, JSON body.
7. Integration test in `tests/Integration/` (`#[Group('integration')]`, skipped without the env variable); add the variable to `.env.dist`.
8. README providers table, `html-translator:check` expectations, CHANGELOG. `composer check` green.
