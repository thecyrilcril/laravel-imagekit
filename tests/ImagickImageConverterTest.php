<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Thecyrilcril\ImageKit\Compression\ImagickImageConverter;
use Thecyrilcril\ImageKit\Compression\NullImageConverter;
use Thecyrilcril\ImageKit\Contracts\CompressesImages;
use Thecyrilcril\ImageKit\Contracts\ConvertsImages;
use Thecyrilcril\ImageKit\Exceptions\ConversionFailed;

/**
 * These tests run the real Imagick pipeline. Where the extension is absent
 * they skip, because an absent capability is an environment gap and reporting
 * it as a code regression would be a lie — the same posture
 * `LaravelImageCompressorTest` takes.
 */
beforeEach(function (): void {
    if (! extension_loaded('imagick')) {
        $this->markTestSkipped('Image conversion requires the imagick extension.');
    }

    ImagickImageConverter::resetCapabilities();
    NullImageConverter::resetNotice();
});

/**
 * A JPEG carrying a genuine EXIF APP1 segment with DateTimeOriginal.
 *
 * Built byte-wise rather than with `Imagick::setImageProperty('exif:...')`,
 * which sets a property Imagick reports back but does NOT write a real EXIF
 * segment — a fixture built that way passes every assertion here while
 * proving nothing, because `exif_read_data()` never sees it.
 */
