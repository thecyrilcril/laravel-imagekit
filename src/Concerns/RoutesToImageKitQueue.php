<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Concerns;

use Thecyrilcril\ImageKit\Support\QueueName;

/**
 * Applies the package's queue config to a job in one call: the action's
 * queue name (see QueueName), and the shared connection, tries and backoff.
 *
 * The host job must use Illuminate\Bus\Queueable.
 */
trait RoutesToImageKitQueue
{
    public int $tries;

    public int $backoff;

    /**
     * @param  'upload'|'remove'|'cleanup'  $action
     */
    private function routeToImageKitQueue(string $action): void
    {
        $this->onQueue(QueueName::for($action));

        /** @var string|null $connection */
        $connection = config('imagekit.queue.connection');

        if ($connection !== null && $connection !== '') {
            $this->onConnection($connection);
        }

        $this->tries = (int) config('imagekit.queue.tries', 3);
        $this->backoff = (int) config('imagekit.queue.backoff', 5);
    }
}
