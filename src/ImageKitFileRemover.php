<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit;

use Override;
use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Exceptions\DeleteFailed;
use Thecyrilcril\ImageKitClient\Contracts\Files;
use Thecyrilcril\ImageKitClient\Exceptions\ImageKitClientException;
use Thecyrilcril\ImageKitClient\Exceptions\NotFound;

final readonly class ImageKitFileRemover implements DeletesRemoteFiles
{
    public function __construct(private Files $files) {}

    #[Override]
    public function delete(string $fileId): bool
    {
        try {
            $this->files->delete($fileId);
        } catch (NotFound) {
            // Already gone: a retried job, or a file removed from the
            // dashboard. The outcome the caller wanted has happened, so a
            // retry must not fail over it.
            return true;
        } catch (ImageKitClientException $exception) {
            throw DeleteFailed::fromClientException($fileId, $exception);
        }

        return true;
    }
}
