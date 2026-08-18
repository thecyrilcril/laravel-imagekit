<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Exceptions;

final class UnknownProfile extends ImageKitException
{
    /**
     * @param  list<string>  $available
     */
    public static function profile(string $name, array $available = []): self
    {
        return new self(sprintf(
            'Unknown ImageKit compression profile [%s]. Available: %s.',
            $name,
            $available === [] ? 'none' : implode(', ', $available),
        ));
    }

    /**
     * @param  list<string>  $available
     */
    public static function preset(string $name, array $available = []): self
    {
        return new self(sprintf(
            'Unknown ImageKit delivery preset [%s]. Available: %s.',
            $name,
            $available === [] ? 'none' : implode(', ', $available),
        ));
    }
}
