<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Exceptions\UnregisteredCollection;
use Thecyrilcril\ImageKit\Exceptions\UploadFailed;
use Thecyrilcril\ImageKit\Facades\ImageKit;
use Thecyrilcril\ImageKit\ImageKitUrlBuilder;
use Thecyrilcril\ImageKit\Jobs\CleanupSource;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Jobs\RemoveFileFromImageKit;
use Thecyrilcril\ImageKit\Support\MediaModel;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;
use Thecyrilcril\ImageKitClient\Exceptions\RequestFailed;

// Issue #26: Cleanup via the profile flag, ->cleanup() and ImageKit::cleanup().

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    Queue::fake();

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

function cleanupProfile(bool $await, bool $cleanup): void
{
    config()->set('imagekit.profiles.avatar', ['compress' => false, 'await' => $await, 'cleanup' => $cleanup]);
}

function uploaderReturning(string $fileId): void
{
    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->once()->andReturn(new UploadedFileResult(
        fileId: $fileId, path: '/avatar/a.jpg', url: 'u', name: 'a.jpg', size: 10,
    ));
    app()->instance(UploadsFiles::class, $uploader);
}

function cleanupImage(): UploadedFile
{
    return UploadedFile::fake()->image('a.jpg', 20, 20);
}

// Awaited path.

it('queues Cleanup after an awaited upload on a cleanup:true profile, once the row has file_id', function (): void {
    cleanupProfile(await: true, cleanup: true);
    uploaderReturning('sync-1');

    $media = $this->model->addMedia(cleanupImage())->toMediaCollection('avatar');

    Queue::assertPushed(CleanupSource::class, function (CleanupSource $job) use ($media): bool {
        // Dispatched only after the row was saved with its file id.
        return $job->mediaId === $media->id
            && MediaModel::find($job->mediaId)?->getCustomProperty('imagekit.file_id') === 'sync-1';
    });
    Queue::assertPushed(CleanupSource::class, 1);
});

it('queues no Cleanup after an awaited upload on a cleanup:false profile', function (): void {
    cleanupProfile(await: true, cleanup: false);
    uploaderReturning('sync-1');

    $this->model->addMedia(cleanupImage())->toMediaCollection('avatar');

    Queue::assertNotPushed(CleanupSource::class);
});

it('queues Cleanup with ->cleanup() on a cleanup:false profile and leaves no override on the row', function (): void {
    cleanupProfile(await: true, cleanup: false);
    uploaderReturning('sync-1');

    $media = $this->model->addMedia(cleanupImage())->cleanup()->toMediaCollection('avatar');

    Queue::assertPushed(CleanupSource::class, 1);
    expect($media->fresh()->custom_properties)->toBe([
        'imagekit' => ['file_id' => 'sync-1', 'file_path' => '/avatar/a.jpg'],
    ]);
});

it('keeps the Source with ->cleanup(false) on a cleanup:true profile', function (): void {
    cleanupProfile(await: true, cleanup: true);
    uploaderReturning('sync-1');

    $media = $this->model->addMedia(cleanupImage())->cleanup(false)->toMediaCollection('avatar');

    Queue::assertNotPushed(CleanupSource::class);
    expect($media->fresh()->custom_properties)->toBe([
        'imagekit' => ['file_id' => 'sync-1', 'file_path' => '/avatar/a.jpg'],
    ]);
});

