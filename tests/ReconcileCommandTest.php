<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Tests\Fixtures\TestModel;
use Thecyrilcril\ImageKitClient\Contracts\Files;
use Thecyrilcril\ImageKitClient\Enums\AssetType;
use Thecyrilcril\ImageKitClient\Exceptions\RequestFailed;
use Thecyrilcril\ImageKitClient\Facades\ImageKitClient;
use Thecyrilcril\ImageKitClient\Files\File;
use Thecyrilcril\ImageKitClient\Files\Folder;
use Thecyrilcril\ImageKitClient\Files\ListRequest;

/**
 * The package deletes remote files whenever a media row goes, but it can only
 * act on rows it can see. Files that predate adoption, rows removed by raw SQL
 * or a restored database, and anything uploaded outside media-library leave a
 * remote file nothing else will ever find.
 *
 * Listings run against the Client fake, which answers a `path` the way
 * ImageKit does: one folder level. So a file seeded in a sub-folder has that
 * folder seeded too, or the walk would never reach it — as on a real account.
 */
beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    Queue::fake();

    $this->client = ImageKitClient::fake();

    $this->model = TestModel::query()->create(['name' => 'subject']);
});

function fileAt(string $fileId, string $filePath): File
{
    return new File(
        fileId: $fileId,
        type: AssetType::File,
        name: basename($filePath),
        filePath: $filePath,
        url: 'https://ik.imagekit.io/test'.$filePath,
        fileType: 'image',
        createdAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        updatedAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
    );
}

function folderAt(string $folderPath): Folder
{
    return new Folder(
        folderId: 'folder-'.md5($folderPath),
        name: basename($folderPath),
        folderPath: $folderPath,
        createdAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        updatedAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
    );
}

function attachWithRemotePath(TestModel $model, string $remotePath): void
{
    $media = $model
        ->addMedia(UploadedFile::fake()->image('a.jpg', 20, 20))
        ->toMediaCollection('plain');

    $media->setCustomProperty('imagekit.file_path', $remotePath);
    $media->save();
}

/**
 * A remover that must never be reached.
 */
function bindUntouchedRemover(): void
{
    $remover = Mockery::mock(DeletesRemoteFiles::class);
    $remover->shouldReceive('delete')->never();
    app()->instance(DeletesRemoteFiles::class, $remover);
}

it('reports when every remote file is accounted for', function (): void {
    attachWithRemotePath($this->model, '/uploads/known/a.jpg');

    $this->client->seedListing(
        folderAt('/uploads/known'),
        fileAt('f1', '/uploads/known/a.jpg'),
    );

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('No orphans found')
        ->assertSuccessful();

    $this->client->assertListed('/uploads');
    $this->client->assertListed('/uploads/known');
});

it('lists an orphan without deleting it', function (): void {
    attachWithRemotePath($this->model, '/uploads/known/a.jpg');

    $this->client->seedListing(
        folderAt('/uploads/known'),
        folderAt('/uploads/stale'),
        fileAt('f1', '/uploads/known/a.jpg'),
        fileAt('orphan-1', '/uploads/stale/gone.jpg'),
    );

    bindUntouchedRemover();

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('/uploads/stale/gone.jpg')
        ->expectsOutputToContain('Nothing was deleted')
        ->assertSuccessful();
});

it('deletes orphans only when explicitly asked', function (): void {
    attachWithRemotePath($this->model, '/uploads/known/a.jpg');

    $this->client->seedListing(
        folderAt('/uploads/known'),
        folderAt('/uploads/stale'),
        fileAt('f1', '/uploads/known/a.jpg'),
        fileAt('orphan-1', '/uploads/stale/gone.jpg'),
    );

    $remover = Mockery::mock(DeletesRemoteFiles::class);
    $remover->shouldReceive('delete')->once()->with('orphan-1')->andReturnTrue();
    $this->app->instance(DeletesRemoteFiles::class, $remover);

    $this->artisan('imagekit:reconcile', ['--delete' => true])
        ->expectsOutputToContain('Deleted 1 orphaned file')
        ->assertSuccessful();
});

it('refuses to delete when no media row references imagekit at all', function (): void {
    // A staging app pointed at a production account looks exactly like this:
    // every remote file appears orphaned. Deleting here would be catastrophic,
    // so the command must stop before it even asks ImageKit for a listing.
    $files = Mockery::mock(Files::class);
    $files->shouldReceive('lazy')->never();
    $files->shouldReceive('list')->never();
    $this->app->instance(Files::class, $files);

    bindUntouchedRemover();

    $this->artisan('imagekit:reconcile', ['--delete' => true])
        ->expectsOutputToContain('Refusing to delete')
        ->assertFailed();
});

it('still lists orphans when nothing is referenced, so the state is inspectable', function (): void {
    $this->client->seedListing(
        folderAt('/uploads/stale'),
        fileAt('orphan-1', '/uploads/stale/gone.jpg'),
    );

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('Orphaned files')
        ->assertSuccessful();
});

it('pages through the listing until a short page arrives', function (): void {
    attachWithRemotePath($this->model, '/uploads/a.jpg');

    $this->client->seedListing(
        fileAt('f1', '/uploads/a.jpg'),
        fileAt('f2', '/uploads/b.jpg'),
        fileAt('f3', '/uploads/c.jpg'),
    );

    $this->artisan('imagekit:reconcile', ['--chunk' => 2])
        ->expectsOutputToContain('Files inspected on ImageKit: 3')
        ->assertSuccessful();

    $this->client->assertListed(fn (ListRequest $request): bool => $request->path === '/uploads'
        && $request->type === AssetType::All
        && $request->limit === 2
        && $request->skip === 0);
    $this->client->assertListed(fn (ListRequest $request): bool => $request->path === '/uploads'
        && $request->limit === 2
        && $request->skip === 2);
});

