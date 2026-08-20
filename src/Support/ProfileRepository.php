<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Support;

use Thecyrilcril\ImageKit\Data\CompressionProfile;
use Thecyrilcril\ImageKit\Exceptions\InvalidProfile;
use Thecyrilcril\ImageKit\Exceptions\UnknownProfile;

final readonly class ProfileRepository
{
    /**
     * @param  array<string, array<string, mixed>>  $profiles
     * @param  array<string, array<string, mixed>>  $presets
     */
    public function __construct(
        private array $profiles,
        private array $presets,
    ) {}

    /**
     * @throws UnknownProfile
     * @throws InvalidProfile
     */
    public function profile(?string $name): CompressionProfile
    {
        $key = $name ?? 'default';

        if (! isset($this->profiles[$key])) {
            throw UnknownProfile::profile($key, array_keys($this->profiles));
        }

        return CompressionProfile::fromArray($this->profiles[$key], $key);
    }

    /**
     * @return array<string, mixed>
     */
    public function preset(?string $name): array
    {
        $key = $name ?? 'default';

        if (! isset($this->presets[$key])) {
            throw UnknownProfile::preset($key, array_keys($this->presets));
        }

        return $this->presets[$key];
    }
}
