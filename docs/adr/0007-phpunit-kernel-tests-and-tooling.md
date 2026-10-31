# 0007. PHPUnit with test kernels; the core's tooling

- Status: accepted
- Date: 2026-10-31

## Context
One maintainer works across three repositories with the same agents and review rules. Bundle tests need a booted container, but not a full application.

## Decision
- PHPUnit (core's version line and conventions) with a small `Tests\Functional\TestKernel` (`MicroKernelTrait`, FrameworkBundle + this bundle, optional Monolog/WebProfiler/Twig per test) configured per test from YAML or PHP fixtures. No third-party bundle-test helper.
- PHPStan at level max with `phpstan/phpstan-symfony` and strict rules; PHP-CS-Fixer with the core's configuration (the Symfony ecosystem's tool); Rector and Infection as in the core.

## Consequences
Same commands (`composer check`), same review rules and same style in all three repositories.
