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
    attachWithRemotePath($this->model, '/uploads/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()->andReturn(listingReturns([
        ['fileId' => 'f1', 'filePath' => '/uploads/known/a.jpg'],
    ]));

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('No orphans found')
        ->assertSuccessful();
});

it('lists an orphan without deleting it', function (): void {
    attachWithRemotePath($this->model, '/uploads/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()->andReturn(listingReturns([
        ['fileId' => 'f1', 'filePath' => '/uploads/known/a.jpg'],
        ['fileId' => 'orphan-1', 'filePath' => '/uploads/stale/gone.jpg'],
    ]));

    $remover = Mockery::mock(DeletesRemoteFiles::class);
    $remover->shouldReceive('delete')->never();
    $this->app->instance(DeletesRemoteFiles::class, $remover);

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('Nothing was deleted')
        ->assertSuccessful();
});

it('deletes orphans only when explicitly asked', function (): void {
    attachWithRemotePath($this->model, '/uploads/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()->andReturn(listingReturns([
        ['fileId' => 'f1', 'filePath' => '/uploads/known/a.jpg'],
        ['fileId' => 'orphan-1', 'filePath' => '/uploads/stale/gone.jpg'],
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
        ['fileId' => 'orphan-1', 'filePath' => '/uploads/stale/gone.jpg'],
    ]));

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('Orphaned files')
        ->assertSuccessful();
});

