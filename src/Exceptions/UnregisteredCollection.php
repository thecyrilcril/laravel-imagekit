<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Exceptions;

final class UnregisteredCollection extends ImageKitException
{
    public function __construct(public readonly string $collection, string $message)
    {
        parent::__construct($message);
    }

    /**
     * Thrown from MediaAddedListener, which runs after media-library has
     * stored the file and saved the row. The row therefore survives the
     * exception with its local URL and no ImageKit file; the caller decides
     * whether to keep or delete it.
     */
    public static function awaited(string $collection): self
    {
        return new self($collection, sprintf(
            'Cannot await the ImageKit upload for collection [%s]: it is not registered with ->toImageKit(). Add it in registerMediaCollections().',
            $collection,
        ));
    }
}
