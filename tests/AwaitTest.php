<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Thecyrilcril\ImageKit\Concerns\RegistersImageKitCollections;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Data\UploadOptions;
use Thecyrilcril\ImageKit\Events\FileUploadFailed;
use Thecyrilcril\ImageKit\Exceptions\UnregisteredCollection;
use Thecyrilcril\ImageKit\Exceptions\UploadFailed;
use Thecyrilcril\ImageKit\Facades\ImageKit;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;
use Thecyrilcril\ImageKitClient\Exceptions\RequestFailed;

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

// Covers AE4.
it('uploads synchronously under the root folder joined with the collection', function (): void {
    Queue::fake();

    config()->set('imagekit.folder', 'kitwire');
    config()->set('imagekit.profiles.avatar', ['compress' => false, 'await' => true]);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')
        ->once()
        ->withArgs(fn (string $contents, UploadOptions $options): bool => $options->folder === 'kitwire/avatar')
        ->andReturn(new UploadedFileResult(
            fileId: 'sync-1', path: '/kitwire/avatar/a.jpg', url: 'u', name: 'a.jpg', size: 10,
        ));
    $this->app->instance(UploadsFiles::class, $uploader);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    expect($media->fresh()->getCustomProperty('imagekit.file_path'))->toBe('/kitwire/avatar/a.jpg');
});

it('keeps the file and queues a retry when an awaited upload fails', function (): void {
    Queue::fake();
    Event::fake([FileUploadFailed::class]);

    config()->set('imagekit.profiles.avatar', [
        'compress' => false, 'await' => true,
    ]);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->andThrow(UploadFailed::fromClientException('a.jpg', new RequestFailed(503, 'ImageKit down', null)));
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

// Issue #20: a fluent ->await() on the FileAdder chain.

it('awaits one upload with ->await() on an await:false profile, queuing nothing', function (): void {
    Queue::fake();
    $fake = ImageKit::fake();

    config()->set('imagekit.profiles.avatar', ['compress' => false, 'await' => false]);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->await()
        ->toMediaCollection('avatar');

    $fake->assertUploaded($media);
    Queue::assertNotPushed(PushFileToImageKit::class);
});

it('forces the queue with ->await(false) on an await:true profile', function (): void {
    Queue::fake();

    config()->set('imagekit.profiles.avatar', ['compress' => false, 'await' => true]);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldNotReceive('upload');
    $this->app->instance(UploadsFiles::class, $uploader);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->await(false)
        ->toMediaCollection('avatar');

    Queue::assertPushed(PushFileToImageKit::class, 1);

    expect($media->fresh()->custom_properties)->toBe([]);
});

it('stores the cdn path before returning and leaves no bookkeeping on the awaited row', function (): void {
    Queue::fake();

    config()->set('imagekit.profiles.avatar', ['compress' => false, 'await' => false]);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->once()->andReturn(new UploadedFileResult(
        fileId: 'await-1', path: '/avatar/a.jpg', url: 'u', name: 'a.jpg', size: 10,
    ));
    $this->app->instance(UploadsFiles::class, $uploader);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->withCustomProperties(['alt' => 'me'])
        ->await()
        ->toMediaCollection('avatar');

    expect($media->fresh()->custom_properties)->toBe([
        'alt' => 'me',
        'imagekit' => ['file_id' => 'await-1', 'file_path' => '/avatar/a.jpg'],
    ]);

    Queue::assertNotPushed(PushFileToImageKit::class);
});

it('keeps the local url, fires FileUploadFailed and queues a retry when an ->await() upload fails', function (): void {
    Queue::fake();
    Event::fake([FileUploadFailed::class]);

    config()->set('imagekit.profiles.avatar', ['compress' => false, 'await' => false]);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->andThrow(UploadFailed::fromClientException('a.jpg', new RequestFailed(503, 'ImageKit down', null)));
    $this->app->instance(UploadsFiles::class, $uploader);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->await()
        ->toMediaCollection('avatar');

    expect($media->fresh()->getUrl())->toContain('/storage/')
        ->and($media->fresh()->custom_properties)->toBe([]);

    Event::assertDispatched(FileUploadFailed::class);
    Queue::assertPushed(PushFileToImageKit::class, 1);
});

it('throws when ->await() is used on a collection that was never registered with toImageKit()', function (): void {
    Queue::fake();
    ImageKit::fake();

    expect(fn (): mixed => $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->await()
        ->toMediaCollection('plain'))
        ->toThrow(UnregisteredCollection::class, 'plain');
});

it('still ignores an unregistered collection when ->await() is not used', function (): void {
    Queue::fake();
    $fake = ImageKit::fake();

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('plain');

    $fake->assertNotUploaded($media);
});

it('records one upload against ImageKit::fake() for an awaited row', function (): void {
    $fake = ImageKit::fake();

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->await()
        ->toMediaCollection('avatar');

    $fake->assertUploaded($media);
    expect($media->fresh()->custom_properties)->toBe([]);
});

it('returns a row that is not ready when ImageKit::fake()->failUploads() meets ->await()', function (): void {
    $fake = ImageKit::fake()->failUploads();

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->await()
        ->toMediaCollection('avatar');

    $fake->assertUploaded($media);
    expect(RegistersImageKitCollections::isReady($media->fresh()))->toBeFalse();
});

it('awaits an upload from addMediaFromString(), which builds a temporary file', function (): void {
    Queue::fake();
    $fake = ImageKit::fake();

    $media = $this->model->addMediaFromString('hello')
        ->usingFileName('a.txt')
        ->await()
        ->toMediaCollection('avatar');

    $fake->assertUploaded($media);
    Queue::assertNotPushed(PushFileToImageKit::class);
});

it('awaits an upload from addMediaFromDisk(), which stores from a remote file', function (): void {
    Queue::fake();
    $fake = ImageKit::fake();
    Storage::fake('source')->put('in/a.jpg', UploadedFile::fake()->image('a.jpg', 20, 20)->getContent());

    $media = $this->model->addMediaFromDisk('in/a.jpg', 'source')
        ->await()
        ->toMediaCollection('avatar');

    $fake->assertUploaded($media);
    Queue::assertNotPushed(PushFileToImageKit::class);
});

it('awaits an upload attached to a model that is saved later', function (): void {
    Queue::fake();
    $fake = ImageKit::fake();

    $model = new TestModel(['name' => 'later']);

    $model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->await()
        ->toMediaCollection('avatar');

    $fake->assertNothingUploaded();

    $model->save();

    $fake->assertUploaded($model->getFirstMedia('avatar'));
    Queue::assertNotPushed(PushFileToImageKit::class);
});
