<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class FileRemoved
{
    use Dispatchable;

    public function __construct(public string $fileId) {}
}
