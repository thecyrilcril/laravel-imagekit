<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Facades;

use Illuminate\Support\Facades\Facade;
use Override;
use Thecyrilcril\ImageKit\Contracts\ImageKitClient;
use Thecyrilcril\ImageKit\Testing\ImageKitFake;

/**
 * @method static void upload(\Spatie\MediaLibrary\MediaCollections\Models\Media $media, ?string $profile = null)
 * @method static \Thecyrilcril\ImageKit\Data\UploadedFileResult|null uploadNow(\Spatie\MediaLibrary\MediaCollections\Models\Media $media, ?string $profile = null)
 * @method static string url(string $path, ?string $preset = null, ?string $mimeType = null)
 * @method static bool delete(string $fileId)
 * @method static int backfill(string $modelClass, string $collection, ?string $profile = null)
 *
 * @see ImageKitClient
 */
final class ImageKit extends Facade
{
    public static function fake(): ImageKitFake
    {
        $fake = new ImageKitFake;

        self::swap($fake);

        return $fake;
    }

    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return ImageKitClient::class;
    }
}
