<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final readonly class FileUploadFailed
{
    use Dispatchable;

    public function __construct(
        public Media $media,
        public Throwable $exception,
    ) {}
}
