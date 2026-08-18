<?php

declare(strict_types=1);

use Thecyrilcril\ImageKit\Data\CompressionProfile;
use Thecyrilcril\ImageKit\Exceptions\UnknownProfile;
use Thecyrilcril\ImageKit\Support\ProfileRepository;

function repository(): ProfileRepository
{
    return new ProfileRepository(
        profiles: [
            'default' => ['compress' => true, 'max_edge' => 2000, 'quality' => 90, 'format' => null],
            'avatar' => ['compress' => true, 'max_edge' => 1200, 'quality' => 88, 'format' => 'webp'],
            'document' => ['compress' => false],
        ],
        presets: [
            'default' => ['quality' => 85],
            'avatar' => ['width' => 200, 'height' => 200],
        ],
    );
}

it('resolves a named profile', function (): void {
    $profile = repository()->profile('avatar');

    expect($profile)->toBeInstanceOf(CompressionProfile::class)
        ->and($profile->compress)->toBeTrue()
        ->and($profile->maxEdge)->toBe(1200)
        ->and($profile->quality)->toBe(88)
        ->and($profile->format)->toBe('webp');
});

it('falls back to the default profile when no name is given', function (): void {
    expect(repository()->profile(null)->maxEdge)->toBe(2000);
});

it('applies defaults for keys a profile omits', function (): void {
    $profile = repository()->profile('document');

    expect($profile->compress)->toBeFalse()
        ->and($profile->maxEdge)->toBe(2000)
        ->and($profile->quality)->toBe(90)
        ->and($profile->format)->toBeNull()
        ->and($profile->await)->toBeFalse();
});

it('reads the await flag, defaulting to queued', function (): void {
    $repository = new ProfileRepository(
        profiles: [
            'default' => ['compress' => true],
            'api-doc' => ['compress' => false, 'await' => true],
        ],
        presets: ['default' => []],
    );

    expect($repository->profile('api-doc')->await)->toBeTrue()
        ->and($repository->profile('default')->await)->toBeFalse();
});

it('throws on an unknown profile name rather than degrading silently', function (): void {
    repository()->profile('avatarr');
})->throws(UnknownProfile::class, 'avatarr');

it('resolves a named preset', function (): void {
    expect(repository()->preset('avatar'))->toBe(['width' => 200, 'height' => 200]);
});

it('throws on an unknown preset name', function (): void {
    repository()->preset('nope');
})->throws(UnknownProfile::class, 'nope');

it('falls back to the default preset when no name is given', function (): void {
    expect(repository()->preset(null))->toBe(['quality' => 85]);
});
