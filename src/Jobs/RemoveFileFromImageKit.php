<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Thecyrilcril\ImageKit\Concerns\RoutesToImageKitQueue;
use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Events\FileRemoved;

final class RemoveFileFromImageKit implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RoutesToImageKitQueue;

    public function __construct(public string $fileId)
    {
        $this->routeToImageKitQueue('remove');
    }

    public function handle(): void
    {
        app(DeletesRemoteFiles::class)->delete($this->fileId);

        FileRemoved::dispatch($this->fileId);
    }
}
