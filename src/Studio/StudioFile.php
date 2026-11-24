<?php

namespace App\Studio;

/**
 * One editable (or declarative read-only) file displayed in the project Studio editor.
 */
final readonly class StudioFile
{
    public function __construct(
        public string $ref,
        public string $group,
        public string $name,
        public string $path,
        public string $language,
        public bool $editable,
        public string $kind,
        public ?int $entityId,
        public string $description,
        public string $content,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }
}
