<?php

namespace App\Factory\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * JSON payload accepted by POST /hub/swarms/{publicId}/orders/{orderPublicId}/progress.
 */
final readonly class HubOrderProgressPayload
{
    /**
     * @param list<string> $files
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 8000)]
        public string $message,
        #[Assert\Length(max: 40)]
        public ?string $nodeId = null,
        public array $files = [],
    ) {
    }
}
