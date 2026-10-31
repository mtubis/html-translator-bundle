# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Configuration tree for every key of `docs/config-reference.yaml`, validated at compile time: keys and required keys per driver, enums, ranges, `default_provider` and `default_profile` references. The `symfony_ai` driver is reserved for 0.3. A `default` profile always exists.
- Project skeleton: architecture, ADRs 0001–0008, configuration specification (`docs/config-reference.yaml`), `HtmlTranslatorBundle` with an empty configuration tree, test kernel.
