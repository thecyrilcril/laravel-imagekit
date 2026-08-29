<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Support;

use Override;
use Thecyrilcril\ImageKit\Contracts\GeneratesFileUrls;
use Thecyrilcril\ImageKitClient\Contracts\Urls;
use Thecyrilcril\ImageKitClient\Urls\UrlRequest;

final readonly class UrlFactory implements GeneratesFileUrls
{
    public function __construct(
        private Urls $urls,
        private ProfileRepository $profiles,
    ) {}

    #[Override]
    public function build(string $path, ?string $preset = null, ?string $mimeType = null): string
    {
        $category = FileCategoryDetector::detect($mimeType);

        // A file we could not identify, or one ImageKit cannot transform,
        // is served raw. Appending image parameters would break the URL.
        //
        // The Preset goes to the Client as configured: its keys are the
        // Client's aliases and short codes, and a key it does not know throws
        // InvalidTransformation rather than emitting a URL ImageKit cannot serve.
        $transformation = $category->transformable()
            ? $this->profiles->preset($preset)
            : [];

        return $this->urls->build(new UrlRequest(
            path: $path,
            transformation: $transformation,
        ));
    }
}
