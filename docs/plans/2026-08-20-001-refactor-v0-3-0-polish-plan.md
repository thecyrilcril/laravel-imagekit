---
title: v0.3.0 Polish - Plan
type: refactor
date: 2026-08-20
topic: v0-3-0-polish
artifact_contract: ce-unified-plan/v1
artifact_readiness: implementation-ready
product_contract_source: ce-brainstorm
execution: code
plan_depth: standard
---

# v0.3.0 Polish - Plan

## Goal Capsule

- **Objective:** Ship `thecyrilcril/laravel-imagekit` v0.3.0 with five polish items: legacy MIME tests, fail-loud profile config, a single contract for the manager and its fake, removal of the inert `SerializesModels` trait, and an app-level root folder prefix for all uploads.
- **Product authority:** Cyril (package owner). Decisions confirmed in the 2026-08-20 brainstorm.
- **Open blockers:** none.

## Product Contract

### Summary

One minor release, v0.3.0, that closes five deferred items in the package. Two of them change behavior (profile validation, root folder) and one changes public types (the contract). The package is v0.x, so these ship in a minor bump with a changelog that names every break.

### Key Decisions

- **One release, not two.** All five items ship together as v0.3.0. Fewer changelogs and PRs outweigh shipping the safe items a few days earlier.
- **Bad profile config throws on first read, not at boot.** `CompressionProfile` validation fails loud the first time a profile is loaded, in the same family as `UnknownProfile`. Boot-time validation was rejected: it would take the app down for a profile that is never used.
- **One new contract replaces the concrete manager everywhere.** A single interface covering `upload`, `uploadNow` and `delete`. Both the real manager and the test fake implement it and both become `final`. Reusing the four existing narrow contracts was rejected: they do not map one-to-one onto the manager's public API.
- **`imagekit.folder` becomes a real root prefix.** Every upload lands at `{folder}/{collection}`. Today the sync path ignores the config value and the queued path uses it only as a fallback, so apps sharing one ImageKit account collide on collection names.

### Requirements

**Tests**

- R1. The four legacy MIME rows in the file-category detector (`application/msword`, `application/vnd.ms-excel`, `application/vnd.ms-powerpoint`, `text/plain`) each have a test asserting they resolve to `Document`.

**Fail-loud profile config**

- R2. Loading a compression profile with a non-string `format`, a `quality` outside 1–100, or a `max_edge` below 1 throws a dedicated exception in the package's exception family.
- R3. The exception message names the profile key and the offending field.
- R4. The compressor no longer clamps `quality` or `max_edge`; it trusts the validated profile.
- R5. Every valid profile in the shipped default config still loads without error.

**Single contract**

- R6. A new contract in `src/Contracts` declares the manager's full public API — `upload`, `uploadNow`, `url`, `delete`, `backfill` — with the current signatures.
- R7. `ImageKitManager` and `ImageKitFake` both implement the contract and both are `final`.
- R8. The service provider binds the contract as the singleton; the facade and `MediaAddedListener` resolve the contract, not the concrete class.
- R9. `ImageKit::fake()` swaps the contract binding, and a test proves uploads are recorded by the fake after `fake()` is called.
- R9a. The fake implements `url` by delegating to the real URL builder (no network call) and `backfill` by returning 0 and recording nothing.
- R10. The `notPath` exemption for `src/ImageKitManager.php` is removed from `pint.json`, and the "deliberately NOT final" docblock is removed.

**Job cleanup**

- R11. `PushFileToImageKit` no longer uses `SerializesModels`; the job still dispatches and runs with `$mediaId` and `$profile` as before.

**Root folder prefix**

- R12. Both the queued upload path and the synchronous `uploadNow` path compute the ImageKit folder as `{imagekit.folder}/{collection_name}`.
- R13. When `collection_name` is empty, the folder is `{imagekit.folder}` alone.
- R14. The root folder value is trimmed of leading and trailing slashes before use, so `/kitwire/` and `kitwire` behave the same.
- R15. The default for `imagekit.folder` stays `uploads`, read from `IMAGEKIT_FOLDER`.

**Coverage gate**

- R20. Pest line coverage reaches 100% and `composer.json` `test:coverage` enforces `--min=100`.

**Documentation and release**

