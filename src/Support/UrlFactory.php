<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Support;

use ImageKit\ImageKit as Sdk;
use Override;
use Thecyrilcril\ImageKit\Contracts\GeneratesFileUrls;

final readonly class UrlFactory implements GeneratesFileUrls
{
    public function __construct(
        private Sdk $sdk,
        private ProfileRepository $profiles,
    ) {}

    #[Override]
    public function build(string $path, ?string $preset = null, ?string $mimeType = null): string
    {
        $category = FileCategoryDetector::detect($mimeType);

        // A file we could not identify, or one ImageKit cannot transform,
        // is served raw. Appending image parameters would break the URL.
        $transformation = $category->transformable()
            ? [$this->profiles->preset($preset)]
            : [];

        return (string) $this->sdk->url([
            'path' => $path,
            'transformation' => $transformation,
        ]);
    }
}
