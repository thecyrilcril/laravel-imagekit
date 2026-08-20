<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Data;

use Thecyrilcril\ImageKit\Exceptions\InvalidProfile;

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
     * Validates rather than coerces: a profile with a bad quality, max_edge
     * or format throws, so the compressor can trust what it receives.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidProfile
     */
    public static function fromArray(array $config, string $name): self
    {
        /** @var mixed $maxEdge */
        $maxEdge = $config['max_edge'] ?? 2000;
        /** @var mixed $quality */
        $quality = $config['quality'] ?? 90;
        /** @var mixed $format */
        $format = $config['format'] ?? null;

        if (! is_int($maxEdge) || $maxEdge < 1) {
            throw InvalidProfile::maxEdge($name, $maxEdge);
        }

        if (! is_int($quality) || $quality < 1 || $quality > 100) {
            throw InvalidProfile::quality($name, $quality);
        }

        if ($format !== null && ! is_string($format)) {
            throw InvalidProfile::format($name, $format);
        }

        return new self(
            compress: (bool) ($config['compress'] ?? true),
            maxEdge: $maxEdge,
            quality: $quality,
            format: $format,
            await: (bool) ($config['await'] ?? false),
        );
    }
}
