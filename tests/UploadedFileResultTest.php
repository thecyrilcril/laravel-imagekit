<?php

declare(strict_types=1);

use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Data\UploadOptions;

it('maps a full SDK result object', function (): void {
    $result = UploadedFileResult::fromResponse((object) [
        'fileId' => 'file-123',
        'filePath' => '/avatars/pic.jpg',
        'url' => 'https://ik.imagekit.io/test/avatars/pic.jpg',
        'name' => 'pic.jpg',
        'size' => 45678,
        'width' => 800,
        'height' => 600,
        'thumbnailUrl' => 'https://ik.imagekit.io/test/tr:n-thumb/avatars/pic.jpg',
    ]);

    expect($result->fileId)->toBe('file-123')
        ->and($result->path)->toBe('/avatars/pic.jpg')
        ->and($result->url)->toBe('https://ik.imagekit.io/test/avatars/pic.jpg')
        ->and($result->name)->toBe('pic.jpg')
        ->and($result->size)->toBe(45678)
        ->and($result->width)->toBe(800)
        ->and($result->height)->toBe(600)
        ->and($result->thumbnailUrl)->toContain('tr:n-thumb');
});

it('tolerates a non-image result that omits dimensions and thumbnail', function (): void {
    $result = UploadedFileResult::fromResponse((object) [
        'fileId' => 'file-pdf',
        'filePath' => '/docs/report.pdf',
        'url' => 'https://ik.imagekit.io/test/docs/report.pdf',
        'name' => 'report.pdf',
        'size' => 91011,
    ]);

    expect($result->width)->toBeNull()
        ->and($result->height)->toBeNull()
        ->and($result->thumbnailUrl)->toBeNull();
});

it('builds SDK upload option keys', function (): void {
    $options = new UploadOptions(
        fileName: 'avatar-1.jpg',
        folder: 'avatars',
        tags: ['avatar', 'user'],
        useUniqueFileName: true,
    );

    expect($options->toArray())->toBe([
        'fileName' => 'avatar-1.jpg',
        'useUniqueFileName' => true,
        'folder' => 'avatars',
        'tags' => ['avatar', 'user'],
    ]);
});

it('omits folder and tags when they are empty', function (): void {
    $options = new UploadOptions(fileName: 'a.jpg', folder: null, tags: [], useUniqueFileName: false);

    expect($options->toArray())->toBe([
        'fileName' => 'a.jpg',
        'useUniqueFileName' => false,
    ]);
});
