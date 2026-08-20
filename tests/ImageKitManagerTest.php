<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Contracts\GeneratesFileUrls;
use Thecyrilcril\ImageKit\Contracts\ImageKitClient;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Events\FileUploadFailed;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

it('returns null and fires FileUploadFailed when the file is missing on disk', function (): void {
    Queue::fake();
    Event::fake([FileUploadFailed::class]);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldNotReceive('upload');
    $this->app->instance(UploadsFiles::class, $uploader);

    $media = $this->model->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('plain');
    unlink($media->getPath());

    expect(app(ImageKitClient::class)->uploadNow($media, 'document'))->toBeNull();

    Event::assertDispatched(FileUploadFailed::class);
    Queue::assertPushed(PushFileToImageKit::class);
});

it('delegates url building to the bound url generator', function (): void {
    $urls = Mockery::mock(GeneratesFileUrls::class);
    $urls->shouldReceive('build')->once()->with('/a.jpg', 'avatar', 'image/jpeg')->andReturn('https://cdn/a.jpg');
    $this->app->instance(GeneratesFileUrls::class, $urls);

    expect(app(ImageKitClient::class)->url('/a.jpg', 'avatar', 'image/jpeg'))->toBe('https://cdn/a.jpg');
});

it('delegates deletion to the bound remote deleter', function (): void {
    $deleter = Mockery::mock(DeletesRemoteFiles::class);
    $deleter->shouldReceive('delete')->once()->with('remote-1')->andReturnTrue();
    $this->app->instance(DeletesRemoteFiles::class, $deleter);

    expect(app(ImageKitClient::class)->delete('remote-1'))->toBeTrue();
});
