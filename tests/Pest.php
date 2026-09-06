<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;
use Thecyrilcril\ImageKit\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * A media row that already serves from ImageKit: stored through
 * media-library, then marked with a remote file id and path by hand.
 */
function uploadedMedia(TestModel $model, string $collection = 'avatar'): Media
{
    $media = $model
        ->addMedia(UploadedFile::fake()->image('p.jpg', 20, 20))
        ->toMediaCollection($collection);

    $media->setCustomProperty('imagekit.file_id', 'remote-1');
    $media->setCustomProperty('imagekit.file_path', '/p.jpg');
    $media->save();

    return $media;
}
