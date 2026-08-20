<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Thecyrilcril\ImageKit\Contracts\ImageKitClient;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Facades\ImageKit;
use Thecyrilcril\ImageKit\ImageKitManager;
use Thecyrilcril\ImageKit\Testing\ImageKitFake;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

it('binds the real manager to the contract by default', function (): void {
    expect(app(ImageKitClient::class))->toBeInstanceOf(ImageKitManager::class)
        ->and(app(ImageKitClient::class))->toBe(app(ImageKitClient::class));
});

it('swaps the contract binding for the fake', function (): void {
    $fake = ImageKit::fake();

    expect(app(ImageKitClient::class))->toBe($fake)
        ->and($fake)->toBeInstanceOf(ImageKitFake::class);
});

it('makes both the manager and the fake final', function (string $class): void {
    expect(new ReflectionClass($class))
        ->isFinal()->toBeTrue()
        ->implementsInterface(ImageKitClient::class)->toBeTrue();
})->with([ImageKitManager::class, ImageKitFake::class]);

// Covers AE6.
it('routes listener-triggered uploads through the fake so no real upload is attempted', function (bool $await): void {
    Queue::fake();

    config()->set('imagekit.profiles.avatar', ['compress' => false, 'await' => $await]);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldNotReceive('upload');
    $this->app->instance(UploadsFiles::class, $uploader);

    $fake = ImageKit::fake();

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    $fake->assertUploaded($media);
    Queue::assertNothingPushed();
})->with(['awaited' => true, 'queued' => false]);

it('builds urls with the real url builder while faked', function (): void {
    ImageKit::fake();

    expect(ImageKit::url('/x.jpg'))->toStartWith('https://ik.imagekit.io/test/');
});

it('backfills nothing while faked', function (): void {
    $fake = ImageKit::fake();

    $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))->toMediaCollection('plain');

    expect(ImageKit::backfill(TestModel::class, 'plain'))->toBe(0);

    $fake->assertNothingUploaded();
});
