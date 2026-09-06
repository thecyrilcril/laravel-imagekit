<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Support;

/**
 * The one place that turns an action name into a queue name.
 *
 * The rule: the action's own entry under `imagekit.queue.names` when it is a
 * non-empty string, otherwise `imagekit.queue.name`, otherwise `imagekit`.
 * An empty override falls through on purpose, so a blank env var can never
 * dispatch to a queue called "".
 */
final class QueueName
{
    /**
     * @param  'upload'|'remove'|'cleanup'  $action
     */
    public static function for(string $action): string
    {
        $override = config('imagekit.queue.names.'.$action);

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $default = config('imagekit.queue.name');

        return is_string($default) && $default !== '' ? $default : 'imagekit';
    }
}
