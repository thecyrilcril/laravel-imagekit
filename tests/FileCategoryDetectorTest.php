<?php

declare(strict_types=1);

use Thecyrilcril\ImageKit\Enums\FileCategory;
use Thecyrilcril\ImageKit\Support\FileCategoryDetector;

it('detects raster images', function (string $mime): void {
    expect(FileCategoryDetector::detect($mime))->toBe(FileCategory::Image);
})->with(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic']);

it('detects vectors separately from raster images', function (): void {
    expect(FileCategoryDetector::detect('image/svg+xml'))->toBe(FileCategory::Vector);
});

it('detects video', function (string $mime): void {
    expect(FileCategoryDetector::detect($mime))->toBe(FileCategory::Video);
})->with(['video/mp4', 'video/webm', 'video/quicktime']);

it('detects documents', function (string $mime): void {
    expect(FileCategoryDetector::detect($mime))->toBe(FileCategory::Document);
})->with([
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/csv',
    'application/msword',
    'application/vnd.ms-excel',
    'application/vnd.ms-powerpoint',
    'text/plain',
]);

it('returns Unknown for unrecognised and missing mime types', function (?string $mime): void {
    expect(FileCategoryDetector::detect($mime))->toBe(FileCategory::Unknown);
})->with(['application/x-cbr', 'application/zip', null, '']);

it('only marks raster images compressible', function (): void {
    expect(FileCategory::Image->compressible())->toBeTrue()
        ->and(FileCategory::Vector->compressible())->toBeFalse()
        ->and(FileCategory::Video->compressible())->toBeFalse()
        ->and(FileCategory::Document->compressible())->toBeFalse()
        ->and(FileCategory::Unknown->compressible())->toBeFalse();
});

it('never marks unknown files transformable', function (): void {
    expect(FileCategory::Unknown->transformable())->toBeFalse()
        ->and(FileCategory::Document->transformable())->toBeFalse()
        ->and(FileCategory::Image->transformable())->toBeTrue();
});
