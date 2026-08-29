<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Exceptions;

use Thecyrilcril\ImageKitClient\Exceptions\ImageKitClientException;

final class UploadFailed extends ImageKitException
{
    /**
     * Any failure the Client reports: a rejection (its message already names
     * the HTTP status), an unreachable ImageKit, or a malformed 2xx. The
     * Client's exception stays attached as the previous.
     */
    public static function fromClientException(string $fileName, ImageKitClientException $exception): self
    {
        return new self(
            sprintf('Upload of [%s] failed: %s', $fileName, $exception->getMessage()),
            0,
            $exception,
        );
    }

    public static function emptyContents(string $fileName): self
    {
        return new self(sprintf('Refusing to upload [%s]: the file is empty.', $fileName));
    }
}
