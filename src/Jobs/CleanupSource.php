<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Concerns\RegistersImageKitCollections;
use Thecyrilcril\ImageKit\Concerns\RoutesToImageKitQueue;
use Thecyrilcril\ImageKit\Exceptions\CleanupFailed;
use Thecyrilcril\ImageKit\Support\MediaModel;

/**
 * Cleanup: deletes the Source (the original, its conversions and its
 * responsive images on the media disk) once ImageKit holds the file, so
 * ImageKit is the only place the file exists. See ADR 0003.
 *
 * Carries the media id only and re-checks the row when it runs. That
 * re-check, not the dispatch site, is the safety rule: a row that is gone,
 * or that lost its `imagekit.file_id`, is left alone.
 */
final class CleanupSource implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RoutesToImageKitQueue;

    public function __construct(public int|string $mediaId)
    {
        $this->routeToImageKitQueue('cleanup');
    }

    public function handle(): void
    {
        $media = MediaModel::find($this->mediaId);

        if (! $media instanceof Media) {
            return;
        }

        if (! RegistersImageKitCollections::isUploaded($media)) {
            return;
        }

        // Media-library's own remover, so a custom file_remover_class and a
        // separate conversions disk are honoured. It tolerates files that
        // are already gone and removes each directory once it is empty.
        app(Filesystem::class)->removeAllFiles($media);

        $leftovers = $this->leftovers($media);

        if ($leftovers !== []) {
            // The remover reports a failed delete and carries on, so this is
            // the one place the failure surfaces. Throwing hands it to the
            // queue, which retries with the package's tries and backoff.
            Log::warning('ImageKit Cleanup could not remove the Source; the queue will retry.', [
                'media_id' => $media->id,
                'leftovers' => $leftovers,
            ]);

            throw CleanupFailed::sourceStillOnDisk($media, $leftovers);
        }

        if ($media->responsive_images !== []) {
            // Responsive images are served from the media disk, not from
            // ImageKit, so every srcset entry breaks once the Source is gone.
            // Logged after the delete succeeded, so a retry after
            // CleanupFailed does not repeat it.
            Log::warning('ImageKit Cleanup removed the Source of a media row that carries responsive images; its srcset entries now point at nothing.', [
                'media_id' => $media->id,
            ]);
        }
    }

    /**
     * The original, every registered conversion and every responsive image
     * the row records that is still on its disk after the remover ran, as
     * "disk:path" strings.
     *
     * @return list<string>
     */
    private function leftovers(Media $media): array
    {
        $conversionsDisk = $media->conversions_disk ?: $media->disk;

        $expected = [[$media->disk, $media->getPathRelativeToRoot()]];

        foreach ($media->getMediaConversionNames() as $conversion) {
            $expected[] = [$conversionsDisk, $media->getPathRelativeToRoot($conversion)];
        }

        // Responsive images live on the media disk under their own
        // directory; the row lists their file names per conversion.
        $responsiveImagesDirectory = app(Filesystem::class)->getResponsiveImagesDirectory($media);

        foreach ($media->responsive_images as $generated) {
            foreach ($generated['urls'] ?? [] as $fileName) {
                $expected[] = [$media->disk, $responsiveImagesDirectory.$fileName];
            }
        }

        $leftovers = [];

        foreach ($expected as [$disk, $path]) {
            if (Storage::disk($disk)->exists($path)) {
                $leftovers[] = $disk.':'.$path;
            }
        }

        return $leftovers;
    }
}
