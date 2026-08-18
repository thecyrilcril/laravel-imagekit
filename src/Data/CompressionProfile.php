<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Data;

final readonly class CompressionProfile
{
    public function __construct(
        public bool $compress,
        public int $maxEdge,
        public int $quality,
        public ?string $format,
        public bool $await = false,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        /** @var mixed $format */
        $format = $config['format'] ?? null;

        return new self(
            compress: (bool) ($config['compress'] ?? true),
            maxEdge: (int) ($config['max_edge'] ?? 2000),
            quality: (int) ($config['quality'] ?? 90),
            format: is_string($format) ? $format : null,
            await: (bool) ($config['await'] ?? false),
        );
    }
}
