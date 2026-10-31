# 0008. Symfony AI driver after Symfony AI 1.0 is stable

- Status: proposed
- Date: 2026-10-31

## Context
`symfony/ai-platform` and `symfony/ai-bundle` reached 1.0.0-BETA1 in October 2026 and are marked experimental (not covered by Symfony's BC promise). A `symfony_ai` driver — a core `ChatModelInterface` over a platform configured in `config/packages/ai.yaml` — would let applications reuse their AI configuration and keys.

## Decision
`symfony_ai` is a reserved driver name from 0.1 (a clear "planned" error). The adapter is built after Symfony AI 1.0.0 is stable, starting with a spike: structured output (the core sends a JSON schema, core ADR-0014), token usage, error mapping to `ProviderException`, and the core's `ChatModelContractTestCase`.

## Consequences
No dependency on an experimental package in 0.x; the direct drivers cover the same vendors meanwhile.
