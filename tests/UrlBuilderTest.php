<?php

declare(strict_types=1);

use ImageKit\ImageKit;
use Thecyrilcril\ImageKit\Contracts\GeneratesFileUrls;
use Thecyrilcril\ImageKit\Exceptions\UnknownProfile;

beforeEach(function (): void {
    $this->sdk = Mockery::mock(ImageKit::class);
    $this->app->instance(ImageKit::class, $this->sdk);
});

it('applies the named preset for a raster image', function (): void {
    $this->sdk->shouldReceive('url')
        ->once()
        ->withArgs(fn (array $options): bool => $options['path'] === '/avatars/a.jpg'
            && [['width' => 200, 'height' => 200, 'focus' => 'face', 'quality' => 85, 'format' => 'auto']] === $options['transformation'])
        ->andReturn('https://ik.imagekit.io/test/tr:w-200/avatars/a.jpg');

    $url = app(GeneratesFileUrls::class)->build('/avatars/a.jpg', 'avatar', 'image/jpeg');

    expect($url)->toContain('tr:w-200');
});

it('applies no transformation to a document', function (): void {
    $this->sdk->shouldReceive('url')
        ->once()
        ->withArgs(fn (array $options): bool => $options['transformation'] === [])
        ->andReturn('https://ik.imagekit.io/test/docs/report.pdf');

    app(GeneratesFileUrls::class)->build('/docs/report.pdf', 'avatar', 'application/pdf');
});

it('applies no transformation to an unidentified file', function (): void {
    $this->sdk->shouldReceive('url')
        ->once()
        ->withArgs(fn (array $options): bool => $options['transformation'] === [])
        ->andReturn('https://ik.imagekit.io/test/x.bin');

    app(GeneratesFileUrls::class)->build('/x.bin', 'avatar', 'application/x-cbr');
});

it('throws on an unknown preset instead of returning an untransformed url', function (): void {
    app(GeneratesFileUrls::class)->build('/a.jpg', 'typo-preset', 'image/jpeg');
})->throws(UnknownProfile::class, 'typo-preset');
