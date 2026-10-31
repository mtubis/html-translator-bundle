# 0004. Validation at compile time where possible, at runtime for env values

- Status: accepted
- Date: 2026-10-31

## Context
Symfony's Config component validates configuration when the container is compiled: types, enums, required keys, defaults, with the path in the message (`Invalid configuration for path "html_translator.providers.anthropic.effort"`). But API keys, models and URLs are typically `%env(...)%` placeholders, whose values are only known at runtime; an `enumNode` cannot validate a placeholder, and secrets must never be resolved at compile time (they would be dumped into the container cache).

## Decision
- **Compile time (configuration tree)**: structure, known keys per driver, enums (`driver`, `json_mode`, `effort`, `fallback`, `term_policy`, `formality`, `preset`), integer ranges, required keys present, references (`default_provider` and `default_profile` name existing entries). Enum-valued keys are plain values (no env placeholders); this is documented with `->info()`.
- **Runtime (`ChatModel\ChatModelFactory`)**: values that may come from env — `api_key`, `model`, `base_url`, `name`, `extra_headers` — are checked when the chat-model service is first created; failures, including core `InvalidArgumentException`s from config constructors, become `InvalidConfigurationException` naming the path (`html_translator.providers.openai.api_key: is empty; set OPENAI_API_KEY`). Secrets are never quoted.
- `html-translator:check` instantiates every provider entry and reports all runtime problems at once.
- Literal secrets in YAML are accepted but the README recommends env vars or Symfony secrets (`secrets:set`), because literals end up in the compiled container.

## Consequences
- Most mistakes fail at `cache:clear` with Symfony's familiar messages; the rest at first use or with `html-translator:check`.
- Two validation layers to keep consistent; per-driver key lists live in one place (a `Drivers` class) used by both.
