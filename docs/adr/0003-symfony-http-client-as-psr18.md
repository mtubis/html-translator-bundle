# 0003. Symfony HttpClient (Psr18Client) as the PSR-18 client

- Status: accepted
- Date: 2026-10-31

## Context
The core adapters need a PSR-18 client and PSR-17 factories (core ADR-0002). Symfony's `Psr18Client` wraps any `HttpClientInterface` and is PSR-18 and PSR-17 at once (with `nyholm/psr7` or another PSR-17 implementation installed). Using FrameworkBundle's `http_client` gives the HTTP profiler panel and lets applications replace the transport with `MockHttpClient` in tests. Unlike Laravel (Laravel ADR-0004) no adapter of our own is needed. The core already keeps transport exceptions out of its exception chains (core docs/providers.md, "secrets"), so `Psr18Client`'s exceptions holding the request are not a leak.

## Decision
- Default: the bundle registers a scoped client `html_translator.http_client` derived from `http_client` (FrameworkBundle) with `timeout` and `max_duration` from config, wrapped in `Symfony\Component\HttpClient\Psr18Client` as `html_translator.psr18_client`. It is passed to the core adapters as client, request factory and stream factory.
- `http.client` may name another `HttpClientInterface` service; it is wrapped the same way.
- No `retry_failed` on the bundle's client: the core `Retrier` retries (config `http.retry`), double retries would multiply cost.
- Require `symfony/http-client` and `nyholm/psr7`.

## Consequences
- Model calls appear in the profiler's HTTP panel; application tests can use `MockHttpClient` (documented in the README).
- A functional test proves that a configured `MockHttpClient` receives the exact request the core adapter built.
