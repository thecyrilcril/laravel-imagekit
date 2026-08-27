# Laravel ImageKit

Upload media-library files to [ImageKit](https://imagekit.io). Compress images first if you want.

The package plugs into [spatie/laravel-medialibrary](https://spatie.be/docs/laravel-medialibrary). You keep writing `addMedia(...)->toMediaCollection(...)`. Mark a collection with `->toImageKit()` and its files go to ImageKit. `$media->getUrl()` returns the CDN URL once the file is there.

One setting, `await`, decides when the upload happens:

| App type | `await` | Why |
|---|---|---|
| Web app | `false` (queued) | The page re-renders later and picks up the CDN URL. |
| API | `true` (synchronous) | The response is the only chance to return the final URL. |
| Hybrid | `false`, then `uploadNow()` where needed | Most uploads queue; a few endpoints wait. |

## Installation

```bash
composer require thecyrilcril/laravel-imagekit -W
php artisan imagekit:install
```

`-W` lets Composer move Guzzle from 8 to 7. The ImageKit SDK needs Guzzle 7, and Laravel accepts both.

The command does the whole setup:

1. Publishes this package's config, and media-library's config and migration.
2. Points media-library's `url_generator` at this package.
3. Asks for your three ImageKit credentials and a root folder, and writes them to `.env`.
4. Offers to run the migrations.

It is safe to run twice. It skips anything already done and tells you. With `--no-interaction` it publishes the files, prints the env block for you to paste, and leaves `.env` and the database alone.

<details>
<summary>Manual installation</summary>

1. Publish the config:

   ```bash
   php artisan vendor:publish --tag=imagekit-config
   ```

2. Add to `.env`:

   ```env
   IMAGEKIT_PUBLIC_KEY=
   IMAGEKIT_PRIVATE_KEY=
   IMAGEKIT_URL_ENDPOINT=
   IMAGEKIT_FOLDER=my-app
   ```

   See [Root folder](#root-folder) for `IMAGEKIT_FOLDER`.

3. Set the URL generator in `config/media-library.php`:

   ```php
   'url_generator' => \Thecyrilcril\ImageKit\ImageKitUrlBuilder::class,
   ```

   The builder returns media-library's normal URL for anything not yet on ImageKit. Collections you have not opted in keep working.

4. Publish media-library's migration and migrate:

   ```bash
   php artisan vendor:publish --tag=medialibrary-migrations
   php artisan migrate
   ```

   This package's own migration adds `imagekit_pending_deletion_at` to the `media` table. It waits until the `media` table exists. On a fresh app, run `migrate` a second time after the table is created.

</details>

## Quick start

### 1. Prepare the model

The model must implement `HasMedia` and use `InteractsWithMedia`. That is media-library's rule. If the model already stores media, it is done.

Mark the collection with `->toImageKit()`:

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
            ->toImageKit('avatar'); // the 'avatar' upload profile, see Configuration
    }
}
```

That is the whole integration. Uploading, compressing, serving CDN URLs and deleting all follow from normal media-library usage.

`->toImageKit()` with no argument uses the `default` profile. A collection without `->toImageKit()` is untouched by this package. Adopt one collection at a time.

### 2. Upload and render

```php
$user->addMedia($request->file('photo'))->toMediaCollection('avatar');

$user->getFirstMediaUrl('avatar'); // same as $media->getUrl(): the 'default' preset
```

With `await: false` (the default) the upload is queued. `getUrl()` returns the local URL until the job finishes, usually a few seconds. After that it returns the CDN URL. The same call always returns the URL that is correct right now.

The package's jobs run on the `imagekit` queue (`imagekit.queue.name`). Make sure a worker listens to it:

```bash
php artisan queue:work --queue=default,imagekit
```

### 3. Serve a preset

A preset is picked by media-library's conversion name. Register a conversion with the preset's name, then ask for it:

```php
use Spatie\MediaLibrary\MediaCollections\Models\Media;

public function registerMediaConversions(?Media $media = null): void
{
    $this->addMediaConversion('avatar')->performOnCollections('avatar');
}
```

```php
$user->getFirstMediaUrl('avatar', 'avatar'); // same as $media->getUrl('avatar')
// https://ik.imagekit.io/<id>/tr:w-200,h-200,fo-face,q-85,f-auto/my-app/avatar/photo.jpg
```

Media-library still generates its own local copy of the conversion. ImageKit does not use it.

### API apps: `await: true`

An API response cannot wait for a queued job. Set `await: true` on the profile so the upload happens before the response:

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

Call `$media->fresh()`. The upload runs inside a media-library event listener. The `$media` object in your controller does not see what the listener wrote. `fresh()` re-reads it.

Base64 uploads work the same way. The trigger is media creation, not the HTTP shape:

```php
$media = $request->user()
    ->addMediaFromBase64($request->string('photo'))
    ->usingFileName('avatar.jpg')
    ->toMediaCollection('avatar');
```

**Read the URL with `getUrl()` on every response. Do not store it.** If a synchronous upload fails, the row keeps its local URL and a retry is queued. A stored URL goes stale when that retry succeeds.

### Hybrid apps: `uploadNow()`

Keep `await: false` on the profile. Call `ImageKit::uploadNow()` where one endpoint must wait:

```php
use Thecyrilcril\ImageKit\Facades\ImageKit;

public function store(Request $request): JsonResponse
{
    $media = $request->user()
        ->addMedia($request->file('photo'))
        ->toMediaCollection('avatar'); // profile says await: false, so this queues

    $result = ImageKit::uploadNow($media, 'avatar'); // this call waits

    return response()->json([
        'avatar_url' => $media->fresh()->getUrl(),
        'uploaded_now' => $result !== null,
    ]);
}
```

`uploadNow()` returns `?UploadedFileResult`. It returns `null` when ImageKit is unreachable. It does not throw. The file is already safe on your local or S3 disk, the error is logged, a `FileUploadFailed` event fires, and a background retry is queued. Always check for `null`:

```php
if ($result === null) {
    // ImageKit is down. The upload will retry in the background.
    // Decide whether the caller needs to know now.
}
```

`await` is explicit on purpose. Queued jobs and console commands have no HTTP request to inspect, so auto-detection would fail exactly where it matters.

### Every upload method works

The push to ImageKit is triggered by media creation. All media-library entry points behave the same:

| Method | Source |
|---|---|
| `addMedia()` | An `UploadedFile` (multipart form) |
| `addMediaFromRequest()` | A named field on the current request |
| `addMediaFromBase64()` | A base64 data URI |
| `addMediaFromStream()` | A PHP stream |
| `addMediaFromUrl()` | A remote URL |
| `addMediaFromDisk()` | A file already on one of your disks |

## Configuration

### Root folder

`IMAGEKIT_FOLDER` (`imagekit.folder`, default `uploads`) is the folder every upload lands under. The final path is `{folder}/{collection}/{file}`. With `IMAGEKIT_FOLDER=kitwire`, a file in `avatars` is stored at `kitwire/avatars/photo.jpg`. Leading and trailing slashes are ignored.

Give every application **and every environment** that shares an ImageKit account its own root, for example `kitwire` and `kitwire-staging`. The root keeps files apart, and `imagekit:reconcile` never looks outside it. ImageKit creates the folder on first upload.

> Before v0.3.0 uploads went to `/{collection}/...`. Files already on ImageKit stay where they are and keep working. Only new uploads use the root folder.

### Profiles and presets

`config/imagekit.php` has two sections:

- **`profiles`** control what is **stored**: compression before upload.
- **`presets`** control what is **served**: ImageKit transformations on the URL.

```php
'profiles' => [
    'avatar' => ['compress' => true, 'max_edge' => 2000, 'quality' => 90, 'format' => null, 'await' => false],
],

'presets' => [
    'avatar' => ['width' => 200, 'height' => 200, 'focus' => 'face', 'quality' => 85, 'format' => 'auto'],
],
```

A profile name and a preset name are independent. The shipped config uses matching names for readability only.

Profile keys:

| Key | Meaning |
|---|---|
| `compress` | `false` uploads the original bytes untouched. |
| `max_edge` | Longest side in pixels. Images are scaled down to this, never up. |
| `quality` | Integer 1–100 for the encoder. Ignored for lossless formats such as PNG. |
| `format` | Output format (`'jpg'`, `'png'`, `'webp'`, …), or `null` to keep the source format. Read [Footguns](#footguns) first. |
| `await` | `false` queues the upload. `true` uploads synchronously. |

A profile is validated the first time it is used. A bad value throws `Thecyrilcril\ImageKit\Exceptions\InvalidProfile` with the profile and field name. Bad values are: `quality` outside 1–100, `max_edge` below 1, a non-string `format`, or a numeric string where an integer is expected (for example `'90'` from `env()`). Nothing is clamped or coerced. An unused profile never throws.

Preset keys pass straight through to ImageKit's [transformation parameters](https://imagekit.io/docs/transformations).

## Compression

Compression runs only when **all three** are true:

1. Laravel 13.20 or newer (ships `Illuminate\Support\Facades\Image`).
2. `intervention/image` is installed.
3. The GD or Imagick PHP extension is loaded.

If one is missing, the package uploads the original bytes and logs one warning per process:

> `ImageKit: image compression is unavailable, uploading originals. Requires Laravel 13.20+ with intervention/image and a GD or Imagick driver.`

Compression applies only where it makes sense:

| File type | Compressed? |
|---|---|
| Raster images (JPEG, PNG, WebP, GIF, …) | Yes. Resized to `max_edge`, re-encoded at `quality`. |
| SVG, video | No. Uploaded as-is. ImageKit still applies presets on read. |
| Plain files (PDF, DOCX, XLSX, ZIP, …) | No. Uploaded as-is. Served as a plain URL, with no transformation. |

**Compression removes EXIF.** GD strips EXIF on every re-encode, even JPEG to JPEG, and `Illuminate\Image` uses GD by default. If you need capture time, GPS or copyright fields, use [Conversion](#conversion-heic-webp-avif--jpeg) instead.

## Conversion (HEIC, WebP, AVIF → JPEG)

Conversion is optional and separate from compression. Use it when you need a JPEG that keeps its EXIF. The common case is an iPhone upload: Safari hands over HEIC, and many image pipelines and vision APIs reject it.

```php
use Thecyrilcril\ImageKit\Contracts\ConvertsImages;

public function store(Request $request, ConvertsImages $converter)
{
    $bytes = $request->file('photo')->get();

    $jpeg = $converter->toJpeg($bytes, $request->file('photo')->getClientOriginalName());
}
```

| Source | Result |
|---|---|
| HEIC / HEIF, WebP, AVIF | Converted to JPEG. EXIF preserved. |
| JPEG | Returned byte for byte. Never re-encoded. |
| Anything else, or an environment that cannot convert | Returned unchanged. |

Format is detected by magic bytes, never by filename. Phones often name a HEIC file `photo.jpg`.

**Convert before you upload, and read the local file.** The CDN strips EXIF on delivery (see [Footguns](#footguns)). Converting through a CDN transformation loses the metadata you converted to keep.

### Check support first

```php
if (! $converter->supported('heic')) {
    // Refuse the upload, or accept it unconverted.
}
```

`supported()` does a trial decode of a real sample file. It does not trust `Imagick::queryFormats()`. That list reports registered coders, not working ones. Read and write support can differ.

### Requirements

Conversion needs the **imagick** extension. Without it the package binds a null converter that returns the original bytes and logs one notice.

HEIC also needs `libheif` **with an HEVC decode plugin**. On Ubuntu and Debian the plugin is only a *Suggests*, so install it:

```bash
sudo apt install libheif-plugin-libde265
```

On a managed platform, check `supported('heic')` before you rely on it. Without support, uploads proceed unconverted. A HEIC that reaches a JPEG-only service is still rejected there.

### Errors

`toJpeg()` throws `ConversionFailed` only for a supported format whose bytes are corrupt or truncated. An unsupported environment is not an error. Use `supported()` to check, not `try/catch`.

### Out of scope

JPEG XL, DNG/RAW, Ultra HDR and Motion/Live Photos are not handled. The last two are already valid JPEGs and need nothing special.

## Deletion

Deleting media through media-library also deletes the file from ImageKit. This covers every path:

- `$media->delete()`
- `$model->clearMediaCollection(...)`
- `singleFile()` collections, when a new upload replaces the old one
- Cascading deletes when the owning model is deleted

The remote delete runs **after** the database transaction commits. A rolled-back transaction never deletes a remote file.

Only files this package uploaded are tracked. A file uploaded through ImageKit's dashboard or API has no `imagekit.file_id` on any `Media` row, so the package cannot clean it up.

### Finding orphans

An orphan is a remote file with no local record. Sources: files from before you adopted this package, rows removed by raw SQL or a restored backup, uploads made outside media-library. Find them with:

```bash
php artisan imagekit:reconcile
```

This lists and does not delete. To delete:

```bash
php artisan imagekit:reconcile --delete
```

Every run stays inside `IMAGEKIT_FOLDER`. Files outside it are never listed and never deleted. If `IMAGEKIT_FOLDER` is empty, the command lists the whole account, but `--delete` refuses to run.

Options: `--folder=avatars` scans one sub-folder under the root. `--chunk=100` sets files fetched per request.

> **Read the listing before you pass `--delete`.**
>
> An orphan is any remote file *this application's* media table does not know. Point a staging app at the production folder and every production file looks orphaned. The root folder protects *other* applications, not a second environment of *this* one. Give each environment its own root (see [Root folder](#root-folder)).
>
> The command refuses `--delete` when no media row references ImageKit at all. An empty local side looks the same as the wrong account.

## Testing

Call `ImageKit::fake()`, then assert on it:

```php
use Thecyrilcril\ImageKit\Facades\ImageKit;

it('uploads the avatar', function () {
    $fake = ImageKit::fake();

    $user->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection('avatar');

    $fake->assertUploaded($media);
});
```

| Assertion | Checks |
|---|---|
| `assertUploaded(Media $media)` | This row was uploaded (via `upload()` or `uploadNow()`). |
| `assertNotUploaded(Media $media)` | This row was not uploaded. |
| `assertDeleted(string $fileId)` | This ImageKit file ID was deleted. |
| `assertNothingUploaded()` | No uploads happened in the test. |

To simulate an outage, make `uploadNow()` return `null`:

```php
$fake = ImageKit::fake()->failUploads();

$result = ImageKit::uploadNow($media, 'avatar');

expect($result)->toBeNull();
```

For injection, type-hint the `Thecyrilcril\ImageKit\Contracts\ImageKitClient` contract. It is bound as a singleton, and `ImageKit::fake()` swaps that binding, so injected consumers get the fake too. `ImageKitManager` is `final` and is not the bound singleton, so an injected `ImageKitManager` is not swapped by the fake.

## Hosting note

An API-only app may have no writable `public/` directory and no `storage:link`. Then there is nowhere to serve a local URL from while an upload is queued. Use `await: true` on those profiles so every response carries a real CDN URL.

## Footguns

**`format: 'png'` on a photo makes the file larger.** PNG is lossless and ignores `quality`. Keep `format: null`, or use `'webp'` for photos.

**`format: 'jpeg'` flattens transparency.** JPEG has no alpha channel. Transparent pixels in a PNG or WebP source are filled with a solid background. The package logs a warning and continues. Decide on purpose if your sources can be transparent.

**The CDN strips EXIF on delivery.** Measured across five delivery variants: no-transform, `?tr=f-jpg`, `?tr=w-800` and `?tr=f-jpg,q-80` all removed the metadata. Only `?tr=orig-true` kept it. A consumer that needs EXIF must convert before upload and read the local file, never the CDN URL. See [Conversion](#conversion-heic-webp-avif--jpeg).
