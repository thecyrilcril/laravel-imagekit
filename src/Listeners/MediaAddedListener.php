<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Listeners;

use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Concerns\RegistersImageKitCollections;
use Thecyrilcril\ImageKit\ImageKitManager;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
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

        if (! RegistersImageKitCollections::isRegistered($media->collection_name)) {
            return;
        }

        $profile = RegistersImageKitCollections::profileFor($media->collection_name);

        // await:true uploads before the response is built, so an API caller
        // receives the final CDN URL instead of a temporary local one.
        if (app(ProfileRepository::class)->profile($profile)->await) {
            app(ImageKitManager::class)->uploadNow($media, $profile);

            return;
        }

        PushFileToImageKit::dispatch($media->id, $profile);
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
