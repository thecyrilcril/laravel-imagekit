<?php

declare(strict_types=1);

use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Exceptions\DeleteFailed;
use Thecyrilcril\ImageKitClient\Contracts\Files;
use Thecyrilcril\ImageKitClient\Exceptions\NotFound;
use Thecyrilcril\ImageKitClient\Exceptions\RequestFailed;
use Thecyrilcril\ImageKitClient\Facades\ImageKitClient;

beforeEach(function (): void {
    $this->client = ImageKitClient::fake();
});

it('deletes a remote file through the Client', function (): void {
    expect(app(DeletesRemoteFiles::class)->delete('file-1'))->toBeTrue();

    $this->client->assertDeleted('file-1');
});

it('treats a file ImageKit no longer has as already deleted', function (): void {
    // A retried delete job, or a file removed from the dashboard, must not
    // fail the job: the outcome the caller wanted has already happened.
    $files = Mockery::mock(Files::class);
    $files->shouldReceive('delete')
        ->once()
        ->with('missing')
        ->andThrow(new NotFound(404, 'The requested file does not exist.', null));
    $this->app->instance(Files::class, $files);

    expect(app(DeletesRemoteFiles::class)->delete('missing'))->toBeTrue();
});

it('throws DeleteFailed, not UploadFailed, when ImageKit rejects the delete', function (): void {
    $files = Mockery::mock(Files::class);
    $files->shouldReceive('delete')
        ->once()
        ->andThrow(new RequestFailed(403, 'Your account cannot be authenticated.', null));
    $this->app->instance(Files::class, $files);

    try {
        app(DeletesRemoteFiles::class)->delete('file-1');
        $this->fail('Expected DeleteFailed when ImageKit rejects the delete.');
    } catch (DeleteFailed $exception) {
        expect($exception->getMessage())->toContain('[file-1]')
            ->and($exception->getMessage())->toContain('cannot be authenticated')
            ->and($exception->getPrevious())->toBeInstanceOf(RequestFailed::class);
    }
});
