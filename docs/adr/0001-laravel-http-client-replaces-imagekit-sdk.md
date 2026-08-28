---
status: accepted
date: 2026-08-28
supersedes: KTD-1 in docs/plans/2026-08-27-002-chore-guzzle-8-sdk-fork-plan.md
---

# Replace the imagekit/imagekit SDK with a Laravel HTTP client in a separate package

`imagekit/imagekit` 4.0.2 pins Guzzle `~6 || ~7`, so Laravel 13 apps (Guzzle 8) cannot install this package without `-W`. Upstream `master` is stale (2024-09) and its Stainless-generated successor is untagged. We first planned to fork the SDK (KTD-1 in the plan above); we now drop the SDK entirely and build `thecyrilcril/imagekit-laravel-client`, a Laravel-native client on `Illuminate\Http\Client`, which this package requires. Reasons: no Packagist fork to own, fewer dependencies for users, `Http::fake()` works, no dependence on a stale upstream, and a client that moves with Laravel.

## Considered options

- **Fork the SDK** (rejected): one-line fix, but a permanent fork to maintain and a namespace collision when upstream ships its rewrite under the same package name.
- **In-package adapter for the 4 calls only** (rejected): smallest change, but the client is meant to cover the whole ImageKit API and be usable without the media-library glue.
- **Separate client package** (chosen).

## Consequences

- Scope of the client: the full current API for files, folders, bulk jobs, cache, custom metadata and versions; webhook signature verification; V2 JWT upload. Account/admin and saved-extension endpoints are out.
- Client shape: one entry point with one small interface per API area (`files()`, `folders()`, `cache()`, `customMetadata()`, `urls()`), typed DTOs, typed exceptions on non-2xx and transport errors, `http.timeout` and `http.retries` config, 429 handled by sleeping `X-RateLimit-Reset` when retries are on.
- Credentials (`public_key`, `private_key`, `url_endpoint`, `http.*`) move to the client's config. Presets, profiles, queue and folder stay here.
- Delivery: client `0.1.0` = the 4 calls this package needs (upload, delete, list, url) with full field coverage and the full ~65-code flat transformation map; this package `v0.6.0` switches to it and removes the SDK. Remaining areas and the fluent transformation builder (layers, conditionals, AI) follow in client `0.2.0+`. Client is published to Packagist before this package depends on it; no `path` repositories.
- Behaviour changes: unknown preset keys throw instead of passing through; uploads accept raw bytes, data URIs and public URLs; signed URLs follow the docs (encode, then sign), not the SDK's decode-then-sign.
- User-facing API of this package (`ImageKitClient` interface, `ImageKit` facade, `ImageKit::fake()`) is unchanged; hence a minor bump, not 1.0.
- Research backing this: `docs/research/2026-08-28-imagekit-api-vs-sdk.md`.
