<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ImageKit\ImageKit;
use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;

/**
 * The package deletes remote files whenever a media row goes, but it can only
 * act on rows it can see. Files that predate adoption, rows removed by raw SQL
 * or a restored database, and anything uploaded outside media-library leave a
 * remote file nothing else will ever find.
 */
beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    Queue::fake();

    $this->sdk = Mockery::mock(ImageKit::class);
    $this->app->instance(ImageKit::class, $this->sdk);

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

/**
 * @param  list<array{fileId: string, filePath: string}>  $files
 */
function listingReturns(array $files): object
{
    return (object) [
        'error' => null,
        'result' => array_map(static fn (array $f): object => (object) $f, $files),
    ];
}

function attachWithRemotePath(TestModel $model, string $remotePath): void
{
    $media = $model
        ->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('plain');

    $media->setCustomProperty('imagekit.file_path', $remotePath);
    $media->save();
}

it('reports when every remote file is accounted for', function (): void {
    attachWithRemotePath($this->model, '/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()->andReturn(listingReturns([
        ['fileId' => 'f1', 'filePath' => '/known/a.jpg'],
    ]));

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('No orphans found')
        ->assertSuccessful();
});

it('lists an orphan without deleting it', function (): void {
    attachWithRemotePath($this->model, '/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()->andReturn(listingReturns([
        ['fileId' => 'f1', 'filePath' => '/known/a.jpg'],
        ['fileId' => 'orphan-1', 'filePath' => '/stale/gone.jpg'],
    ]));

    $remover = Mockery::mock(DeletesRemoteFiles::class);
    $remover->shouldReceive('delete')->never();
    $this->app->instance(DeletesRemoteFiles::class, $remover);

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('Nothing was deleted')
        ->assertSuccessful();
});

it('deletes orphans only when explicitly asked', function (): void {
    attachWithRemotePath($this->model, '/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()->andReturn(listingReturns([
        ['fileId' => 'f1', 'filePath' => '/known/a.jpg'],
        ['fileId' => 'orphan-1', 'filePath' => '/stale/gone.jpg'],
    ]));

    $remover = Mockery::mock(DeletesRemoteFiles::class);
    $remover->shouldReceive('delete')->once()->with('orphan-1')->andReturnTrue();
    $this->app->instance(DeletesRemoteFiles::class, $remover);

    $this->artisan('imagekit:reconcile', ['--delete' => true])
        ->expectsOutputToContain('Deleted 1 orphaned file')
        ->assertSuccessful();
});

it('refuses to delete when no media row references imagekit at all', function (): void {
    // A staging app pointed at a production account looks exactly like this:
    // every remote file appears orphaned. Deleting here would be catastrophic.
    $this->sdk->shouldReceive('listFiles')->never();

    $remover = Mockery::mock(DeletesRemoteFiles::class);
    $remover->shouldReceive('delete')->never();
    $this->app->instance(DeletesRemoteFiles::class, $remover);

    $this->artisan('imagekit:reconcile', ['--delete' => true])
        ->expectsOutputToContain('Refusing to delete')
        ->assertFailed();
});

it('still lists orphans when nothing is referenced, so the state is inspectable', function (): void {
    $this->sdk->shouldReceive('listFiles')->once()->andReturn(listingReturns([
        ['fileId' => 'orphan-1', 'filePath' => '/stale/gone.jpg'],
    ]));

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('Orphaned files')
        ->assertSuccessful();
});

it('pages through the listing until a short page arrives', function (): void {
    attachWithRemotePath($this->model, '/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()
        ->with(['limit' => 2, 'skip' => 0])
        ->andReturn(listingReturns([
            ['fileId' => 'f1', 'filePath' => '/known/a.jpg'],
            ['fileId' => 'f2', 'filePath' => '/page-one/b.jpg'],
        ]));

    $this->sdk->shouldReceive('listFiles')->once()
        ->with(['limit' => 2, 'skip' => 2])
        ->andReturn(listingReturns([
            ['fileId' => 'f3', 'filePath' => '/page-two/c.jpg'],
        ]));

    $this->artisan('imagekit:reconcile', ['--chunk' => 2])
        ->expectsOutputToContain('Files inspected on ImageKit: 3')
        ->assertSuccessful();
});

it('scopes the listing to a folder when asked', function (): void {
    attachWithRemotePath($this->model, '/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()
        ->with(['limit' => 100, 'skip' => 0, 'path' => 'avatars'])
        ->andReturn(listingReturns([]));

    $this->artisan('imagekit:reconcile', ['--folder' => 'avatars'])
        ->assertSuccessful();
});

it('fails loudly when imagekit rejects the listing', function (): void {
    attachWithRemotePath($this->model, '/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()->andReturn((object) [
        'error' => (object) ['message' => 'Invalid private key'],
        'result' => null,
    ]);

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('Invalid private key')
        ->assertFailed();
});

it('ignores media rows that were never uploaded to imagekit', function (): void {
    attachWithRemotePath($this->model, '/known/a.jpg');

    // A second row with no imagekit.file_path must not be mistaken for a
    // reference to anything, nor crash the path collection.
    $this->model->addMedia(UploadedFile::fake()->image('b.jpg', 20, 20))->toMediaCollection('plain');

    $this->sdk->shouldReceive('listFiles')->once()->andReturn(listingReturns([
        ['fileId' => 'f1', 'filePath' => '/known/a.jpg'],
    ]));

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('No orphans found')
        ->assertSuccessful();
});
