<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Thecyrilcril\ImageKit\Support\EnvFile;

/**
 * Every write goes to a scratch directory created per test, so nothing can
 * leak into the shared Testbench skeleton and change other tests' behaviour.
 */
beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/imagekit-envfile-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->dir);
    $this->path = $this->dir.'/.env';
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

it('reports a key as absent on a fresh file and present after appending it', function (): void {
    File::put($this->path, "APP_NAME=Laravel\n");
    $env = new EnvFile($this->path);

    expect($env->has('IMAGEKIT_PUBLIC_KEY'))->toBeFalse();

    $env->append('IMAGEKIT_PUBLIC_KEY', 'abc');

    expect($env->has('IMAGEKIT_PUBLIC_KEY'))->toBeTrue()
        ->and(File::get($this->path))->toBe("APP_NAME=Laravel\nIMAGEKIT_PUBLIC_KEY=abc\n");
});

it('treats a missing file as empty and creates it on append', function (): void {
    $env = new EnvFile($this->path);

    expect($env->exists())->toBeFalse()
        ->and($env->has('IMAGEKIT_PUBLIC_KEY'))->toBeFalse();

    $env->append('IMAGEKIT_PUBLIC_KEY', 'abc');

    expect($env->exists())->toBeTrue()
        ->and(File::get($this->path))->toBe("IMAGEKIT_PUBLIC_KEY=abc\n");
});

it('does not glue the appended line onto a file without a trailing newline', function (): void {
    File::put($this->path, 'APP_NAME=Laravel');

    (new EnvFile($this->path))->append('IMAGEKIT_FOLDER', 'my-app');

    expect(File::get($this->path))->toBe("APP_NAME=Laravel\nIMAGEKIT_FOLDER=my-app\n");
});

it('does not count a commented-out line as present', function (): void {
    File::put($this->path, "# IMAGEKIT_PUBLIC_KEY=old\n");

    expect((new EnvFile($this->path))->has('IMAGEKIT_PUBLIC_KEY'))->toBeFalse();
});

it('does not count a key that merely shares a prefix as present', function (): void {
    File::put($this->path, "IMAGEKIT_PUBLIC_KEY_OLD=x\n");

    expect((new EnvFile($this->path))->has('IMAGEKIT_PUBLIC_KEY'))->toBeFalse();
});

it('quotes a value containing a space or a hash', function (string $value, string $expected): void {
    (new EnvFile($this->path))->append('IMAGEKIT_FOLDER', $value);

    expect(File::get($this->path))->toBe("IMAGEKIT_FOLDER={$expected}\n");
})->with([
    'space' => ['my app', '"my app"'],
    'hash' => ['app#1', '"app#1"'],
    'double quote' => ['say "hi"', '"say \"hi\""'],
]);

it('rejects a value containing a newline or another control character', function (string $value): void {
    File::put($this->path, "APP_NAME=Laravel\n");
    $env = new EnvFile($this->path);

    expect(fn () => $env->append('IMAGEKIT_PUBLIC_KEY', $value))
        ->toThrow(InvalidArgumentException::class)
        ->and(File::get($this->path))->toBe("APP_NAME=Laravel\n");
})->with([
    'newline' => ["abc\nEVIL=1"],
    'carriage return' => ["abc\rEVIL=1"],
    'null byte' => ["abc\0"],
]);

it('rejects a key that is not a plain env identifier', function (): void {
    $env = new EnvFile($this->path);

    expect(fn () => $env->append('BAD KEY', 'x'))->toThrow(InvalidArgumentException::class)
        ->and($env->exists())->toBeFalse();
});

it('never modifies existing lines', function (): void {
    $before = "APP_NAME=Laravel\n# IMAGEKIT_PUBLIC_KEY=old\nAPP_DEBUG=true\n";
    File::put($this->path, $before);

    (new EnvFile($this->path))->append('IMAGEKIT_PUBLIC_KEY', 'abc');

    expect(File::get($this->path))->toStartWith($before)
        ->and(File::get($this->path))->toBe($before."IMAGEKIT_PUBLIC_KEY=abc\n");
});
