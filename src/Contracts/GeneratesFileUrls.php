<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Contracts;

use Thecyrilcril\ImageKit\Exceptions\UnknownProfile;

interface GeneratesFileUrls
{
    /**
     * Build a delivery URL for a stored remote path.
     *
     * @throws UnknownProfile
     */
    public function build(string $path, ?string $preset = null, ?string $mimeType = null): string;
}
