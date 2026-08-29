<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Contracts\CompressesImages;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadOptions;
use Thecyrilcril\ImageKit\Events\FileUploaded;
use Thecyrilcril\ImageKit\Events\FileUploadFailed;
use Thecyrilcril\ImageKit\Exceptions\InvalidProfile;
use Thecyrilcril\ImageKit\Exceptions\UnknownProfile;
use Thecyrilcril\ImageKit\Support\FileCategoryDetector;
use Thecyrilcril\ImageKit\Support\FolderResolver;
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

    public int $tries;

    public int $backoff;

    public function __construct(
        public int|string $mediaId,
        public ?string $profile = null,
    ) {
        /** @var string $queue */
        $queue = config('imagekit.queue.name', 'imagekit');
        /** @var string|null $connection */
        $connection = config('imagekit.queue.connection');

        $this->onQueue($queue);

        if ($connection !== null && $connection !== '') {
            $this->onConnection($connection);
        }

        $this->tries = (int) config('imagekit.queue.tries', 3);
        $this->backoff = (int) config('imagekit.queue.backoff', 5);
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

        $media->setCustomProperty('imagekit.file_id', $result->fileId);
        $media->setCustomProperty('imagekit.file_path', $result->path);
        $media->save();

        FileUploaded::dispatch($media, $result);
    }
}
