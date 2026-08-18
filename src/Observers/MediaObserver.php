<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Observers;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Concerns\RegistersImageKitCollections;
use Thecyrilcril\ImageKit\ImageKitManager;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Jobs\RemoveFileFromImageKit;
use Thecyrilcril\ImageKit\Support\ProfileRepository;

final readonly class MediaObserver
{
    public function created(Media $media): void
    {
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
     * Fires for every deletion route media-library offers: delete(),
     * clearMediaCollection(), singleFile() replacement, and cascades.
     * Dispatched after commit so a rolled-back transaction cannot strand
     * a deleted remote file.
     */
    public function deleted(Media $media): void
    {
        $fileId = $media->getCustomProperty('imagekit.file_id');

        if (! is_string($fileId) || $fileId === '') {
            return;
        }

        RemoveFileFromImageKit::dispatch($fileId)->afterCommit();
    }

    /**
     * Collections are declared inside registerMediaCollections(), which
     * media-library only runs on demand. Touching the collections here
     * guarantees the macro has run before we check the registry.
     */
    private function ensureCollectionsRegistered(Media $media): void
    {
        $model = $media->model;

        if (method_exists($model, 'registerMediaCollections')) {
            $model->registerMediaCollections();
        }
    }
}
