<?php

declare(strict_types=1);

use Thecyrilcril\ImageKitClient\Contracts\Client;
use Thecyrilcril\ImageKitClient\ImageKitClientServiceProvider;

it('merges the package configuration', function (): void {
    expect(config('imagekit.queue.name'))->toBe('imagekit')
        ->and(config('imagekit.profiles.avatar.quality'))->toBe(90)
        ->and(config('imagekit.profiles.document.compress'))->toBeFalse()
        ->and(config('imagekit.presets.avatar.focus'))->toBe('face');
});

it('carries no credential keys: those live in the Client\'s config', function (): void {
    foreach (['public_key', 'private_key', 'url_endpoint'] as $credential) {
        expect(config('imagekit'))->not->toHaveKey($credential);
    }

    expect(config('imagekit'))->toHaveKeys(['queue', 'folder', 'profiles', 'presets']);
});

it('registers the Client\'s service provider itself, so package discovery is not load-bearing', function (): void {
    expect($this->app->getProvider(ImageKitClientServiceProvider::class))->toBeInstanceOf(ImageKitClientServiceProvider::class)
        ->and(app(Client::class))->toBeInstanceOf(Client::class);
});
