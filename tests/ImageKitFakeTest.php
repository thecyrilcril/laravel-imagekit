<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use Thecyrilcril\ImageKit\Concerns\RegistersImageKitCollections;
use Thecyrilcril\ImageKit\Events\FileUploaded;
use Thecyrilcril\ImageKit\Facades\ImageKit;
use Thecyrilcril\ImageKit\ImageKitUrlBuilder;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

// Issue #25: ImageKit::fake() mirrors the real manager's success side effects.

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    config()->set('media-library.url_generator', ImageKitUrlBuilder::class);
    config()->set('imagekit.profiles.avatar', ['compress' => false, 'await' => true]);

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

it('leaves the row ready and serving the ImageKit url after a faked awaited upload', function (): void {
    ImageKit::fake();

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    $fresh = $media->fresh();

    expect(RegistersImageKitCollections::isReady($fresh))->toBeTrue()
        ->and($fresh->getCustomProperty('imagekit.file_id'))->toBe('fake-'.$media->id)
        ->and($fresh->getCustomProperty('imagekit.file_path'))->toBe('/uploads/avatar/a.jpg')
        ->and($fresh->getUrl())->toStartWith('https://ik.imagekit.io/test/')
        ->and($fresh->getUrl())->toContain('/uploads/avatar/a.jpg');
});

it('builds the faked path from the configured folder, so folder changes show in urls', function (): void {
    ImageKit::fake();
    config()->set('imagekit.folder', 'kitwire');

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    expect($media->fresh()->getCustomProperty('imagekit.file_path'))->toBe('/kitwire/avatar/a.jpg');
});

it('builds a single-slash path when the app has no root folder', function (): void {
    ImageKit::fake();
    config()->set('imagekit.folder', '');

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    expect($media->fresh()->getCustomProperty('imagekit.file_path'))->toBe('/avatar/a.jpg');
});

it('fires FileUploaded with the same result it returns', function (): void {
    ImageKit::fake();
    Event::fake([FileUploaded::class]);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    Event::assertDispatched(FileUploaded::class, fn (FileUploaded $event): bool => $event->media->is($media)
        && $event->result->fileId === 'fake-'.$media->id
        && $event->result->path === '/uploads/avatar/a.jpg');
});

it('writes nothing and fires nothing when uploads are set to fail', function (): void {
    ImageKit::fake()->failUploads();
    Event::fake([FileUploaded::class]);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    expect(RegistersImageKitCollections::isReady($media->fresh()))->toBeFalse()
        ->and($media->fresh()->custom_properties)->toBe([])
        ->and($media->fresh()->getUrl())->toContain('/storage/');

    Event::assertNotDispatched(FileUploaded::class);
});

it('writes nothing for a queued upload, so a row is not ready until a worker runs', function (): void {
    ImageKit::fake();
    config()->set('imagekit.profiles.avatar', ['compress' => false, 'await' => false]);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    expect(RegistersImageKitCollections::isReady($media->fresh()))->toBeFalse()
        ->and($media->fresh()->custom_properties)->toBe([]);
});

it('asserts the profile an upload used', function (): void {
    $fake = ImageKit::fake();

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    $fake->assertUploaded($media, profile: 'avatar');
    $fake->assertUploaded($media);
});

it('asserts the default profile for a collection registered with a plain toImageKit()', function (): void {
    $fake = ImageKit::fake();

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('photos');

    $fake->assertUploaded($media, profile: 'default');
});

it('asserts the default profile for a queued upload on a plain toImageKit() collection', function (): void {
    $fake = ImageKit::fake();
    config()->set('imagekit.profiles.default.await', false);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('photos');

    $fake->assertUploaded($media, profile: 'default');
});

it('fails assertUploaded when the profile does not match, naming the media and the profile', function (): void {
    $fake = ImageKit::fake();

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    expect(fn () => $fake->assertUploaded($media, profile: 'photos'))
        ->toThrow(AssertionFailedError::class, "media [{$media->id}]")
        ->toThrow(AssertionFailedError::class, '[photos]');
});

it('asserts that nothing was deleted, and lists the ids once something is', function (): void {
    $fake = ImageKit::fake();

    $fake->assertNothingDeleted();

    uploadedMedia($this->model)->delete();

    expect(fn () => $fake->assertNothingDeleted())
        ->toThrow(AssertionFailedError::class, 'remote-1');
});

it('returns 0 from the bulk cleanup(), mirroring backfill()', function (): void {
    $fake = ImageKit::fake();

    expect($fake->cleanup(TestModel::class, 'avatar'))->toBe(0);
});
