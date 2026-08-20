<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Exceptions;

/**
 * Thrown the first time a misconfigured compression profile is read, not at
 * boot, so a broken profile only breaks the uploads that use it.
 */
final class InvalidProfile extends ImageKitException
{
    public static function quality(string $profile, mixed $value): self
    {
        return self::field($profile, 'quality', 'must be an integer between 1 and 100', $value);
    }

    public static function maxEdge(string $profile, mixed $value): self
    {
        return self::field($profile, 'max_edge', 'must be an integer of at least 1', $value);
    }

    public static function format(string $profile, mixed $value): self
    {
        return self::field($profile, 'format', 'must be a string or null', $value);
    }

    private static function field(string $profile, string $field, string $rule, mixed $value): self
    {
        return new self(sprintf(
            'Invalid ImageKit compression profile [%s]: %s %s, got %s.',
            $profile,
            $field,
            $rule,
            self::describe($value),
        ));
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => sprintf('"%s"', $value),
            is_scalar($value) => var_export($value, true),
            default => get_debug_type($value),
        };
    }
}
