<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Exceptions;

final class DeleteFailed extends ImageKitException
{
    public static function fromResponse(string $fileId, ?string $message): self
    {
        return new self(sprintf(
            'ImageKit could not delete file [%s]: %s',
            $fileId,
            $message ?? 'unknown error',
        ));
    }
}
