<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Data;

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
     * Map the SDK's untyped stdClass exactly once, so no other class
     * needs a PHPStan suppression for its dynamic properties.
     */
    public static function fromResponse(object $result): self
    {
        /** @var array<string, mixed> $data */
        $data = (array) $result;

        return new self(
            fileId: (string) ($data['fileId'] ?? ''),
            path: (string) ($data['filePath'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            size: (int) ($data['size'] ?? 0),
            width: isset($data['width']) ? (int) $data['width'] : null,
            height: isset($data['height']) ? (int) $data['height'] : null,
            thumbnailUrl: isset($data['thumbnailUrl']) ? (string) $data['thumbnailUrl'] : null,
        );
    }
}
