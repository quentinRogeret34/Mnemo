<?php

namespace App\Factory\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * JSON payload accepted by POST /hub/swarms/{publicId}/nodes/join.
 */
final readonly class HubJoinPayload
{
    /**
     * @param array<int|string, mixed> $capabilities
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name,
        #[Assert\Length(max: 100)]
        public ?string $client = null,
        #[Assert\Length(max: 60)]
        public string $role = 'developer',
        #[Assert\Length(max: 200)]
        public ?string $branch = null,
        public array $capabilities = [],
    ) {
    }
}
