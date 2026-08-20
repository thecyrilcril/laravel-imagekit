# Laravel ImageKit

Upload files to [ImageKit](https://imagekit.io) alongside [spatie/laravel-medialibrary](https://spatie.be/docs/laravel-medialibrary), with optional first-party image compression before upload.

The package hooks into media-library's normal lifecycle. You keep writing `addMedia(...)->toMediaCollection(...)` exactly as before; a collection you mark with `->toImageKit()` gets pushed to ImageKit automatically, and `$media->getUrl()` serves the ImageKit CDN URL once it lands.

It serves three kinds of application equally:

- **Web apps**, where the upload can happen in the background and the page just re-renders with the CDN URL once it is ready.
- **API-only apps**, where the response has to carry the final CDN URL because there is no second render to catch up later.
- **Hybrid apps**, where most uploads are queued but specific API endpoints need to wait.

The `await` setting is what tells the package which behaviour you want, per collection or per call. See the three quick-starts below.

## Installation

```bash
composer require thecyrilcril/laravel-imagekit
```

Publish the config file:

```bash
php artisan vendor:publish --tag=imagekit-config
```

Set the three ImageKit credentials in `.env`:

```env
IMAGEKIT_PUBLIC_KEY=
IMAGEKIT_PRIVATE_KEY=
IMAGEKIT_URL_ENDPOINT=
```

Point media-library's URL generator at the package's builder, in `config/media-library.php`:

```php
'url_generator' => \Thecyrilcril\ImageKit\ImageKitUrlBuilder::class,
```

This builder falls back to media-library's normal URL for any media that has not been uploaded to ImageKit yet, so adopting the package does not disrupt collections you have not opted in.

## Quick start (web)

### Prepare the model

Files hang off an Eloquent model through media-library, so the model must implement `HasMedia` and use the `InteractsWithMedia` trait. That part is media-library's requirement, not this package's — if the model already stores media, it is done and you can skip to the next step.

Mark the collection you want pushed to ImageKit with `->toImageKit()`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class User extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile()
            ->toImageKit('avatar'); // the 'avatar' upload profile, see Configuration below
    }
}
```

That is the whole integration. `->toImageKit()` records which upload profile the collection uses; everything after this — uploading, compressing, storing the CDN path, serving CDN URLs, deleting the remote file — follows from ordinary media-library usage.

`->toImageKit()` with no argument uses the `default` profile. A collection without it is ignored by this package entirely and behaves exactly as media-library always did, so you can adopt this one collection at a time.

### Upload and render

Upload it the normal media-library way:

```php
$user->addMedia($request->file('photo'))->toMediaCollection('avatar');
```

Render it the normal media-library way:

```php
$user->getUrl('avatar'); // or getUrl() with no conversion, for the 'default' preset
```

By default (`await: false`) the upload is queued. The **local** URL is served until the queued job finishes and stores the CDN path — usually a few seconds later. The next time the page renders, `getUrl()` returns the CDN URL automatically. There is nothing else to write: the same call serves whichever URL is currently correct.

## Quick start (API)

The model is prepared exactly as above — `implements HasMedia`, `use InteractsWithMedia`, and `->toImageKit()` on the collection. Only the profile and the controller differ.

An API response is a single shot — there is no later render to pick up a CDN URL that lands after the response is sent. Set `await: true` on the profile so the upload happens synchronously and the response can carry the final URL:

```php
// config/imagekit.php
'profiles' => [
    'avatar' => ['compress' => true, 'max_edge' => 2000, 'quality' => 90, 'format' => null, 'await' => true],
],
```

```php
use Thecyrilcril\ImageKit\Concerns\RegistersImageKitCollections;

