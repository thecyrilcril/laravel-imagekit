<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Listeners;

use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Concerns\RegistersImageKitCollections;
use Thecyrilcril\ImageKit\Contracts\ImageKitClient;
use Thecyrilcril\ImageKit\Exceptions\UnregisteredCollection;
use Thecyrilcril\ImageKit\Support\ProfileRepository;

/**
 * Triggers the ImageKit upload once a file has actually landed on the disk.
 *
 * Eloquent's `created` event is the wrong hook: media-library inserts the
 * media row first and copies the file afterwards, so at `created` time
 * `$media->getPath()` points at a file that does not exist yet. Queued
 * uploads survived that only because the job re-reads from disk much later;
 * an `await: true` upload ran immediately and failed every time, silently
 * falling back to a background retry and never returning the CDN URL the
 * API caller was waiting for.
 *
 * MediaHasBeenAddedEvent is dispatched by the media-library Filesystem
 * immediately after copyToMediaLibrary() succeeds, which is the first
 * moment the bytes are readable.
 */
final readonly class MediaAddedListener
{
    public function handle(MediaHasBeenAddedEvent $event): void
    {
        $media = $event->media;

        $this->ensureCollectionsRegistered($media);

        $override = $this->pullAwaitOverride($media);

        if (! RegistersImageKitCollections::isRegistered($media->collection_name)) {
            // A row with no override on a plain collection is normal. A row
            // that asked to await is a missing toImageKit(): fail on the
            // first run instead of silently doing nothing.
            if ($override !== null) {
                throw UnregisteredCollection::awaited($media->collection_name);
            }

            return;
        }

        $profile = RegistersImageKitCollections::profileFor($media->collection_name);

        // await:true uploads before the response is built, so an API caller
        // receives the final CDN URL instead of a temporary local one.
        // Both branches go through the bound client, so ImageKit::fake()
        // intercepts queued collections as well as awaited ones.
        $client = app(ImageKitClient::class);

        if ($override ?? app(ProfileRepository::class)->profile($profile)->await) {
            $client->uploadNow($media, $profile);

            return;
        }

        $client->upload($media, $profile);
    }

    /**
     * Reads the per-call override set by the ->await() macro and strips it
     * from the row in the same step, so neither the awaited nor the queued
     * path leaves package bookkeeping in custom_properties, and a failed
     * uploadNow() cannot hand the flag to the retry job.
     *
     * Only a real boolean counts as an override; anything else, including
     * an absent key, means "use the Profile".
     */
    private function pullAwaitOverride(Media $media): ?bool
    {
        $property = RegistersImageKitCollections::AWAIT_PROPERTY;

        if (! $media->hasCustomProperty($property)) {
            return null;
        }

        /** @var mixed $value */
        $value = $media->getCustomProperty($property);

        $media->forgetCustomProperty($property);

        // Arr::forget() leaves the parent `imagekit` key as an empty array.
        // Drop it too, so a queued row looks exactly as if ->await() was
        // never called.
        if ($media->getCustomProperty('imagekit') === []) {
            $media->forgetCustomProperty('imagekit');
        }

        $media->save();

        return is_bool($value) ? $value : null;
    }

    /**
     * Collections are declared inside registerMediaCollections(), which
     * media-library only runs on demand. Touching the collections here
     * guarantees the toImageKit() macro has run before we check the registry.
     */
    private function ensureCollectionsRegistered(Media $media): void
    {
        $model = $media->model;

        if (method_exists($model, 'registerMediaCollections')) {
            $model->registerMediaCollections();
        }
    }
}
