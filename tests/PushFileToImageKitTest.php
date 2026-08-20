<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Data\UploadOptions;
use Thecyrilcril\ImageKit\Events\FileUploaded;
use Thecyrilcril\ImageKit\Events\FileUploadFailed;
use Thecyrilcril\ImageKit\Exceptions\UploadFailed;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    // The `avatar` collection is registered for ImageKit (see TestModel), so
    // MediaAddedListener auto-dispatches a real PushFileToImageKit for every
    // attachImage() call below. These tests exercise the job in isolation via
    // manual ->handle() calls, so the listener's own copy must be faked out
    // rather than left to run for real.
    Queue::fake();

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

function attachImage(TestModel $model, string $collection = 'avatar'): Media
{
    return $model
        ->addMedia(UploadedFile::fake()->image('photo.jpg', 50, 50))
        ->toMediaCollection($collection);
}

it('uploads and writes back the two custom properties', function (): void {
    $media = attachImage($this->model);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->once()->andReturn(new UploadedFileResult(
        fileId: 'f-1',
        path: '/avatar/photo.jpg',
        url: 'https://ik.imagekit.io/test/avatar/photo.jpg',
        name: 'photo.jpg',
        size: 100,
    ));
    $this->app->instance(UploadsFiles::class, $uploader);

    (new PushFileToImageKit($media->id, 'avatar'))->handle();

    $media->refresh();

    expect($media->getCustomProperty('imagekit.file_id'))->toBe('f-1')
        ->and($media->getCustomProperty('imagekit.file_path'))->toBe('/avatar/photo.jpg');
});

// Covers AE4.
it('uploads under the root folder joined with the collection', function (): void {
    config()->set('imagekit.folder', 'kitwire');

    $media = attachImage($this->model);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')
        ->once()
        ->withArgs(fn (string $contents, UploadOptions $options): bool => $options->folder === 'kitwire/avatar'
            && $options->tags === ['avatar'])
        ->andReturn(new UploadedFileResult(
            fileId: 'f-1', path: '/kitwire/avatar/photo.jpg', url: 'u', name: 'photo.jpg', size: 1,
        ));
    $this->app->instance(UploadsFiles::class, $uploader);

    (new PushFileToImageKit($media->id, 'avatar'))->handle();

    expect($media->fresh()->getCustomProperty('imagekit.file_id'))->toBe('f-1');
});

it('fires FileUploaded on success', function (): void {
    Event::fake([FileUploaded::class]);

    $media = attachImage($this->model);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->andReturn(new UploadedFileResult(
        fileId: 'f-1', path: '/p.jpg', url: 'u', name: 'p.jpg', size: 1,
    ));
    $this->app->instance(UploadsFiles::class, $uploader);

    (new PushFileToImageKit($media->id, 'avatar'))->handle();

    Event::assertDispatched(FileUploaded::class);
});

it('fires FileUploadFailed and rethrows when the upload fails', function (): void {
    Event::fake([FileUploadFailed::class]);

    $media = attachImage($this->model);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->andThrow(UploadFailed::fromResponse('p.jpg', 'boom'));
    $this->app->instance(UploadsFiles::class, $uploader);

    try {
        (new PushFileToImageKit($media->id, 'avatar'))->handle();
        $this->fail('Expected UploadFailed to be rethrown so the queue can retry.');
    } catch (UploadFailed) {
        // expected
    }

    Event::assertDispatched(FileUploadFailed::class);
});

it('exits quietly when the media row has already been deleted', function (): void {
    $media = attachImage($this->model);
    $id = $media->id;
    $media->delete();

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->never();
    $this->app->instance(UploadsFiles::class, $uploader);

    (new PushFileToImageKit($id, 'avatar'))->handle();

    // The job returned without touching the uploader. Asserting the row really
    // is gone keeps this behavioural, rather than leaning solely on Mockery's
    // ->never() expectation, which PHPUnit alone counts as a risky test.
    expect(Media::query()->find($id))->toBeNull();
});

it('is routed onto the configured queue', function (): void {
    config()->set('imagekit.queue.name', 'custom-queue');

    $job = new PushFileToImageKit(1, 'avatar');

    expect($job->queue)->toBe('custom-queue')
        ->and($job->tries)->toBe(3);
});

it('survives a serialize round-trip with its id and profile intact', function (): void {
    $job = unserialize(serialize(new PushFileToImageKit(7, 'avatar')));

    expect($job)->toBeInstanceOf(PushFileToImageKit::class)
        ->and($job->mediaId)->toBe(7)
        ->and($job->profile)->toBe('avatar');
});

it('is routed onto the configured connection when one is set', function (): void {
    config()->set('imagekit.queue.connection', 'redis-uploads');

    expect((new PushFileToImageKit(1, 'avatar'))->connection)->toBe('redis-uploads');
});

it('fails with a RuntimeException when the file is missing on disk', function (): void {
    Event::fake([FileUploadFailed::class]);

    $media = attachImage($this->model);
    unlink($media->getPath());

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldNotReceive('upload');
    $this->app->instance(UploadsFiles::class, $uploader);

    try {
        (new PushFileToImageKit($media->id, 'avatar'))->handle();
        $this->fail('Expected a RuntimeException for the missing file.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('Unable to read media file');
    }

    Event::assertDispatched(FileUploadFailed::class);
});
