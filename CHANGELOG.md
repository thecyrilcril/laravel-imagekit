# Changelog

All notable changes to `laravel-imagekit` will be documented in this file.

## v0.1.0 — Initial release

- Upload files to ImageKit alongside spatie/laravel-medialibrary. Mark a media collection with `->toImageKit()` and every upload through media-library (`addMedia`, `addMediaFromRequest`, `addMediaFromBase64`, `addMediaFromStream`, `addMediaFromUrl`, `addMediaFromDisk`) is pushed automatically.
- Two upload modes per profile via `await`: queued (`false`, default) for web apps, synchronous (`true`) for API responses that need the final CDN URL in the same response.
- `ImageKit::uploadNow()` for overriding a profile's default at the call site; returns `?UploadedFileResult`, `null` on failure, with the media row surviving on its local URL and a background retry queued automatically.
- Optional upload-time image compression when Laravel 13.20+, `intervention/image`, and a GD or Imagick driver are all present; silently skipped otherwise with a single log line.
- Delivery-time transformations via named presets, applied only to file types ImageKit can actually transform.
- Automatic remote cleanup on every media deletion path, including `singleFile()` replacement, dispatched after commit.
- `ImageKit::fake()` for testing, with `assertUploaded`, `assertNotUploaded`, `assertDeleted`, `assertNothingUploaded`, and a `failUploads()` toggle to test outage handling.
- `RegistersImageKitCollections::isReady()` to check whether a media row's CDN path has landed yet.
