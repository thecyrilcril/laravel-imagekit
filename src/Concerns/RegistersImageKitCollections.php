<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Concerns;

use Spatie\MediaLibrary\MediaCollections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class RegistersImageKitCollections
{
    /**
     * Collections registered for ImageKit, keyed by collection name,
     * valued by the compression profile to use.
     *
     * @var array<string, string|null>
     */
    private static array $registered = [];

    public static function register(): void
    {
        MediaCollection::macro('toImageKit', function (?string $profile = null): MediaCollection {
            /** @var MediaCollection $this */
            RegistersImageKitCollections::remember($this->name, $profile);

            return $this;
        });
    }

    /**
     * Whether the CDN path has landed on this media row yet.
     *
     * `Media` extends Eloquent's `Model`, which is not `Macroable` — any
     * undefined call on a model instance is forwarded to a fresh query
     * builder instead of reaching `Macroable::__call`, so `Media::macro()`
     * silently registers a method nothing ever calls. A plain static
     * helper is the correct extension point here, not a macro.
     */
    public static function isReady(Media $media): bool
    {
        $path = $media->getCustomProperty('imagekit.file_path');

        return is_string($path) && $path !== '';
    }

    public static function remember(string $collection, ?string $profile): void
    {
        self::$registered[$collection] = $profile;
    }

    public static function isRegistered(string $collection): bool
    {
        return array_key_exists($collection, self::$registered);
    }

    public static function profileFor(string $collection): ?string
    {
        return self::$registered[$collection] ?? null;
    }

    public static function flush(): void
    {
        self::$registered = [];
    }
}
