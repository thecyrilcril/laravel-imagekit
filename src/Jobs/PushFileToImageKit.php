<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Concerns\RoutesToImageKitQueue;
use Thecyrilcril\ImageKit\Contracts\CompressesImages;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadOptions;
use Thecyrilcril\ImageKit\Events\FileUploadFailed;
use Thecyrilcril\ImageKit\Exceptions\InvalidProfile;
use Thecyrilcril\ImageKit\Exceptions\UnknownProfile;
use Thecyrilcril\ImageKit\Support\FileCategoryDetector;
use Thecyrilcril\ImageKit\Support\FolderResolver;
use Thecyrilcril\ImageKit\Support\MarkUploaded;
use Thecyrilcril\ImageKit\Support\MediaContents;
use Thecyrilcril\ImageKit\Support\MediaModel;
use Thecyrilcril\ImageKit\Support\ProfileRepository;
use Throwable;

/**
 * Carries a media id, never a model or an image object. Illuminate\Image
 * refuses to serialize by design, and media-library's disk already holds
 * the bytes, so the job re-reads them when it runs.
 */
final class PushFileToImageKit implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RoutesToImageKitQueue;

    /**
     * $cleanup null means "use the Profile"; the ->cleanup() override
     * travels here as an argument, never on the row (ADR 0003).
     */
    public function __construct(
        public int|string $mediaId,
        public ?string $profile = null,
        public ?bool $cleanup = null,
    ) {
        $this->routeToImageKitQueue('upload');
    }

    public function handle(): void
    {
        // Resolved through the app's configured media_model. Querying the
        // vendor class directly resolves the key wrong on an application
        // model with a different key type, so the row is never found and the
        // upload fails with a misleading "file not found".
        $media = MediaModel::find($this->mediaId);

        if (! $media instanceof Media) {
            // The row was deleted between dispatch and execution. Nothing to do.
            return;
        }

        try {
            $this->push($media);
        } catch (InvalidProfile|UnknownProfile $exception) {
            // A configuration error is deterministic: retrying it tries x
            // backoff times only delays the failed_jobs entry.
            FileUploadFailed::dispatch($media, $exception);

            $this->fail($exception);
        } catch (Throwable $exception) {
            FileUploadFailed::dispatch($media, $exception);

            throw $exception;
        }
    }

    private function push(Media $media): void
    {
        // Same guard as ImageKitManager::backfill(). The README hybrid
        // pattern (addMedia() on an await:false profile, then uploadNow())
        // and the outage retry both leave a second job pointing at a row
        // that another path already pushed. Uploading again would orphan
        // the first remote file, so a row that already serves from ImageKit
        // is left alone. This is the expected path, not an error: no log.
        if ($media->getCustomProperty('imagekit.file_id') !== null) {
            return;
        }

        $profiles = app(ProfileRepository::class);
        $profile = $profiles->profile($this->profile);

        $contents = MediaContents::read($media);

        $category = FileCategoryDetector::detect($media->mime_type);

        if ($category->compressible()) {
            $contents = app(CompressesImages::class)->compress($contents, $profile, $media->file_name);
        }

        $result = app(UploadsFiles::class)->upload($contents, new UploadOptions(
            fileName: $media->file_name,
            folder: FolderResolver::resolve($media->collection_name),
            tags: [$media->collection_name],
        ));

        MarkUploaded::on($media, $result, $profile, $this->cleanup);
    }
}
