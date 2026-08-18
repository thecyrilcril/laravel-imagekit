<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Compression;

use Illuminate\Support\Facades\Log;
use Override;
use Thecyrilcril\ImageKit\Contracts\CompressesImages;
use Thecyrilcril\ImageKit\Data\CompressionProfile;

/**
 * Used when Illuminate\Image is absent (Laravel < 13.20) or no GD/Imagick
 * driver is installed. Uploads proceed with the original bytes.
 */
final class NullImageCompressor implements CompressesImages
{
    private static bool $noticed = false;

    /**
     * Test seam: allows the once-per-process notice to be re-asserted.
     */
    public static function resetNotice(): void
    {
        self::$noticed = false;
    }

    #[Override]
    public function compress(string $contents, CompressionProfile $profile, string $fileName): string
    {
        if ($profile->compress && ! self::$noticed) {
            self::$noticed = true;

            Log::warning(
                'ImageKit: image compression is unavailable, uploading originals. '
                .'Requires Laravel 13.20+ with intervention/image and a GD or Imagick driver.'
            );
        }

        return $contents;
    }

    #[Override]
    public function supported(): bool
    {
        return false;
    }
}
