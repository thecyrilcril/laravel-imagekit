<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Compression;

use Illuminate\Support\Facades\Log;
use Override;
use Thecyrilcril\ImageKit\Contracts\ConvertsImages;

/**
 * Used where Imagick is absent, or is present but cannot decode the format in
 * question. Uploads proceed with the original bytes.
 *
 * REFUSING IS THE CORRECT BEHAVIOUR, NOT A DEGRADED ONE. The alternative on a
 * machine with only GD is a conversion that succeeds and silently discards
 * EXIF — and a consumer converts precisely because it needs the metadata. A
 * stripped `DateTimeOriginal` in a package used for proof photographs is a
 * security regression, not a quality one, so passing the bytes through
 * untouched leaves the caller able to detect and refuse; a lossy conversion
 * would not.
 */
final class NullImageConverter implements ConvertsImages
{
    private static bool $noticed = false;

    /** Test seam: allows the once-per-process notice to be re-asserted. */
    public static function resetNotice(): void
    {
        self::$noticed = false;
    }

    #[Override]
    public function toJpeg(string $contents, string $fileName): string
    {
        if (! self::$noticed) {
            self::$noticed = true;

            Log::warning(
                'ImageKit: image conversion is unavailable, uploading originals. '
                .'Requires the imagick extension with a working decoder for the source format '
                .'(HEIC additionally needs libheif with an HEVC decode plugin, e.g. libheif-plugin-libde265).'
            );
        }

        return $contents;
    }

    #[Override]
    public function supported(string $format = 'heic'): bool
    {
        return false;
    }
}
