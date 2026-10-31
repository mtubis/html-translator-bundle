# html-translator-bundle

Symfony integration of `mtubis/html-translator` (the **core**): configuration tree, DI services per provider, HttpClient, cache pools, Monolog, console, Web Profiler; later Messenger and Doctrine. All translation logic lives in the core.

## Read before planning
- `docs/ARCHITECTURE.md` — public services (§4), container wiring and its rules (§5), testing (§7).
- `docs/config-reference.yaml` — the configuration is the specification of what the bundle supports.
- `docs/adr/` — accepted decisions. Do not contradict an accepted ADR; propose a new one (skill `write-adr`).
- The GitHub issue (`gh issue view <n>`); its acceptance criteria are the definition of done.
- Core behaviour: `vendor/mtubis/html-translator/docs/` (usage.md, providers.md, profiles.md) and its `src/`. Read, never edit. The Laravel package (github.com/mtubis/html-translator-laravel) solved the same mapping problems — reuse its key names, messages and test ideas, not its framework code.

## Commands
- `composer check` — PHP-CS-Fixer (dry run), Rector dry-run, PHPStan (max + phpstan-symfony), PHPUnit. Must be green before you say you are done.
- `composer test` · `composer test:unit` · `composer test:functional` · `composer test:integration` (real APIs; do not run unless asked)
- `composer stan` · `composer cs:fix` · `composer rector` · `composer infection`
- `vendor/bin/phpunit --filter ConfigurationTest` · `tests/console <command>` (console of the test kernel)

## Rules
- PHP ^8.4, Symfony `^7.4 || ^8.1` (ADR-0005): use only APIs available in 7.4. `declare(strict_types=1);`, `final` classes, `readonly` where possible, constructor injection, attributes (`#[AsCommand]`, `#[Target]`) over YAML.
- **Core public API only** (ADR-0002): never inject `Segmenter`, `HtmlLoader`, `StructureValidator`, `Reassembler`, `Batcher`, `TermChecker`, `PromptBuilder`. Never edit `vendor/`, copy or subclass core classes. If the core needs a change, use the `draft-core-issue` skill and tell me.
- **Compile time vs runtime** (ADR-0004): the bundle class and `DependencyInjection\*` only build definitions — they never instantiate core objects or resolve env values. Enum-like keys are validated in the configuration tree; env-provided values in `ChatModelFactory` at runtime.
- Services are private by default. Public surface = the aliases and classes in ARCHITECTURE §4. Service ids are `html_translator.*`.
- Optional integrations (Monolog, WebProfiler, Twig, later Messenger, Symfony AI) are wired only when present; the bundle must boot without them.
- Every bundle exception implements `HtmlTranslator\Exception\TranslatorException`; runtime config errors are `InvalidConfigurationException` naming the config path.
- Secrets (API keys, extra header values) never appear in exception messages, logs, command output, profiler data, compiled container (no compile-time resolution) or test snapshots. Test this when you touch such code. Parameters receiving entries are `#[\SensitiveParameter]`.
- Config keys and command names match the Laravel package; Symfony conventions (snake_case YAML, service ids, autowiring aliases) where the frameworks differ.
- English for code, comments, docs and commits. Ask before adding a Composer dependency.

## Workflow
Use the `work-on-issue` skill for every issue: plan (plan mode) → tests first → implement → `composer check` → `architecture-reviewer` subagent → Conventional Commits with `Refs #<n>`. Public services from ARCHITECTURE §4 change only with an ADR.

## Testing conventions
- PHPUnit attributes, snake_case behaviour names (`it_rejects_an_unknown_effort`), as in the core.
- `tests/Unit` — no kernel (configuration tree via `Processor`, factories). `tests/Functional` — `TestKernel` with a config fixture per scenario; use the `pseudo` driver or `MockHttpClient` with canned vendor JSON. No network outside `#[Group('integration')]`.
- Each functional test boots its own kernel config; never rely on another test's cache directory (`TestKernel` uses a per-config cache dir).

## Don't
- Don't wrap or re-export `TranslatedDocument`; return the core result.
- Don't add `retry_failed` or other HTTP-level retries (the core `Retrier` retries).
- Don't edit `.github/`, `composer.json` dependencies or `.claude/` unless the issue asks for it. Don't push or open PRs unless asked.
