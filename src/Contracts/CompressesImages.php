<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Contracts;

use Thecyrilcril\ImageKit\Data\CompressionProfile;

interface CompressesImages
{
    /**
     * Return compressed bytes, or the input unchanged when compression
     * does not apply. Implementations must never throw for an
     * unsupported environment — check supported() instead.
     */
    public function compress(string $contents, CompressionProfile $profile, string $fileName): string;

    /**
     * Whether this environment can actually compress images.
     */
    public function supported(): bool;
}
