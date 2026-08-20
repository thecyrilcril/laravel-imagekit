<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Reads a media row's bytes from its local disk. Checks the file exists
 * first: a bare file_get_contents() on a missing path surfaces as a PHP
 * warning-turned-ErrorException, which hides the real cause.
 */
final class MediaContents
{
    /**
     * @throws RuntimeException when the file is missing on disk
     */
    public static function read(Media $media): string
    {
        $path = $media->getPath();

        if (! is_file($path)) {
            throw new RuntimeException("Unable to read media file [{$path}].");
        }

        return File::get($path);
    }
}
