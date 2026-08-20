<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Support;

/**
 * The one place that decides where an upload lands on ImageKit: the
 * configured root folder, then the media collection. Both the queued job
 * and the synchronous uploadNow() path call this, so they cannot drift.
 */
final class FolderResolver
{
    /**
     * The application's root folder on ImageKit, without surrounding slashes.
     * Empty when the app has opted out of a root.
     */
    public static function root(): string
    {
        /** @var scalar|null $configured */
        $configured = config('imagekit.folder', 'uploads');

        return trim((string) $configured, '/');
    }

    public static function resolve(string $collection): string
    {
        $root = self::root();

        if ($collection === '') {
            return $root;
        }

        return $root === '' ? $collection : $root.'/'.$collection;
    }
}
