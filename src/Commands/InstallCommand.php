<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Thecyrilcril\ImageKit\ImageKitUrlBuilder;
use Thecyrilcril\ImageKit\Support\EnvFile;

/**
 * One-step setup for this package and spatie/laravel-medialibrary.
 *
 * After `composer require` there are four things to do before the first
 * upload works: publish both configs, point media-library's `url_generator`
 * at this package's builder, set the `IMAGEKIT_*` env keys, and migrate.
 * Each is a chance to stop halfway or mistype a key name. This command does
 * them in order, in one run.
 *
 * It is safe to run again. Publishing never overwrites an existing file, the
 * config edit no-ops once the builder is in place, and an env key that is
 * already set is left alone and its prompt skipped. Where the command cannot
 * be certain a write is correct — an unrecognised `url_generator` line, say —
 * it prints the one-line manual instruction instead of guessing.
 *
 * With --no-interaction it publishes everything, prints the env block for
 * copy-paste, and leaves `.env` and the database untouched.
 */
final class InstallCommand extends Command
{
    private const string URL_GENERATOR_VALUE = '\\'.ImageKitUrlBuilder::class.'::class';

    private const string COLUMN_MIGRATION = 'add_imagekit_pending_deletion_to_media_table';

    /** @var list<string> */
    private const array ENV_KEYS = [
        'IMAGEKIT_PUBLIC_KEY',
        'IMAGEKIT_PRIVATE_KEY',
        'IMAGEKIT_URL_ENDPOINT',
        'IMAGEKIT_FOLDER',
    ];

    /** @var string */
    protected $signature = 'imagekit:install';

    /** @var string */
    protected $description = 'Publish the configs and migrations, point media-library at ImageKit, and set up the env keys';

    public function handle(): int
    {
        $this->components->info('Publishing configuration and migrations.');

        $this->publish('imagekit-config', config_path('imagekit.php'));
        $this->publish('medialibrary-config', config_path('media-library.php'));
        $this->publish('medialibrary-migrations', $this->migrationPath('create_media_table'));
        $this->publishColumnMigration();

        $this->pointUrlGenerator();
        $this->scaffoldEnv();
        $this->offerMigrations();

        $this->newLine();
        $this->components->info(
            'Done. Next, mark a media collection with ->toImageKit() on your model — '
            .'see "Prepare the model" in the README.'
        );

        return self::SUCCESS;
    }

    /**
     * `vendor:publish` without --force already skips existing files, which is
     * what makes re-running safe; the existence check is only so the report
     * can say which happened.
     */
    private function publish(string $tag, ?string $target): void
    {
        $existed = $target !== null && File::exists($target);

        if (! $existed) {
            $this->callSilently('vendor:publish', ['--tag' => $tag]);
        }

        $this->report(sprintf('Publish %s', $tag), $existed ? 'SKIPPED (already published)' : 'DONE');
    }

    /**
     * The package's own migration adds a column to the `media` table, and the
     * migrator sorts by filename, so its vendor date runs before the
     * `create_media_table` migration published today. A copy timestamped one
     * second after that file puts a fresh app in the right order; the vendor
     * copy stays registered and guards itself against the missing table.
     */
    private function publishColumnMigration(): void
    {
        $existing = $this->migrationPath(self::COLUMN_MIGRATION);

        if ($existing !== null) {
            $this->report('Publish column migration', 'SKIPPED (already published)');

            return;
        }

        $table = $this->migrationPath('create_media_table');
        $timestamp = $table === null ? null : $this->timestampAfter(basename($table));

        if ($timestamp === null) {
            $this->components->warn(
                'No published create_media_table migration was found, so the '
                .self::COLUMN_MIGRATION.' migration was not published. The copy shipped with the '
                .'package still runs once the media table exists.'
            );

            return;
        }

        File::copy(
            __DIR__.'/../../database/migrations/2026_08_18_000000_'.self::COLUMN_MIGRATION.'.php',
            database_path('migrations/'.$timestamp.'_'.self::COLUMN_MIGRATION.'.php'),
        );

        $this->report('Publish column migration', 'DONE');
    }

    /**
     * The first migration in the app whose name ends with the given suffix.
     */
    private function migrationPath(string $suffix): ?string
    {
        $matches = File::glob(database_path('migrations/*_'.$suffix.'.php'));

        return $matches[0] ?? null;
    }

