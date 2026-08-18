<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Enums;

enum FileCategory: string
{
    case Image = 'image';
    case Vector = 'vector';
    case Video = 'video';
    case Document = 'document';
    case Unknown = 'unknown';

    /**
     * Only raster images can be resized and re-encoded before upload.
     */
    public function compressible(): bool
    {
        return $this === self::Image;
    }

    /**
     * Whether ImageKit can apply delivery transformations to this category.
     * Unknown is deliberately false: appending image parameters to a file we
     * failed to identify produces a broken URL.
     */
    public function transformable(): bool
    {
        return in_array($this, [self::Image, self::Vector, self::Video], true);
    }
}
