<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Contracts;

use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Data\UploadOptions;
use Thecyrilcril\ImageKit\Exceptions\UploadFailed;

interface UploadsFiles
{
    /**
     * Send raw file bytes to the remote service.
     *
     * @throws UploadFailed
     */
    public function upload(string $contents, UploadOptions $options): UploadedFileResult;
}
