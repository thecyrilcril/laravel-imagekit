<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Exceptions;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class CleanupFailed extends ImageKitException
{
    /**
     * Media-library's remover reports a failed delete to the exception
     * handler and carries on, so the job checks the disk afterwards and
     * throws this when part of the Source is still there. The queue then
     * retries with the package's tries and backoff.
     *
     * @param  list<string>  $leftovers  "disk:path" of each file still present
     */
    public static function sourceStillOnDisk(Media $media, array $leftovers): self
    {
        return new self(sprintf(
            'Cleanup of media [%s] left part of the Source on disk: [%s]; the delete did not take.',
            $media->id,
            implode(', ', $leftovers),
        ));
    }
}
