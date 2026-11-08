<?php

namespace App\Twig\Components;

use App\Entity\Project;
use App\Graph\GraphReader;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class FileSearch
{
    use DefaultActionTrait;

    #[LiveProp]
    public Project $project;

    #[LiveProp(writable: true, url: true)]
    public string $query = '';

    public function __construct(
        private readonly GraphReader $graphReader,
    ) {
    }

    /**
     * @return array{total: int, files: list<array{path: string, symbols: int, matched_symbols: ?string}>}
     */
    public function getResults(): array
    {
        return $this->graphReader->searchFiles($this->project, $this->query);
    }
}
