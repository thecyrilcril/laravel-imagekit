<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\ImageKitUrlBuilder;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

/**
 * Exercises the media-library adapter itself, through Spatie's real URL
 * machinery, rather than the injectable UrlFactory it delegates to.
 *
 * The local fallback is the property that makes this package safe to install
 * into an application that already uses media-library: media with no ImageKit
 * data must keep behaving exactly as it did before. It also covers the window
 * between an upload and the queued job writing the CDN path back.
 */
beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    config()->set('media-library.url_generator', ImageKitUrlBuilder::class);

    Queue::fake();

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

function attach(TestModel $model, string $collection = 'plain'): Media
{
    return $model
        ->addMedia(UploadedFile::fake()->image('photo.jpg', 30, 30))
        ->toMediaCollection($collection);
}

it('is the configured url generator', function (): void {
    expect(config('media-library.url_generator'))->toBe(ImageKitUrlBuilder::class);
});

it('serves the ordinary local url when the media has no imagekit data', function (): void {
    $url = attach($this->model)->getUrl();

    expect($url)->toContain('/storage/')
        ->and($url)->not->toContain('ik.imagekit.io');
});

it('serves the imagekit url once the cdn path has been written back', function (): void {
    $media = attach($this->model);

    $media->setCustomProperty('imagekit.file_path', '/plain/photo.jpg');
    $media->save();

    expect($media->fresh()->getUrl())->toContain('ik.imagekit.io');
});

it('falls back to the local url when the stored path is an empty string', function (): void {
    $media = attach($this->model);

    $media->setCustomProperty('imagekit.file_path', '');
    $media->save();

    expect($media->fresh()->getUrl())->toContain('/storage/');
});

it('falls back to the local url when the stored path is not a string', function (): void {
    $media = attach($this->model);

    // A hand-edited or partially-migrated row could hold anything here.
    $media->setCustomProperty('imagekit.file_path', ['unexpected']);
    $media->save();

    expect($media->fresh()->getUrl())->toContain('/storage/');
});

it('passes the media mime type through so non-images are not transformed', function (): void {
    $media = $this->model
        ->addMedia(UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'))
        ->toMediaCollection('plain');

    $media->setCustomProperty('imagekit.file_path', '/plain/report.pdf');
    $media->save();

    $url = $media->fresh()->getUrl();

    // A document is not transformable, so no tr: transformation segment.
    expect($url)->toContain('ik.imagekit.io')
        ->and($url)->not->toContain('tr:');
});
