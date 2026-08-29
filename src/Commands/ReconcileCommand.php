<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Support\FolderResolver;
use Thecyrilcril\ImageKit\Support\MediaModel;
use Thecyrilcril\ImageKitClient\Contracts\Files;
use Thecyrilcril\ImageKitClient\Enums\AssetType;
use Thecyrilcril\ImageKitClient\Exceptions\ImageKitClientException;
use Thecyrilcril\ImageKitClient\Files\Folder;
use Thecyrilcril\ImageKitClient\Files\ListRequest;

/**
 * Finds files in ImageKit that no media row points at.
 *
 * The package deletes remote files automatically whenever a media row goes,
 * but it can only act on rows it can see. Files uploaded before the package
 * was adopted, rows removed by a raw SQL delete or a restored database, and
 * anything uploaded outside media-library all leave a remote file with no
 * local record. Nothing else will ever find those.
 *
 * Deleting is opt-in for a reason: this compares against the media table, so
 * running it against the wrong environment — a staging app pointed at a
 * production ImageKit account, say — would report every production file as an
 * orphan. Read the list before passing --delete.
 *
 * Every run is confined to this application's root folder (imagekit.folder).
 * Several applications commonly share one ImageKit account, and each of them
 * holds no rows for the others' files, so without that boundary every other
 * app's file looks like an orphan. Files outside the root are never reported,
 * and --delete refuses to run when no root is configured.
 */
final class ReconcileCommand extends Command
{
    /** @var string */
    protected $signature = 'imagekit:reconcile
        {--folder= : Only inspect this sub-folder of the configured root folder}
        {--delete : Delete the orphans instead of only listing them}
        {--chunk=100 : How many files to fetch from ImageKit per request (at most '.ListRequest::MAX_LIMIT.')}';

    /** @var string */
    protected $description = 'Find ImageKit files that no media row references';

    public function handle(Files $files, DeletesRemoteFiles $remover): int
    {
        $chunk = max(1, min(ListRequest::MAX_LIMIT, (int) $this->option('chunk')));
        $shouldDelete = (bool) $this->option('delete');

        $root = FolderResolver::root();

        if ($root === '' && $shouldDelete) {
            $this->components->error(
                'No root folder is configured, so a delete could reach every file on the account. '
                .'Set IMAGEKIT_FOLDER to this application\'s folder and run again.'
            );

            return self::FAILURE;
        }

        $scope = $this->scope($root);

        if ($scope === null) {
            $this->components->warn('No root folder is configured: inspecting the whole account.');
        } else {
            $this->components->info(sprintf('Scoped to ImageKit folder %s.', $scope));
        }

        $known = $this->knownFilePaths();

        $this->components->info(sprintf(
            'Media rows referencing an ImageKit file: %d.',
            $known->count(),
        ));

        if ($known->isEmpty() && $shouldDelete) {
            $this->components->error(
                'No media row references an ImageKit file. Refusing to delete every remote '
                .'file on the assumption that is intentional — inspect the listing first.'
            );

            return self::FAILURE;
        }

        $orphans = [];
        $inspected = 0;

        // ImageKit's `path` filter lists one folder level only, and uploads
        // land at {root}/{collection}/{file}, so the root itself holds nothing
        // but folders. Walk them. An unscoped listing (no path) is already
        // recursive, so it is a single walk of one null "folder".
        $pending = [$scope];

        try {
            while ($pending !== []) {
                $folder = array_shift($pending);

                foreach ($files->lazy($this->listing($folder, $chunk)) as $entry) {
                    if ($entry instanceof Folder) {
                        $pending[] = $entry->folderPath;

                        continue;
                    }

                    $inspected++;

                    if ($known->contains($entry->filePath)) {
                        continue;
                    }

                    // Belt and braces: even if ImageKit's listing returned a file
                    // from outside the root, it is not ours to report or delete.
                    if ($root !== '' && ! str_starts_with($entry->filePath, '/'.$root.'/')) {
                        continue;
                    }

                    $orphans[] = ['path' => $entry->filePath, 'fileId' => $entry->fileId];
                }
            }
        } catch (ImageKitClientException $exception) {
            // The lazy listing sends each page as it is reached, so a
            // rejection surfaces here, mid-walk, rather than from lazy().
            $this->components->error('Could not list files on ImageKit: '.$exception->getMessage());

            return self::FAILURE;
        }

        return $this->report($inspected, $orphans, $shouldDelete, $remover);
    }

    /**
     * One folder level, folders included, so the walk can descend; or, with
     * no folder, every file on the account in one recursive listing.
     */
    private function listing(?string $folder, int $chunk): ListRequest
    {
        if ($folder === null) {
            return new ListRequest(limit: $chunk);
        }

        return new ListRequest(limit: $chunk, path: $folder, type: AssetType::All);
    }

    /**
     * Every ImageKit path the database still points at.
     *
     * @return Collection<int, string>
     */
    private function knownFilePaths(): Collection
    {
        /** @var list<string> $paths */
        $paths = [];

        foreach (MediaModel::query()->cursor() as $media) {
            $path = $media->getCustomProperty('imagekit.file_path');

            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return new Collection($paths);
    }

    /**
     * @param  list<array{path: string, fileId: string}>  $orphans
     */
    private function report(int $inspected, array $orphans, bool $shouldDelete, DeletesRemoteFiles $remover): int
    {
        $this->components->info(sprintf('Files inspected on ImageKit: %d.', $inspected));

        if ($orphans === []) {
            $this->components->info('No orphans found. Every remote file is accounted for.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf('Orphaned files (no media row references these): %d.', count($orphans)));

        $this->table(
            ['ImageKit path', 'File id'],
            array_map(static fn (array $o): array => [$o['path'], $o['fileId']], $orphans),
        );

        if (! $shouldDelete) {
            $this->components->info('Nothing was deleted. Re-run with --delete to remove them.');

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($orphans as $orphan) {
            $remover->delete($orphan['fileId']);
            $deleted++;
        }

        $this->components->info(sprintf('Deleted %d orphaned file(s) from ImageKit.', $deleted));

        return self::SUCCESS;
    }

    /**
     * The ImageKit path to list: the root folder, plus the optional --folder
     * sub-folder beneath it. Null only when no root is configured and no
     * sub-folder was given.
     */
    private function scope(string $root): ?string
    {
        $subFolder = $this->stringOption('folder');
        $subFolder = $subFolder === null ? '' : trim($subFolder, '/');

        $path = FolderResolver::resolve($subFolder);

        return $path === '' ? null : '/'.$path;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
