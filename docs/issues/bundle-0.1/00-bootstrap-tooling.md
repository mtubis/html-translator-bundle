# Bootstrap: dev tooling, TestKernel, `composer check` green

## Context
The skeleton ships configuration for PHPUnit, PHPStan (with phpstan-symfony), PHP-CS-Fixer, Rector and Infection, a minimal `HtmlTranslatorBundle` and a `TestKernel`, written without being run. `require` and `require-dev` are incomplete on purpose: install current versions. This is also the first dry run of the Claude Code workflow here.

## Scope
- `composer require mtubis/html-translator:^0.4 symfony/config:"^7.4 || ^8.1" symfony/dependency-injection:"^7.4 || ^8.1" symfony/http-kernel:"^7.4 || ^8.1" symfony/http-client:"^7.4 || ^8.1" symfony/console:"^7.4 || ^8.1" nyholm/psr7` (check which components the code really needs; `symfony/framework-bundle` only in `require-dev` unless a hard dependency is unavoidable — record the decision in the PR).
- `composer require --dev symfony/framework-bundle symfony/yaml symfony/monolog-bundle symfony/web-profiler-bundle symfony/twig-bundle phpunit/phpunit phpstan/phpstan phpstan/phpstan-symfony phpstan/phpstan-strict-rules phpstan/phpstan-phpunit phpstan/extension-installer friendsofphp/php-cs-fixer rector/rector infection/infection`
- Make `composer check` and `composer infection` run; fix configuration problems, prefer fixing config over relaxing rules.
- Verify the PostToolUse hook (cs-fixer + PHPStan on the edited file).

## Acceptance criteria
- [ ] `composer validate --strict` passes; caret constraints, Symfony as `^7.4 || ^8.1`.
- [ ] `BundleBootTest` boots `TestKernel` and finds the bundle's extension alias `html_translator`.
- [ ] `composer check` green on PHP 8.4 with Symfony 7.4 and 8.1 (`composer require symfony/*:8.1.* --no-update` locally or via CI).
- [ ] CI green on the first push.
- [ ] Any rule deliberately relaxed is listed in the PR description with a reason.
