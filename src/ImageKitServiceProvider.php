<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit;

use Illuminate\Support\Facades\Image;
use Illuminate\Support\ServiceProvider;
use ImageKit\ImageKit as Sdk;
use Intervention\Image\ImageManager;
use Override;
use Thecyrilcril\ImageKit\Compression\LaravelImageCompressor;
use Thecyrilcril\ImageKit\Compression\NullImageCompressor;
use Thecyrilcril\ImageKit\Contracts\CompressesImages;
use Thecyrilcril\ImageKit\Contracts\DeletesRemoteFiles;
use Thecyrilcril\ImageKit\Contracts\GeneratesFileUrls;
use Thecyrilcril\ImageKit\Contracts\UploadsFiles;
use Thecyrilcril\ImageKit\Support\ProfileRepository;
use Thecyrilcril\ImageKit\Support\UrlFactory;

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

        $this->app->singleton(Sdk::class, function (): Sdk {
            return new Sdk(
                (string) config('imagekit.public_key'),
                (string) config('imagekit.private_key'),
                (string) config('imagekit.url_endpoint'),
            );
        });

        $this->app->singleton(UploadsFiles::class, ImageKitUploader::class);
        $this->app->singleton(DeletesRemoteFiles::class, ImageKitFileRemover::class);

        $this->app->singleton(ProfileRepository::class, function (): ProfileRepository {
            /** @var array<string, array<string, mixed>> $profiles */
            $profiles = config('imagekit.profiles', []);
            /** @var array<string, array<string, mixed>> $presets */
            $presets = config('imagekit.presets', []);

            return new ProfileRepository($profiles, $presets);
        });

        $this->app->singleton(GeneratesFileUrls::class, UrlFactory::class);
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
