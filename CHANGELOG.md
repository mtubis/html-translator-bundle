# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Provider services per entry: a core chat model and `LlmTranslationProvider` for every LLM driver (openai, gemini, ollama, vllm, lm_studio, openai_compatible, anthropic) and `PseudoLocalizationProvider` for `pseudo`. HTTP goes through FrameworkBundle's `http_client` scoped with `http.timeout`/`http.max_duration` (or the service named in `http.client`, or a client of its own without FrameworkBundle), wrapped as PSR-18; retries through the core `Retrier` configured by `http.retry`. Values from env are checked when a provider is first used; errors name the config path and the env variable of the reference configuration, never a secret.
- Configuration tree for every key of `docs/config-reference.yaml`, validated at compile time: keys and required keys per driver, enums, ranges, `default_provider` and `default_profile` references. The `symfony_ai` driver is reserved for 0.3. A `default` profile always exists.
- Project skeleton: architecture, ADRs 0001–0008, configuration specification (`docs/config-reference.yaml`), `HtmlTranslatorBundle` with an empty configuration tree, test kernel.
