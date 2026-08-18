<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Thecyrilcril\ImageKit\ImageKitServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            MediaLibraryServiceProvider::class,
            ImageKitServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $config->set('cache.default', 'array');
        $config->set('queue.default', 'sync');

        $config->set('imagekit.public_key', 'public_test');
        $config->set('imagekit.private_key', 'private_test');
        $config->set('imagekit.url_endpoint', 'https://ik.imagekit.io/test');
    }

    protected function defineDatabaseMigrations(): void
    {
        // spatie/laravel-medialibrary ships its migration as an unpublished
        // .php.stub, which Testbench's directory-based loadMigrationsFrom()
        // cannot discover (it globs *.php). Run it directly so the `media`
        // table exists before this package's own migration alters it.
        $mediaTableMigration = include __DIR__.'/../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';
        $mediaTableMigration->up();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
