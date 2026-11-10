<?php

namespace App\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class LearnRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $cwd = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 1_000_000)]
        public string $text = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $label = '',
        #[Assert\Choice(choices: ['conversation', 'file', 'diff', 'review'])]
        public string $kind = 'conversation',
    ) {
    }
}
