<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Compression;

use Closure;
use Imagick;
use Override;
use Thecyrilcril\ImageKit\Contracts\ConvertsImages;
use Thecyrilcril\ImageKit\Exceptions\ConversionFailed;
use Throwable;

/**
 * Normalises HEIC/HEIF, WebP and AVIF to JPEG with EXIF intact, using Imagick.
 *
 * NOT `final`, deliberately: `supported()` answers a question about the
 * ENVIRONMENT, and the only way to exercise the degradation path on a machine
 * that can convert is to override it. A consumer overriding it in production
 * is choosing to lie about their own environment, which is their business.
 *
 * IMAGICK, NEVER GD, AND THE REASON IS EVIDENCE PRESERVATION. Measured
 * 2026-08-26: GD strips EXIF on EVERY re-encode — including JPEG→JPEG, where
 * nothing about the format changes — and cannot decode HEIC at all. Imagick
 * preserves EXIF automatically across every conversion path tested. Since
 * `Illuminate\Image` defaults to the GD driver, a converter that merely asked
 * the framework for an image manager would quietly destroy the metadata it
 * exists to protect.
 *
 * `stripImage()` IS NEVER CALLED. One call undoes the preservation this class
 * is built around. It is not a tidying step; it is the thing to avoid.
 *
 * `Imagick::queryFormats()` IS NOT CONSULTED. It reports coder registration,
 * not usable delegates, and read and write support differ. Measured on the
 * development machine: it listed HEIC while `writeImage()` to HEIC failed with
 * `NoEncodeDelegateForThisImageFormat`, and yet real HEIC files decoded fine.
 * {@see supported()} therefore performs a trial DECODE of a real sample of the
 * format, which is the only question this class actually needs answered.
 *
 * SCOPE IS DELIBERATE. HEIC/HEIF is the case that must work — it is what an
 * iPhone produces. WebP and AVIF are handled because they arrive from the same
 * upload paths and cost nothing extra. JPEG XL, DNG/RAW, Ultra HDR and
 * Motion/Live Photos are explicitly out of scope: the last two are already
 * valid baseline JPEGs that decode correctly and need no special handling.
 *
 * AN ALREADY-JPEG INPUT IS RETURNED BYTE-FOR-BYTE. Not re-encoded, not
 * re-compressed — returned. A needless round trip would cost quality and risk
 * the metadata for no gain whatsoever.
 */
final class ImagickImageConverter implements ConvertsImages
{
    /**
     * @param  (Closure(string): ?string)|null  $probeSource  supplies the trial-decode
     *                                                        sample for a format. Injected so the
     *                                                        degradation paths — a missing probe, a
     *                                                        registered-but-broken delegate — can be
     *                                                        exercised on a machine where conversion
     *                                                        actually works. Null uses the real one.
     */
    public function __construct(private readonly ?Closure $probeSource = null) {}

    /**
     * Formats this converter will decode, by their magic-byte signature.
     *
     * Detection is by CONTENT, never by file extension. A phone that names a
     * HEIC `photo.jpg` is common, and trusting the name would send those bytes
     * through the "already JPEG, pass through" branch untouched.
     */
    private const array CONVERTIBLE = ['heic', 'heif', 'webp', 'avif'];

    /**
     * Memoised trial-decode results, keyed by format.
     *
     * @var array<string, bool>
     */
    private static array $capabilities = [];

    /** Test seam: forces the next `supported()` call to trial-decode again. */
    public static function resetCapabilities(): void
    {
        self::$capabilities = [];
    }

    #[Override]
    public function toJpeg(string $contents, string $fileName): string
    {
        $format = self::detectFormat($contents);

        // Already a JPEG: return the exact bytes. Re-encoding would cost
        // quality and put the EXIF at risk for no benefit at all.
        if ($format === 'jpeg') {
            return $contents;
        }

        // A format outside this converter's scope, or unrecognisable bytes.
        // Passing them through is right: this class normalises what it knows
        // and is not a validator for everything else.
        if (! in_array($format, self::CONVERTIBLE, true)) {
            return $contents;
        }

        if (! $this->supported($format)) {
            return (new NullImageConverter)->toJpeg($contents, $fileName);
        }

        try {
            $image = new Imagick;
            $image->readImageBlob($contents);

            // Bake in EXIF orientation, then declare the result upright.
            // Without the second step a viewer applies the rotation a SECOND
            // time and the photograph lands on its side — the double-rotation
            // bug. `autoOrientImage()` is not available in every Imagick
            // build (it is absent from the one this was developed against),
            // so the transform is applied explicitly.
            self::applyOrientation($image);

            // JPEG has no alpha channel. Flattening onto white is what the
            // README's existing transparency footgun describes; leaving it
            // unflattened produces black or corrupt regions instead.
            if ($image->getImageAlphaChannel()) {
                $image->setImageBackgroundColor('white');
                $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }

            $image->setImageFormat('jpeg');

            // NO stripImage() ANYWHERE IN THIS METHOD. Imagick carries the
            // EXIF across on its own; one call to strip it would defeat the
            // entire purpose of this class.
            $converted = $image->getImagesBlob();

            $image->clear();

            return $converted;
        } catch (Throwable $e) {
            // Reached only for a format this converter DECLARED it can decode,
            // so these bytes are corrupt or truncated. Failing loudly is right:
            // passing a broken file through would upload it as though sound.
            throw ConversionFailed::forFile($fileName, $e);
        }
    }

