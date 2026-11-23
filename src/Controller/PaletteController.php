<?php

namespace App\Controller;

use App\Graph\GraphReader;
use App\Graph\KnowledgeReader;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class PaletteController extends AbstractController
{
    private const LIMIT = 5;

    public function __construct(
        private readonly ProjectScope $projectScope,
        private readonly KnowledgeReader $knowledgeReader,
        private readonly GraphReader $graphReader,
    ) {
    }

    #[Route('/palette', name: 'palette', methods: ['GET'], format: 'json')]
    public function search(#[MapQueryParameter] string $q = '', #[MapQueryParameter] ?string $project = null): JsonResponse
    {
        $project = $this->projectScope->bySlug($project);
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return $this->json(['memories' => [], 'files' => []]);
        }

        // Without a project, KnowledgeReader reads every project: a caller limited to some of them gets none.
        $memories = null === $project && null !== $this->projectScope->slugs() ? [] : $this->knowledgeReader->memories($project, query: $q, limit: self::LIMIT);
        $files = null === $project ? [] : $this->graphReader->searchFiles($project, $q, self::LIMIT)['files'];

        return $this->json([
            'memories' => array_map(fn (array $memory): array => ['label' => mb_strimwidth($memory['text'], 0, 120, '…'), 'url' => $this->generateUrl('memory_show', ['id' => $memory['id']])], $memories),
            'files' => array_map(fn (array $file): array => ['label' => $file['path'], 'url' => $this->generateUrl('file_show', ['slug' => $project->getSlug(), 'path' => $file['path']])], $files),
        ]);
    }
}
