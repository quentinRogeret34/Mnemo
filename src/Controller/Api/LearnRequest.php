<?php

namespace App\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class LearnRequest
{
    public function __construct(
        public string $cwd = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 1_000_000)]
        public string $text = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $label = '',
        #[Assert\Choice(choices: ['conversation', 'file', 'diff', 'review'])]
        public string $kind = 'conversation',
        #[Assert\Length(max: 100)]
        public string $project = '',
        #[Assert\Length(max: 255)]
        public string $repository = '',
    ) {
    }

    #[Assert\IsTrue(message: 'Send the project slug, the repository or the working directory.')]
    public function hasProjectOrDirectory(): bool
    {
        return '' !== $this->project || '' !== $this->repository || '' !== $this->cwd;
    }
}
