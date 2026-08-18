<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit;

use Override;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;
use Thecyrilcril\ImageKit\Contracts\GeneratesFileUrls;

/**
 * Serves ImageKit URLs for media that has been uploaded, and falls back to
 * media-library's own URL for media that has not. The fallback is what makes
 * adoption non-disruptive: absent ImageKit data means ordinary behaviour.
 */
final class ImageKitUrlBuilder extends DefaultUrlGenerator
{
    #[Override]
    public function getUrl(): string
    {
        $path = $this->media->getCustomProperty('imagekit.file_path');

        if (! is_string($path) || $path === '') {
            return parent::getUrl();
        }

        return app(GeneratesFileUrls::class)->build(
            $path,
            $this->conversion?->getName(),
            $this->media->mime_type,
        );
    }
}
