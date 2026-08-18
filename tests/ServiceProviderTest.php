<?php

declare(strict_types=1);

it('merges the package configuration', function (): void {
    expect(config('imagekit.queue.name'))->toBe('imagekit')
        ->and(config('imagekit.profiles.avatar.quality'))->toBe(90)
        ->and(config('imagekit.profiles.document.compress'))->toBeFalse()
        ->and(config('imagekit.presets.avatar.focus'))->toBe('face');
});
