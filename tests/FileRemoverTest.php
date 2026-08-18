<?php

declare(strict_types=1);

use ImageKit\ImageKit;
use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Exceptions\DeleteFailed;

beforeEach(function (): void {
    $this->sdk = Mockery::mock(ImageKit::class);
    $this->app->instance(ImageKit::class, $this->sdk);
});

it('deletes a remote file', function (): void {
    $this->sdk->shouldReceive('deleteFile')
        ->once()
        ->with('file-1')
        ->andReturn((object) ['error' => null, 'result' => (object) []]);

    expect(app(DeletesRemoteFiles::class)->delete('file-1'))->toBeTrue();
});

it('throws DeleteFailed, not UploadFailed, when a delete fails', function (): void {
    $this->sdk->shouldReceive('deleteFile')
        ->once()
        ->andReturn((object) ['error' => (object) ['message' => 'Not found'], 'result' => null]);

    app(DeletesRemoteFiles::class)->delete('missing');
})->throws(DeleteFailed::class, 'Not found');