- R16. README documents `IMAGEKIT_FOLDER` as the per-app root folder, shows the resulting path shape, and states that ImageKit creates folders on first upload so no setup is needed.
- R17. README documents that `imagekit:reconcile --delete` must be scoped with `--folder=<root>` on a shared account.
- R18. CHANGELOG v0.3.0 lists three breaking changes: invalid profiles now throw, `ImageKitManager` type-hints must become the contract, and uploads move under the root folder so existing files keep their old paths until re-uploaded.
- R19. README and CHANGELOG both state plainly that apps which never set `IMAGEKIT_FOLDER` move from `/{collection}/...` to `/uploads/{collection}/...` for new uploads.

### Acceptance Examples

- AE1. Given `profiles.avatar.quality = 900`, when the `avatar` profile is loaded, then the dedicated exception is thrown and its message contains `avatar` and `quality`.
- AE2. Given `profiles.avatar.max_edge = 0`, when the profile is loaded, then the exception is thrown; it is not clamped to 1.
- AE3. Given `profiles.avatar.format = 123`, when the profile is loaded, then the exception is thrown; it is not coerced to `null`.
- AE4. Given `IMAGEKIT_FOLDER=kitwire` and a media row in collection `avatars`, when it is uploaded by the queued job or by `uploadNow`, then the upload options carry folder `kitwire/avatars`.
- AE5. Given `IMAGEKIT_FOLDER=/kitwire/` and an empty collection name, when uploaded, then the folder is `kitwire`.
- AE6. Given `ImageKit::fake()` has been called, when `MediaAddedListener` triggers an upload, then the fake records it and no real upload is attempted.

### Scope Boundaries

- No migration of binitng or portal-x to the package (declined 2026-08-19).
- No change to kitwire in this release; kitwire bumps the version and sets `IMAGEKIT_FOLDER=kitwire` in its own PR.
- No moving of existing ImageKit files to the new root folder; old paths remain valid.
- No boot-time profile validation.

### Dependencies / Assumptions

- ImageKit's Upload API creates missing folders on upload; no folder-creation call is needed (confirmed from current behavior of the package in production).
- No consumer outside kitwire type-hints `ImageKitManager` directly (assumption; kitwire verified).

### Outstanding Questions

**Deferred to Planning**

- Whether `ReconcileCommand --folder` should default to `imagekit.folder` when the option is omitted, or stay opt-in.

### Sources

- `src/Support/FileCategoryDetector.php:16-27` — the EXACT MIME map.
- `src/Data/CompressionProfile.php:20-32` — current silent coercion.
- `src/Compression/LaravelImageCompressor.php:30-31` — current clamp.
- `src/ImageKitManager.php:25-30` — the non-final docblock; `pint.json` `notPath`.
- `src/ImageKitServiceProvider.php:63`, `src/Facades/ImageKit.php:35`, `src/Listeners/MediaAddedListener.php:46` — concrete-class references to replace.
- `src/Jobs/PushFileToImageKit.php:34,99-103` and `src/ImageKitManager.php:82-85` — the two upload paths that disagree on folder.
- `config/imagekit.php:17` — `imagekit.folder` default.

---

## Planning Contract

**Product Contract preservation:** changed: R6 (contract covers all five manager methods, not three — the fake would otherwise lose `url`/`backfill`), R9a added, R19 added (README path-change note, confirmed 2026-08-20). All other R-IDs unchanged.

**Target repo:** `~/Code/laravel-imagekit` (all paths below are relative to it).

### Key Technical Decisions

