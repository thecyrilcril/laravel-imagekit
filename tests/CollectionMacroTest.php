<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Thecyrilcril\ImageKit\Concerns\RegistersImageKitCollections;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    Queue::fake();

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

it('dispatches the push job for a registered collection', function (): void {
    $this->model
        ->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    Queue::assertPushed(PushFileToImageKit::class,
        fn (PushFileToImageKit $job): bool => $job->profile === 'avatar');
});

it('ignores collections that were not registered for ImageKit', function (): void {
    $this->model
        ->addMedia(UploadedFile::fake()->image('b.jpg', 20, 20))
        ->toMediaCollection('plain');

    Queue::assertNotPushed(PushFileToImageKit::class);
});

it('reports not ready until the CDN path is written back', function (): void {
    $media = $this->model
        ->addMedia(UploadedFile::fake()->image('c.jpg', 20, 20))
        ->toMediaCollection('avatar');

    expect(RegistersImageKitCollections::isReady($media))->toBeFalse();

    $media->setCustomProperty('imagekit.file_path', '/avatar/c.jpg');
    $media->save();

    expect(RegistersImageKitCollections::isReady($media))->toBeTrue();
});

it('forgets registered collections on flush', function (): void {
    expect(RegistersImageKitCollections::isRegistered('avatar'))->toBeTrue();

    RegistersImageKitCollections::flush();

    expect(RegistersImageKitCollections::isRegistered('avatar'))->toBeFalse();

    // Collections are re-registered lazily by MediaObserver::created(), so
    // restore state for any test that runs after this one in the process.
    RegistersImageKitCollections::register();
    $this->model->registerMediaCollections();
});
