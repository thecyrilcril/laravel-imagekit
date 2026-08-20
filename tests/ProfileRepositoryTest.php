<?php

declare(strict_types=1);

use Thecyrilcril\ImageKit\Data\CompressionProfile;
use Thecyrilcril\ImageKit\Exceptions\InvalidProfile;
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

/**
 * @param  array<string, mixed>  $overrides
 */
function avatarProfile(array $overrides): CompressionProfile
{
    $repository = new ProfileRepository(
        profiles: ['avatar' => ['compress' => true, 'max_edge' => 1200, 'quality' => 88, 'format' => null, ...$overrides]],
        presets: [],
    );

    return $repository->profile('avatar');
}

// Covers AE1.
it('throws InvalidProfile naming the profile and field for an out-of-range quality', function (): void {
    avatarProfile(['quality' => 900]);
})->throws(InvalidProfile::class, 'Invalid ImageKit compression profile [avatar]: quality must be an integer between 1 and 100, got 900.');

it('rejects a numeric-string quality rather than coercing it', function (): void {
    avatarProfile(['quality' => '90']);
})->throws(InvalidProfile::class, 'quality');

it('accepts the quality boundaries', function (int $quality): void {
    expect(avatarProfile(['quality' => $quality])->quality)->toBe($quality);
})->with([1, 100]);

// Covers AE2.
it('throws InvalidProfile for a max_edge below 1 instead of clamping', function (mixed $maxEdge): void {
    avatarProfile(['max_edge' => $maxEdge]);
})->with([0, -5, '2000'])->throws(InvalidProfile::class, 'max_edge');

it('rejects a float quality rather than truncating it', function (): void {
    avatarProfile(['quality' => 90.5]);
})->throws(InvalidProfile::class, 'got 90.5');

it('describes a non-scalar value by its type in the message', function (): void {
    avatarProfile(['max_edge' => ['bad']]);
})->throws(InvalidProfile::class, 'got array');

// Covers AE3.
it('throws InvalidProfile for a non-string format instead of coercing to null', function (): void {
    avatarProfile(['format' => 123]);
})->throws(InvalidProfile::class, 'format');

it('accepts a null or string format', function (?string $format): void {
    expect(avatarProfile(['format' => $format])->format)->toBe($format);
})->with([null, 'webp']);

it('loads every profile in the shipped default config', function (string $name): void {
    expect(app(ProfileRepository::class)->profile($name))->toBeInstanceOf(CompressionProfile::class);
})->with(['default', 'avatar', 'document']);
