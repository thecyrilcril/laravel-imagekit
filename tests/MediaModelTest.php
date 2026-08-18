<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Jobs\PushFileToImageKit;
use Thecyrilcril\ImageKit\Support\MediaModel;
use Thecyrilcril\ImageKit\Tests\Fixtures\CustomMedia;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

/**
 * Media-library lets an application swap in its own media model, and real
 * applications do — usually to change the primary key type. Package code that
 * queries the vendor class directly resolves the key against the wrong type,
 * finds nothing, and the upload fails with a misleading "file not found" on a
 * real queue worker.
 */
beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
});

it('defaults to the vendor media model', function (): void {
    expect(MediaModel::class())->toBe(Media::class);
});

it('resolves the application-configured media model', function (): void {
    config()->set('media-library.media_model', CustomMedia::class);

    expect(MediaModel::class())->toBe(CustomMedia::class)
        ->and(MediaModel::query()->getModel())->toBeInstanceOf(CustomMedia::class);
});

it('returns null for a media id that does not exist', function (): void {
    expect(MediaModel::find('missing-id'))->toBeNull();
});

it('finds a row through the configured model rather than the vendor class', function (): void {
    config()->set('media-library.media_model', CustomMedia::class);

    $media = TestModel::query()->create(['name' => 'subject'])
        ->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('plain');

    expect($media)->toBeInstanceOf(CustomMedia::class)
        ->and(MediaModel::find($media->id))->not->toBeNull();
});

it('uploads media belonging to a custom model, which a hardcoded lookup would miss', function (): void {
    config()->set('media-library.media_model', CustomMedia::class);

    $uploader = Mockery::mock(UploadsFiles::class);
    $uploader->shouldReceive('upload')->once()->andReturn(new UploadedFileResult(
        fileId: 'custom-1',
        path: '/plain/a.jpg',
        url: 'https://ik.imagekit.io/test/plain/a.jpg',
        name: 'a.jpg',
        size: 10,
    ));
    $this->app->instance(UploadsFiles::class, $uploader);

    $media = TestModel::query()->create(['name' => 'subject'])
        ->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('plain');

    (new PushFileToImageKit($media->id, 'default'))->handle();

    expect($media->fresh()->getCustomProperty('imagekit.file_path'))->toBe('/plain/a.jpg');
});
