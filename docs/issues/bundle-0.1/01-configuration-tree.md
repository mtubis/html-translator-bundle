# Configuration tree

## Context
ADR-0004 (what is validated at compile time), `docs/config-reference.yaml` (the specification), ARCHITECTURE §5. Laravel's `config/html-translator.php` and its `ChatModelFactory::KEYS` show the same keys per driver — keep names identical.

## Scope
- `HtmlTranslatorBundle::configure(DefinitionConfigurator)` — or a `DependencyInjection\Configuration` class it delegates to, if that keeps the bundle class small — covering every key of the reference with `->info()` texts and defaults.
- `src/Drivers.php` (or similar): the single list of drivers and their allowed/required keys, used by the tree and later by `ChatModelFactory`.
- Per-driver validation with `validate()->ifTrue()`: unknown keys for the driver, missing required keys, `pseudo` options only on `pseudo`. Reserved driver `symfony_ai` → "planned for 0.3" (ADR-0008).
- Cross-references: `default_provider` ∈ providers, `default_profile` ∈ profiles; at least one provider.
- Enum nodes for `driver`, `json_mode`, `effort`, `preset`, `formality`, `term_policy`, `fallback` — values taken from the core enums (`JsonMode::cases()`, …) where they are backed; `fallback` and `preset` mapped explicitly.
- Env placeholders allowed for `api_key`, `model`, `base_url`, `name`, `extra_headers` values, `timeout`.

## Acceptance criteria
- [ ] Unit tests with `Processor` (no kernel): the full reference config (comments removed) processes; one test per invalid value/type/unknown key/missing key/bad reference, asserting the path in the message.
- [ ] `config:dump-reference html_translator` output is compared with a committed snapshot (functional test), so tree changes are visible in review.
- [ ] A test proves an `%env()%` api_key is not resolved at compile time.
- [ ] `composer check` green; Infection MSI for the tree ≥ 85 %.
