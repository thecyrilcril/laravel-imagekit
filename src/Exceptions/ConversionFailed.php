<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Exceptions;

use Throwable;

/**
 * Raised when a convertible input cannot be decoded — corrupt or truncated
 * bytes, not an absent capability.
 *
 * AN UNSUPPORTED ENVIRONMENT IS NOT AN ERROR and never reaches here: that path
 * returns the input unchanged, mirroring `NullImageCompressor`. This is
 * reserved for "I recognise this format, I can decode this format, and these
 * particular bytes are broken" — a condition the caller genuinely must handle,
 * because silently passing a truncated file through would upload it as though
 * it were sound.
 */
final class ConversionFailed extends ImageKitException
{
    public static function forFile(string $fileName, Throwable $previous): self
    {
        return new self(
            sprintf('Failed to convert [%s] to JPEG: %s', $fileName, $previous->getMessage()),
            previous: $previous,
        );
    }
}