    /**
     * Null when the filename does not start with Laravel's Y_m_d_His prefix.
     */
    private function timestampAfter(string $filename): ?string
    {
        $prefix = substr($filename, 0, 17);
        $time = DateTimeImmutable::createFromFormat('!Y_m_d_His', $prefix);

        return $time === false ? null : $time->modify('+1 second')->format('Y_m_d_His');
    }

    /**
     * A bounded single-line edit that fails closed. Only a value that is a
     * `::class` constant or a quoted class-string with no commas or
     * parentheses is rewritten; any other shape gets the manual instruction,
     * because writing back a half-matched expression is the one outcome a
     * setup command must never produce.
     */
    private function pointUrlGenerator(): void
    {
        $path = config_path('media-library.php');

        if (! File::exists($path)) {
            $this->urlGeneratorFallback('config/media-library.php was not found.');

            return;
        }

        $contents = File::get($path);
        $pattern = '/^([ \t]*\'url_generator\'\s*=>\s*)(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*::class|\'[^\'\n,()]+\'|"[^"\n,()]+")(\s*,)/m';

        if (preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            $this->urlGeneratorFallback('No url_generator line it recognises was found in config/media-library.php.');

            return;
        }

        [$value, $offset] = $match[2];

        if (str_contains($value, 'ImageKitUrlBuilder')) {
            $this->report('Point url_generator at ImageKitUrlBuilder', 'SKIPPED (already set)');

            return;
        }

        File::put($path, substr_replace($contents, self::URL_GENERATOR_VALUE, $offset, strlen($value)));

        $this->report('Point url_generator at ImageKitUrlBuilder', 'DONE');
    }

    private function report(string $step, string $status): void
    {
        $this->components->twoColumnDetail($step, $status);
    }

    private function urlGeneratorFallback(string $reason): void
    {
        $this->components->warn($reason.' Set this line in config/media-library.php yourself:');
        $this->line("    'url_generator' => ".self::URL_GENERATOR_VALUE.',');
    }

    private function scaffoldEnv(): void
    {
        $env = new EnvFile(base_path('.env'));

        if (! $this->input->isInteractive()) {
            $this->components->info('Non-interactive run: add these keys to .env yourself.');
            $this->printEnvBlock();

            return;
        }

        if (! $env->exists()) {
            $this->components->warn('No .env file was found, so nothing was written. Add these keys once it exists:');
            $this->printEnvBlock();

            return;
        }

        foreach (self::ENV_KEYS as $key) {
            if ($env->has($key)) {
                $this->report(sprintf('Set %s', $key), 'SKIPPED (already in .env)');

                continue;
            }

            $answer = $this->promptFor($key);

            if ($answer === '') {
                $this->report(sprintf('Set %s', $key), 'SKIPPED (left blank)');

                continue;
            }

            $env->append($key, $answer);
            $this->report(sprintf('Set %s', $key), 'DONE');
        }

        $this->scaffoldEnvExample();
    }

    private function promptFor(string $key): string
    {
        $answer = match ($key) {
            'IMAGEKIT_PRIVATE_KEY' => $this->secret('IMAGEKIT_PRIVATE_KEY (input is hidden; leave blank to skip)', false),
            'IMAGEKIT_FOLDER' => $this->ask('IMAGEKIT_FOLDER (this application\'s root folder on ImageKit)', $this->defaultFolder()),
            default => $this->ask(sprintf('%s (leave blank to skip)', $key)),
        };

        return is_string($answer) ? trim($answer) : '';
    }

    private function defaultFolder(): string
    {
        $slug = Str::slug((string) config('app.name'));

        return $slug === '' ? 'uploads' : $slug;
    }

    /**
     * Placeholders only, never values: `.env.example` is committed.
     */
    private function scaffoldEnvExample(): void
    {
        $example = new EnvFile(base_path('.env.example'));

        if (! $example->exists()) {
            return;
        }

        foreach (self::ENV_KEYS as $key) {
            if (! $example->has($key)) {
                $example->append($key, '');
            }
        }

        $this->report('Add placeholders to .env.example', 'DONE');
    }

    private function printEnvBlock(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->line(sprintf('    %s=%s', $key, $key === 'IMAGEKIT_FOLDER' ? $this->defaultFolder() : ''));
        }
    }

    /**
     * Interactivity is checked first on purpose: confirm() under
     * --no-interaction does not skip, it silently returns its default, and a
     * CI run must never migrate because of a default.
     */
    private function offerMigrations(): void
    {
        if ($this->input->isInteractive() && $this->confirm('Run the migrations now?', true)) {
            $this->call('migrate');

            return;
        }

        $this->components->info('Remaining step: run php artisan migrate.');
    }
}
