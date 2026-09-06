<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Override;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A model with a registered conversion, so a Source has more than the
 * original: media-library's remover only deletes conversions it knows.
 */
final class ConvertingModel extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'test_models';

    protected $guarded = [];

    public $timestamps = false;

    #[Override]
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->toImageKit('avatar');
    }

    #[Override]
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(10)->queued();
    }
}
