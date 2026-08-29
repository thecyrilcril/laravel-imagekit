<?php

declare(strict_types=1);

use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Data\UploadOptions;
use Thecyrilcril\ImageKitClient\Enums\UploadSourceKind;
use Thecyrilcril\ImageKitClient\Files\UploadedFile;

it('maps the Client\'s upload response', function (): void {
    $result = UploadedFileResult::fromUploadedFile(new UploadedFile(
        fileId: 'file-123',
        name: 'pic.jpg',
        filePath: '/avatars/pic.jpg',
        url: 'https://ik.imagekit.io/test/avatars/pic.jpg',
        fileType: 'image',
        size: 45678,
        thumbnailUrl: 'https://ik.imagekit.io/test/tr:n-thumb/avatars/pic.jpg',
        width: 800,
        height: 600,
    ));

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
    $result = UploadedFileResult::fromUploadedFile(new UploadedFile(
        fileId: 'file-pdf',
        name: 'report.pdf',
        filePath: '/docs/report.pdf',
        url: 'https://ik.imagekit.io/test/docs/report.pdf',
        fileType: 'non-image',
        size: 91011,
    ));

    expect($result->width)->toBeNull()
        ->and($result->height)->toBeNull()
        ->and($result->thumbnailUrl)->toBeNull();
});

it('builds a Client upload request from raw bytes', function (): void {
    $request = (new UploadOptions(
        fileName: 'avatar-1.jpg',
        folder: 'avatars',
        tags: ['avatar', 'user'],
        useUniqueFileName: true,
    ))->toUploadRequest('bytes');

    expect($request->source->kind)->toBe(UploadSourceKind::Bytes)
        ->and($request->source->value)->toBe('bytes')
        ->and($request->fileName)->toBe('avatar-1.jpg')
        ->and($request->useUniqueFileName)->toBeTrue()
        ->and($request->folder)->toBe('avatars')
        ->and($request->tags)->toBe(['avatar', 'user']);
});

it('leaves folder and tags off the request when they are empty', function (?string $folder): void {
    $request = (new UploadOptions(fileName: 'a.jpg', folder: $folder, tags: [], useUniqueFileName: false))
        ->toUploadRequest('bytes');

    expect($request->useUniqueFileName)->toBeFalse()
        ->and($request->folder)->toBeNull()
        ->and($request->tags)->toBeNull();
})->with(['null folder' => [null], 'empty folder' => ['']]);
