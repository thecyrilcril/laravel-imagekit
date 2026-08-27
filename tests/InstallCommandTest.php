<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\PendingCommand;

/**
 * These are the suite's only tests that write into the Testbench skeleton, so
 * every file the command can create is removed again after each test, and
 * the skeleton's own `.env.example` is restored byte for byte. The schema is
 * emptied first as well: the base TestCase pre-creates the `media` table,
 * which would make the migrate-path assertions meaningless.
 */
beforeEach(function (): void {
    Schema::dropIfExists('media');
    DB::table('migrations')->delete();

    $this->env = base_path('.env');
    $this->example = base_path('.env.example');
    $this->exampleBackup = File::get($this->example);

    File::put($this->env, "APP_NAME=Laravel\n");
});

afterEach(function (): void {
    File::delete([
        config_path('imagekit.php'),
        config_path('media-library.php'),
        $this->env,
        ...File::glob(database_path('migrations/*.php')),
    ]);

    File::put($this->example, $this->exampleBackup);
});

function mediaLibraryConfig(): string
{
    return File::get(config_path('media-library.php'));
}

function nonInteractiveInstall(): PendingCommand
{
    return test()->artisan('imagekit:install', ['--no-interaction' => true]);
}

it('is registered with artisan', function (): void {
    expect(Artisan::all())->toHaveKey('imagekit:install');
});

// AE1
it('sets up a fresh app end to end', function (): void {
    $this->artisan('imagekit:install')
        ->expectsQuestion('IMAGEKIT_PUBLIC_KEY (leave blank to skip)', 'pub')
        ->expectsQuestion('IMAGEKIT_PRIVATE_KEY (input is hidden; leave blank to skip)', 'priv')
        ->expectsQuestion('IMAGEKIT_URL_ENDPOINT (leave blank to skip)', 'https://ik.imagekit.io/x')
        ->expectsQuestion('IMAGEKIT_FOLDER (this application\'s root folder on ImageKit)', 'my app')
        ->expectsConfirmation('Run the migrations now?', 'yes')
        ->assertSuccessful();

    expect(File::exists(config_path('imagekit.php')))->toBeTrue()
        ->and(mediaLibraryConfig())->toContain("'url_generator' => \\Thecyrilcril\\ImageKit\\ImageKitUrlBuilder::class,")
        ->and(File::get($this->env))->toBe(
            "APP_NAME=Laravel\nIMAGEKIT_PUBLIC_KEY=pub\nIMAGEKIT_PRIVATE_KEY=priv\n"
            ."IMAGEKIT_URL_ENDPOINT=https://ik.imagekit.io/x\nIMAGEKIT_FOLDER=\"my app\"\n"
        )
        ->and(File::get($this->example))->toBe(
            $this->exampleBackup."IMAGEKIT_PUBLIC_KEY=\nIMAGEKIT_PRIVATE_KEY=\nIMAGEKIT_URL_ENDPOINT=\nIMAGEKIT_FOLDER=\n"
        )
        ->and(Schema::hasColumn('media', 'imagekit_pending_deletion_at'))->toBeTrue();

    $table = File::glob(database_path('migrations/*_create_media_table.php'));
    $column = File::glob(database_path('migrations/*_add_imagekit_pending_deletion_to_media_table.php'));

    expect($table)->toHaveCount(1)
        ->and($column)->toHaveCount(1)
        ->and(strcmp(basename($column[0]), basename($table[0])))->toBeGreaterThan(0);
});

// AE2
it('is idempotent: a second run reports every step as skipped and changes nothing', function (): void {
    $this->artisan('imagekit:install')
        ->expectsQuestion('IMAGEKIT_PUBLIC_KEY (leave blank to skip)', 'pub')
        ->expectsQuestion('IMAGEKIT_PRIVATE_KEY (input is hidden; leave blank to skip)', 'priv')
        ->expectsQuestion('IMAGEKIT_URL_ENDPOINT (leave blank to skip)', 'https://ik.imagekit.io/x')
        ->expectsQuestion('IMAGEKIT_FOLDER (this application\'s root folder on ImageKit)', 'my-app')
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->assertSuccessful();

    $snapshot = fn (): array => [
        File::get($this->env),
        File::get($this->example),
        mediaLibraryConfig(),
        File::glob(database_path('migrations/*.php')),
    ];
    $before = $snapshot();

    $this->artisan('imagekit:install')
        ->expectsOutputToContain('already published')
        ->expectsOutputToContain('already set')
        ->expectsOutputToContain('already in .env')
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->assertSuccessful();

    expect($snapshot())->toBe($before)
        ->and($before[3])->toHaveCount(2);
});