it('treats ->await()->cleanup() and ->cleanup()->await() the same', function (): void {
    cleanupProfile(await: false, cleanup: false);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->twice()->andReturn(new UploadedFileResult(
        fileId: 'sync-1', path: '/avatar/a.jpg', url: 'u', name: 'a.jpg', size: 10,
    ));
    $this->app->instance(UploadsFiles::class, $uploader);

    // 'avatar' is singleFile, so the second call replaces the first row.
    $first = $this->model->addMedia(cleanupImage())->await()->cleanup()->toMediaCollection('avatar');
    $firstId = $first->id;
    $second = $this->model->addMedia(cleanupImage())->cleanup()->await()->toMediaCollection('avatar');

    Queue::assertNotPushed(PushFileToImageKit::class);
    Queue::assertPushed(CleanupSource::class, fn (CleanupSource $job): bool => $job->mediaId === $firstId);
    Queue::assertPushed(CleanupSource::class, fn (CleanupSource $job): bool => $job->mediaId === $second->id);

    expect($second->fresh()->custom_properties)->toBe([
        'imagekit' => ['file_id' => 'sync-1', 'file_path' => '/avatar/a.jpg'],
    ]);
});

it('queues no Cleanup when an awaited upload fails, and strips the override from the retry', function (): void {
    cleanupProfile(await: true, cleanup: true);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->andThrow(UploadFailed::fromClientException('a.jpg', new RequestFailed(503, 'ImageKit down', null)));
    $this->app->instance(UploadsFiles::class, $uploader);

    $media = $this->model->addMedia(cleanupImage())->cleanup()->toMediaCollection('avatar');

    Queue::assertNotPushed(CleanupSource::class);
    Queue::assertPushed(PushFileToImageKit::class, fn (PushFileToImageKit $job): bool => $job->cleanup === true);
    expect($media->fresh()->custom_properties)->toBe([]);
});

it('queues Cleanup after a manual uploadNow() with a cleanup:true profile', function (): void {
    cleanupProfile(await: false, cleanup: true);
    uploaderReturning('now-1');

    $media = $this->model->addMedia(cleanupImage())->toMediaCollection('avatar');

    expect(ImageKit::uploadNow($media, 'avatar'))->not->toBeNull();

    Queue::assertPushed(CleanupSource::class, 1);
});

it('throws when ->cleanup() is used on a collection never registered with toImageKit()', function (): void {
    expect(fn (): mixed => $this->model->addMedia(cleanupImage())->cleanup()->toMediaCollection('plain'))
        ->toThrow(fn (UnregisteredCollection $exception) => expect($exception->collection)->toBe('plain')
            ->and($exception->getMessage())->toContain('clean up'));
});

// Queued path.

it('carries the ->cleanup() override to the queued job and cleans up after it succeeds', function (): void {
    cleanupProfile(await: false, cleanup: false);
    uploaderReturning('job-1');

    $media = $this->model->addMedia(cleanupImage())->cleanup()->toMediaCollection('avatar');

    expect($media->fresh()->custom_properties)->toBe([]);

    Queue::assertPushed(PushFileToImageKit::class, fn (PushFileToImageKit $job): bool => $job->cleanup === true);

    foreach (Queue::pushed(PushFileToImageKit::class) as $job) {
        $job->handle();
    }

    Queue::assertPushed(CleanupSource::class, fn (CleanupSource $job): bool => $job->mediaId === $media->id);
});

it('follows the profile on the queued job when no override was given', function (): void {
    cleanupProfile(await: false, cleanup: true);
    uploaderReturning('job-1');

    $media = $this->model->addMedia(cleanupImage())->toMediaCollection('avatar');

    Queue::assertPushed(PushFileToImageKit::class, fn (PushFileToImageKit $job): bool => $job->cleanup === null);

    foreach (Queue::pushed(PushFileToImageKit::class) as $job) {
        $job->handle();
    }

    Queue::assertPushed(CleanupSource::class, fn (CleanupSource $job): bool => $job->mediaId === $media->id);
});

it('queues no Cleanup when the queued job fails', function (): void {
    cleanupProfile(await: false, cleanup: true);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->andThrow(UploadFailed::fromClientException('a.jpg', new RequestFailed(503, 'ImageKit down', null)));
    $this->app->instance(UploadsFiles::class, $uploader);

    $this->model->addMedia(cleanupImage())->toMediaCollection('avatar');

    foreach (Queue::pushed(PushFileToImageKit::class) as $job) {
        expect(fn () => $job->handle())->toThrow(UploadFailed::class);
    }

    Queue::assertNotPushed(CleanupSource::class);
});

