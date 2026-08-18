<?php

declare(strict_types=1);

use ImageKit\ImageKit;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadOptions;
use Thecyrilcril\ImageKit\Exceptions\UploadFailed;

beforeEach(function (): void {
    $this->sdk = Mockery::mock(ImageKit::class);
    $this->app->instance(ImageKit::class, $this->sdk);
});

it('uploads bytes and returns a typed result', function (): void {
    $this->sdk->shouldReceive('uploadFile')
        ->once()
        ->andReturn((object) [
            'error' => null,
            'result' => (object) [
                'fileId' => 'f1',
                'filePath' => '/avatars/a.jpg',
                'url' => 'https://ik.imagekit.io/test/avatars/a.jpg',
                'name' => 'a.jpg',
                'size' => 1234,
                'width' => 200,
                'height' => 200,
            ],
        ]);

    $result = app(UploadsFiles::class)->upload(
        'bytes',
        new UploadOptions(fileName: 'a.jpg', folder: 'avatars'),
    );

    expect($result->fileId)->toBe('f1')
        ->and($result->path)->toBe('/avatars/a.jpg')
        ->and($result->width)->toBe(200);
});

it('base64 encodes the payload for the SDK', function (): void {
    $this->sdk->shouldReceive('uploadFile')
        ->once()
        ->withArgs(function (array $options): bool {
            return str_starts_with((string) $options['file'], 'data:')
                && str_contains((string) $options['file'], base64_encode('bytes'))
                && $options['fileName'] === 'a.jpg';
        })
        ->andReturn((object) [
            'error' => null,
            'result' => (object) ['fileId' => 'f1', 'filePath' => '/a.jpg', 'url' => 'u', 'name' => 'a.jpg', 'size' => 5],
        ]);

    app(UploadsFiles::class)->upload('bytes', new UploadOptions(fileName: 'a.jpg'));
});

it('converts the SDK error object into an exception', function (): void {
    $this->sdk->shouldReceive('uploadFile')
        ->once()
        ->andReturn((object) [
            'error' => (object) ['message' => 'Invalid private key'],
            'result' => null,
        ]);

    app(UploadsFiles::class)->upload('bytes', new UploadOptions(fileName: 'a.jpg'));
})->throws(UploadFailed::class, 'Invalid private key');

it('refuses to upload empty contents', function (): void {
    app(UploadsFiles::class)->upload('', new UploadOptions(fileName: 'a.jpg'));
})->throws(UploadFailed::class, 'empty');
