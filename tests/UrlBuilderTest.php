<?php

declare(strict_types=1);

use Thecyrilcril\ImageKit\Contracts\GeneratesFileUrls;
use Thecyrilcril\ImageKit\Exceptions\UnknownProfile;
use Thecyrilcril\ImageKitClient\Exceptions\InvalidTransformation;

/**
 * URL building is pure string work, so these run against the Client's real
 * builder: ImageKitClient::fake() would hand back the same object. The
 * expected strings are the URLs the old `imagekit/imagekit` dependency produced,
 * byte for byte, which is what keeps ImageKit's CDN cache warm across the upgrade.
 */
it('applies the named preset for a raster image', function (): void {
    $url = app(GeneratesFileUrls::class)->build('/avatars/a.jpg', 'avatar', 'image/jpeg');

    expect($url)->toBe('https://ik.imagekit.io/test/tr:w-200,h-200,fo-face,q-85,f-auto/avatars/a.jpg');
});

it('applies the default preset when none is named', function (): void {
    $url = app(GeneratesFileUrls::class)->build('/avatars/a.jpg', null, 'image/png');

    expect($url)->toBe('https://ik.imagekit.io/test/tr:q-85,f-auto/avatars/a.jpg');
});

it('applies no transformation to a document', function (): void {
    $url = app(GeneratesFileUrls::class)->build('/docs/report.pdf', 'avatar', 'application/pdf');

    expect($url)->toBe('https://ik.imagekit.io/test/docs/report.pdf');
});

it('applies no transformation to an unidentified file', function (): void {
    $url = app(GeneratesFileUrls::class)->build('/x.bin', 'avatar', 'application/x-cbr');

    expect($url)->toBe('https://ik.imagekit.io/test/x.bin');
});

it('throws on an unknown preset instead of returning an untransformed url', function (): void {
    app(GeneratesFileUrls::class)->build('/a.jpg', 'typo-preset', 'image/jpeg');
})->throws(UnknownProfile::class, 'typo-preset');

it('surfaces an unknown key inside a preset as the Client\'s InvalidTransformation', function (): void {
    config()->set('imagekit.presets.typo', ['widht' => 200]);

    app(GeneratesFileUrls::class)->build('/a.jpg', 'typo', 'image/jpeg');
})->throws(InvalidTransformation::class, 'widht');