public function store(Request $request): JsonResponse
{
    $request->validate(['photo' => ['required', 'image', 'max:10240']]);

    $media = $request->user()
        ->addMedia($request->file('photo'))
        ->toMediaCollection('avatar');

    return response()->json([
        'avatar_url' => $media->fresh()->getUrl(),
        'ready' => RegistersImageKitCollections::isReady($media->fresh()),
    ]);
}
```

`$media->fresh()` matters: the upload happens inside the `MediaHasBeenAddedEvent` listener that media-library fires as part of `toMediaCollection()`, and the in-memory `$media` object returned to your controller does not see the custom properties that listener wrote. Re-fetching picks them up.

JSON clients commonly send the file as base64 rather than multipart — that works identically, because the trigger is media creation, not the shape of the HTTP request:

```php
$media = $request->user()
    ->addMediaFromBase64($request->string('photo'))
    ->usingFileName('avatar.jpg')
    ->toMediaCollection('avatar');
```

**Do not store the URL you get back.** Re-read it with `getUrl()` on every response instead. If a synchronous upload fails (see [`uploadNow()` below](#quick-start-hybrid)), the media row keeps its local URL and a background retry is queued — a URL you stored at upload time can silently go stale once that retry succeeds later.

## Quick start (hybrid)

Most applications are not purely one or the other: a profile can carry the default `await` behaviour, and a specific call site can override it. Call `ImageKit::uploadNow()` directly when one particular endpoint needs to wait even though the collection's profile normally queues:

```php
use Thecyrilcril\ImageKit\Facades\ImageKit;

public function store(Request $request): JsonResponse
{
    $media = $request->user()
        ->addMedia($request->file('photo'))
        ->toMediaCollection('avatar'); // profile default is await: false — queued

    $result = ImageKit::uploadNow($media, 'avatar'); // this call waits regardless

    return response()->json([
        'avatar_url' => $media->fresh()->getUrl(),
        'uploaded_now' => $result !== null,
    ]);
}
```

`await` is deliberately explicit rather than auto-detected from "is this an HTTP request" — queued jobs and console commands have no request to inspect, so an implicit rule would have nothing to key off in exactly the cases where the choice matters most.

`uploadNow()` returns `?UploadedFileResult` — **nullable**. If ImageKit is unreachable, the exception is caught, logged, a `FileUploadFailed` event fires, and a background retry is queued automatically. The method returns `null` in that case rather than throwing, because a CDN outage must not fail the request: the file is already safely stored by media-library on your local/S3 disk regardless of what ImageKit does. Always null-check the result:

```php
$result = ImageKit::uploadNow($media, 'avatar');

if ($result === null) {
    // ImageKit is down. The upload is not lost — it will retry in the
    // background. Decide here whether the caller needs to know that now.
}
```

## All the ways to upload

The push to ImageKit is triggered by **media being created**, not by any particular HTTP shape. Every entry point media-library offers works identically:

| Method | Typical source |
|---|---|
| `addMedia()` | An `UploadedFile` (multipart form upload) |
| `addMediaFromRequest()` | A named field straight off the current request |
| `addMediaFromBase64()` | A base64 data URI, common in JSON API payloads |
| `addMediaFromStream()` | A PHP stream resource |
| `addMediaFromUrl()` | A remote URL the server fetches itself |
| `addMediaFromDisk()` | A file already sitting on one of your filesystem disks |

## Configuration

### Root folder

Every upload lands under one root folder, set by `IMAGEKIT_FOLDER` (`imagekit.folder`, default `uploads`). The final ImageKit path is `{folder}/{collection}/{file}`, so with `IMAGEKIT_FOLDER=kitwire` a file in the `avatars` collection is stored at `kitwire/avatars/photo.jpg`. Leading and trailing slashes on the value are ignored: `/kitwire/` and `kitwire` behave the same.

Give each application that shares an ImageKit account its own root. That keeps their collections apart and gives `imagekit:reconcile --folder=<root>` a safe boundary (see [Finding orphans](#finding-orphans)). ImageKit creates folders on first upload, so there is nothing to set up on the ImageKit side.

> If you never set `IMAGEKIT_FOLDER`, new uploads go to `/uploads/{collection}/...`. Before v0.3.0 they went to `/{collection}/...`. Files already on ImageKit stay where they are and keep working; only new uploads use the root folder.

### Profiles and presets

`config/imagekit.php` has two separate sections that are easy to conflate but govern different things:

- **`profiles`** control what gets **stored** — compression applied before the file is uploaded to ImageKit.
- **`presets`** control what gets **served** — transformations ImageKit applies to the URL at render time.

```php
'profiles' => [
    'avatar' => ['compress' => true, 'max_edge' => 2000, 'quality' => 90, 'format' => null, 'await' => false],
],

