<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Tests\Fixtures;

use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * Stands in for a consuming application's own media model.
 *
 * Applications commonly swap `media-library.media_model` for a subclass —
 * kitwire does, with a UUID key and a deletion tombstone. Package code that
 * queries the vendor class directly bypasses this model entirely, which on a
 * differently-keyed model means the row is never found and the upload fails
 * with a misleading "file not found" on a real queue worker.
 *
 * The key type is left alone here so the fixture works against the vendor
 * migration's integer id; the marker below is what proves which class the
 * package actually queried.
 */
final class CustomMedia extends SpatieMedia
{
    public function isTheApplicationModel(): bool
    {
        return true;
    }
}
