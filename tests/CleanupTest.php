<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Events\FileRemoved;
use Thecyrilcril\ImageKit\Jobs\RemoveFileFromImageKit;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

it('queues a remote delete when media is deleted directly', function (): void {
    Queue::fake();

    uploadedMedia($this->model)->delete();

    Queue::assertPushed(RemoveFileFromImageKit::class,
        fn (RemoveFileFromImageKit $job): bool => $job->fileId === 'remote-1');
});

it('queues a remote delete when the collection is cleared', function (): void {
    Queue::fake();

    uploadedMedia($this->model);

    $this->model->clearMediaCollection('avatar');

    Queue::assertPushed(RemoveFileFromImageKit::class);
});

it('queues a remote delete when singleFile replaces the previous upload', function (): void {
    Queue::fake();

    uploadedMedia($this->model);

    $this->model
        ->addMedia(UploadedFile::fake()->image('new.jpg', 20, 20))
        ->toMediaCollection('avatar');

    Queue::assertPushed(RemoveFileFromImageKit::class,
        fn (RemoveFileFromImageKit $job): bool => $job->fileId === 'remote-1');
});

it('does not queue a delete for media that was never uploaded', function (): void {
    Queue::fake();

    $this->model
        ->addMedia(UploadedFile::fake()->image('local.jpg', 20, 20))
        ->toMediaCollection('plain')
        ->delete();

    Queue::assertNotPushed(RemoveFileFromImageKit::class);
});

it('deletes remotely and fires FileRemoved when the job runs', function (): void {
    Event::fake([FileRemoved::class]);

    $remover = Mockery::mock(DeletesRemoteFiles::class);
    $remover->shouldReceive('delete')->once()->with('remote-1')->andReturnTrue();
    $this->app->instance(DeletesRemoteFiles::class, $remover);

    (new RemoveFileFromImageKit('remote-1'))->handle();

    Event::assertDispatched(FileRemoved::class);
});

it('routes the remove job onto the configured connection when one is set', function (): void {
    config()->set('imagekit.queue.connection', 'redis-uploads');

    expect((new RemoveFileFromImageKit('remote-1'))->connection)->toBe('redis-uploads');
});