    #[Override]
    public function supported(string $format = 'heic'): bool
    {
        $format = mb_strtolower($format);

        if (array_key_exists($format, self::$capabilities)) {
            return self::$capabilities[$format];
        }

        return self::$capabilities[$format] = $this->trialDecode($format);
    }

    /**
     * Rotate and flip according to the EXIF orientation tag, then reset it.
     *
     * Resetting is not cosmetic: an image whose pixels have been rotated but
     * whose tag still says "rotate me" is displayed rotated twice. Both halves
     * are required, and only together do they produce an upright JPEG.
     */
    private static function applyOrientation(Imagick $image): void
    {
        $orientation = $image->getImageOrientation();

        match ($orientation) {
            Imagick::ORIENTATION_TOPRIGHT => $image->flopImage(),
            Imagick::ORIENTATION_BOTTOMRIGHT => $image->rotateImage('#000', 180),
            Imagick::ORIENTATION_BOTTOMLEFT => $image->flipImage(),
            Imagick::ORIENTATION_LEFTTOP => self::flopAndRotate($image, 90),
            Imagick::ORIENTATION_RIGHTTOP => $image->rotateImage('#000', 90),
            Imagick::ORIENTATION_RIGHTBOTTOM => self::flopAndRotate($image, 270),
            Imagick::ORIENTATION_LEFTBOTTOM => $image->rotateImage('#000', 270),
            default => null,
        };

        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }

    private static function flopAndRotate(Imagick $image, float $degrees): bool
    {
        $image->flopImage();

        return $image->rotateImage('#000', $degrees);
    }

    /**
     * The format these bytes actually are, by magic-byte signature.
     *
     * Returns a bare name, or an empty string when nothing matches.
     */
    private static function detectFormat(string $contents): string
    {
        if (str_starts_with($contents, "\xFF\xD8\xFF")) {
            return 'jpeg';
        }

        if (str_starts_with($contents, 'RIFF') && mb_substr($contents, 8, 4) === 'WEBP') {
            return 'webp';
        }

        // ISO base media: a `ftyp` box at offset 4, with the brand following.
        // HEIC, HEIF and AVIF all share this container.
        if (mb_substr($contents, 4, 4) === 'ftyp') {
            $brand = mb_strtolower(mb_substr($contents, 8, 4));

            return match (true) {
                in_array($brand, ['heic', 'heix', 'hevc', 'hevx'], true) => 'heic',
                in_array($brand, ['mif1', 'msf1', 'heim', 'heis'], true) => 'heif',
                in_array($brand, ['avif', 'avis'], true) => 'avif',
                default => '',
            };
        }

        return '';
    }

    /**
     * Whether Imagick can genuinely DECODE this format here.
     *
     * A real sample of each format is decoded rather than asking
     * `queryFormats()`, which answers a different question — see the class
     * docblock. HEIC and HEIF share a decoder, so the HEIF probe stands for
     * both; the samples are minimal valid files, small enough to embed.
     */
    private function trialDecode(string $format): bool
    {
        // No `extension_loaded('imagick')` guard here, deliberately. The
        // service provider never binds this class without the extension, and
        // `probeFor()`/`decodes()` both answer "no" rather than throwing if it
        // somehow is missing — so the guard would be an unreachable branch
        // duplicating a decision already made, not a safety net.
        $sample = $this->probeSource instanceof Closure
            ? ($this->probeSource)($format)
            : self::probeFor($format);

        if ($sample === null) {
            return false;
        }

        return self::decodes($sample);
    }

    /**
     * Whether Imagick can read these bytes at all.
     *
     * The catch is the whole point: a delegate that is registered but broken
     * throws here rather than returning a bad image, and a capability check
     * that propagated would take down the caller while merely ASKING whether
     * conversion is possible.
     */
    private static function decodes(string $sample): bool
    {
        try {
            $image = new Imagick;
            $image->readImageBlob($sample);
            $decoded = $image->getImageWidth() > 0;
            $image->clear();

            return $decoded;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A minimal valid file of the given format, for the trial decode.
     *
     * WebP and AVIF are probed with generated samples because Imagick can
     * write both wherever it can read them. HEIC CANNOT be generated at
     * runtime on a machine whose decoder works but whose encoder does not —
     * the exact split `queryFormats()` hides, and the state of the development
     * machine this was written on — so it ships as a 2x2 fixture in
     * `resources/probes/probe.heic` instead.
     */
    private static function probeFor(string $format): ?string
    {
        if ($format === 'heic' || $format === 'heif') {
            $probe = __DIR__.'/../../resources/probes/probe.heic';

            return is_file($probe) ? (file_get_contents($probe) ?: null) : null;
        }

        if (! in_array($format, ['webp', 'avif'], true)) {
            return null;
        }

        return self::generateProbe($format);
    }

    /**
     * A freshly encoded 2x2 sample, or null where this build cannot write the
     * format.
     *
     * The catch matters for the same reason as `decodes()`: an encoder that is
     * listed but missing its delegate throws, and a capability check must
     * answer "no" rather than blow up the request that asked.
     */
    private static function generateProbe(string $format): ?string
    {
        try {
            $image = new Imagick;
            $image->newImage(2, 2, 'white');
            $image->setImageFormat($format);
            $sample = $image->getImagesBlob();
            $image->clear();

            return $sample === '' ? null : $sample;
        } catch (Throwable) {
            return null;
        }
    }
}
