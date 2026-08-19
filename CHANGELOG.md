# Changelog

All notable changes to `laravel-imagekit` will be documented in this file.

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
