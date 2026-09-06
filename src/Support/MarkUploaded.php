<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Data\CompressionProfile;
use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Events\FileUploaded;
use Thecyrilcril\ImageKit\Jobs\CleanupSource;

/**
 * The one success routine every upload path runs: the manager's awaited
 * upload, the queued job and ImageKit::fake() all call this, so they cannot
 * drift. The order is a precondition, not the safety rule: the row is saved
 * with its file id first, FileUploaded fires second, and only then is
 * Cleanup queued, after commit. The safety rule is the job's own re-check
 * of the row before it deletes anything (ADR 0003).
 *
 * The Cleanup decision is resolved here too: a per-call override wins,
 * otherwise the Profile's `cleanup` flag applies. Null means "use the
 * Profile", as everywhere the override travels.
 */
final class MarkUploaded
{
    public static function on(Media $media, UploadedFileResult $result, CompressionProfile $profile, ?bool $cleanup): void
    {
        $media->setCustomProperty('imagekit.file_id', $result->fileId);
        $media->setCustomProperty('imagekit.file_path', $result->path);
        $media->save();

        FileUploaded::dispatch($media, $result);

        if ($cleanup ?? $profile->cleanup) {
            CleanupSource::dispatch($media->id)->afterCommit();
        }
    }
}
