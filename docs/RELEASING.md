# Releasing

Versions follow [Semantic Versioning](https://semver.org/); while the version is 0.x, a minor version may break the
public API (ARCHITECTURE §4), and the CHANGELOG says how. Releases are cut from `main`.

## Checklist

1. **Green build**: `main` is up to date and `composer check` passes locally and in CI (all jobs, lowest dependencies
   and mutation testing included).
2. **Real APIs**: `set -a; . ./.env; set +a; composer test:integration` passes with keys for at least one
   OpenAI-compatible provider and Anthropic (it costs a few tokens; see `.env.dist`; each provider without its
   variables is skipped; ollama needs a local `ollama serve`, and `OLLAMA_BASE_URL` must end with `/v1`, the
   OpenAI-compatible API, or the requests fail with 404).
3. **Documentation**: the README snippets are tested (`composer test` includes `tests/Documentation`); check that the
   README's providers table and [config-reference.yaml](config-reference.yaml) match the release.
4. **CHANGELOG**: move the entries of `[Unreleased]` into a new `## [X.Y.Z] - YYYY-MM-DD` section (the tag date), with
   a one-line summary and links to the ADRs of new decisions. Update the link references at the bottom: `[Unreleased]`
   compares `vX.Y.Z...HEAD`, `[X.Y.Z]` points to the release tag.
5. **Version constraints**: the core constraint in `composer.json` is `^1.0`, raised only for a core feature the bundle uses (ADR-0002), and
   Symfony stays `^7.4 || ^8.1` (ADR-0005) unless an ADR says otherwise.
6. **Roadmap**: mark the milestone as done in [ROADMAP](ROADMAP.md) and close the GitHub milestone.
7. **Commit and merge** these changes (`docs: release X.Y.Z`) through a pull request.
8. **Tag and publish** (maintainer only): tag the merge commit on `main` with `vX.Y.Z`, push the tag, create the GitHub
   release from the CHANGELOG section and check that Packagist picked up the version. If the tag is created on a later
   day than the CHANGELOG date, correct the date first.

```bash
git switch main && git pull
git log -1 --oneline        # the merge commit of the release pull request
git tag -a vX.Y.Z -m "X.Y.Z"
git push origin vX.Y.Z
gh release create vX.Y.Z --verify-tag --title "X.Y.Z" \
    --notes "$(awk '/^## \[X.Y.Z\]/{f=1;next} f&&/^(## \[|\[[^]]+\]: )/{exit} f' CHANGELOG.md)"
```

9. **Recipe**: when the recipe changed (or for the first release), submit [docs/recipe](recipe/README.md) to
   symfony/recipes-contrib as `mtubis/html-translator-bundle/<minor>/`; a new minor without recipe changes needs none.
10. **Announce**: link the release in the core's ROADMAP ("Then" section) through a pull request in
    mtubis/html-translator.

## First release only: Packagist

1. Sign in at [packagist.org](https://packagist.org) with the GitHub account that owns the repository.
2. **Submit** `https://github.com/mtubis/html-translator-bundle`; the package name comes from `composer.json`
   (`mtubis/html-translator-bundle`).
3. Enable automatic updates (the GitHub hook, or the Packagist GitHub app) so later tags appear without a manual update.
4. Check that `composer require mtubis/html-translator-bundle` resolves `v0.1.0` in fresh Symfony applications
   (`symfony new app --webapp --version=7.4` and `--version=8.1`), and that `bin/console html-translator:check` and the
   profiler panel work there. Until the recipe is merged in recipes-contrib, `composer require` installs no recipe:
   configure as described in the README ("Without Flex"); afterwards Flex asks before running the contrib recipe.
