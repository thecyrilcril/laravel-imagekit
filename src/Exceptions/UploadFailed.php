<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Exceptions;

final class UploadFailed extends ImageKitException
{
    public static function fromResponse(string $fileName, ?string $message): self
    {
        return new self(sprintf(
            'ImageKit rejected the upload of [%s]: %s',
            $fileName,
            $message ?? 'unknown error',
        ));
    }

    public static function emptyContents(string $fileName): self
    {
        return new self(sprintf('Refusing to upload [%s]: the file is empty.', $fileName));
    }
}
