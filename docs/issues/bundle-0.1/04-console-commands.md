# Console: html-translator:translate and html-translator:check

## Context
ARCHITECTURE §4, ADR-0004. Same command names and options as the Laravel package (its `TranslateCommand`/`CheckCommand` are a useful reference for behaviour and messages — read them on GitHub, do not copy framework-specific code). Commands are thin; logic lives in services.

## Scope
- `html-translator:translate <input> --to=… (multiple) --from= --provider= --profile= --fallback= --output=` — file or `-` for STDIN; one file per locale when several `--to` or `--output` is a directory; STDOUT for one target without `--output`; `ProgressBar` per batch via `onProgress`; per-document summary (units, batches, cache hits, retries, tokens, issues); exit code 1 when incomplete. `#[AsCommand]`, `SymfonyStyle`.
- `html-translator:check [--provider=…] [--ping]` — instantiates every (or the named) provider entry and profile, lists all runtime configuration problems at once; `--ping` translates `<p>ok</p>` once per provider; exit code 1 on any error; never prints secrets.

## Acceptance criteria
- [ ] `CommandTester` tests with the pseudo driver: file, STDIN, several targets, `--output` file/dir, incomplete result → exit 1, unknown provider/profile messages.
- [ ] `check` reports two broken entries (empty env key, invalid base URL) in one run; a test asserts the fake key never appears in the output.
- [ ] `composer check` green.
