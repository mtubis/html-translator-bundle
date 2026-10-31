# 0005. Supported PHP and Symfony versions

- Status: accepted
- Date: 2026-10-31

## Context
The core requires PHP ^8.4. In October 2026: Symfony 7.4 is the LTS (bug fixes until November 2028), 8.1 the current minor (until January 2027), 8.0 is end of life, 8.2 ships in November 2026. Many Symfony applications — especially in enterprise and agency settings — stay on the LTS.

## Decision
- PHP `^8.4`, tested on 8.4 and 8.5.
- Symfony components `^7.4 || ^8.1`. CI matrix: 7.4.* and the latest 8.x (8.1.* now, 8.2.* once released), plus lowest dependencies on 7.4.
- An 8.x minor is dropped from CI when it reaches end of maintenance; 7.4 stays until its bug-fix window ends.

## Consequences
- LTS users are served; only APIs available in 7.4 may be used (no 8.x-only features without a version check).
- Deprecations triggered on 8.x must be fixed promptly (`failOnDeprecation` in PHPUnit).