'presets' => [
    'avatar' => ['width' => 200, 'height' => 200, 'focus' => 'face', 'quality' => 85, 'format' => 'auto'],
],
```

A collection's profile name and preset name are independent — nothing requires them to share a name, though the shipped config uses matching names (`avatar`/`avatar`) for readability.

Profile keys:

| Key | Meaning |
|---|---|
| `compress` | Whether to compress before upload at all. `false` uploads the original bytes untouched. |
| `max_edge` | Longest side, in pixels, the image is scaled down to. Never scales up — an image already smaller than this is left alone. |
| `quality` | Integer 1–100, passed to the encoder. Ignored for lossless formats such as PNG. |
| `format` | Force a specific output format (`'jpg'`, `'png'`, `'webp'`, …), or `null` to keep the source format. See [Footguns](#footguns) before setting this. |
| `await` | `false` (default) queues the upload — suits web pages. `true` uploads synchronously — suits API responses. |

A profile is validated the first time it is used. A `quality` outside 1–100, a `max_edge` below 1, a non-string `format`, or a numeric string where an integer is expected (for example `'90'` from `env()`) throws `Thecyrilcril\ImageKit\Exceptions\InvalidProfile`, naming the profile and the field. Nothing is clamped or coerced silently. A profile that is never used never throws.

Preset keys are passed straight through to ImageKit's [transformation parameters](https://imagekit.io/docs/transformations) (`width`, `height`, `focus`, `quality`, `format`, and any other transformation ImageKit supports).

## Compression

Upload-time compression is only active when **all three** are true:

1. Laravel 13.20 or newer (ships `Illuminate\Support\Facades\Image`)
2. `intervention/image` is installed
3. A GD or Imagick PHP extension is loaded

Missing any one of the three does not error and does not need configuring around — the package silently uploads original bytes instead, logging exactly one warning per process so your logs are not flooded:

> `ImageKit: image compression is unavailable, uploading originals. Requires Laravel 13.20+ with intervention/image and a GD or Imagick driver.`

Even when compression is available, it only ever applies to files it makes sense for:

| File type | Compressed? |
|---|---|
| Raster images (JPEG, PNG, WebP, GIF, …) | Yes — resized to `max_edge`, re-encoded at `quality` |
| Other media ImageKit can transform (SVG, video) | No — uploaded as-is; ImageKit still applies delivery-time presets on read |
| Plain files (PDF, DOCX, XLSX, ZIP, …) | No — uploaded as-is; no transformation is ever attempted |

## Non-image files

PDFs, DOCX, XLSX, ZIP, and any other non-image file upload and serve correctly. `addMedia()` and friends work the same way regardless of file type. The only thing that changes is transformation: it applies only to media ImageKit can actually transform (images, vector, video). An unrecognised or non-transformable MIME type is served as a plain URL with no transformation parameters, because appending them would return a broken link rather than a working file.

## Deletion

Deleting media through media-library automatically deletes the matching file from ImageKit — no extra call needed. This covers every deletion path media-library offers:

- `$media->delete()`
- `$model->clearMediaCollection(...)`
- `singleFile()` collections, when a new upload replaces the old one
- Cascading deletes when the owning model is deleted

The remote delete is dispatched **after** the database transaction commits, so a rolled-back transaction can never strand a delete against a media row that ends up not actually being removed.

One limitation worth knowing: only files this package uploaded are tracked. A file that reached ImageKit some other way (uploaded directly through ImageKit's own dashboard or API, outside media-library) has no `imagekit.file_id` custom property on any `Media` row, so nothing here knows to clean it up.

### Finding orphans

Automatic deletion only reaches rows the package can see. Files that predate adopting this package, rows removed by a raw SQL delete or a restored database backup, and anything uploaded outside media-library all leave a remote file with no local record. Nothing else will ever find those:

```bash
php artisan imagekit:reconcile
```

It lists, and does not delete. To act on the list:

```bash
php artisan imagekit:reconcile --delete
```

Options: `--folder=kitwire` limits the scan to one ImageKit folder, and `--chunk=100` sets how many files are fetched per request.

On a shared ImageKit account, always scope `--delete` to this application's root folder — the value of `IMAGEKIT_FOLDER`:

```bash
php artisan imagekit:reconcile --delete --folder=kitwire
```

> **Read the listing before you pass `--delete`.**
>
> The command decides what counts as an orphan by comparing ImageKit against *this application's* media table. Point a staging app at a production ImageKit account and every production file looks orphaned. The same is true when one ImageKit account is shared by several applications: each sees the others' files as orphans, because it holds no rows referencing them. Scope with `--folder=<root>` (your `IMAGEKIT_FOLDER`) when an account is shared.
>
> As a guard, the command refuses `--delete` outright when no media row references ImageKit at all, since an empty local side is indistinguishable from being pointed at the wrong account.

## Testing

Swap in the fake with `ImageKit::fake()`, then assert on it like any other Laravel fake:

```php
use Thecyrilcril\ImageKit\Facades\ImageKit;