// AE3
it('rewrites a url_generator that points at a custom class', function (string $value): void {
    nonInteractiveInstall()->assertSuccessful();
    $original = mediaLibraryConfig();
    File::put(config_path('media-library.php'), str_replace(
        '\\Thecyrilcril\\ImageKit\\ImageKitUrlBuilder::class',
        $value,
        $original,
    ));

    nonInteractiveInstall()->expectsOutputToContain('Point url_generator')->assertSuccessful();

    expect(mediaLibraryConfig())->toBe($original);
})->with([
    'class constant' => ['App\\Support\\MyUrlGenerator::class'],
    'quoted class string' => ["'App\\\\Support\\\\MyUrlGenerator'"],
]);

it('rewrites the default url_generator line and leaves the rest of the file alone', function (): void {
    nonInteractiveInstall()->assertSuccessful();

    $vendor = File::get(__DIR__.'/../vendor/spatie/laravel-medialibrary/config/media-library.php');

    expect(mediaLibraryConfig())->toBe(str_replace(
        "'url_generator' => DefaultUrlGenerator::class,",
        "'url_generator' => \\Thecyrilcril\\ImageKit\\ImageKitUrlBuilder::class,",
        $vendor,
    ));
});

it('prints the manual instruction instead of guessing when the url_generator line is unrecognisable', function (string $line): void {
    nonInteractiveInstall()->assertSuccessful();
    $edited = preg_replace('/^.*\'url_generator\'.*$/m', $line, mediaLibraryConfig());
    File::put(config_path('media-library.php'), $edited);

    nonInteractiveInstall()
        ->expectsOutputToContain('No url_generator line it recognises')
        ->expectsOutputToContain("'url_generator' => \\Thecyrilcril\\ImageKit\\ImageKitUrlBuilder::class,")
        ->assertSuccessful();

    expect(mediaLibraryConfig())->toBe($edited);
})->with([
    'line deleted' => [''],
    'function call' => ["    'url_generator' => resolve_generator(),"],
    'comma-containing expression' => ["    'url_generator' => env('X', 'Y'),"],
]);

it('prints the manual instruction when config/media-library.php is missing', function (): void {
    // Publishing never fails in the skeleton, so make the tag publish nothing:
    // the provider re-registers the group when the next test boots a fresh app.
    unset(ServiceProvider::$publishGroups['medialibrary-config']);

    nonInteractiveInstall()
        ->expectsOutputToContain('config/media-library.php was not found')
        ->expectsOutputToContain("'url_generator' => \\Thecyrilcril\\ImageKit\\ImageKitUrlBuilder::class,")
        ->assertSuccessful();

    expect(File::exists(config_path('media-library.php')))->toBeFalse();
});

// AE4
it('publishes everything, prints the env block and touches nothing else when non-interactive', function (): void {
    nonInteractiveInstall()
        ->expectsOutputToContain('Non-interactive run')
        ->expectsOutputToContain('IMAGEKIT_PUBLIC_KEY=')
        ->expectsOutputToContain('IMAGEKIT_FOLDER=laravel')
        ->expectsOutputToContain('Remaining step: run php artisan migrate.')
        ->assertSuccessful();

    expect(File::exists(config_path('imagekit.php')))->toBeTrue()
        ->and(File::exists(config_path('media-library.php')))->toBeTrue()
        ->and(File::glob(database_path('migrations/*.php')))->toHaveCount(2)
        ->and(File::get($this->env))->toBe("APP_NAME=Laravel\n")
        ->and(File::get($this->example))->toBe($this->exampleBackup)
        ->and(Schema::hasTable('media'))->toBeFalse();
});

