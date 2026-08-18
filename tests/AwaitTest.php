<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Thecyrilcril\ImageKit\Concerns\RegistersImageKitCollections;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Events\FileUploadFailed;
use Thecyrilcril\ImageKit\Exceptions\UploadFailed;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

it('queues the upload when await is false', function (): void {
    Queue::fake();

    config()->set('imagekit.profiles.avatar', [
        'compress' => false, 'await' => false,
    ]);

    $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    Queue::assertPushed(PushFileToImageKit::class);
});

it('uploads before returning when await is true, so the cdn path is stored immediately', function (): void {
    Queue::fake();

    config()->set('imagekit.profiles.avatar', [
        'compress' => false, 'await' => true,
    ]);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->once()->andReturn(new UploadedFileResult(
        fileId: 'sync-1',
        path: '/avatar/a.jpg',
        url: 'https://ik.imagekit.io/test/avatar/a.jpg',
        name: 'a.jpg',
        size: 10,
    ));
    $this->app->instance(UploadsFiles::class, $uploader);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    expect($media->fresh()->getCustomProperty('imagekit.file_path'))->toBe('/avatar/a.jpg');

    Queue::assertNotPushed(PushFileToImageKit::class);
});

it('keeps the file and queues a retry when an awaited upload fails', function (): void {
    Queue::fake();
    Event::fake([FileUploadFailed::class]);

    config()->set('imagekit.profiles.avatar', [
        'compress' => false, 'await' => true,
    ]);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->andThrow(UploadFailed::fromResponse('a.jpg', 'ImageKit down'));
    $this->app->instance(UploadsFiles::class, $uploader);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    // The upload survived: the media row is intact and serves a local URL.
    expect($media->fresh())->not->toBeNull()
        ->and($media->fresh()->getUrl())->toContain('/storage/');

    Event::assertDispatched(FileUploadFailed::class);
    Queue::assertPushed(PushFileToImageKit::class);
});

it('reports readiness only once the cdn path is stored', function (): void {
    Queue::fake();

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    expect(RegistersImageKitCollections::isReady($media))->toBeFalse();

    $media->setCustomProperty('imagekit.file_path', '/avatar/a.jpg');
    $media->save();

    expect(RegistersImageKitCollections::isReady($media->fresh()))->toBeTrue();
});
