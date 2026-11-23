<?php

namespace App\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PromptContextRequest
{
    public function __construct(
        public string $cwd = '',
        #[Assert\Length(max: 20_000)]
        public string $prompt = '',
        #[Assert\Length(max: 100)]
        public string $project = '',
        #[Assert\Length(max: 255)]
        public string $repository = '',
    ) {
    }
}
