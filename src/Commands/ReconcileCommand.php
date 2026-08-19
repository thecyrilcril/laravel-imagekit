<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use ImageKit\ImageKit as Sdk;
use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Support\MediaModel;

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
 */
final class ReconcileCommand extends Command
{
    /** @var string */
    protected $signature = 'imagekit:reconcile
        {--folder= : Only inspect files under this ImageKit folder}
        {--delete : Delete the orphans instead of only listing them}
        {--chunk=100 : How many files to fetch from ImageKit per request}';

    /** @var string */
    protected $description = 'Find ImageKit files that no media row references';

    public function handle(Sdk $sdk, DeletesRemoteFiles $remover): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $folder = $this->stringOption('folder');
        $shouldDelete = (bool) $this->option('delete');

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
        $skip = 0;

        do {
            $parameters = ['limit' => $chunk, 'skip' => $skip];

            if ($folder !== null) {
                $parameters['path'] = $folder;
            }

            $response = $sdk->listFiles($parameters);

            if (($response->error ?? null) !== null) {
                $this->components->error('ImageKit rejected the listing request: '
                    .($response->error->message ?? 'unknown error'));

                return self::FAILURE;
            }

            // The SDK types `result` as object|null, but the listing endpoint
            // genuinely returns a JSON array which json_decode gives back as a
            // PHP array. Normalising through (array) keeps that honest without
            // suppressing the type mismatch.
            /** @var list<object> $files */
            $files = array_values((array) ($response->result ?? []));

            foreach ($files as $file) {
                $inspected++;

                $path = isset($file->filePath) ? (string) $file->filePath : '';
                $fileId = isset($file->fileId) ? (string) $file->fileId : '';

                if ($path === '' || $fileId === '' || $known->contains($path)) {
                    continue;
                }

                $orphans[] = ['path' => $path, 'fileId' => $fileId];
            }

            $skip += $chunk;
        } while (count($files) === $chunk);

        return $this->report($inspected, $orphans, $shouldDelete, $remover);
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

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
