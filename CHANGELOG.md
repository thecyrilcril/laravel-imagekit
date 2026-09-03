# Changelog

All notable changes to `laravel-imagekit` will be documented in this file.

## v0.7.0

### Added

- **Fluent `->await()` on the media-library chain.** `$user->addMedia($file)->await()->toMediaCollection('avatar')` uploads to ImageKit before `toMediaCollection()` returns, exactly as an `await: true` profile does, and queues nothing for that row. `->await(false)` forces the queue on an `await: true` profile. Without a `->await()` call the profile's `await` value applies, so existing code is unchanged. The method is a macro on Spatie's `FileAdder`, registered next to `toImageKit()`, so every `addMedia*()` entry point and `copyMedia()` have it, including files attached to a model that is saved later. The override rides on the row as a custom property that `MediaAddedListener` strips before it uploads, so `custom_properties` never carries it; because Spatie's `withCustomProperties()` replaces the whole array, call it before `->await()`. On failure `->await()` behaves like `await: true`: the row keeps its local URL, one warning is logged, `FileUploadFailed` fires and a retry is queued. `ImageKit::fake()` records the upload once and `failUploads()` applies. Larastan reads the macro; plain PHPStan needs a `@var FileAdder` hint, shown in the README ([#20](https://github.com/thecyrilcril/laravel-imagekit/issues/20)).
- `Thecyrilcril\ImageKit\Exceptions\UnregisteredCollection`, thrown when `->await()` is used on a collection that was never registered with `->toImageKit()`. A row with no `->await()` call on an unregistered collection is still ignored, as before.

### Unchanged

- The `await` profile flag, the `uploadNow()` hybrid pattern (not deprecated), queue config, `PushFileToImageKit`, the `ImageKitClient` contract and the `ImageKit` facade.

## v0.6.1

### Fixed