it('pages through the listing until a short page arrives', function (): void {
    attachWithRemotePath($this->model, '/uploads/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()
        ->with(['limit' => 2, 'skip' => 0, 'path' => '/uploads', 'includeFolder' => 'true'])
        ->andReturn(listingReturns([
            ['fileId' => 'f1', 'filePath' => '/uploads/known/a.jpg'],
            ['fileId' => 'f2', 'filePath' => '/uploads/page-one/b.jpg'],
        ]));

    $this->sdk->shouldReceive('listFiles')->once()
        ->with(['limit' => 2, 'skip' => 2, 'path' => '/uploads', 'includeFolder' => 'true'])
        ->andReturn(listingReturns([
            ['fileId' => 'f3', 'filePath' => '/uploads/page-two/c.jpg'],
        ]));

    $this->artisan('imagekit:reconcile', ['--chunk' => 2])
        ->expectsOutputToContain('Files inspected on ImageKit: 3')
        ->assertSuccessful();
});

it('walks into sub-folders, because ImageKit only lists one folder level per path', function (): void {
    attachWithRemotePath($this->model, '/uploads/avatars/known.jpg');

    // The root itself holds no files, only folders — the shape every real
    // install has, since uploads land at {root}/{collection}/{file}.
    $this->sdk->shouldReceive('listFiles')->once()
        ->with(['limit' => 100, 'skip' => 0, 'path' => '/uploads', 'includeFolder' => 'true'])
        ->andReturn(listingReturns([
            ['type' => 'folder', 'folderPath' => '/uploads/avatars'],
            ['type' => 'folder', 'folderPath' => '/uploads/documents'],
        ]));

    $this->sdk->shouldReceive('listFiles')->once()
        ->with(['limit' => 100, 'skip' => 0, 'path' => '/uploads/avatars', 'includeFolder' => 'true'])
        ->andReturn(listingReturns([
            ['type' => 'file', 'fileId' => 'f1', 'filePath' => '/uploads/avatars/known.jpg'],
            ['type' => 'folder', 'folderPath' => '/uploads/avatars/nested'],
        ]));

    $this->sdk->shouldReceive('listFiles')->once()
        ->with(['limit' => 100, 'skip' => 0, 'path' => '/uploads/avatars/nested', 'includeFolder' => 'true'])
        ->andReturn(listingReturns([
            ['type' => 'file', 'fileId' => 'o1', 'filePath' => '/uploads/avatars/nested/orphan.jpg'],
        ]));

    $this->sdk->shouldReceive('listFiles')->once()
        ->with(['limit' => 100, 'skip' => 0, 'path' => '/uploads/documents', 'includeFolder' => 'true'])
        ->andReturn(listingReturns([]));

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('Files inspected on ImageKit: 2')
        ->expectsOutputToContain('/uploads/avatars/nested/orphan.jpg')
        ->doesntExpectOutputToContain('/uploads/avatars/known.jpg')
        ->assertSuccessful();
});

it('scopes the listing to a folder when asked', function (): void {
    attachWithRemotePath($this->model, '/uploads/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()
        ->with(['limit' => 100, 'skip' => 0, 'path' => '/uploads/avatars', 'includeFolder' => 'true'])
        ->andReturn(listingReturns([]));

    $this->artisan('imagekit:reconcile', ['--folder' => 'avatars'])
        ->expectsOutputToContain('/uploads/avatars')
        ->assertSuccessful();
});

it('always scopes the listing to the configured root folder', function (): void {
    config()->set('imagekit.folder', '/kitwire/');
    attachWithRemotePath($this->model, '/kitwire/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()
        ->with(['limit' => 100, 'skip' => 0, 'path' => '/kitwire', 'includeFolder' => 'true'])
        ->andReturn(listingReturns([]));

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('/kitwire')
        ->assertSuccessful();
});

it('never treats a file outside the root folder as an orphan, even if imagekit returns one', function (): void {
    config()->set('imagekit.folder', 'kitwire');
    attachWithRemotePath($this->model, '/kitwire/known/a.jpg');

    // Another app's file leaking into the listing must be invisible here:
    // this app holds no row for it, so it would otherwise look orphaned.
    $this->sdk->shouldReceive('listFiles')->once()->andReturn(listingReturns([
        ['fileId' => 'f1', 'filePath' => '/kitwire/known/a.jpg'],
        ['fileId' => 'theirs', 'filePath' => '/kwadatis/photos/p.jpg'],
        ['fileId' => 'lookalike', 'filePath' => '/kitwire-old/x.jpg'],
    ]));

    $remover = Mockery::mock(DeletesRemoteFiles::class);
    $remover->shouldReceive('delete')->never();
    $this->app->instance(DeletesRemoteFiles::class, $remover);

    $this->artisan('imagekit:reconcile', ['--delete' => true])
        ->expectsOutputToContain('No orphans found')
        ->assertSuccessful();
});

it('refuses to delete when no root folder is configured', function (): void {
    config()->set('imagekit.folder', '');
    attachWithRemotePath($this->model, '/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->never();

    $remover = Mockery::mock(DeletesRemoteFiles::class);
    $remover->shouldReceive('delete')->never();
    $this->app->instance(DeletesRemoteFiles::class, $remover);

    $this->artisan('imagekit:reconcile', ['--delete' => true])
        ->expectsOutputToContain('IMAGEKIT_FOLDER')
        ->assertFailed();
});

it('still lists the whole account when no root folder is configured', function (): void {
    config()->set('imagekit.folder', '');
    attachWithRemotePath($this->model, '/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()
        ->with(['limit' => 100, 'skip' => 0])
        ->andReturn(listingReturns([
            ['fileId' => 'f1', 'filePath' => '/known/a.jpg'],
            ['fileId' => 'o1', 'filePath' => '/anything/else.jpg'],
        ]));

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('Orphaned files')
        ->assertSuccessful();
});

it('fails loudly when imagekit rejects the listing', function (): void {
    attachWithRemotePath($this->model, '/uploads/known/a.jpg');

    $this->sdk->shouldReceive('listFiles')->once()->andReturn((object) [
        'error' => (object) ['message' => 'Invalid private key'],
        'result' => null,
    ]);

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('Invalid private key')
        ->assertFailed();
});

it('ignores media rows that were never uploaded to imagekit', function (): void {
    attachWithRemotePath($this->model, '/uploads/known/a.jpg');

    // A second row with no imagekit.file_path must not be mistaken for a
    // reference to anything, nor crash the path collection.
    $this->model->addMedia(UploadedFile::fake()->image('b.jpg', 20, 20))->toMediaCollection('plain');

    $this->sdk->shouldReceive('listFiles')->once()->andReturn(listingReturns([
        ['fileId' => 'f1', 'filePath' => '/uploads/known/a.jpg'],
    ]));

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('No orphans found')
        ->assertSuccessful();
});
