<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit;

use Illuminate\Support\ServiceProvider;
use Override;

final class ImageKitServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/imagekit.php', 'imagekit');
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
}
