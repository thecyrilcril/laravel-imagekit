<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Contracts;

use Thecyrilcril\ImageKit\Exceptions\DeleteFailed;

interface DeletesRemoteFiles
{
    /**
     * @throws DeleteFailed
     */
    public function delete(string $fileId): bool;
}
