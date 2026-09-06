<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Contracts;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Data\UploadedFileResult;

/**
 * The package's public API. Bound as a singleton by the service provider,
 * resolved by the ImageKit facade, and swapped for ImageKitFake by
 * ImageKit::fake(). Type-hint this, never the concrete manager.
 */
interface ImageKitClient
{
    /**
     * Queue an upload for a media row that already exists. $cleanup null
     * means "use the Profile"; true or false overrides it for this row.
     */
    public function upload(Media $media, ?string $profile = null, ?bool $cleanup = null): void;

    /**
     * Upload synchronously, so the caller can return the final CDN URL in the
     * same response. Returns null on failure rather than throwing: the media
     * row keeps its local URL and a background retry is queued. $cleanup
     * null means "use the Profile"; true or false overrides it for this row.
     */
    public function uploadNow(Media $media, ?string $profile = null, ?bool $cleanup = null): ?UploadedFileResult;

    /**
     * Build a delivery URL for a stored path, applying a named preset when the
     * file type supports transformations.
     */
    public function url(string $path, ?string $preset = null, ?string $mimeType = null): string;

    /**
     * Delete a remote file by its ImageKit file id.
     */
    public function delete(string $fileId): bool;

    /**
     * Queue an upload for every media row in a collection that has not
     * already been pushed. Returns the number queued.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function backfill(string $modelClass, string $collection, ?string $profile = null): int;

    /**
     * Queue Cleanup for every media row in a collection that already serves
     * from ImageKit, so files uploaded before the `cleanup` flag existed can
     * lose their Source in bulk. Returns the number queued.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function cleanup(string $modelClass, string $collection): int;
}
