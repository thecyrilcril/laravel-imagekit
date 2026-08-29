<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Data\UploadOptions;
use Thecyrilcril\ImageKit\Exceptions\UploadFailed;
use Thecyrilcril\ImageKitClient\Contracts\Files;
use Thecyrilcril\ImageKitClient\Enums\UploadSourceKind;
use Thecyrilcril\ImageKitClient\Exceptions\RequestFailed;
use Thecyrilcril\ImageKitClient\Exceptions\TransportError;
use Thecyrilcril\ImageKitClient\Facades\ImageKitClient;
use Thecyrilcril\ImageKitClient\Files\UploadRequest;

beforeEach(function (): void {
    $this->client = ImageKitClient::fake();
});

it('uploads bytes and returns a typed result', function (): void {
    $result = app(UploadsFiles::class)->upload(
        'bytes',
        new UploadOptions(fileName: 'a.jpg', folder: 'avatars'),
    );

    expect($result->fileId)->toBe('fake_1')
        ->and($result->path)->toBe('/avatars/a.jpg')
        ->and($result->url)->toBe('https://ik.imagekit.io/test/avatars/a.jpg')
        ->and($result->name)->toBe('a.jpg')
        ->and($result->size)->toBe(5)
        ->and($result->thumbnailUrl)->toBe('https://ik.imagekit.io/test/tr:n-ik_ml_thumbnail/avatars/a.jpg');
});

it('sends the raw bytes as the upload source, not a base64 data uri', function (): void {
    app(UploadsFiles::class)->upload('bytes', new UploadOptions(fileName: 'a.jpg'));

    $this->client->assertUploaded(fn (UploadRequest $request): bool => $request->source->kind === UploadSourceKind::Bytes
        && $request->source->value === 'bytes'
        && $request->fileName === 'a.jpg');
});

it('passes the folder, tags and unique-name flag through to the Client', function (): void {
    app(UploadsFiles::class)->upload('bytes', new UploadOptions(
        fileName: 'a.jpg',
        folder: 'avatars',
        tags: ['avatar', 'user'],
        useUniqueFileName: false,
    ));

    $this->client->assertUploaded(fn (UploadRequest $request): bool => $request->folder === 'avatars'
        && $request->tags === ['avatar', 'user']
        && $request->useUniqueFileName === false);
});

it('refuses to upload empty contents before reaching the Client', function (): void {
    try {
        app(UploadsFiles::class)->upload('', new UploadOptions(fileName: 'a.jpg'));
        $this->fail('Expected UploadFailed for empty contents.');
    } catch (UploadFailed $exception) {
        expect($exception->getMessage())->toContain('empty');
    }

    $this->client->assertNothingUploaded();
});

it('wraps a rejection from ImageKit in UploadFailed and keeps the Client exception as previous', function (): void {
    $this->client->failUploads();

    try {
        app(UploadsFiles::class)->upload('bytes', new UploadOptions(fileName: 'a.jpg'));
        $this->fail('Expected UploadFailed when ImageKit rejects the upload.');
    } catch (UploadFailed $exception) {
        expect($exception->getMessage())->toContain('[a.jpg]')
            ->and($exception->getMessage())->toContain('HTTP 500')
            ->and($exception->getPrevious())->toBeInstanceOf(RequestFailed::class);
    }
});

it('wraps an unreachable ImageKit in UploadFailed too', function (): void {
    $files = Mockery::mock(Files::class);
    $files->shouldReceive('upload')
        ->once()
        ->andThrow(TransportError::wrap(new ConnectionException('Could not resolve host: upload.imagekit.io')));
    $this->app->instance(Files::class, $files);

    app(UploadsFiles::class)->upload('bytes', new UploadOptions(fileName: 'a.jpg'));
})->throws(UploadFailed::class, 'Could not resolve host');
