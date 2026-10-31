# README, Flex recipe, integration tests and release 0.1.0

## Context
The core and the Laravel package test their documentation snippets; do the same. A contrib Flex recipe makes `composer require` configure the bundle.

## Scope
- README: install, `.env`, minimal YAML, injecting translators (default, named, `#[Target]`), profiles, options factory, cache, logging, profiler, commands, testing your app (pseudo driver in `when@test`, `MockHttpClient`), errors, secrets (`secrets:set`), link to the core docs.
- Snippet test: PHP snippets marked `<!-- runnable -->` run in a test kernel with the pseudo driver; YAML snippets processed by the configuration tree.
- Flex recipe for `symfony/recipes-contrib`: `config/packages/html_translator.yaml` (openai default, pseudo in `when@test`), `.env` entries; drafted in `docs/recipe/` and submitted as a PR to recipes-contrib after the release.
- Integration tests (`#[Group('integration')]`) per LLM driver with real keys, skipped without them; `.env.dist`.
- `docs/RELEASING.md` (adapted from the Laravel package), CHANGELOG `0.1.0`, Packagist.

## Acceptance criteria
- [ ] Every runnable README snippet is covered by the snippet test.
- [ ] `composer test:integration` passes locally against at least one OpenAI-compatible provider and Anthropic.
- [ ] Recipe validated with the recipes-contrib checks (or their documented manifest rules).
- [ ] Tag `v0.1.0` on Packagist; ROADMAP and core ROADMAP ("Then" section) link the release.
