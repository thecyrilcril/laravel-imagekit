<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Image;
use Intervention\Image\ImageManager;
use Thecyrilcril\ImageKit\Compression\LaravelImageCompressor;
use Thecyrilcril\ImageKit\Contracts\CompressesImages;
use Thecyrilcril\ImageKit\Data\CompressionProfile;
use Thecyrilcril\ImageKit\Exceptions\CompressionFailed;

/**
 * These tests execute the real Illuminate\Image pipeline, so they only run
 * where compression is genuinely available — the same three conditions the
 * service provider checks before binding this compressor rather than the null
 * one. Where any is missing these skip, because an absent capability is an
 * environment gap and reporting it as a code regression would be a lie.
 *
 * The source image is drawn with GD rather than UploadedFile::fake(), whose
 * temporary file is collected before the bytes can be read back.
 */
beforeEach(function (): void {
    // Compression is optional by design: the package supports Laravel 12,
    // where Illuminate\Image does not exist at all.
    if (! class_exists(Image::class)) {
        $this->markTestSkipped('Image compression requires Laravel 13.20+.');
    }

    // intervention/image is a suggested dependency, not a required one, so a
    // consumer — or a CI leg proving the fallback — may not have it installed.
    if (! class_exists(ImageManager::class)) {
        $this->markTestSkipped('Image compression requires intervention/image.');
    }

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

it('passes a validated quality to the driver untouched', function (): void {
    $baseline = (new LaravelImageCompressor)->compress(imageBytes(), profile(quality: 50), 'source.jpg');
    $result = (new LaravelImageCompressor)->compress(imageBytes(), profile(quality: 100), 'source.jpg');

    // Quality is the only difference, so a larger payload proves 100 reached
    // the driver rather than being clamped or altered on the way.
    expect(getimagesizefromstring($result))->not->toBeFalse()
        ->and(mb_strlen($result, '8bit'))->toBeGreaterThan(mb_strlen($baseline, '8bit'));
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
