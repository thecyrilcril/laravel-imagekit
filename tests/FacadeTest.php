<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Thecyrilcril\ImageKit\Facades\ImageKit;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

it('queues an upload through the facade', function (): void {
    Queue::fake();

    $media = $this->model
        ->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('plain');

    ImageKit::upload($media, 'document');

    Queue::assertPushed(PushFileToImageKit::class,
        fn (PushFileToImageKit $job): bool => $job->profile === 'document');
});

it('counts the media it queues for backfill', function (): void {
    Queue::fake();

    $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))->toMediaCollection('plain');
    $this->model->addMedia(UploadedFile::fake()->image('b.jpg', 20, 20))->toMediaCollection('plain');

    expect(ImageKit::backfill(TestModel::class, 'plain'))->toBe(2);
});

it('skips media already uploaded when backfilling', function (): void {
    Queue::fake();

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))->toMediaCollection('plain');
    $media->setCustomProperty('imagekit.file_id', 'already');
    $media->save();

    expect(ImageKit::backfill(TestModel::class, 'plain'))->toBe(0);
});

it('records uploads and deletions against the fake', function (): void {
    $fake = ImageKit::fake();

    $media = $this->model
        ->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('plain');

    ImageKit::upload($media, 'document');
    ImageKit::delete('remote-9');

    $fake->assertUploaded($media);
    $fake->assertDeleted('remote-9');
});

it('reports media that was never uploaded', function (): void {
    $fake = ImageKit::fake();

    $media = $this->model
        ->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('plain');

    $fake->assertNotUploaded($media);
    $fake->assertNothingUploaded();
});

it('returns a result from an awaited upload by default', function (): void {
    ImageKit::fake();

    $media = $this->model
        ->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('plain');

    expect(ImageKit::uploadNow($media, 'document'))->not->toBeNull();
});

it('can simulate an outage so consumers test their own failure handling', function (): void {
    $fake = ImageKit::fake()->failUploads();

    $media = $this->model
        ->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('plain');

    // Mirrors the real manager: null, but the attempt is still recorded and
    // the media row survives with its local URL.
    expect(ImageKit::uploadNow($media, 'document'))->toBeNull();

    $fake->assertUploaded($media);
});
