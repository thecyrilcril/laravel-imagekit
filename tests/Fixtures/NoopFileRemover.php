<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Tests\Fixtures;

use Illuminate\Contracts\Filesystem\Factory;
use Override;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\FileRemover\FileRemover;

/**
 * A remover that deletes nothing, standing in for a disk whose delete
 * failed and was swallowed by media-library's own remover.
 */
final class NoopFileRemover implements FileRemover
{
    public function __construct(Filesystem $mediaFileSystem, Factory $filesystem) {}

    #[Override]
    public function removeAllFiles(Media $media): void {}

    #[Override]
    public function removeResponsiveImages(Media $media, string $conversionName): void {}

    #[Override]
    public function removeFile(string $path, string $disk): void {}
}
