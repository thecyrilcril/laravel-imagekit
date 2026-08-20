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
    public static function resolve(string $collection): string
    {
        /** @var string $root */
        $root = config('imagekit.folder', 'uploads');
        $root = trim($root, '/');

        if ($collection === '') {
            return $root;
        }

        return $root === '' ? $collection : $root.'/'.$collection;
    }
}
