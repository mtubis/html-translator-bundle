# Contributing

1. Read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/config-reference.yaml](docs/config-reference.yaml) and the
   [ADRs](docs/adr/). Translation behaviour belongs to the [core library](https://github.com/mtubis/html-translator) —
   report it there.
2. `composer install`, then make your change with tests.
3. `composer check` must pass (PHP-CS-Fixer, Rector, PHPStan at level max, PHPUnit).
4. Use [Conventional Commits](https://www.conventionalcommits.org/).

## Trying commands by hand

`tests/console` is the console of the functional test kernel:

```bash
HTML_TRANSLATOR_TEST_CONFIG=empty.yaml tests/console debug:config html_translator
# with a pseudo-driver fixture, once the providers exist (Bundle 0.1):
HTML_TRANSLATOR_TEST_CONFIG=pseudo.yaml tests/console html-translator:translate page.html --to=de
```

## Testing against several Symfony versions

```bash
composer require "symfony/*:7.4.*" --no-update   # or install symfony/flex globally and set SYMFONY_REQUIRE=7.4.*
composer update
```

Revert `composer.json` afterwards.

## Working on the core at the same time

Temporarily add a path repository to `composer.json` and revert it before committing:

```json
"repositories": [{ "type": "path", "url": "../html-translator", "options": { "symlink": true } }]
```

then `composer require mtubis/html-translator:"*@dev"`.

## Integration tests

Real API calls are in the `integration` group and are not run by default. Copy `.env.dist` to `.env`, add keys, then:

```bash
set -a; . ./.env; set +a; composer test:integration
```
