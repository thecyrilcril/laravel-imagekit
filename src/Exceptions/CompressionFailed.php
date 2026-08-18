<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Exceptions;

use Throwable;

final class CompressionFailed extends ImageKitException
{
    public static function forFile(string $fileName, Throwable $previous): self
    {
        return new self(
            sprintf('Failed to compress [%s]: %s', $fileName, $previous->getMessage()),
            previous: $previous,
        );
    }
}