- **KTD1. New contract `Thecyrilcril\ImageKit\Contracts\ImageKitClient`.** Declares `upload`, `uploadNow`, `url`, `delete`, `backfill`. The existing four narrow contracts (`UploadsFiles`, etc.) stay untouched; they describe adapters, not the manager. Name is final unless planning review objects.
- **KTD2. New exception `Thecyrilcril\ImageKit\Exceptions\InvalidProfile extends ImageKitException`** with static constructors per field (`quality`, `maxEdge`, `format`), each taking the profile key. Mirrors `UnknownProfile`'s shape.
- **KTD3. Validation lives in `CompressionProfile::fromArray(array $config, string $name)`.** `ProfileRepository::profile()` already owns the key and passes it. No boot-time pass. Rules: `quality` int 1–100, `max_edge` int ≥ 1, `format` string or null. `compress` and `await` keep their `(bool)` casts; they cannot be "wrong" in a harmful way.
- **KTD4. The fake stops extending and both classes go `final`.** `ImageKit::fake()` keeps using `self::swap()`; because the facade accessor becomes the contract, the swap binds the contract, so `app(ImageKitClient::class)` in `MediaAddedListener` resolves the fake. Verified from `Facade::swap()` → `instance(accessor)`.
- **KTD5. Folder resolution in one helper.** A small `Support\FolderResolver` (static `resolve(string $collection): string`) trims slashes from `config('imagekit.folder')` and joins with the collection. Both upload paths call it, so they cannot drift again.
- **KTD6. Existing clamp tests are replaced, not deleted silently.** `tests/LaravelImageCompressorTest.php:147,153` assert the clamp. They become tests that a validated profile is passed through untouched; the out-of-range cases move to `ProfileRepositoryTest`.

### Execution order

U1, U2, U3 and U5 have no dependencies and may land in any order. U4 depends on U3 (its listener test relies on the contract binding). U6 depends on U3, U4 and U5 (needs the final names). U7 depends on U3, U4 and U5 (raise the gate only once their new code is in, so one pass closes every gap).

---

## Implementation Units

### U1. Legacy MIME tests

**Goal:** cover the four untested `EXACT` rows.
**Requirements:** R1.
**Dependencies:** none.
**Files:** `tests/FileCategoryDetectorTest.php`.
**Approach:** extend the existing `it('detects documents')` dataset with the four MIME strings. No source change.
**Test scenarios:**
- `application/msword`, `application/vnd.ms-excel`, `application/vnd.ms-powerpoint`, `text/plain` each → `FileCategory::Document`.
**Verification:** the dataset test passes; coverage for `FileCategoryDetector` is unchanged at 100%.

### U2. Remove `SerializesModels` from the job

**Goal:** drop the inert trait.
**Requirements:** R11.
**Dependencies:** none.
**Files:** `src/Jobs/PushFileToImageKit.php`, `tests/PushFileToImageKitTest.php`.
**Approach:** remove the `use` statement and the import. Nothing else changes.
**Test scenarios:**
- Existing job tests still pass (dispatch, handle, queue routing).
- A new assertion: `serialize(new PushFileToImageKit(1, 'avatar'))` round-trips and `$mediaId`/`$profile` survive — proves no serializer hook was load-bearing.
**Verification:** PHPStan clean; `composer test` green.

### U3. Single contract for manager and fake