it('clamps --chunk to the largest page ImageKit will serve', function (): void {
    attachWithRemotePath($this->model, '/uploads/a.jpg');

    $this->artisan('imagekit:reconcile', ['--chunk' => 5000])->assertSuccessful();

    $this->client->assertListed(fn (ListRequest $request): bool => $request->limit === ListRequest::MAX_LIMIT);
});

it('walks into sub-folders, because ImageKit only lists one folder level per path', function (): void {
    attachWithRemotePath($this->model, '/uploads/avatars/known.jpg');

    // The root itself holds no files, only folders — the shape every real
    // install has, since uploads land at {root}/{collection}/{file}.
    $this->client->seedListing(
        folderAt('/uploads/avatars'),
        folderAt('/uploads/documents'),
        folderAt('/uploads/avatars/nested'),
        fileAt('f1', '/uploads/avatars/known.jpg'),
        fileAt('o1', '/uploads/avatars/nested/orphan.jpg'),
    );

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('Files inspected on ImageKit: 2')
        ->expectsOutputToContain('/uploads/avatars/nested/orphan.jpg')
        ->doesntExpectOutputToContain('/uploads/avatars/known.jpg')
        ->assertSuccessful();

    foreach (['/uploads', '/uploads/avatars', '/uploads/avatars/nested', '/uploads/documents'] as $path) {
        $this->client->assertListed(fn (ListRequest $request): bool => $request->path === $path
            && $request->type === AssetType::All);
    }
});

it('scopes the listing to a folder when asked', function (): void {
    attachWithRemotePath($this->model, '/uploads/known/a.jpg');

    $this->artisan('imagekit:reconcile', ['--folder' => 'avatars'])
        ->expectsOutputToContain('/uploads/avatars')
        ->assertSuccessful();

    $this->client->assertListed('/uploads/avatars');
});

it('always scopes the listing to the configured root folder', function (): void {
    config()->set('imagekit.folder', '/kitwire/');
    attachWithRemotePath($this->model, '/kitwire/known/a.jpg');

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('/kitwire')
        ->assertSuccessful();

    $this->client->assertListed('/kitwire');
});

it('never treats a file outside the root folder as an orphan, even if imagekit returns one', function (): void {
    config()->set('imagekit.folder', 'kitwire');
    attachWithRemotePath($this->model, '/kitwire/known/a.jpg');

    // The fake honours `path` as ImageKit documents it, so it can never hand
    // back a file from another folder. This guard exists for the day the API
    // does; a mocked listing is the only way to make it.
    $files = Mockery::mock(Files::class);
    $files->shouldReceive('lazy')->once()->andReturn(LazyCollection::make([
        fileAt('f1', '/kitwire/known/a.jpg'),
        fileAt('theirs', '/kwadatis/photos/p.jpg'),
        fileAt('lookalike', '/kitwire-old/x.jpg'),
    ]));
    $this->app->instance(Files::class, $files);

    bindUntouchedRemover();

    $this->artisan('imagekit:reconcile', ['--delete' => true])
        ->expectsOutputToContain('No orphans found')
        ->assertSuccessful();
});

it('refuses to delete when no root folder is configured', function (): void {
    config()->set('imagekit.folder', '');
    attachWithRemotePath($this->model, '/known/a.jpg');

    $files = Mockery::mock(Files::class);
    $files->shouldReceive('lazy')->never();
    $this->app->instance(Files::class, $files);

    bindUntouchedRemover();

    $this->artisan('imagekit:reconcile', ['--delete' => true])
        ->expectsOutputToContain('IMAGEKIT_FOLDER')
        ->assertFailed();
});

it('still lists the whole account when no root folder is configured', function (): void {
    config()->set('imagekit.folder', '');
    attachWithRemotePath($this->model, '/known/a.jpg');

    // An unscoped listing is already recursive on ImageKit's side, so it
    // asks for files only, from every folder, in one walk.
    $this->client->seedListing(
        fileAt('f1', '/known/a.jpg'),
        fileAt('o1', '/anything/else.jpg'),
    );

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('/anything/else.jpg')
        ->expectsOutputToContain('Orphaned files')
        ->assertSuccessful();

    $this->client->assertListed(fn (ListRequest $request): bool => $request->path === null
        && $request->type === null
        && $request->limit === 100);
});

it('fails loudly when imagekit rejects the listing', function (): void {
    attachWithRemotePath($this->model, '/uploads/known/a.jpg');

    // The Client's lazy listing only sends a request once it is consumed, so
    // the rejection surfaces from inside the walk, not from the call itself.
    $files = Mockery::mock(Files::class);
    $files->shouldReceive('lazy')->once()->andReturn(LazyCollection::make(function (): Generator {
        throw new RequestFailed(403, 'Your account cannot be authenticated.', null);
        yield;
    }));
    $this->app->instance(Files::class, $files);

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('cannot be authenticated')
        ->assertFailed();
});

it('ignores media rows that were never uploaded to imagekit', function (): void {
    attachWithRemotePath($this->model, '/uploads/known/a.jpg');

    // A second row with no imagekit.file_path must not be mistaken for a
    // reference to anything, nor crash the path collection.
    $this->model->addMedia(UploadedFile::fake()->image('b.jpg', 20, 20))->toMediaCollection('plain');

    $this->client->seedListing(
        folderAt('/uploads/known'),
        fileAt('f1', '/uploads/known/a.jpg'),
    );

    $this->artisan('imagekit:reconcile')
        ->expectsOutputToContain('No orphans found')
        ->assertSuccessful();
});
