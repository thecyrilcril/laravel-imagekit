<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    Queue::fake();

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

it('pushes a multipart upload', function (): void {
    $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('avatar');

    Queue::assertPushed(PushFileToImageKit::class);
});

it('pushes a base64 JSON upload', function (): void {
    // The fake must be kept in a variable: as an inline expression its
    // temp file is garbage-collected before file_get_contents() can read
    // the path back off it.
    $file = UploadedFile::fake()->image('a.png', 20, 20);
    $png = base64_encode((string) file_get_contents($file->getRealPath()));

    $this->model->addMediaFromBase64('data:image/png;base64,'.$png)
        ->usingFileName('a.png')
        ->toMediaCollection('avatar');

    Queue::assertPushed(PushFileToImageKit::class);
});

it('pushes a stream upload', function (): void {
    $file = UploadedFile::fake()->image('a.jpg', 20, 20);
    $stream = fopen($file->getRealPath(), 'r');

    $this->model->addMediaFromStream($stream)
        ->usingFileName('a.jpg')
        ->toMediaCollection('avatar');

    Queue::assertPushed(PushFileToImageKit::class);
});

it('pushes a non-image document upload', function (): void {
    $this->model->addMedia(UploadedFile::fake()->create('report.pdf', 40, 'application/pdf'))
        ->toMediaCollection('avatar');

    Queue::assertPushed(PushFileToImageKit::class);
});
