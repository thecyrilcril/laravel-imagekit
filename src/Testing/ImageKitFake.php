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

final class ImageKitFake implements ImageKitClient
{
    /** @var list<array{media: Media, profile: string|null}> */
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

    #[Override]
    public function upload(Media $media, ?string $profile = null): void
    {
        $this->uploads[] = ['media' => $media, 'profile' => $profile];
    }

    #[Override]
    public function uploadNow(Media $media, ?string $profile = null): ?UploadedFileResult
    {
        $this->uploads[] = ['media' => $media, 'profile' => $profile];

        if ($this->failUploads) {
            return null;
        }

        return new UploadedFileResult(
            fileId: 'fake-'.$media->id,
            path: '/fake/'.$media->file_name,
            url: 'https://imagekit.test/fake/'.$media->file_name,
            name: $media->file_name,
            size: (int) $media->size,
        );
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

    public function assertUploaded(Media $media): void
    {
        $ids = array_map(static fn (array $row): string => (string) $row['media']->id, $this->uploads);

        Assert::assertContains(
            (string) $media->id,
            $ids,
            "Expected media [{$media->id}] to have been uploaded to ImageKit.",
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
}
