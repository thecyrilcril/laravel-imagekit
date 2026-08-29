<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Exceptions;

use Thecyrilcril\ImageKitClient\Exceptions\ImageKitClientException;

final class DeleteFailed extends ImageKitException
{
    /**
     * Any failure the Client reports other than not-found, which the remover
     * treats as success. The Client's exception stays attached as the previous.
     */
    public static function fromClientException(string $fileId, ImageKitClientException $exception): self
    {
        return new self(
            sprintf('Delete of ImageKit file [%s] failed: %s', $fileId, $exception->getMessage()),
            0,
            $exception,
        );
    }
}
