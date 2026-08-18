<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit;

use Illuminate\Support\Facades\Image;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\ImageManager;
use Override;
use Thecyrilcril\ImageKit\Compression\LaravelImageCompressor;
use Thecyrilcril\ImageKit\Compression\NullImageCompressor;
use Thecyrilcril\ImageKit\Contracts\CompressesImages;

final class ImageKitServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/imagekit.php', 'imagekit');

        $this->app->singleton(CompressesImages::class, function (): CompressesImages {
            return $this->compressionAvailable()
                ? new LaravelImageCompressor
                : new NullImageCompressor;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/imagekit.php' => config_path('imagekit.php'),
            ], 'imagekit-config');
        }
    }

    /**
     * A capability check, not a version check: Laravel 13.20 alone is not
     * enough, because intervention/image is optional and a GD or Imagick
     * driver must also be present.
     */
    private function compressionAvailable(): bool
    {
        return class_exists(Image::class)
            && class_exists(ImageManager::class)
            && (extension_loaded('gd') || extension_loaded('imagick'));
    }
}
