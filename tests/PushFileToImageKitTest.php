<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Events\FileUploaded;
use Thecyrilcril\ImageKit\Events\FileUploadFailed;
use Thecyrilcril\ImageKit\Exceptions\UploadFailed;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

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
