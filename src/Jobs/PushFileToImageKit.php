<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Contracts\CompressesImages;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadOptions;
use Thecyrilcril\ImageKit\Events\FileUploaded;
use Thecyrilcril\ImageKit\Events\FileUploadFailed;
use Thecyrilcril\ImageKit\Support\FileCategoryDetector;
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
    use SerializesModels;

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
        $media = Media::query()->find($this->mediaId);

        if (! $media instanceof Media) {
            // The row was deleted between dispatch and execution. Nothing to do.
            return;
        }

        try {
            $this->push($media);
        } catch (Throwable $exception) {
            FileUploadFailed::dispatch($media, $exception);

            throw $exception;
        }
    }

    private function push(Media $media): void
    {
        $profiles = app(ProfileRepository::class);
        $profile = $profiles->profile($this->profile);

        $contents = file_get_contents($media->getPath());

        if ($contents === false) {
            throw new RuntimeException("Unable to read media file [{$media->getPath()}].");
        }

        $category = FileCategoryDetector::detect($media->mime_type);

        if ($category->compressible()) {
            $contents = app(CompressesImages::class)->compress($contents, $profile, $media->file_name);
        }

        /** @var string $folder */
        $folder = config('imagekit.folder', 'uploads');

        $result = app(UploadsFiles::class)->upload($contents, new UploadOptions(
            fileName: $media->file_name,
            folder: $media->collection_name !== '' ? $media->collection_name : $folder,
            tags: [$media->collection_name],
        ));

        $media->setCustomProperty('imagekit.file_id', $result->fileId);
        $media->setCustomProperty('imagekit.file_path', $result->path);
        $media->save();

        FileUploaded::dispatch($media, $result);
    }
}