**Goal:** both classes `final`, pint exemption gone.
**Requirements:** R6, R7, R8, R9, R9a, R10.
**Dependencies:** none.
**Files:** create `src/Contracts/ImageKitClient.php`; modify `src/ImageKitManager.php`, `src/Testing/ImageKitFake.php`, `src/Facades/ImageKit.php`, `src/ImageKitServiceProvider.php`, `src/Listeners/MediaAddedListener.php`, `pint.json`; tests `tests/FacadeTest.php`, `tests/ServiceProviderTest.php`.
**Approach:**
- Contract declares the five methods with the manager's current signatures and docblocks.
- Manager: `final class ImageKitManager implements ImageKitClient`; delete the "Deliberately NOT final" docblock.
- Fake: `final class ImageKitFake implements ImageKitClient`; keep `#[Override]` on `upload`/`uploadNow`/`delete` (they now override the contract) and add it to `url()`/`backfill()` and to the manager's five contract methods, matching `LaravelImageCompressor` and `UrlFactory`; add `url()` → `app(GeneratesFileUrls::class)->build(...)`; add `backfill()` → `return 0`.
- Provider: `singleton(ImageKitClient::class, ImageKitManager::class)`.
- Facade: accessor returns `ImageKitClient::class`; `@see` and `fake()` return type unchanged.
- Listener: both branches go through the contract. The `await: true` branch calls `app(ImageKitClient::class)->uploadNow(...)`; the queued branch replaces its direct `PushFileToImageKit::dispatch(...)` with `app(ImageKitClient::class)->upload($media, $profile)` (the manager's `upload()` already does exactly that dispatch). Without this, `ImageKit::fake()` never intercepts queued collections — and every shipped profile is `await => false`.
- `pint.json`: remove the `notPath` entry.
**Patterns to follow:** the four existing contracts in `src/Contracts` and their bindings in the provider.
**Test scenarios:**
- Covers AE6. With `ImageKit::fake()` active, firing `MediaHasBeenAddedEvent` for a `toImageKit()` collection records the upload on the fake and a Mockery `UploadsFiles` mock receives no `upload` call — once with an `await: true` profile and once with `await: false`.
- `app(ImageKitClient::class)` resolves to `ImageKitManager` by default and to the fake after `fake()`.
- `ImageKit::url('/x.jpg')` after `fake()` returns a string built by the real URL builder.
- `ImageKit::backfill(TestModel::class, 'plain')` after `fake()` returns 0 and `assertNothingUploaded()` passes.
- Pint `--test` passes with no `notPath`; both classes report `final` via reflection (arch test or `ReflectionClass::isFinal()`).
**Verification:** `composer ci` green; `grep -rn 'ImageKitManager::class' src` returns only the provider binding.

### U4. Fail-loud profile validation

**Goal:** bad config throws `InvalidProfile` on first read; compressor stops clamping.
**Requirements:** R2, R3, R4, R5.
**Dependencies:** U3.
**Files:** create `src/Exceptions/InvalidProfile.php`; modify `src/Data/CompressionProfile.php`, `src/Support/ProfileRepository.php`, `src/Compression/LaravelImageCompressor.php`; tests `tests/ProfileRepositoryTest.php`, `tests/LaravelImageCompressorTest.php`, `tests/ServiceProviderTest.php`.
**Approach:** per KTD2/KTD3/KTD6. `fromArray` gains a required `string $name` second parameter; the only caller is `ProfileRepository`. Message format: `Invalid ImageKit compression profile [avatar]: quality must be an integer between 1 and 100, got 900.`
**Execution note:** write the three failing `InvalidProfile` tests first; they document the contract before the clamp is removed.
**Test scenarios:**
- Covers AE1. `quality => 900` → `InvalidProfile`, message contains `avatar` and `quality`.
- Covers AE2. `max_edge => 0` → `InvalidProfile`; also `max_edge => -5` and `max_edge => '2000'` (string) → throws.
- Covers AE3. `format => 123` → `InvalidProfile`; `format => null` and `format => 'webp'` → ok.
- `quality => 1` and `quality => 100` → ok (boundaries).
- `quality => '90'` (numeric string) → `InvalidProfile`; CHANGELOG names this so `env()`-populated profiles are not a surprise.
- Every profile in `config/imagekit.php` (`default`, `avatar`, `document`) loads without throwing (R5).
- `document` profile (no `quality`/`max_edge` keys) still gets defaults 90 / 2000.
- Compressor: given a valid profile with `quality => 100`, the driver receives 100 unchanged (replaces the two clamp tests).
**Verification:** `grep -n 'max(1' src/Compression/LaravelImageCompressor.php` returns nothing; coverage does not drop.

### U5. Root folder prefix on both upload paths

**Goal:** `{folder}/{collection}` everywhere.
**Requirements:** R12, R13, R14, R15.
**Dependencies:** none.
**Files:** create `src/Support/FolderResolver.php`; modify `src/Jobs/PushFileToImageKit.php`, `src/ImageKitManager.php`; tests create `tests/FolderResolverTest.php`, modify `tests/PushFileToImageKitTest.php`, `tests/AwaitTest.php` (or wherever `uploadNow` is exercised).
**Approach:** per KTD5. Resolver: `trim(config folder, '/')`; if collection is `''` return the root; else `root.'/'.$collection`. Both call sites replace their inline folder logic with the resolver. `PushFileToImageKit` keeps `tags: [$collection]`.
**Test scenarios:**
- Covers AE4. `IMAGEKIT_FOLDER=kitwire`, collection `avatars` → `UploadOptions->folder === 'kitwire/avatars'` for the queued job **and** for `uploadNow`.
- Covers AE5. Folder `/kitwire/`, empty collection → `kitwire`.
- Default config, collection `avatars` → `uploads/avatars`.
- Folder `''` (explicitly empty) with collection `avatars` → `avatars` (no leading slash).
**Verification:** both upload tests assert the same folder string for the same inputs; `grep -rn "collection_name" src | grep folder` returns nothing outside the resolver.

### U6. Docs and release notes

**Goal:** README and CHANGELOG tell the truth about v0.3.0.
**Requirements:** R16, R17, R18, R19.
**Dependencies:** U3, U4, U5 (content depends on final names).
**Files:** `README.md` (Configuration §185, Finding orphans §253, Testing §275, Footguns §314), `CHANGELOG.md`.
**Approach:**
- Configuration: document `IMAGEKIT_FOLDER`, show `kitwire/avatars` as the resulting path, state ImageKit creates folders on first upload.
- Finding orphans / Footguns: on a shared account always run `imagekit:reconcile --delete --folder=<root>`; the root is the value of `IMAGEKIT_FOLDER`.
- Testing: mention `ImageKitClient` as the type to inject instead of `ImageKitManager`.
- CHANGELOG `## v0.3.0` with a **Breaking** list (three items from R18, plus the R19 default-path note) and an **Added/Changed** list (legacy MIME tests, trait removal).
**Test expectation:** none — documentation only.
**Verification:** a reader can find `IMAGEKIT_FOLDER`, `--folder`, and `ImageKitClient` in README by search; CHANGELOG names all four upgrade impacts.

### U7. Close the coverage gap and raise the gate to 100%

**Goal:** every line in `src/` is covered; the gate enforces it.
**Requirements:** R20.
**Dependencies:** U3, U4, U5.
**Files:** `composer.json` (`test:coverage` script `--min=90` → `--min=100`); tests `tests/FacadeTest.php` or a new `tests/ImageKitManagerTest.php`, `tests/UploaderTest.php`, `tests/PushFileToImageKitTest.php`, `tests/CleanupTest.php` (or wherever `RemoveFileFromImageKit` is exercised).
**Approach:** the gaps measured on 2026-08-20 at 96.5% are: `ImageKitManager` 74, 98–103; `ImageKitUploader` 43, 62–68; `PushFileToImageKit` 52, 89; `RemoveFileFromImageKit` 34. Re-measure after U3–U5 land; cover whatever remains. Do not exclude files from coverage.
**Test scenarios:**
- Manager: a media row whose file is missing on disk → `uploadNow` returns null and fires `FileUploadFailed` (covers the `file_get_contents === false` branch). `url()` and `delete()` called on the real manager (not the fake) delegate to the bound `GeneratesFileUrls` / `DeletesRemoteFiles`.
- Uploader: an SDK response with no `result` object → `UploadFailed`. Dataset over the extension→MIME `match`: `jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`, `svg`, `pdf`, `mp4`, and an unknown extension → `application/octet-stream`.
- Push job: `imagekit.queue.connection` set → `$job->connection` equals it; missing file on disk → `RuntimeException`.
- Remove job: `imagekit.queue.connection` set → `$job->connection` equals it.
**Verification:** `composer test:coverage` passes at `--min=100`; the coverage table shows no file below 100%.

---

## Verification Contract

- `composer ci` passes: Pint (`--test`, no `notPath`), PHPStan level 7, Pest with coverage = 100% (`--min=100`).
- All six acceptance examples have a named test (see `Covers AE` markers).
- `grep -rn 'notPath' pint.json` shows an empty list.
- `grep -rn 'SerializesModels' src` returns nothing.

## Definition of Done

- All units merged to `main` of `laravel-imagekit`.
- `CHANGELOG.md` has a `## v0.3.0` section and `git tag v0.3.0` is created after merge (tagging is the user's manual step, as with v0.2.0).
- kitwire follow-up (separate PR, out of this plan): bump to `^0.3`, set `IMAGEKIT_FOLDER=kitwire`, rename any `ImageKitManager` type-hints.

## Scope Boundaries

### Deferred to Follow-Up Work
- `ReconcileCommand --folder` defaulting to `imagekit.folder` when omitted. Cheap, but it changes a CLI contract; decide after v0.3.0 lands.
- Moving existing ImageKit files under the new root.

## Risks

- **Silent path split on upgrade.** Old files stay at `/avatars/...`, new ones go to `/uploads/avatars/...`. Reconcile with `--folder=avatars` would then list nothing new. Mitigation: R19 docs; no code mitigation.
- **Downstream type-hints.** Any consumer injecting `ImageKitManager` gets a container error after upgrade. Mitigation: CHANGELOG item; binding the concrete class as an alias was rejected because it keeps the non-final class alive.