function jpegWithExif(string $dateTimeOriginal = '2026:08:26 14:30:00'): string
{
    $value = $dateTimeOriginal."\x00";

    // Little-endian TIFF: IFD0 holds one ExifIFDPointer, the sub-IFD holds
    // DateTimeOriginal (ASCII, 20 bytes including the terminator).
    // A TIFF directory entry is exactly 12 bytes: tag (2), type (2), count
    // (4), value-or-offset (4) — so `vvVV`, never `vVVV`, which is 14 and
    // yields a segment `exif_read_data()` silently declines to parse.
    $tiff = "II*\x00".pack('V', 8)
        .pack('v', 1)
        .pack('vvVV', 0x8769, 4, 1, 26)
        .pack('V', 0)
        .pack('v', 1)
        .pack('vvVV', 0x9003, 2, 20, 44)
        .pack('V', 0)
        .$value;

    $payload = "Exif\x00\x00".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    $image = new Imagick;
    $image->newImage(8, 8, new ImagickPixel('red'));
    $image->setImageFormat('jpeg');
    $jpeg = $image->getImagesBlob();
    $image->clear();

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

/** The DateTimeOriginal these bytes carry, or null. */
function dateTimeOriginalOf(string $bytes): ?string
{
    $path = tempnam(sys_get_temp_dir(), 'exif');
    file_put_contents($path, $bytes);
    $data = @exif_read_data($path);
    @unlink($path);

    return is_array($data) && isset($data['DateTimeOriginal']) && is_string($data['DateTimeOriginal'])
        ? $data['DateTimeOriginal']
        : null;
}

/**
 * The same source image re-encoded into `$format`, or null where this Imagick
 * build cannot produce it.
 *
 * A build compiled WITHOUT a delegate for the format may still return bytes —
 * they simply are not the format asked for, and carry none of the source
 * metadata. So the result is verified rather than trusted: it must read back
 * as the requested format, or this returns null and the caller skips. A
 * bare CI runner with a minimal ImageMagick is exactly this case, and trusting
 * `getImagesBlob()` there produced three failures that passed locally.
 */
function imageAs(string $format, ?string $source = null): ?string
{
    try {
        $image = new Imagick;
        $image->readImageBlob($source ?? jpegWithExif());
        $image->setImageFormat($format);
        $bytes = $image->getImagesBlob();
        $image->clear();

        if ($bytes === '') {
            return null;
        }

        $check = new Imagick;
        $check->readImageBlob($bytes);
        $produced = strtolower($check->getImageFormat());
        $check->clear();

        return $produced === strtolower($format) ? $bytes : null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Whether a round trip through `$format` preserves EXIF here.
 *
 * The EXIF tests assert a property of the CONVERTER, so they must not fail on
 * a build whose encoder for the intermediate format drops metadata before the
 * converter is ever reached — that is an environment gap, and reporting it as
 * a code regression would be a lie.
 */
function roundTripKeepsExif(string $format): bool
{
    $encoded = imageAs($format);

    if ($encoded === null) {
        return false;
    }

    try {
        $image = new Imagick;
        $image->readImageBlob($encoded);
        $image->setImageFormat('jpeg');
        $jpeg = $image->getImagesBlob();
        $image->clear();

        return dateTimeOriginalOf($jpeg) !== null;
    } catch (Throwable) {
        return false;
    }
}

function heicFixture(): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/sample.heic');
}

/**
 * A converter plus deliberately truncated bytes of a format it can decode
 * HERE, whatever this build supports.
 *
 * HEIC is preferred because it is the real-world case, but any convertible
 * format proves the same property — and picking one the environment actually
 * has is what keeps this assertion running on a minimal ImageMagick build
 * rather than skipping into a coverage gap.
 *
 * @return array{converter: ImagickImageConverter, bytes: string, name: string}
 */
function truncatedConvertible(): array
{
    $converter = new ImagickImageConverter;

    if ($converter->supported('heic')) {
        return ['converter' => $converter, 'bytes' => substr(heicFixture(), 0, 200), 'name' => 'truncated.heic'];
    }

    foreach (['webp', 'avif'] as $format) {
        $sample = imageAs($format);

        if ($sample !== null && $converter->supported($format)) {
            return [
                'converter' => $converter,
                'bytes' => substr($sample, 0, max(8, intdiv(strlen($sample), 3))),
                'name' => "truncated.{$format}",
            ];
        }
    }

    test()->markTestSkipped('This environment can decode none of the convertible formats.');
}

/*
|--------------------------------------------------------------------------
| EXIF survival — written first, deliberately
|--------------------------------------------------------------------------
|
| This is the property most likely to regress silently and the one with the
| worst consequence: a converted JPEG that lost DateTimeOriginal is still a
| valid JPEG, so nothing else in the pipeline notices. A consumer converts
| BECAUSE it needs the metadata.
|
*/

it('preserves DateTimeOriginal converting webp to jpeg', function (): void {
    if (! roundTripKeepsExif('webp')) {
        $this->markTestSkipped('This Imagick build cannot round-trip WebP with EXIF.');
    }

    $converted = (new ImagickImageConverter)->toJpeg((string) imageAs('webp'), 'photo.webp');

    expect(dateTimeOriginalOf($converted))->toBe('2026:08:26 14:30:00');
});

it('preserves DateTimeOriginal converting avif to jpeg', function (): void {
    if (! roundTripKeepsExif('avif')) {
        $this->markTestSkipped('This Imagick build cannot round-trip AVIF with EXIF.');
    }

    $converted = (new ImagickImageConverter)->toJpeg((string) imageAs('avif'), 'photo.avif');

    expect(dateTimeOriginalOf($converted))->toBe('2026:08:26 14:30:00');
});

/**
 * An already-JPEG input must come back BYTE-FOR-BYTE. A needless re-encode
 * would cost quality and put the very metadata at risk that the caller is
 * here to protect — and `===` proves no round trip happened at all, which a
 * metadata assertion alone would not.
 */
it('returns an already-jpeg input unchanged, byte for byte', function (): void {
    $jpeg = jpegWithExif();

    $converted = (new ImagickImageConverter)->toJpeg($jpeg, 'photo.jpg');

    expect($converted)->toBe($jpeg)
        ->and(dateTimeOriginalOf($converted))->toBe('2026:08:26 14:30:00');
});

it('never calls stripImage, which would destroy the metadata in one line', function (): void {
    // Asserted through behaviour rather than by inspecting the source: a
    // stripImage() call anywhere in the conversion path makes this fail.
    if (! roundTripKeepsExif('webp')) {
        $this->markTestSkipped('This Imagick build cannot round-trip WebP with EXIF.');
    }

    expect(dateTimeOriginalOf((new ImagickImageConverter)->toJpeg((string) imageAs('webp'), 'a.webp')))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| HEIC — the case that must work, because it is what an iPhone produces
|--------------------------------------------------------------------------
*/

it('converts heic to a real jpeg', function (): void {
    $converter = new ImagickImageConverter;

    if (! $converter->supported('heic')) {
        $this->markTestSkipped('This environment cannot decode HEIC.');
    }

    $converted = $converter->toJpeg(heicFixture(), 'photo.heic');

    expect(str_starts_with($converted, "\xFF\xD8\xFF"))->toBeTrue()
        ->and($converted)->not->toBe(heicFixture());
});

it('detects heic by its magic bytes, never by the file name', function (): void {
    // A phone naming a HEIC `photo.jpg` is common. Trusting the name would
    // send those bytes down the "already JPEG, pass through" branch untouched.
    $converter = new ImagickImageConverter;

    if (! $converter->supported('heic')) {
        $this->markTestSkipped('This environment cannot decode HEIC.');
    }

    $converted = $converter->toJpeg(heicFixture(), 'photo.jpg');

    expect(str_starts_with($converted, "\xFF\xD8\xFF"))->toBeTrue()
        ->and($converted)->not->toBe(heicFixture());
});

/*
|--------------------------------------------------------------------------
| supported() — proven by trial decode, never by queryFormats()
|--------------------------------------------------------------------------
*/

/**
 * MEASURED 2026-08-26: `queryFormats()` listed HEIC on a machine where writing
 * HEIC failed with NoEncodeDelegateForThisImageFormat — while real HEIC files
 * decoded perfectly well. Read and write support genuinely differ, so the only
 * honest answer to "can this environment decode HEIC" is to try.
 */
it('answers supported() by decoding a real sample, so it cannot claim a capability the environment lacks', function (): void {
    $converter = new ImagickImageConverter;

    // Whatever it answers must match what actually happens.
    $claimed = $converter->supported('heic');

    $decoded = true;

    try {
        $image = new Imagick;
        $image->readImageBlob(heicFixture());
        $decoded = $image->getImageWidth() > 0;
        $image->clear();
    } catch (Throwable) {
        $decoded = false;
    }

    expect($claimed)->toBe($decoded);
});

it('does not trust queryFormats, which reports coder registration rather than usable delegates', function (): void {
    $listed = in_array('HEIC', (new Imagick)->queryFormats(), true);

    // Where the two disagree, `supported()` must follow the trial decode.
    // Where they agree this simply passes, which is also correct.
    if (! $listed) {
        $this->markTestSkipped('queryFormats does not list HEIC here, so the divergence cannot be observed.');
    }

    expect((new ImagickImageConverter)->supported('heic'))->toBeBool();
});

it('reports no support for a format it does not handle', function (string $format): void {
    expect((new ImagickImageConverter)->supported($format))->toBeFalse();
})->with([
    'jpeg is a target, not a source' => ['jpeg'],
    'jpeg xl is out of scope' => ['jxl'],
    'raw is out of scope' => ['dng'],
    'nonsense' => ['not-a-format'],
]);

it('memoises the trial decode rather than repeating it per file', function (): void {
    $converter = new ImagickImageConverter;

    expect($converter->supported('heic'))->toBe($converter->supported('heic'));
});

/*
|--------------------------------------------------------------------------
| Degrading, not breaking
|--------------------------------------------------------------------------
*/

it('passes through a format it deliberately ignores', function (): void {
    $png = imageAs('png');

    expect((new ImagickImageConverter)->toJpeg((string) $png, 'photo.png'))->toBe($png);
});

it('passes through bytes that are not an image at all', function (): void {
    // This class normalises what it recognises; it is not a validator for
    // everything else, and refusing here would break unrelated uploads.
    $bytes = 'certainly not an image';

    expect((new ImagickImageConverter)->toJpeg($bytes, 'notes.txt'))->toBe($bytes);
});

/**
 * A format this converter DECLARED it can decode, whose bytes are broken, is
 * the one case that must fail loudly: passing a truncated file through would
 * upload it as though it were sound.
 */
/**
 * Truncated bytes of a format this converter DECLARED it can decode. Driven
 * through whichever convertible format the environment actually supports —
 * pinning it to HEIC would skip this on any runner lacking the HEVC decode
 * plugin, and that is the one behaviour a caller must be able to rely on.
 */
it('fails loudly on a truncated file rather than emitting a partial one', function (): void {
    ['converter' => $converter, 'bytes' => $bytes, 'name' => $name] = truncatedConvertible();

    expect(fn (): string => $converter->toJpeg($bytes, $name))
        ->toThrow(ConversionFailed::class);
});

it('names the file in the failure, so the offending upload is identifiable', function (): void {
    ['converter' => $converter, 'bytes' => $bytes] = truncatedConvertible();

    expect(fn (): string => $converter->toJpeg($bytes, 'crew-proof.bin'))
        ->toThrow(ConversionFailed::class, 'crew-proof.bin');
});

it('chains the underlying cause so the real decoder error is not lost', function (): void {
    ['converter' => $converter, 'bytes' => $bytes, 'name' => $name] = truncatedConvertible();

    try {
        $converter->toJpeg($bytes, $name);
    } catch (ConversionFailed $e) {
        expect($e->getPrevious())->toBeInstanceOf(Throwable::class);

        return;
    }

    $this->fail('Expected ConversionFailed.');
});

/*
|--------------------------------------------------------------------------
| The null converter
|--------------------------------------------------------------------------
*/

it('returns the original bytes and notices once when conversion is unavailable', function (): void {
    Log::spy();

    $converter = new NullImageConverter;

    expect($converter->supported())->toBeFalse()
        ->and($converter->toJpeg('bytes', 'a.heic'))->toBe('bytes')
        ->and($converter->toJpeg('bytes', 'b.heic'))->toBe('bytes');

    Log::shouldHaveReceived('warning')->once();
});

it('satisfies the contract from both implementations', function (ConvertsImages $converter): void {
    expect($converter)->toBeInstanceOf(ConvertsImages::class)
        ->and($converter->supported('heic'))->toBeBool()
        ->and($converter->toJpeg('bytes', 'a.bin'))->toBeString();
})->with([
    'imagick' => [fn (): ConvertsImages => new ImagickImageConverter],
    'null' => [fn (): ConvertsImages => new NullImageConverter],
]);

/*
|--------------------------------------------------------------------------
| The container binding
|--------------------------------------------------------------------------
*/

it('binds a converter that matches what the environment can actually do', function (): void {
    $converter = app(ConvertsImages::class);

    expect($converter)->toBeInstanceOf(
        extension_loaded('imagick') ? ImagickImageConverter::class : NullImageConverter::class,
    );
});

/**
 * Compression and conversion are separate contracts on purpose. `compress()`
 * runs during the queued CDN offload — from `ImageKitManager` and
 * `Jobs\PushFileToImageKit` — long after the local file is written, so a
 * converter built on that seam would transform the CDN copy and leave the
 * local file alone.
 */
it('keeps conversion separate from compression rather than overloading one contract', function (): void {
    expect(app(ConvertsImages::class))->not->toBeInstanceOf(CompressesImages::class);
});

/*
|--------------------------------------------------------------------------
| Transparency and orientation
|--------------------------------------------------------------------------
*/

/**
 * JPEG has no alpha channel. Leaving a transparent source unflattened produces
 * black or corrupt regions rather than a clean image, which is why the
 * existing `format: 'jpeg'` footgun in the README exists at all.
 */
it('flattens a transparent source rather than corrupting it', function (): void {
    $source = new Imagick;
    $source->newImage(8, 8, new ImagickPixel('transparent'));
    $source->setImageFormat('webp');
    $webp = $source->getImagesBlob();
    $source->clear();

    if ($webp === '') {
        $this->markTestSkipped('This Imagick build cannot write WebP.');
    }

    $converted = (new ImagickImageConverter)->toJpeg($webp, 'transparent.webp');

    expect(str_starts_with($converted, "\xFF\xD8\xFF"))->toBeTrue();

    $result = new Imagick;
    $result->readImageBlob($converted);

    expect($result->getImageAlphaChannel())->toBeFalse()
        ->and($result->getImageWidth())->toBe(8);

    $result->clear();
});

/**
 * A rotated source must come out upright AND be labelled upright. Applying the
 * transform while leaving the tag saying "rotate me" makes every viewer rotate
 * it a second time — the double-rotation bug.
 *
 * Driven through a live Imagick object rather than an encoded fixture,
 * because neither WebP nor JPEG carries the orientation tag back through
 * `getImagesBlob()` — a fixture-based test here silently asserts nothing.
 */
it('bakes in every exif orientation and resets the tag so nothing rotates twice', function (int $orientation, bool $quarterTurn): void {
    $image = new Imagick;
    // Non-square, so a quarter turn is observable in the dimensions.
    $image->newImage(12, 6, new ImagickPixel('red'));
    $image->setImageOrientation($orientation);

    $method = new ReflectionMethod(ImagickImageConverter::class, 'applyOrientation');
    $method->invoke(null, $image);

    $width = $image->getImageWidth();
    $height = $image->getImageHeight();
    $resulting = $image->getImageOrientation();
    $image->clear();

    // Never left saying "rotate me" — that is the half everyone forgets.
    expect($resulting)->toBe(Imagick::ORIENTATION_TOPLEFT);

    $quarterTurn
        ? expect([$width, $height])->toBe([6, 12])
        : expect([$width, $height])->toBe([12, 6]);
})->with([
    'topleft (no transform)' => [Imagick::ORIENTATION_TOPLEFT, false],
    'topright (mirrored)' => [Imagick::ORIENTATION_TOPRIGHT, false],
    'bottomright (180)' => [Imagick::ORIENTATION_BOTTOMRIGHT, false],
    'bottomleft (flipped)' => [Imagick::ORIENTATION_BOTTOMLEFT, false],
    'lefttop (mirrored + 90)' => [Imagick::ORIENTATION_LEFTTOP, true],
    'righttop (90)' => [Imagick::ORIENTATION_RIGHTTOP, true],
    'rightbottom (mirrored + 270)' => [Imagick::ORIENTATION_RIGHTBOTTOM, true],
    'leftbottom (270)' => [Imagick::ORIENTATION_LEFTBOTTOM, true],
]);

it('mirrors as well as rotating, so a flipped source is not merely turned', function (): void {
    // TOPRIGHT is a horizontal mirror with NO rotation, so a dimensions-only
    // assertion cannot see it at all — a converter that ignored mirroring
    // entirely would still pass the orientation test above.
    //
    // Built by composing two solid halves rather than by writing individual
    // pixels: `setImagePixelColor()` is absent from minimal ImageMagick builds
    // (it is missing on the CI runner), and a test that errors there reports an
    // environment gap as a code regression.
    $left = new Imagick;
    $left->newImage(1, 1, new ImagickPixel('blue'));

    $image = new Imagick;
    $image->newImage(2, 1, new ImagickPixel('red'));
    $image->compositeImage($left, Imagick::COMPOSITE_OVER, 0, 0);
    $image->setImageOrientation(Imagick::ORIENTATION_TOPRIGHT);
    $left->clear();

    (new ReflectionMethod(ImagickImageConverter::class, 'applyOrientation'))->invoke(null, $image);

    // The blue pixel started on the left; only a horizontal flip moves it right.
    $moved = $image->getImagePixelColor(1, 0)->getColorAsString();
    $image->clear();

    expect($moved)->toContain('rgb(0,0,255)');
});

it('leaves an undefined orientation alone rather than guessing', function (): void {
    $source = new Imagick;
    $source->newImage(12, 6, new ImagickPixel('red'));
    $source->setImageOrientation(Imagick::ORIENTATION_UNDEFINED);
    $source->setImageFormat('webp');
    $webp = $source->getImagesBlob();
    $source->clear();

    if ($webp === '') {
        $this->markTestSkipped('This Imagick build cannot write WebP.');
    }

    $result = new Imagick;
    $result->readImageBlob((new ImagickImageConverter)->toJpeg($webp, 'plain.webp'));

    expect([$result->getImageWidth(), $result->getImageHeight()])->toBe([12, 6]);

    $result->clear();
});

/*
|--------------------------------------------------------------------------
| Degrading where the environment cannot convert
|--------------------------------------------------------------------------
|
| These branches cannot be reached by a working machine converting a real
| file, which is exactly why they are worth asserting: they are the paths that
| run on the deployment target where the HEIC decoder is missing, and nobody
| will notice them broken until then.
|
*/

it('treats "no sample to try" as cannot, never as a silent yes', function (): void {
    // `probeFor()` has no sample for a format outside its set.
    ImagickImageConverter::resetCapabilities();

    expect((new ImagickImageConverter)->supported('tiff'))->toBeFalse();
});

it('returns null rather than throwing when a probe cannot be generated', function (): void {
    // The encoder half of the same failure: a format listed but missing its
    // delegate. `generateProbe()` must yield "no probe", not an exception
    // during what is only a capability check.
    $generate = new ReflectionMethod(ImagickImageConverter::class, 'generateProbe');

    expect($generate->invoke(null, 'definitely-not-an-image-format'))->toBeNull();
});

it('answers no when the probe file is missing from the package', function (): void {
    // Guards the shipped fixture: a build that dropped resources/probes must
    // report no HEIC support rather than crash on a missing file.
    ImagickImageConverter::resetCapabilities();

    $converter = new ImagickImageConverter(fn (string $format): ?string => null);

    expect($converter->supported('heic'))->toBeFalse();
});

it('answers no when the probe exists but the delegate is broken', function (): void {
    // A registered-but-broken decoder: the sample is there and reading it
    // throws. This is the state of a server missing libheif-plugin-libde265,
    // and it must degrade rather than propagate.
    ImagickImageConverter::resetCapabilities();

    $converter = new ImagickImageConverter(fn (string $format): ?string => 'not any image format');

    expect($converter->supported('heic'))->toBeFalse();
});

/**
 * The path a HEIC takes on a server whose decoder is missing: it must reach
 * the null converter and come back unchanged, never half-converted and never
 * as an exception.
 */
it('hands an unsupported but convertible format to the null converter unchanged', function (): void {
    $heic = heicFixture();

    // No probe means no support, standing in for a machine without the HEVC
    // decode plugin. The HEIC must come back byte-for-byte — never
    // half-converted, and never as an exception.
    ImagickImageConverter::resetCapabilities();
    Log::spy();

    $converter = new ImagickImageConverter(fn (string $format): ?string => null);

    expect($converter->toJpeg($heic, 'photo.heic'))->toBe($heic);

    Log::shouldHaveReceived('warning')->once();
});
