<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Thecyrilcril\ImageKit\Compression\NullImageCompressor;
use Thecyrilcril\ImageKit\Contracts\CompressesImages;
use Thecyrilcril\ImageKit\Data\CompressionProfile;

it('returns bytes unchanged when compression is unsupported', function (): void {
    $compressor = new NullImageCompressor;
    $bytes = 'raw-image-bytes';

    $profile = new CompressionProfile(compress: true, maxEdge: 2000, quality: 90, format: null);

    expect($compressor->compress($bytes, $profile, 'a.jpg'))->toBe($bytes)
        ->and($compressor->supported())->toBeFalse();
});

it('logs the unavailability once, not on every upload', function (): void {
    NullImageCompressor::resetNotice();

    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'compression is unavailable')
    );

    $compressor = new NullImageCompressor;
    $profile = new CompressionProfile(compress: true, maxEdge: 2000, quality: 90, format: null);

    $compressor->compress('a', $profile, 'a.jpg');
    $compressor->compress('b', $profile, 'b.jpg');
    $compressor->compress('c', $profile, 'c.jpg');
});

it('does not log when the profile disables compression anyway', function (): void {
    NullImageCompressor::resetNotice();

    Log::shouldReceive('warning')->never();

    $profile = new CompressionProfile(compress: false, maxEdge: 2000, quality: 90, format: null);

    (new NullImageCompressor)->compress('bytes', $profile, 'doc.pdf');
});

it('binds a compressor implementation in the container', function (): void {
    expect(app(CompressesImages::class))->toBeInstanceOf(CompressesImages::class);
});
