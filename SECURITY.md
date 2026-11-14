# Security policy

## Supported versions

Only the latest minor release receives security fixes while the bundle is 0.x.

| Version | Supported          |
|---------|--------------------|
| 0.1.x   | :white_check_mark: |

Security fixes are released as patch versions of the latest minor release.

## Reporting a vulnerability

Please do **not** open a public issue, discussion or pull request for a security problem.

Report it privately through GitHub:
[Report a vulnerability](https://github.com/mtubis/html-translator-bundle/security/advisories/new)
(the *Security* tab of the repository, then *Report a vulnerability*).

Include the bundle, core, Symfony and PHP versions, the configuration (`config/packages/html_translator.yaml`) and the
steps that reproduce the problem, and the impact you expect. Never include real API keys or other secrets; use
placeholders.

You can expect a first reply within 7 days. Once the report is confirmed, a fix is released as a patch version and a
GitHub security advisory is published, crediting you unless you prefer otherwise.

## Scope

Of particular interest:

- API keys or extra header values that leak into exception messages, logs, console output, Web Profiler data or the
  compiled container;
- Web Profiler data that renders unit texts or provider messages as markup instead of escaped text;
- configuration that sends requests somewhere other than the configured provider.

Problems in translation logic, HTML handling or the provider clients belong to the core,
[`mtubis/html-translator`](https://github.com/mtubis/html-translator), but reporting here is fine; the report is
forwarded.