- **`PushFileToImageKit` no longer re-uploads a row that already has `imagekit.file_id`.** The README hybrid pattern (`addMedia()` on an `await: false` profile, then `uploadNow()` in the same request) and the outage retry (a failed `uploadNow()` queues its own retry next to the original job) both left two queued paths pointing at one media row. Both uploaded, and the first remote file became an orphan with no row pointing at it. The job now returns early when the row already carries a `file_id`, the same guard `ImageKit::backfill()` has always used, so either scenario ends with exactly one remote file and `imagekit:reconcile` reports no orphan. The skip is silent: it is the expected path, not an error. A deliberate re-push of new bytes to the same row is not covered; that needs an explicit flag and is out of scope here ([#18](https://github.com/thecyrilcril/laravel-imagekit/issues/18)).

## v0.6.0

### Changed

- **The official `imagekit/imagekit` SDK is gone; the package now depends on [`thecyrilcril/imagekit-laravel-client`](https://github.com/thecyrilcril/imagekit-laravel-client) `^0.1`.** The SDK pinned Guzzle `~6 || ~7`, so a fresh Laravel 13 app (Guzzle 8) could only install this package with `composer require -W`, which downgraded Guzzle for the whole app. The Client is built on Laravel's `Http` client and has no Guzzle pin: `composer require thecyrilcril/laravel-imagekit` now installs cleanly on Laravel 12 (Guzzle 7) and Laravel 13 (Guzzle 8) with no flags. Why a replacement and not a fork: [ADR-0001](docs/adr/0001-laravel-http-client-replaces-imagekit-sdk.md).
- **Credentials moved to the Client's config.** `public_key`, `private_key` and `url_endpoint` are read from `config/imagekit-client.php`, not `config/imagekit.php`. The env keys are unchanged (`IMAGEKIT_PUBLIC_KEY`, `IMAGEKIT_PRIVATE_KEY`, `IMAGEKIT_URL_ENDPOINT`), so an app that sets them in `.env` needs only `php artisan vendor:publish --tag=imagekit-client-config`. `imagekit:install` publishes both files. The old keys in a published `config/imagekit.php` are ignored.
- **Uploads send raw bytes as a multipart file part** instead of a base64 data URI, so a request holds one copy of the file rather than a copy plus a third-larger encoding.
- **An unknown preset key now throws.** The Client's URL builder rejects a transformation key it does not know with `Thecyrilcril\ImageKitClient\Exceptions\InvalidTransformation`; the SDK used to pass typos into the URL as-is. Preset keys are the Client's aliases (`width`, `focus`, …) or ImageKit short codes (`w`, `fo`, …).
- `imagekit:reconcile`, the uploader and the file remover read typed results and catch typed exceptions; a delete of a file ImageKit no longer has counts as done.
- CI now proves Guzzle `^7.8` and `^8.0` on every PHP × Laravel leg (Laravel 12 × Guzzle 8 is excluded: Laravel 12 pins Guzzle `^7.8.2`).

### Unchanged

- The `ImageKitClient` contract, the `ImageKit` facade, `ImageKit::fake()` and its assertions, `uploadNow()` returning `null` on any failure, profiles, presets, queue and folder config, and every URL this package emits (transformations stay in the path; [ADR-0002](docs/adr/0002-transformations-in-url-path.md)).

### Verified

- Live in the test app on Guzzle 8.1.0: 3000×2000 PNG upload with `await: true` served HTTP 200; the `avatar` preset URL carries `w-200,h-200,fo-face` and renders 200×200; single-file replacement removes the old remote file after `queue:work --queue=imagekit`; `imagekit:reconcile` inspects the folder and finds the orphan after a raw row delete; with `api.imagekit.io` blocked, `uploadNow()` returns `null`, logs once and queues a retry. Evidence on [PR #17](https://github.com/thecyrilcril/laravel-imagekit/pull/17).

## v0.5.1

### Fixed

- **`imagekit:reconcile` now finds files inside sub-folders.** ImageKit's `path` filter lists one folder level only, and uploads land at `{root}/{collection}/{file}`, so a run scoped to the root inspected zero files and reported "no orphans" on every real install. The command now walks every sub-folder under the scope.

### Changed

- README: install with `composer require -W` (the ImageKit SDK pins Guzzle 7, fresh Laravel apps lock Guzzle 8), run a worker on the `imagekit` queue, and register a media-library conversion to use a named preset.

## v0.5.0

### Added

- **`imagekit:install` sets up the package in one run.** After `composer require`, the command publishes this package's config and media-library's config and `create_media_table` migration, rewrites `url_generator` in `config/media-library.php` to `ImageKitUrlBuilder`, prompts for `IMAGEKIT_PUBLIC_KEY`, `IMAGEKIT_PRIVATE_KEY`, `IMAGEKIT_URL_ENDPOINT` and `IMAGEKIT_FOLDER` (appending them to `.env`, with empty placeholders in `.env.example`), and asks before running `migrate`. It never overwrites a published file, an existing env key, or a `url_generator` already pointing at the builder, so re-running it on an installed app changes nothing and reports each step as skipped. When it cannot recognise the `url_generator` line — a function call, an expression with commas, or the line removed — it refuses to guess and prints the one-line manual edit instead. Under `--no-interaction` it publishes everything, prints the env block, and touches neither `.env` nor the database.
- `Thecyrilcril\ImageKit\Support\EnvFile`, the append-only dotenv writer behind the command. It adds `KEY=value` lines, never edits existing ones, and rejects any value containing a newline or control character.

### Fixed

- **Fresh apps no longer crash on `migrate`.** The package's `add_imagekit_pending_deletion_to_media_table` migration is dated before any `create_media_table` migration published today, so on a new app it ran first and failed on the missing table. It now does nothing until the `media` table exists and skips when the column is already present; `imagekit:install` also publishes a copy timestamped one second after the published table migration, so the column lands in the right order. Apps that already ran the vendor copy are unaffected.

## v0.4.1

### Fixed

- Use PHP 8.3-compatible syntax in the converter.
- Conversion tests skip honestly on a minimal ImageMagick build and cover `ConversionFailed` and magic-byte detection without needing HEIC support.

## v0.4.0

### Added

- **Convert HEIC, WebP and AVIF to JPEG with EXIF intact.** `ConvertsImages::toJpeg()` uses Imagick, detects the format by magic bytes, returns JPEG input byte for byte, and returns the input unchanged where the environment cannot convert. `supported()` does a trial decode of a real sample.

## v0.3.1

### Changed

- **`imagekit:reconcile` is now confined to `IMAGEKIT_FOLDER`.** The listing is always scoped to the configured root folder, and any file outside `/{root}/` is ignored even if ImageKit returns it, so a bare `--delete` can no longer reach another application's files on a shared account. `--folder=<name>` now means a sub-folder *under* the root (`--folder=avatars` inspects `/{root}/avatars`), not an absolute ImageKit path. When `IMAGEKIT_FOLDER` is empty the command still lists the whole account but refuses `--delete`.
- README's Installation section now includes `IMAGEKIT_FOLDER` alongside the three credentials.

## v0.3.0

### Breaking

- **Invalid compression profiles now throw.** A profile with a `quality` outside 1–100, a `max_edge` below 1, or a non-string `format` throws `Thecyrilcril\ImageKit\Exceptions\InvalidProfile` the first time it is loaded, naming the profile and the field. Previously the values were clamped or coerced silently. Integers must be real integers: a numeric string such as `'90'` from `env()` is rejected, so cast in config. Validation runs on first use, not at boot, so a profile that is never used never throws. First use includes `MediaAddedListener` reading the profile's `await` flag inside `addMedia()`, so a broken profile fails the upload request for its collection rather than silently storing an uncompressed file. When the queued job meets an `InvalidProfile` or `UnknownProfile`, it fails on the first attempt instead of retrying a configuration error `tries` times.
- **`ImageKitManager` is now `final` and is no longer the bound singleton.** Type-hint the new `Thecyrilcril\ImageKit\Contracts\ImageKitClient` contract instead. The facade, the service provider and `MediaAddedListener` all resolve the contract; `ImageKit::fake()` swaps it, so the fake is also `final` and reaches injected consumers. A constructor that still injects `ImageKitManager` keeps compiling — Laravel auto-resolves any concrete class — but it gets a fresh, unfaked instance that `ImageKit::fake()` never intercepts, so such tests would hit the real SDK. Rename those type-hints.
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
