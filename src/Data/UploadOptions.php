<?php

declare(strict_types=1);

namespace Thecyrilcril\ImageKit\Data;

final readonly class UploadOptions
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $fileName,
        public ?string $folder = null,
        public array $tags = [],
        public bool $useUniqueFileName = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $options = [
            'fileName' => $this->fileName,
            'useUniqueFileName' => $this->useUniqueFileName,
        ];

        if ($this->folder !== null && $this->folder !== '') {
            $options['folder'] = $this->folder;
        }

        if ($this->tags !== []) {
            $options['tags'] = $this->tags;
        }

        return $options;
    }
}
