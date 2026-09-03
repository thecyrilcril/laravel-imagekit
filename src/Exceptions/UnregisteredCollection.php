<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Exceptions;

final class UnregisteredCollection extends ImageKitException
{
    public static function awaited(string $collection): self
    {
        return new self(sprintf(
            'Cannot await the ImageKit upload for collection [%s]: it is not registered with ->toImageKit(). Add it in registerMediaCollections().',
            $collection,
        ));
    }
}
