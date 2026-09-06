<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Exceptions\CleanupFailed;
use Thecyrilcril\ImageKit\Jobs\CleanupSource;
use Thecyrilcril\ImageKit\Tests\Fixtures\ConvertingModel;
use Thecyrilcril\ImageKit\Tests\Fixtures\NoopFileRemover;
use Thecyrilcril\ImageKit\Tests\Fixtures\OriginalOnlyFileRemover;

// Issue #26: the Cleanup job removes the whole Source (ADR 0003).

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    // Conversions are queued by media-library; faking the queue keeps them
    // out of the way so the files below are placed by hand and stay put.
    Queue::fake();

    $this->model = ConvertingModel::query()->create(['name' => 'subject']);
});

/**
 * A row that serves from ImageKit, with an original, one conversion and one
 * responsive image on the media disk.
 *
 * @return array{media: Media, paths: list<string>}
 */
function sourceOnDisk(ConvertingModel $model, bool $uploaded = true): array
{
    $media = $model->addMedia(UploadedFile::fake()->image('p.jpg', 20, 20))
        ->toMediaCollection('avatar');

    if ($uploaded) {
        $media->setCustomProperty('imagekit.file_id', 'remote-1');
        $media->setCustomProperty('imagekit.file_path', '/p.jpg');
    }

    $media->markAsConversionGenerated('thumb');
    $media->save();

    $conversion = $media->getPathRelativeToRoot('thumb');
    $responsive = app(Filesystem::class)->getResponsiveImagesDirectory($media).'p___media_library_original_20_20.jpg';

    Storage::disk('public')->put($conversion, 'thumb-bytes');
    Storage::disk('public')->put($responsive, 'responsive-bytes');

    return ['media' => $media, 'paths' => [$media->getPathRelativeToRoot(), $conversion, $responsive]];
}

it('removes the original, the conversions, the responsive images and the empty directories', function (): void {
    ['media' => $media, 'paths' => $paths] = sourceOnDisk($this->model);

    foreach ($paths as $path) {
        Storage::disk('public')->assertExists($path);
    }

    (new CleanupSource($media->id))->handle();

    foreach ($paths as $path) {
        Storage::disk('public')->assertMissing($path);
    }

    expect(Storage::disk('public')->directories())->toBe([])
        ->and($media->fresh()->getCustomProperty('imagekit.file_id'))->toBe('remote-1')
        ->and($media->fresh()->getCustomProperty('imagekit.file_path'))->toBe('/p.jpg');
});

it('does nothing when the row is gone', function (): void {
    ['media' => $media, 'paths' => $paths] = sourceOnDisk($this->model);
    $id = $media->id;

    $media->deleteQuietly();

    (new CleanupSource($id))->handle();

    foreach ($paths as $path) {
        Storage::disk('public')->assertExists($path);
    }
});

it('does nothing when the row has no file_id, so an unfinished upload never loses its Source', function (): void {
    ['media' => $media, 'paths' => $paths] = sourceOnDisk($this->model, uploaded: false);

    (new CleanupSource($media->id))->handle();

    foreach ($paths as $path) {
        Storage::disk('public')->assertExists($path);
    }
});

it('succeeds silently when the files are already gone', function (): void {
    ['media' => $media] = sourceOnDisk($this->model);

    (new CleanupSource($media->id))->handle();
    (new CleanupSource($media->id))->handle();

    expect($media->fresh())->not->toBeNull();
});

it('logs one warning when the row carries responsive-image data', function (): void {
    ['media' => $media] = sourceOnDisk($this->model);
    $media->responsive_images = ['media_library_original' => ['urls' => ['p___media_library_original_20_20.jpg'], 'base64svg' => '']];
    $media->save();

    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'responsive') && $context['media_id'] === $media->id,
    );

    (new CleanupSource($media->id))->handle();
});

it('logs nothing when the row carries no responsive-image data', function (): void {
    ['media' => $media] = sourceOnDisk($this->model);

    Log::shouldReceive('warning')->never();

    (new CleanupSource($media->id))->handle();
});

it('logs one warning and throws so the queue retries when the original is still on disk after the remover ran', function (): void {
    config()->set('media-library.file_remover_class', NoopFileRemover::class);

    ['media' => $media] = sourceOnDisk($this->model);

    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'retry') && $context['media_id'] === $media->id,
    );

    expect(fn () => (new CleanupSource($media->id))->handle())
        ->toThrow(CleanupFailed::class, "[{$media->id}]");
});

it('throws so the queue retries when a conversion is still on disk after the remover ran', function (): void {
    config()->set('media-library.file_remover_class', OriginalOnlyFileRemover::class);

    ['media' => $media, 'paths' => $paths] = sourceOnDisk($this->model);

    Log::shouldReceive('warning')->once();

    expect(fn () => (new CleanupSource($media->id))->handle())
        ->toThrow(CleanupFailed::class, 'public:'.$paths[1]);

    Storage::disk('public')->assertMissing($paths[0]);
});

it('lands on the cleanup queue name with the shared connection, tries and backoff', function (): void {
    config()->set('imagekit.queue.names.cleanup', 'imagekit-cleanup');
    config()->set('imagekit.queue.connection', 'redis-uploads');

    $job = new CleanupSource(1);

    expect($job->queue)->toBe('imagekit-cleanup')
        ->and($job->connection)->toBe('redis-uploads')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(5);
});

it('survives a serialize round-trip with its id intact', function (): void {
    $job = unserialize(serialize(new CleanupSource(7)));

    expect($job)->toBeInstanceOf(CleanupSource::class)
        ->and($job->mediaId)->toBe(7);
});
