# Changelog

All notable changes to `laravel-imagekit` will be documented in this file.

## v0.3.0

### Breaking

- **Invalid compression profiles now throw.** A profile with a `quality` outside 1–100, a `max_edge` below 1, or a non-string `format` throws `Thecyrilcril\ImageKit\Exceptions\InvalidProfile` the first time it is loaded, naming the profile and the field. Previously the values were clamped or coerced silently. Integers must be real integers: a numeric string such as `'90'` from `env()` is rejected, so cast in config. Validation runs on first use, not at boot, so a profile that is never used never throws.
- **`ImageKitManager` is now `final` and is no longer bound on its own.** Type-hint the new `Thecyrilcril\ImageKit\Contracts\ImageKitClient` contract instead. The facade, the service provider and `MediaAddedListener` all resolve the contract; `ImageKit::fake()` swaps it, so the fake is also `final` and reaches injected consumers. Any constructor or method that injects `ImageKitManager` will fail to resolve after upgrading.
- **Uploads move under the root folder.** Both the queued job and `uploadNow()` now upload to `{imagekit.folder}/{collection}`; before, the sync path used `{collection}` alone and the queued path only fell back to the folder when the collection name was empty. Leading and trailing slashes on the folder are trimmed. Files already on ImageKit are not moved and keep their old paths; only new uploads use the root. Run `imagekit:reconcile` with `--folder=<root>` on a shared account.
- **Default path change if you never set `IMAGEKIT_FOLDER`.** The default root stays `uploads`, so new uploads go from `/{collection}/...` to `/uploads/{collection}/...`. Set `IMAGEKIT_FOLDER` per application before upgrading if you want a different root.

### Added

- `Thecyrilcril\ImageKit\Contracts\ImageKitClient`, the single public API contract (`upload`, `uploadNow`, `url`, `delete`, `backfill`).
- `ImageKit::fake()` now intercepts queued uploads triggered by `MediaAddedListener` as well as awaited ones. Previously only `await: true` collections were faked, and every shipped profile is `await: false`.
- The fake answers `url()` with the real URL builder (no network call) and `backfill()` with `0`.
- Tests for the legacy Office and plain-text MIME types (`application/msword`, `application/vnd.ms-excel`, `application/vnd.ms-powerpoint`, `text/plain`) resolving to `Document`.

### Changed

- `PushFileToImageKit` no longer uses `SerializesModels`; the job only ever carried a media id and a profile name, so the trait did nothing.
- The `ImageKitManager` exemption in `pint.json` is gone; every class in the package is `final`.
- Test coverage is enforced at 100%.

## v0.2.0

- Lowered the PHP requirement to `^8.3`. Nothing in the package needed 8.4 — the constraint was simply stricter than Laravel's own, which meant a current Laravel 13 application running on PHP 8.3 could not install this at all. Verified against PHP 8.3.29 on both Laravel 12 and 13, and CI now covers 8.3 alongside 8.4 and 8.5.

## v0.1.1

- Static analysis now type-checks the compression path on Laravel 12 as well as 13. `Illuminate\Image` only exists from Laravel 13.20, so analysing against Laravel 12 reported the facade as an unknown class. That was suppressed with an `ignoreErrors` rule, which also hid any genuine mistake in the same file; a scanned stub declaring the surface the compressor uses replaces it, so a mistyped method there is now reported on both versions.

No runtime changes — nothing under `src/` behaves differently, and no upgrade steps are needed.

## v0.1.0 — Initial release

- Upload files to ImageKit alongside spatie/laravel-medialibrary. Mark a media collection with `->toImageKit()` and every upload through media-library (`addMedia`, `addMediaFromRequest`, `addMediaFromBase64`, `addMediaFromStream`, `addMediaFromUrl`, `addMediaFromDisk`) is pushed automatically.
- Two upload modes per profile via `await`: queued (`false`, default) for web apps, synchronous (`true`) for API responses that need the final CDN URL in the same response.
- `ImageKit::uploadNow()` for overriding a profile's default at the call site; returns `?UploadedFileResult`, `null` on failure, with the media row surviving on its local URL and a background retry queued automatically.
- Optional upload-time image compression when Laravel 13.20+, `intervention/image`, and a GD or Imagick driver are all present; silently skipped otherwise with a single log line.
- Delivery-time transformations via named presets, applied only to file types ImageKit can actually transform.
- Automatic remote cleanup on every media deletion path, including `singleFile()` replacement, dispatched after commit.
- `ImageKit::fake()` for testing, with `assertUploaded`, `assertNotUploaded`, `assertDeleted`, `assertNothingUploaded`, and a `failUploads()` toggle to test outage handling.
- `RegistersImageKitCollections::isReady()` to check whether a media row's CDN path has landed yet.
- `php artisan imagekit:reconcile` to find remote files no media row references — files predating adoption, rows removed by raw SQL or a restored backup, anything uploaded outside media-library. Lists by default; deletes only with `--delete`, and refuses to delete at all when no media row references ImageKit, since that is indistinguishable from being pointed at the wrong account.
