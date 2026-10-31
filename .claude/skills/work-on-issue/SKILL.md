---
name: work-on-issue
description: Use when asked to work on, implement, start or continue a GitHub issue in this repository (e.g. "work on #4", "implement issue 7"). Runs the plan → tests → implement → verify → review → commit loop.
---

# Work on an issue

Argument: issue number.

1. **Load context**
   - `gh issue view <n>` — scope, acceptance criteria, out-of-scope.
   - `docs/ARCHITECTURE.md` sections and ADRs the issue names; `docs/config-reference.yaml` for every config key involved.
   - The core code and docs you will call, in `vendor/mtubis/html-translator/`. Do not guess core signatures — read the constructors.
   - Existing bundle code and tests you will touch. For behaviour the Laravel package already has (drivers, commands, messages), look at its implementation for edge cases.
2. **Branch**: `git switch -c <type>/<n>-<short-slug>` unless already on one.
3. **Plan** (plan mode): files, service ids and aliases, config keys read, compile-time vs runtime split (ADR-0004), test cases mapped to each acceptance criterion. Flag anything that needs a core change (ADR-0002), conflicts with an ADR, or a new dependency. Wait for approval.
4. **Tests first.** Unit tests (no kernel) for the configuration tree and factories; functional tests with `TestKernel` for wiring. Stop and show the tests when the issue says "review tests first" or touches public services (ARCHITECTURE §4) or secrets.
5. **Implement** the minimum, following `CLAUDE.md`. Run the relevant tests often.
6. **Verify**: `composer check` green on the installed Symfony version; if the change uses a Symfony API, confirm it exists in 7.4 (read `vendor/symfony/...` or the 7.4 docs). Tick every acceptance criterion in your summary; say why if one is not met. For user-visible behaviour, also run it through `tests/console`.
7. **Review**: `architecture-reviewer` subagent on `git diff main...HEAD`. Fix real findings; list skipped ones with a reason.
8. **Commit** in logical Conventional Commits (`feat(config): …`, `feat(di): …`, `feat(console): …`, `test: …`), each ending with `Refs #<n>`. Update CHANGELOG `[Unreleased]` for user-visible changes.
9. **Report**: what changed, criteria status, core friction found (draft core issue titles), follow-ups. Do not push or open a PR unless asked.
