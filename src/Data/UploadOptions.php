<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Data;

use Thecyrilcril\ImageKitClient\Files\UploadRequest;
use Thecyrilcril\ImageKitClient\Files\UploadSource;

final readonly class UploadOptions
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $fileName,
        public ?string $folder = null,
        public array $tags = [],
        public bool $useUniqueFileName = true,
    ) {}

    /**
     * The Client request for these options, carrying the bytes as-is. An
     * empty folder or tag list stays off the wire, so ImageKit applies its
     * own default rather than an empty value.
     */
    public function toUploadRequest(string $contents): UploadRequest
    {
        return new UploadRequest(
            source: UploadSource::bytes($contents),
            fileName: $this->fileName,
            useUniqueFileName: $this->useUniqueFileName,
            folder: $this->folder === null || $this->folder === '' ? null : $this->folder,
            tags: $this->tags === [] ? null : $this->tags,
        );
    }
}
