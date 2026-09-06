<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Testing;

use Illuminate\Database\Eloquent\Model;
use Override;
use PHPUnit\Framework\Assert;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Thecyrilcril\ImageKit\Contracts\GeneratesFileUrls;
use Thecyrilcril\ImageKit\Contracts\ImageKitClient;
use Thecyrilcril\ImageKit\Data\UploadedFileResult;
use Thecyrilcril\ImageKit\Support\FolderResolver;
use Thecyrilcril\ImageKit\Support\MarkUploaded;
use Thecyrilcril\ImageKit\Support\ProfileRepository;

/**
 * Stands in for the real manager so tests never talk to ImageKit. It records
 * every call, and on a successful awaited upload it does what the manager
 * does: writes `imagekit.file_id` and `imagekit.file_path` on the row and
 * fires FileUploaded. So a row a test stored through media-library looks
 * ready, exactly as in production. What it recorded stays private: assert
 * through the assert*() methods.
 */
final class ImageKitFake implements ImageKitClient
{
    /** @var list<array{media: Media, profile: string}> */
    private array $uploads = [];

    /** @var list<string> */
    private array $deletions = [];

    private bool $failUploads = false;

    /**
     * Make awaited uploads behave as though ImageKit were unreachable, so a
     * consumer can test its own outage handling. The real manager returns null
     * in that case: the media row survives with its local URL and a background
     * retry is queued.
     */
    public function failUploads(bool $fail = true): self
    {
        $this->failUploads = $fail;

        return $this;
    }

    /**
     * Records only. A queued upload writes nothing until a worker runs, and
     * the fake keeps that true so "not ready yet" stays testable.
     */
    #[Override]
    public function upload(Media $media, ?string $profile = null, ?bool $cleanup = null): void
    {
        $this->record($media, $profile);
    }

    #[Override]
    public function uploadNow(Media $media, ?string $profile = null, ?bool $cleanup = null): ?UploadedFileResult
    {
        $this->record($media, $profile);

        if ($this->failUploads) {
            return null;
        }

        // Root plus collection plus file name, as the real upload builds it.
        // trim() keeps the path honest when the app has no root folder.
        $path = '/'.ltrim(FolderResolver::resolve($media->collection_name).'/'.$media->file_name, '/');

        $result = new UploadedFileResult(
            fileId: 'fake-'.$media->id,
            path: $path,
            url: 'https://imagekit.test'.$path,
            name: $media->file_name,
            size: (int) $media->size,
        );

        // The same success routine as the real manager, so the row is
        // ready, FileUploaded fires and Cleanup is queued when asked for.
        // Resolving the Profile here is what makes an unknown name throw,
        // as it does in production.
        MarkUploaded::on($media, $result, app(ProfileRepository::class)->profile($profile), $cleanup);

        return $result;
    }

    #[Override]
    public function delete(string $fileId): bool
    {
        $this->deletions[] = $fileId;

        return true;
    }

    /**
     * URLs are pure string building, so the fake delegates to the real
     * builder: consumers see the same URLs in tests as in production.
     */
    #[Override]
    public function url(string $path, ?string $preset = null, ?string $mimeType = null): string
    {
        return app(GeneratesFileUrls::class)->build($path, $preset, $mimeType);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    #[Override]
    public function backfill(string $modelClass, string $collection, ?string $profile = null): int
    {
        return 0;
    }

    /**
     * Bulk Cleanup over a collection. Queues nothing and returns 0, mirroring
     * backfill().
     *
     * @param  class-string<Model>  $modelClass
     */
    #[Override]
    public function cleanup(string $modelClass, string $collection): int
    {
        return 0;
    }

    /**
     * A null profile is recorded under the default name, so a collection
     * registered with a plain ->toImageKit() and one registered with
     * ->toImageKit('default') look the same to assertUploaded(). The name
     * is resolved here, once, at record time; assertUploaded() compares
     * plain strings.
     */
    private function record(Media $media, ?string $profile): void
    {
        $this->uploads[] = ['media' => $media, 'profile' => $profile ?? ProfileRepository::DEFAULT];
    }

    /**
     * With a profile, passes only when a recorded upload for this row used
     * that profile name. `profile: 'default'` matches an upload from a
     * collection registered with a plain ->toImageKit().
     */
    public function assertUploaded(Media $media, ?string $profile = null): void
    {
        $rows = array_filter($this->uploads, static fn (array $row): bool => (string) $row['media']->id === (string) $media->id);

        Assert::assertNotEmpty(
            $rows,
            "Expected media [{$media->id}] to have been uploaded to ImageKit.",
        );

        if ($profile === null) {
            return;
        }

        $profiles = array_map(static fn (array $row): string => $row['profile'], $rows);

        Assert::assertContains(
            $profile,
            $profiles,
            "Expected media [{$media->id}] to have been uploaded to ImageKit with profile [{$profile}].",
        );
    }

    public function assertNotUploaded(Media $media): void
    {
        $ids = array_map(static fn (array $row): string => (string) $row['media']->id, $this->uploads);

        Assert::assertNotContains(
            (string) $media->id,
            $ids,
            "Expected media [{$media->id}] not to have been uploaded to ImageKit.",
        );
    }

    public function assertDeleted(string $fileId): void
    {
        Assert::assertContains(
            $fileId,
            $this->deletions,
            "Expected ImageKit file [{$fileId}] to have been deleted.",
        );
    }

    public function assertNothingUploaded(): void
    {
        Assert::assertSame([], $this->uploads, 'Expected no ImageKit uploads.');
    }

    public function assertNothingDeleted(): void
    {
        Assert::assertSame(
            [],
            $this->deletions,
            'Expected no ImageKit deletions, but these file ids were deleted: ['.implode(', ', $this->deletions).'].',
        );
    }
}
