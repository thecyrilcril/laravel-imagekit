<?php

declare(strict_types=1);

use Thecyrilcril\ImageKit\Support\FolderResolver;

it('prefixes the collection with the configured root folder', function (): void {
    config()->set('imagekit.folder', 'kitwire');

    expect(FolderResolver::resolve('avatars'))->toBe('kitwire/avatars');
});

it('defaults the root folder to uploads', function (): void {
    expect(config('imagekit.folder'))->toBe('uploads')
        ->and(FolderResolver::resolve('avatars'))->toBe('uploads/avatars');
});

// Covers AE5.
it('trims slashes from the root and returns it alone for an empty collection', function (): void {
    config()->set('imagekit.folder', '/kitwire/');

    expect(FolderResolver::resolve(''))->toBe('kitwire')
        ->and(FolderResolver::resolve('avatars'))->toBe('kitwire/avatars');
});

it('returns the bare collection when the root folder is empty', function (): void {
    config()->set('imagekit.folder', '');

    expect(FolderResolver::resolve('avatars'))->toBe('avatars');
});
