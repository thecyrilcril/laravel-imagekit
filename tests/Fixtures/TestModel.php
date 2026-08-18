<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Override;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class TestModel extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'test_models';

    protected $guarded = [];

    public $timestamps = false;

    #[Override]
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->singleFile()->toImageKit('avatar');
        $this->addMediaCollection('plain');
    }
}
