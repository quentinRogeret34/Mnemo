<?php

namespace App\Factory\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * JSON payload accepted by POST /hub/swarms/{publicId}/orders/{orderPublicId}/complete.
 */
final readonly class HubOrderCompletePayload
{
    /**
     * @param list<string> $files
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 8000)]
        public string $summary,
        #[Assert\Choice(choices: ['completed', 'failed'])]
        public string $status = 'completed',
        public ?string $output = null,
        public array $files = [],
        #[Assert\Length(max: 200)]
        public ?string $branch = null,
        #[Assert\Length(max: 40)]
        public ?string $nodeId = null,
    ) {
    }
}
