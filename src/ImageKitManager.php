<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Override;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Contracts\CompressesImages;
use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Contracts\GeneratesFileUrls;
use Thecyrilcril\ImageKit\Contracts\ImageKitClient;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Data\UploadOptions;
use Thecyrilcril\ImageKit\Events\FileUploaded;
use Thecyrilcril\ImageKit\Events\FileUploadFailed;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Support\FileCategoryDetector;
use Thecyrilcril\ImageKit\Support\MediaModel;
use Thecyrilcril\ImageKit\Support\ProfileRepository;
use Throwable;

final class ImageKitManager implements ImageKitClient
{
    /**
     * Queue an upload for a media row that already exists.
     */
    #[Override]
    public function upload(Media $media, ?string $profile = null): void
    {
        PushFileToImageKit::dispatch($media->id, $profile);
    }

    /**
     * Upload synchronously, so the caller can return the final CDN URL in the
     * same response. Used by API controllers and by await:true profiles.
     *
     * On failure the media row is deliberately left intact with its working
     * local URL, a FileUploadFailed event is fired and a background retry is
     * queued. A CDN outage must not cost the user their upload, so null is
     * returned rather than an exception propagating into the response.
     */
    #[Override]
    public function uploadNow(Media $media, ?string $profile = null): ?UploadedFileResult
    {
        try {
            return $this->performUpload($media, $profile);
        } catch (Throwable $exception) {
            Log::warning('ImageKit synchronous upload failed; retrying in the background.', [
                'media_id' => $media->id,
                'error' => $exception->getMessage(),
            ]);

            FileUploadFailed::dispatch($media, $exception);

            PushFileToImageKit::dispatch($media->id, $profile);

            return null;
        }
    }

    private function performUpload(Media $media, ?string $profile): UploadedFileResult
    {
        $compressionProfile = app(ProfileRepository::class)->profile($profile);

        $contents = file_get_contents($media->getPath());

        if ($contents === false) {
            throw new RuntimeException("Unable to read media file [{$media->getPath()}].");
        }

        if (FileCategoryDetector::detect($media->mime_type)->compressible()) {
            $contents = app(CompressesImages::class)
                ->compress($contents, $compressionProfile, $media->file_name);
        }

        $result = app(UploadsFiles::class)->upload($contents, new UploadOptions(
            fileName: $media->file_name,
            folder: $media->collection_name,
        ));

        $media->setCustomProperty('imagekit.file_id', $result->fileId);
        $media->setCustomProperty('imagekit.file_path', $result->path);
        $media->save();

        FileUploaded::dispatch($media, $result);

        return $result;
    }

    #[Override]
    public function url(string $path, ?string $preset = null, ?string $mimeType = null): string
    {
        return app(GeneratesFileUrls::class)->build($path, $preset, $mimeType);
    }

    #[Override]
    public function delete(string $fileId): bool
    {
        return app(DeletesRemoteFiles::class)->delete($fileId);
    }

    /**
     * Queue an upload for every media row in a collection that has not
     * already been pushed. Returns the number queued.
     *
     * @param  class-string<Model>  $modelClass
     */
    #[Override]
    public function backfill(string $modelClass, string $collection, ?string $profile = null): int
    {
        $queued = 0;

        MediaModel::query()
            ->where('model_type', $modelClass)
            ->where('collection_name', $collection)
            ->whereNull('imagekit_pending_deletion_at')
            ->chunkById(100, function ($chunk) use ($profile, &$queued): void {
                foreach ($chunk as $media) {
                    if ($media->getCustomProperty('imagekit.file_id') !== null) {
                        continue;
                    }

                    PushFileToImageKit::dispatch($media->id, $profile);
                    $queued++;
                }
            });

        return $queued;
    }
}
