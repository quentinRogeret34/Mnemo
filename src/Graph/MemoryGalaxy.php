<?php

namespace App\Graph;

use App\Entity\Project;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Memories as satellites of what they are about: code files, business rules and documents are the hubs.
 * A memory about nothing orbits its projects, or a "no link" hub when it has none.
 */
final class MemoryGalaxy
{
    public function __construct(
        private readonly KnowledgeReader $knowledgeReader,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array{source: string, target: string}>}
     */
    public function build(?Project $project): array
    {
        $data = $this->knowledgeReader->memoryGalaxy($project);
        $nodes = $edges = $linked = [];

        foreach ($data['memories'] as $memory) {
            $nodes['m'.$memory['id']] = ['id' => 'm'.$memory['id'], 'kind' => 'memory', 'type' => $memory['type'] ?? 'fact', 'label' => $memory['text'], 'url' => $this->urlGenerator->generate('memory_show', ['id' => $memory['id']])];
        }
        foreach ($data['links'] as $link) {
            $hub = 'n'.$link['id'];
            $nodes[$hub] ??= ['id' => $hub, 'kind' => $link['type'], 'label' => 'business_rule' === $link['type'] ? $link['code'].' '.$link['label'] : ($link['path'] ?? $link['label']), 'url' => $this->hubUrl($link), 'weight' => 0];
            ++$nodes[$hub]['weight'];
            $edges[] = ['source' => 'm'.$link['memory_id'], 'target' => $hub];
            $linked[$link['memory_id']] = true;
        }
        $orphans = array_diff_key(array_column($data['memories'], 'id', 'id'), $linked);
        foreach ($data['projects'] as $membership) {
            if (!isset($orphans[$membership['memory_id']])) {
                continue;
            }
            $hub = 'p'.$membership['slug'];
            $nodes[$hub] ??= ['id' => $hub, 'kind' => 'project', 'label' => $membership['name'], 'url' => $this->urlGenerator->generate('project_show', ['slug' => $membership['slug']]), 'weight' => 0];
            ++$nodes[$hub]['weight'];
            $edges[] = ['source' => 'm'.$membership['memory_id'], 'target' => $hub];
            $linked[$membership['memory_id']] = true;
        }
        foreach (array_diff_key($orphans, $linked) as $id) {
            $nodes['none'] ??= ['id' => 'none', 'kind' => 'none', 'label' => 'Sans projet ni lien', 'url' => null, 'weight' => 0];
            ++$nodes['none']['weight'];
            $edges[] = ['source' => 'm'.$id, 'target' => 'none'];
        }

        return ['nodes' => array_values($nodes), 'edges' => $edges];
    }

    /**
     * @param array{id: int, type: string, path: ?string, code: string, project_slug: ?string} $link
     */
    private function hubUrl(array $link): ?string
    {
        return match (true) {
            'document' === $link['type'] => $this->urlGenerator->generate('document_show', ['id' => $link['id']]),
            null === $link['project_slug'] => null,
            'business_rule' === $link['type'] => $this->urlGenerator->generate('rule_show', ['slug' => $link['project_slug'], 'code' => $link['code']]),
            default => $this->urlGenerator->generate('file_show', ['slug' => $link['project_slug'], 'path' => $link['path']]),
        };
    }
}
