<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Data;

use Thecyrilcril\ImageKitClient\Files\UploadedFile;

final readonly class UploadedFileResult
{
    public function __construct(
        public string $fileId,
        public string $path,
        public string $url,
        public string $name,
        public int $size,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $thumbnailUrl = null,
    ) {}

    /**
     * The subset of the Client's upload response this package stores and
     * hands to its events, under the names its consumers already use.
     */
    public static function fromUploadedFile(UploadedFile $file): self
    {
        return new self(
            fileId: $file->fileId,
            path: $file->filePath,
            url: $file->url,
            name: $file->name,
            size: $file->size,
            width: $file->width,
            height: $file->height,
            thumbnailUrl: $file->thumbnailUrl,
        );
    }
}
