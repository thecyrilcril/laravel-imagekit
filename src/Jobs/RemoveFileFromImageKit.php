<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Events\FileRemoved;

final class RemoveFileFromImageKit implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public int $backoff;

    public function __construct(public string $fileId)
    {
        /** @var string $queue */
        $queue = config('imagekit.queue.name', 'imagekit');
        /** @var string|null $connection */
        $connection = config('imagekit.queue.connection');

        $this->onQueue($queue);

        if ($connection !== null && $connection !== '') {
            $this->onConnection($connection);
        }

        $this->tries = (int) config('imagekit.queue.tries', 3);
        $this->backoff = (int) config('imagekit.queue.backoff', 5);
    }

    public function handle(): void
    {
        app(DeletesRemoteFiles::class)->delete($this->fileId);

        FileRemoved::dispatch($this->fileId);
    }
}