// Lifecycle after Cleanup.

it('keeps serving the ImageKit url after Cleanup ran', function (): void {
    config()->set('media-library.url_generator', ImageKitUrlBuilder::class);

    $media = uploadedMedia($this->model);

    (new CleanupSource($media->id))->handle();

    Storage::disk('public')->assertMissing($media->getPathRelativeToRoot());

    expect($media->fresh()->getUrl())->toStartWith('https://ik.imagekit.io/test/')
        ->and($media->fresh()->getUrl())->toContain('/p.jpg');
});

it('still queues the remote delete when a cleaned row is deleted, and throws nothing on the missing files', function (): void {
    $media = uploadedMedia($this->model);

    (new CleanupSource($media->id))->handle();

    $media->delete();

    Queue::assertPushed(RemoveFileFromImageKit::class, fn (RemoveFileFromImageKit $job): bool => $job->fileId === 'remote-1');
});

it('returns early from the upload job on a cleaned row, so a missing Source is never re-pushed', function (): void {
    $media = uploadedMedia($this->model);

    (new CleanupSource($media->id))->handle();

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->never();
    $this->app->instance(UploadsFiles::class, $uploader);

    (new PushFileToImageKit($media->id, 'avatar'))->handle();

    expect(ImageKit::backfill(TestModel::class, 'avatar'))->toBe(0);
});

// Bulk.

it('queues one Cleanup per row with a file id, skipping the rest, and returns the count', function (): void {
    $uploaded = uploadedMedia($this->model, 'plain');
    $another = uploadedMedia($this->model, 'plain');
    $local = $this->model->addMedia(cleanupImage())->toMediaCollection('plain');
    $adopted = $this->model->addMedia(cleanupImage())->toMediaCollection('plain');
    $adopted->setCustomProperty('imagekit.file_path', '/adopted.jpg');
    $adopted->save();
    $leaving = uploadedMedia($this->model, 'plain');
    $leaving->forceFill(['imagekit_pending_deletion_at' => now()])->save();

    expect(ImageKit::cleanup(TestModel::class, 'plain'))->toBe(2);

    Queue::assertPushed(CleanupSource::class, 2);
    Queue::assertPushed(CleanupSource::class, fn (CleanupSource $job): bool => $job->mediaId === $uploaded->id);
    Queue::assertPushed(CleanupSource::class, fn (CleanupSource $job): bool => $job->mediaId === $another->id);
    Queue::assertNotPushed(CleanupSource::class, fn (CleanupSource $job): bool => in_array($job->mediaId, [$local->id, $adopted->id, $leaving->id], true));
});

// Fake.

it('queues Cleanup from ImageKit::fake() after a faked awaited upload on a cleanup:true profile', function (): void {
    cleanupProfile(await: true, cleanup: true);
    ImageKit::fake();

    $media = $this->model->addMedia(cleanupImage())->toMediaCollection('avatar');

    Queue::assertPushed(CleanupSource::class, fn (CleanupSource $job): bool => $job->mediaId === $media->id);
});

it('queues Cleanup from ImageKit::fake() with ->cleanup() on a cleanup:false profile, and not with ->cleanup(false)', function (): void {
    cleanupProfile(await: true, cleanup: true);
    ImageKit::fake();

    $this->model->addMedia(cleanupImage())->cleanup(false)->toMediaCollection('avatar');
    Queue::assertNotPushed(CleanupSource::class);

    cleanupProfile(await: true, cleanup: false);
    $media = $this->model->addMedia(cleanupImage())->cleanup()->toMediaCollection('avatar');
    Queue::assertPushed(CleanupSource::class, fn (CleanupSource $job): bool => $job->mediaId === $media->id);
});
