<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('adds the tombstone column to the media table', function (): void {
    expect(Schema::hasColumn('media', 'imagekit_pending_deletion_at'))->toBeTrue();
});

it('leaves the rest of the media table untouched', function (): void {
    expect(Schema::hasColumn('media', 'custom_properties'))->toBeTrue()
        ->and(Schema::hasColumn('media', 'collection_name'))->toBeTrue();
});
