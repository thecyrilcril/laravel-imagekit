<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Observers;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Jobs\RemoveFileFromImageKit;

/**
 * Owns the deletion half of the lifecycle only.
 *
 * Uploads are triggered by MediaAddedListener instead, because Eloquent's
 * `created` event fires before media-library has copied the file onto the
 * disk — see that class for the full explanation.
 */
final readonly class MediaObserver
{
    /**
     * Fires for every deletion route media-library offers: delete(),
     * clearMediaCollection(), singleFile() replacement, and cascades.
     * Dispatched after commit so a rolled-back transaction cannot strand
     * a deleted remote file.
     */
    public function deleted(Media $media): void
    {
        $fileId = $media->getCustomProperty('imagekit.file_id');

        if (! is_string($fileId) || $fileId === '') {
            return;
        }

        RemoveFileFromImageKit::dispatch($fileId)->afterCommit();
    }
}
