<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Contracts;

use Thecyrilcril\ImageKit\Exceptions\ConversionFailed;

/**
 * Normalises an image to JPEG while preserving its EXIF metadata.
 *
 * WHY THIS IS NOT PART OF `CompressesImages`. `compress()` is invoked during
 * the queued CDN offload — from `ImageKitManager` and `Jobs\PushFileToImageKit`
 * — which happens long after the file has been written to local disk. A
 * converter built on that seam would transform the copy sent to ImageKit and
 * leave the local file untouched, which is the wrong file for any consumer
 * that reads bytes back off disk.
 *
 * Conversion therefore belongs at STORE time, before the upload pipeline is
 * entered at all, so this is a separate contract with its own binding and is
 * directly invocable with raw bytes. It mirrors `CompressesImages`' SHAPE — a
 * `supported()` capability check and a never-throw-on-unsupported posture —
 * without inheriting its call site.
 *
 * EXIF SURVIVAL IS THE POINT, NOT A SIDE EFFECT. Consumers convert precisely
 * because they need a JPEG that still carries `DateTimeOriginal` or GPS tags;
 * an implementation that produced a valid JPEG with the metadata gone would
 * satisfy the type and defeat the purpose. Two measured traps make that easy
 * to get wrong:
 *
 * - GD strips EXIF on EVERY re-encode, including JPEG→JPEG, and cannot decode
 *   HEIC at all. `Illuminate\Image` defaults to the GD driver, so an
 *   implementation must refuse rather than silently convert lossily.
 * - `Imagick::stripImage()` destroys EXIF in one call. Imagick otherwise
 *   preserves it automatically across HEIC/WebP/AVIF→JPEG.
 *
 * NEVER THROW FOR AN UNSUPPORTED ENVIRONMENT. Return the input unchanged and
 * let the caller decide, exactly as `NullImageCompressor` does — check
 * `supported()` first.
 */
interface ConvertsImages
{
    /**
     * Convert to JPEG, or return the input unchanged when conversion does not
     * apply — an already-JPEG input, an unsupported environment, or a format
     * this converter deliberately ignores.
     *
     * @param  string  $contents  the raw source bytes
     * @param  string  $fileName  the original name, used only for logging
     *
     * @throws ConversionFailed when the input
     *                          is a format this converter handles but the bytes are corrupt
     */
    public function toJpeg(string $contents, string $fileName): string;

    /**
     * Whether this environment can actually convert the given format.
     *
     * MUST BE PROVEN BY A TRIAL DECODE, NOT BY `Imagick::queryFormats()`.
     * Measured 2026-08-26: `queryFormats()` listed HEIC on a machine where
     * writing HEIC failed outright, and read and write support genuinely
     * differ. A capability check that reports something the environment lacks
     * is worse than one that reports nothing.
     *
     * @param  string  $format  a bare extension-style name: 'heic', 'webp', 'avif'
     */
    public function supported(string $format = 'heic'): bool;
}