it('uploads the avatar', function () {
    $fake = ImageKit::fake();

    $user->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection('avatar');

    $fake->assertUploaded($media);
});
```

Available assertions:

| Assertion | Checks |
|---|---|
| `assertUploaded(Media $media)` | This media row was uploaded (via `upload()` or `uploadNow()`) |
| `assertNotUploaded(Media $media)` | This media row was **not** uploaded |
| `assertDeleted(string $fileId)` | This ImageKit file ID was deleted |
| `assertNothingUploaded()` | No uploads happened at all during the test |

When your own code needs the client injected rather than called through the facade, type-hint the `Thecyrilcril\ImageKit\Contracts\ImageKitClient` contract. The service provider binds it as a singleton, and `ImageKit::fake()` swaps that same binding, so the fake reaches injected consumers too. Do not type-hint `ImageKitManager`: it is `final`, it is no longer the bound singleton, and an auto-resolved instance is not swapped by `ImageKit::fake()`.

To test how your own code handles an ImageKit outage, force `uploadNow()` to return `null` the way it would on a real failure:

```php
$fake = ImageKit::fake()->failUploads();

$result = ImageKit::uploadNow($media, 'avatar');

expect($result)->toBeNull();
```

## Hosting note

If your application is API-only, it may have no writable `public/` directory and no `storage:link` — there is nowhere for a "local" URL to be served from while an upload is queued. In that setup, prefer `await: true` on the relevant profiles: the response always carries a real ImageKit CDN URL, and the package never needs to fall back to a local disk URL that would not resolve to anything.

## Footguns

**`imagekit:reconcile --delete` on a shared account without `--folder`** deletes every other application's files, because none of them have rows in this app's media table. Always pass `--folder=<root>`, where the root is this app's `IMAGEKIT_FOLDER`.

Two `format` choices look reasonable and are not:

- **`format: 'png'` on a photograph makes the file *larger* than the source JPEG**, not smaller. PNG is lossless and ignores `quality` entirely, so a photo re-encoded as PNG loses none of the detail JPEG's lossy compression would have discarded — and pays for that in file size. Leave `format: null` (source format kept) or use `'webp'` for photographs.
- **`format: 'jpeg'` flattens transparency to a solid background.** JPEG has no alpha channel, so any transparent pixels in a PNG or WebP source are filled in rather than preserved. The package logs a warning when this happens but does not block the upload — decide deliberately if any of your source images can have transparency.
