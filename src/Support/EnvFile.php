<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Support;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Append-only access to a dotenv file.
 *
 * The install command scaffolds `IMAGEKIT_*` keys into `.env` and
 * `.env.example`. It only ever adds lines, never edits existing ones, because
 * a key that is already there was put there on purpose: a value the user set
 * by hand is not ours to replace, and an install command that rewrites
 * credentials is worse than one that does nothing. `has()` is the check that
 * makes the command idempotent; `append()` is the single write path, and it
 * refuses any value that could span more than one line so a prompt answer can
 * never smuggle a second key into the file.
 */
final class EnvFile
{
    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return File::exists($this->path);
    }

    /**
     * Whether an uncommented `KEY=` line exists. A commented-out `# KEY=`
     * does not count: the app is not reading it, so the key is not set.
     */
    public function has(string $key): bool
    {
        if (! $this->exists()) {
            return false;
        }

        $pattern = '/^\s*'.preg_quote($key, '/').'=/m';

        return preg_match($pattern, File::get($this->path)) === 1;
    }

    /**
     * Append `KEY=value` as a new line, creating the file when it is missing.
     *
     * @throws InvalidArgumentException when the key is not a plain identifier
     *                                  or the value contains a control character
     */
    public function append(string $key, string $value): void
    {
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid env key [{$key}].");
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("Env value for [{$key}] contains a control character.");
        }

        $contents = $this->exists() ? File::get($this->path) : '';

        if ($contents !== '' && ! str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        File::put($this->path, $contents.$key.'='.$this->quote($value)."\n");
    }

    /**
     * Dotenv reads an unquoted value up to the first space or `#`, so values
     * containing either are wrapped in double quotes.
     */
    private function quote(string $value): string
    {
        if (preg_match('/[\s#"]/', $value) !== 1) {
            return $value;
        }

        return '"'.str_replace('"', '\"', $value).'"';
    }
}
