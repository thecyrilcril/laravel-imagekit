<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Support;

use Thecyrilcril\ImageKit\Enums\FileCategory;

final readonly class FileCategoryDetector
{
    /**
     * Exact MIME matches that must beat the prefix rules below.
     *
     * @var array<string, FileCategory>
     */
    private const array EXACT = [
        'image/svg+xml' => FileCategory::Vector,
        'application/pdf' => FileCategory::Document,
        'application/msword' => FileCategory::Document,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => FileCategory::Document,
        'application/vnd.ms-excel' => FileCategory::Document,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => FileCategory::Document,
        'application/vnd.ms-powerpoint' => FileCategory::Document,
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => FileCategory::Document,
        'text/csv' => FileCategory::Document,
        'text/plain' => FileCategory::Document,
    ];

    public static function detect(?string $mimeType): FileCategory
    {
        if ($mimeType === null || $mimeType === '') {
            return FileCategory::Unknown;
        }

        $normalised = mb_strtolower(trim($mimeType));

        if (isset(self::EXACT[$normalised])) {
            return self::EXACT[$normalised];
        }

        if (str_starts_with($normalised, 'image/')) {
            return FileCategory::Image;
        }

        if (str_starts_with($normalised, 'video/')) {
            return FileCategory::Video;
        }

        return FileCategory::Unknown;
    }
}
