<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Contracts;

use Thecyrilcril\ImageKit\Exceptions\UnknownProfile;
use Thecyrilcril\ImageKitClient\Exceptions\InvalidTransformation;

interface GeneratesFileUrls
{
    /**
     * Build a delivery URL for a stored remote path.
     *
     * @throws UnknownProfile when no Preset has that name
     * @throws InvalidTransformation when the Preset holds a key the Client cannot render
     */
    public function build(string $path, ?string $preset = null, ?string $mimeType = null): string;
}
