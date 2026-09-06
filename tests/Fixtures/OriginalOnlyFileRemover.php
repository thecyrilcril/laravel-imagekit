<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Tests\Fixtures;

use Illuminate\Contracts\Filesystem\Factory;
use Override;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\FileRemover\FileRemover;

/**
 * A remover whose conversion delete failed and was swallowed: it removes
 * the original and leaves every conversion in place.
 */
final class OriginalOnlyFileRemover implements FileRemover
{
    public function __construct(Filesystem $mediaFileSystem, private Factory $filesystem) {}

    #[Override]
    public function removeAllFiles(Media $media): void
    {
        $this->filesystem->disk($media->disk)->delete($media->getPathRelativeToRoot());
    }

    #[Override]
    public function removeResponsiveImages(Media $media, string $conversionName): void {}

    #[Override]
    public function removeFile(string $path, string $disk): void {}
}
