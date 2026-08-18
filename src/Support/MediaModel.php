<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Support;

use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Resolves the media class the consuming application actually uses.
 *
 * Media-library lets an application swap in its own model via the
 * `media-library.media_model` config key, and applications do — commonly to
 * change the primary key type or add columns. Querying the vendor class
 * directly looks harmless but resolves against the wrong key on a model with
 * a different key type, so the row is never found and the upload fails with
 * a misleading "file not found".
 */
final readonly class MediaModel
{
    /**
     * @return class-string<Media>
     */
    public static function class(): string
    {
        /** @var class-string<Media> $model */
        $model = config('media-library.media_model', Media::class);

        return $model;
    }

    /**
     * @return Builder<Media>
     */
    public static function query(): Builder
    {
        return self::class()::query();
    }

    public static function find(int|string $id): ?Media
    {
        /** @var Media|null $media */
        $media = self::query()->find($id);

        return $media;
    }
}
