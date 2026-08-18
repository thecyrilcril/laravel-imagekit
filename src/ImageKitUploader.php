<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit;

use ImageKit\ImageKit as Sdk;
use Override;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Data\UploadOptions;
use Thecyrilcril\ImageKit\Exceptions\UploadFailed;

final readonly class ImageKitUploader implements UploadsFiles
{
    public function __construct(private Sdk $sdk) {}

    #[Override]
    public function upload(string $contents, UploadOptions $options): UploadedFileResult
    {
        if ($contents === '') {
            throw UploadFailed::emptyContents($options->fileName);
        }

        $response = $this->sdk->uploadFile([
            ...$options->toArray(),
            'file' => $this->toDataUri($contents, $options->fileName),
        ]);

        // The SDK never throws: it returns an object carrying ->error.
        if (null !== ($response->error ?? null)) {
            $message = $response->error->message ?? null;

            throw UploadFailed::fromResponse(
                $options->fileName,
                is_string($message) ? $message : null,
            );
        }

        $result = $response->result ?? null;

        if (! is_object($result)) {
            throw UploadFailed::fromResponse($options->fileName, 'the response contained no result');
        }

        return UploadedFileResult::fromResponse($result);
    }

    private function toDataUri(string $contents, string $fileName): string
    {
        $mime = $this->guessMimeFromName($fileName);

        return sprintf('data:%s;base64,%s', $mime, base64_encode($contents));
    }

    private function guessMimeFromName(string $fileName): string
    {
        $extension = mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            default => 'application/octet-stream',
        };
    }
}
