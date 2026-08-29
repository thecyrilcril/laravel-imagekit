<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Events\FileUploaded;
use Thecyrilcril\ImageKit\Exceptions\UploadFailed;
use Thecyrilcril\ImageKit\Facades\ImageKit;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;
use Thecyrilcril\ImageKitClient\Exceptions\RequestFailed;

/**
 * Covers issue #18: the README hybrid pattern (addMedia() on an await:false
 * profile, then uploadNow() in the same request) and the outage retry both
 * leave two queued paths pointing at one media row. Only one of them may
 * reach ImageKit, or the first remote file becomes an orphan.
 *
 * Queue::fake() stands in for a worker that has not started yet: the jobs
 * queued during the request are collected and run afterwards by hand, in
 * the order a worker would pick them up.
 */
beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    config()->set('imagekit.profiles.avatar', ['compress' => false, 'await' => false]);

    Queue::fake();

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

function runQueuedPushJobs(): void
{
    foreach (Queue::pushed(PushFileToImageKit::class) as $job) {
        $job->handle();
    }
}

it('uploads exactly once when uploadNow() follows a queued addMedia()', function (): void {
    Event::fake([FileUploaded::class]);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->once()->andReturn(new UploadedFileResult(
        fileId: 'now-1', path: '/avatar/a.jpg', url: 'u', name: 'a.jpg', size: 10,
    ));
    $this->app->instance(UploadsFiles::class, $uploader);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    Queue::assertPushed(PushFileToImageKit::class, 1);

    expect(ImageKit::uploadNow($media, 'avatar'))->not->toBeNull();

    runQueuedPushJobs();

    expect($media->fresh()->getCustomProperty('imagekit.file_id'))->toBe('now-1');

    Event::assertDispatchedTimes(FileUploaded::class, 1);
});

it('uploads exactly once after a failed uploadNow() and the original job both run on recovery', function (): void {
    Event::fake([FileUploaded::class]);

    $outage = Mockery::mock(UploadsFiles::class);
    $outage->shouldReceive('upload')->once()->andThrow(
        UploadFailed::fromClientException('a.jpg', new RequestFailed(503, 'ImageKit down', null)),
    );
    $this->app->instance(UploadsFiles::class, $outage);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    expect(ImageKit::uploadNow($media, 'avatar'))->toBeNull();

    // addMedia() queued one job; the failed uploadNow() queued its own retry.
    Queue::assertPushed(PushFileToImageKit::class, 2);

    $recovered = Mockery::mock(UploadsFiles::class);
    $recovered->shouldReceive('upload')->once()->andReturn(new UploadedFileResult(
        fileId: 'retry-1', path: '/avatar/a.jpg', url: 'u', name: 'a.jpg', size: 10,
    ));
    $this->app->instance(UploadsFiles::class, $recovered);

    runQueuedPushJobs();

    expect($media->fresh()->getCustomProperty('imagekit.file_id'))->toBe('retry-1');

    Event::assertDispatchedTimes(FileUploaded::class, 1);
});
