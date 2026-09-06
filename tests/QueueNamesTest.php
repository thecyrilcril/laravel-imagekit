<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Jobs\RemoveFileFromImageKit;
use Thecyrilcril\ImageKit\Support\QueueName;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

// Issue #24: per-action queue names with one shared default.

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    config()->set('imagekit.profiles.avatar', ['compress' => false, 'await' => false]);

    Queue::fake();

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

function queueUpload(TestModel $model): Media
{
    return $model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');
}

function queueRemove(TestModel $model): void
{
    uploadedMedia($model)->delete();
}

it('lands every job on the imagekit queue when no name is set', function (): void {
    queueUpload($this->model);
    queueRemove($this->model);

    Queue::assertPushedOn('imagekit', PushFileToImageKit::class);
    Queue::assertPushedOn('imagekit', RemoveFileFromImageKit::class);
});

it('follows queue.name for every job when only the default is changed', function (): void {
    config()->set('imagekit.queue.name', 'media');

    queueUpload($this->model);
    queueRemove($this->model);

    Queue::assertPushedOn('media', PushFileToImageKit::class);
    Queue::assertPushedOn('media', RemoveFileFromImageKit::class);
});

it('moves only the upload job when queue.names.upload is set', function (): void {
    config()->set('imagekit.queue.names.upload', 'imagekit-uploads');

    queueUpload($this->model);
    queueRemove($this->model);

    Queue::assertPushedOn('imagekit-uploads', PushFileToImageKit::class);
    Queue::assertPushedOn('imagekit', RemoveFileFromImageKit::class);
});

it('moves only the remove job when queue.names.remove is set', function (): void {
    config()->set('imagekit.queue.names.remove', 'imagekit-deletes');

    queueUpload($this->model);
    queueRemove($this->model);

    Queue::assertPushedOn('imagekit', PushFileToImageKit::class);
    Queue::assertPushedOn('imagekit-deletes', RemoveFileFromImageKit::class);
});

it('falls back to the default name when an override is an empty string', function (): void {
    config()->set('imagekit.queue.name', 'media');
    config()->set('imagekit.queue.names.upload', '');
    config()->set('imagekit.queue.names.remove', '');

    queueUpload($this->model);
    queueRemove($this->model);

    Queue::assertPushedOn('media', PushFileToImageKit::class);
    Queue::assertPushedOn('media', RemoveFileFromImageKit::class);
});

it('reserves a cleanup name that resolves through the same rule', function (): void {
    expect(QueueName::for('cleanup'))->toBe('imagekit');

    config()->set('imagekit.queue.names.cleanup', 'imagekit-cleanup');

    expect(QueueName::for('cleanup'))->toBe('imagekit-cleanup');
});