it('writes nothing for a prompt left blank', function (): void {
    $this->artisan('imagekit:install')
        ->expectsQuestion('IMAGEKIT_PUBLIC_KEY (leave blank to skip)', '')
        ->expectsQuestion('IMAGEKIT_PRIVATE_KEY (input is hidden; leave blank to skip)', null)
        ->expectsQuestion('IMAGEKIT_URL_ENDPOINT (leave blank to skip)', '  ')
        ->expectsQuestion('IMAGEKIT_FOLDER (this application\'s root folder on ImageKit)', 'laravel')
        ->expectsOutputToContain('left blank')
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->assertSuccessful();

    expect(File::get($this->env))->toBe("APP_NAME=Laravel\nIMAGEKIT_FOLDER=laravel\n");
});

it('skips the prompt for a key that is already in .env and never rewrites it', function (): void {
    File::put($this->env, "APP_NAME=Laravel\nIMAGEKIT_PUBLIC_KEY=keep-me\n");

    $this->artisan('imagekit:install')
        ->expectsQuestion('IMAGEKIT_PRIVATE_KEY (input is hidden; leave blank to skip)', 'priv')
        ->expectsQuestion('IMAGEKIT_URL_ENDPOINT (leave blank to skip)', '')
        ->expectsQuestion('IMAGEKIT_FOLDER (this application\'s root folder on ImageKit)', '')
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->assertSuccessful();

    expect(File::get($this->env))->toBe("APP_NAME=Laravel\nIMAGEKIT_PUBLIC_KEY=keep-me\nIMAGEKIT_PRIVATE_KEY=priv\n");
});

it('warns and creates nothing when .env is absent', function (): void {
    File::delete($this->env);

    $this->artisan('imagekit:install')
        ->expectsOutputToContain('No .env file was found')
        ->expectsOutputToContain('IMAGEKIT_PRIVATE_KEY=')
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->assertSuccessful();

    expect(File::exists($this->env))->toBeFalse()
        ->and(File::get($this->example))->toBe($this->exampleBackup);
});

it('does not create .env.example when the app has none', function (): void {
    File::delete($this->example);

    $this->artisan('imagekit:install')
        ->expectsQuestion('IMAGEKIT_PUBLIC_KEY (leave blank to skip)', 'pub')
        ->expectsQuestion('IMAGEKIT_PRIVATE_KEY (input is hidden; leave blank to skip)', '')
        ->expectsQuestion('IMAGEKIT_URL_ENDPOINT (leave blank to skip)', '')
        ->expectsQuestion('IMAGEKIT_FOLDER (this application\'s root folder on ImageKit)', '')
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->assertSuccessful();

    expect(File::exists($this->example))->toBeFalse();
});

it('does not migrate when the confirmation is declined', function (): void {
    $this->artisan('imagekit:install')
        ->expectsQuestion('IMAGEKIT_PUBLIC_KEY (leave blank to skip)', '')
        ->expectsQuestion('IMAGEKIT_PRIVATE_KEY (input is hidden; leave blank to skip)', '')
        ->expectsQuestion('IMAGEKIT_URL_ENDPOINT (leave blank to skip)', '')
        ->expectsQuestion('IMAGEKIT_FOLDER (this application\'s root folder on ImageKit)', '')
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->expectsOutputToContain('Remaining step: run php artisan migrate.')
        ->assertSuccessful();

    expect(Schema::hasTable('media'))->toBeFalse();
});

it('does not publish the column migration when the table migration has no timestamp prefix', function (): void {
    File::put(database_path('migrations/x_create_media_table.php'), '<?php');

    nonInteractiveInstall()
        ->expectsOutputToContain('No published create_media_table migration was found')
        ->assertSuccessful();

    expect(File::glob(database_path('migrations/*_add_imagekit_pending_deletion_to_media_table.php')))->toBe([]);
});

it('ships a column migration that no-ops without the media table and with the column already present', function (): void {
    $migration = include __DIR__.'/../database/migrations/2026_08_18_000000_add_imagekit_pending_deletion_to_media_table.php';

    $migration->up();
    $migration->down();
    expect(Schema::hasTable('media'))->toBeFalse();

    $mediaTable = include __DIR__.'/../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';
    $mediaTable->up();

    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('media', 'imagekit_pending_deletion_at'))->toBeTrue();

    $migration->down();
    $migration->down();
    expect(Schema::hasColumn('media', 'imagekit_pending_deletion_at'))->toBeFalse();
});
