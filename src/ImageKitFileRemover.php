<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit;

use ImageKit\ImageKit as Sdk;
use Override;
use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Exceptions\DeleteFailed;

final readonly class ImageKitFileRemover implements DeletesRemoteFiles
{
    public function __construct(private Sdk $sdk) {}

    #[Override]
    public function delete(string $fileId): bool
    {
        $response = $this->sdk->deleteFile($fileId);

        if (null !== ($response->error ?? null)) {
            $message = $response->error->message ?? null;

            throw DeleteFailed::fromResponse($fileId, is_string($message) ? $message : null);
        }

        return true;
    }
}
