<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit;

use Override;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Data\UploadOptions;
use Thecyrilcril\ImageKit\Exceptions\UploadFailed;
use Thecyrilcril\ImageKitClient\Contracts\Files;
use Thecyrilcril\ImageKitClient\Exceptions\ImageKitClientException;

/**
 * Sends the bytes to ImageKit through the Client as a multipart file part.
 * Nothing is base64-encoded on the way, so the request holds one copy of the
 * file, not a copy and a third-larger encoding of it.
 */
final readonly class ImageKitUploader implements UploadsFiles
{
    public function __construct(private Files $files) {}

    #[Override]
    public function upload(string $contents, UploadOptions $options): UploadedFileResult
    {
        if ($contents === '') {
            throw UploadFailed::emptyContents($options->fileName);
        }

        // Built outside the try on purpose: a request the Client refuses to
        // build (an empty file name, say) is a caller bug, not an upload that
        // failed, and must not be reported as one.
        $request = $options->toUploadRequest($contents);

        try {
            $uploaded = $this->files->upload($request);
        } catch (ImageKitClientException $exception) {
            throw UploadFailed::fromClientException($options->fileName, $exception);
        }

        return UploadedFileResult::fromUploadedFile($uploaded);
    }
}
