<?php

declare(strict_types=1);

use Thecyrilcril\ImageKit\Compression\LaravelImageCompressor;
use Thecyrilcril\ImageKit\Contracts\CompressesImages;
use Thecyrilcril\ImageKit\Data\CompressionProfile;
use Thecyrilcril\ImageKit\Exceptions\CompressionFailed;

/**
 * These tests execute the real Illuminate\Image pipeline, so they only run
 * where intervention/image and a GD or Imagick driver are present — the same
 * capability the service provider checks before binding this compressor.
 *
 * Composer guarantees intervention/image (require-dev), but GD and Imagick are
 * PHP extensions it cannot install. Without the guard below, a CI image built
 * without either would report an environment gap as a code regression.
 *
 * The source image is drawn with GD rather than UploadedFile::fake(), whose
 * temporary file is collected before the bytes can be read back.
 */
beforeEach(function (): void {
    if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
        $this->markTestSkipped('Image compression requires the gd or imagick extension.');
    }

    // imageBytes() draws its fixture with GD, so an imagick-only build cannot
    // produce the source image even though the compressor itself would work.
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('These fixtures are drawn with gd.');
    }
});
function imageBytes(int $width = 1200, int $height = 800): string
{
    $canvas = imagecreatetruecolor($width, $height);

    expect($canvas)->not->toBeFalse();

    // A flat fill compresses to almost nothing, which would make the
    // "smaller than the original" assertion meaningless. Draw noise instead.
    for ($x = 0; $x < $width; $x += 4) {
        for ($y = 0; $y < $height; $y += 4) {
            $colour = imagecolorallocate($canvas, ($x * 7) % 256, ($y * 13) % 256, ($x + $y) % 256);
            imagefilledrectangle($canvas, $x, $y, $x + 3, $y + 3, (int) $colour);
        }
    }

    ob_start();
    imagejpeg($canvas, null, 100);
    $contents = (string) ob_get_clean();
    imagedestroy($canvas);

    return $contents;
}

function profile(
    bool $compress = true,
    int $maxEdge = 400,
    int $quality = 90,
    ?string $format = null,
): CompressionProfile {
    return new CompressionProfile(
        compress: $compress,
        maxEdge: $maxEdge,
        quality: $quality,
        format: $format,
    );
}

it('reports itself supported', function (): void {
    expect((new LaravelImageCompressor)->supported())->toBeTrue();
});

it('is the implementation bound when the environment can compress', function (): void {
    expect(app(CompressesImages::class))->toBeInstanceOf(LaravelImageCompressor::class);
});

it('returns the bytes untouched when the profile disables compression', function (): void {
    $original = imageBytes();

    $result = (new LaravelImageCompressor)->compress($original, profile(compress: false), 'source.jpg');

    expect($result)->toBe($original);
});

it('scales an oversized image down to the long-edge cap', function (): void {
    $result = (new LaravelImageCompressor)->compress(imageBytes(1200, 800), profile(maxEdge: 400), 'source.jpg');

    $size = getimagesizefromstring($result);

    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe(400)
        ->and($size[1])->toBe(267);
});

it('never enlarges an image already smaller than the cap', function (): void {
    $result = (new LaravelImageCompressor)->compress(imageBytes(120, 90), profile(maxEdge: 2000), 'small.jpg');

    $size = getimagesizefromstring($result);

    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe(120)
        ->and($size[1])->toBe(90);
});

it('produces a smaller payload than the original', function (): void {
    $original = imageBytes(1600, 1200);

    $result = (new LaravelImageCompressor)->compress($original, profile(maxEdge: 400), 'source.jpg');

    expect(mb_strlen($result, '8bit'))->toBeLessThan(mb_strlen($original, '8bit'));
});

it('keeps the original format when no format is requested', function (): void {
    $result = (new LaravelImageCompressor)->compress(imageBytes(), profile(format: null), 'source.jpg');

    $size = getimagesizefromstring($result);

    expect($size)->not->toBeFalse()
        ->and($size['mime'])->toBe('image/jpeg');
});

it('converts to the requested format', function (string $format, string $expectedMime): void {
    $result = (new LaravelImageCompressor)->compress(imageBytes(), profile(format: $format), 'source.jpg');

    $size = getimagesizefromstring($result);

    expect($size)->not->toBeFalse()
        ->and($size['mime'])->toBe($expectedMime);
})->with([
    ['webp', 'image/webp'],
    ['png', 'image/png'],
]);

it('clamps an out-of-range quality instead of passing it to the driver', function (): void {
    $result = (new LaravelImageCompressor)->compress(imageBytes(), profile(quality: 5000), 'source.jpg');

    expect(getimagesizefromstring($result))->not->toBeFalse();
});

it('clamps a zero long-edge cap instead of passing it to the driver', function (): void {
    $result = (new LaravelImageCompressor)->compress(imageBytes(), profile(maxEdge: 0), 'source.jpg');

    $size = getimagesizefromstring($result);

    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe(1);
});

it('wraps a decoding failure in CompressionFailed and chains the cause', function (): void {
    try {
        (new LaravelImageCompressor)->compress('this is not an image', profile(), 'broken.jpg');

        $this->fail('Expected CompressionFailed to be thrown.');
    } catch (CompressionFailed $exception) {
        expect($exception->getMessage())->toContain('broken.jpg')
            ->and($exception->getPrevious())->toBeInstanceOf(Throwable::class);
    }
});
