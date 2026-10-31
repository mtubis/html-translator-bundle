# 0001. Record architecture decisions

- Status: accepted
- Date: 2026-10-31

## Context
As in the core and the Laravel package: the bundle is built mostly by coding agents steered by one maintainer, and agents start every session without memory.

## Decision
Significant decisions are recorded as short ADRs in `docs/adr/`, numbered sequentially, using `0000-template.md`. Agents read the relevant ADRs before planning and propose a new ADR instead of deviating from an accepted one. References to other repositories are written as "core ADR-0011" and "Laravel ADR-0004".

## Consequences
Consistent decisions across sessions; changes of direction are explicit.
