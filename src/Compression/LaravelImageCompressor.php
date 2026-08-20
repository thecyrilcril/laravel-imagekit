<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Compression;

use Illuminate\Support\Facades\Image;
use Override;
use Thecyrilcril\ImageKit\Contracts\CompressesImages;
use Thecyrilcril\ImageKit\Data\CompressionProfile;
use Thecyrilcril\ImageKit\Exceptions\CompressionFailed;
use Throwable;

/**
 * Compresses with Laravel's first-party Illuminate\Image component.
 *
 * scale() is used rather than resize(): it only ever shrinks, so a photo
 * already smaller than the cap is left at its original dimensions.
 */
final readonly class LaravelImageCompressor implements CompressesImages
{
    #[Override]
    public function compress(string $contents, CompressionProfile $profile, string $fileName): string
    {
        if (! $profile->compress) {
            return $contents;
        }

        try {
            $image = Image::fromBytes($contents)
                ->orient()
                ->scale($profile->maxEdge, $profile->maxEdge);

            $image = $profile->format === null
                ? $image
                : $image->toFormat($profile->format);

            return $image->quality($profile->quality)->toBytes();
        } catch (Throwable $exception) {
            throw CompressionFailed::forFile($fileName, $exception);
        }
    }

    #[Override]
    public function supported(): bool
    {
        return true;
    }
}
