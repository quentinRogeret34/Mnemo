<?php

namespace App\Graph;

use App\Entity\Node;
use App\Entity\Project;
use App\Repository\DocumentRepository;
use App\Repository\KnowledgeSearchRepository;
use App\Repository\MemoryRepository;
use App\Repository\ProposalRepository;
use App\Repository\RuleRepository;

/**
 * Facade delegating graph read queries to domain-focused repositories under App\Repository.
 */
final readonly class KnowledgeReader
{
    public function __construct(
        private MemoryRepository $memories,
        private DocumentRepository $documents,
        private RuleRepository $rules,
        private KnowledgeSearchRepository $search,
        private ProposalRepository $proposals,
    ) {
    }

    /**
     * @return array{documents: int, memories: int, rules: int, rules_to_review: int, files: int}
     */
    public function counts(Project $project): array
    {
        return $this->search->countsByProject([$project->getId()])[$project->getId()];
    }

    /**
     * @param list<int> $projectIds
     *
     * @return array<int, array{documents: int, memories: int, rules: int, rules_to_review: int, files: int}>
     */
    public function countsByProject(array $projectIds): array
    {
        return $this->search->countsByProject($projectIds);
    }

    public function memoryCount(): int
    {
        return $this->memories->countActive();
    }

    /**
     * @return list<array{id: int, text: string, type: string, source: ?string, status: string, verdict: ?string, demo: bool, created_at: string, updated_at: string, projects: ?string, links: int, total: int}>
     */
    public function memories(?Project $project = null, ?string $type = null, string $query = '', bool $archived = false, ?string $verdict = null, ?string $source = null, ?int $limit = null, int $offset = 0): array
    {
        return $this->memories->findFiltered($project, $type, $query, $archived, $verdict, $source, $limit, $offset);
    }

    /**
     * @return list<string>
     */
    public function memorySources(): array
    {
        return $this->memories->sources();
    }

    /**
     * @return array{memories: list<array{id: int, text: string, type: ?string}>, links: list<array{memory_id: int, id: int, type: string, label: string, path: ?string, code: string, project_slug: ?string}>, projects: list<array{memory_id: int, slug: string, name: string}>}
     */
    public function memoryGalaxy(?Project $project, int $limit = 300): array
    {
        return $this->memories->galaxy($project, $limit);
    }

    /**
     * @return list<array{edge_id: int, id: int, type: string, label: string, path: ?string, code: string, format: ?string, project_slug: ?string, project_name: ?string}>
     */
    public function memoryLinks(Node $memory): array
    {
        return $this->memories->links($memory);
    }

    /**
     * @return list<array{id: int, type: string, label: string, path: ?string, code: string, project_name: ?string}>
     */
    public function memoryLinkables(Node $memory, string $query, int $limit = 10): array
    {
        return $this->memories->linkables($memory, $query, $limit);
    }

    /**
     * @return list<array{id: int, text: string, type: string, demo: bool, verdict: ?string, adr: ?array<string, string>}>
     */
    public function memoriesAbout(int $nodeId): array
    {
        return $this->memories->aboutNode($nodeId);
    }

    /**
     * @return list<array{id: int, title: string, filename: string, format: string, size: int, demo: bool, created_at: string, rules: int, folder: ?string, projects: ?string, code_links: int, total: int}>
     */
    public function documents(?Project $project = null, ?int $folderId = null, ?int $limit = null, int $offset = 0): array
    {
        return $this->documents->findPaginated($project, $folderId, $limit, $offset);
    }

    /**
     * @return list<array{id: int, title: string, format: string, folder_id: ?int}>
     */
    public function documentTree(): array
    {
        return $this->documents->documentTree();
    }

    /**
     * @return list<array{id: int, name: string, parent_id: ?int, depth: int, documents: int}>
     */
    public function folderTree(): array
    {
        return $this->documents->folderTree();
    }

    /**
     * @return list<array{edge_id: int, path: string, project_slug: string, project_name: string, confidence: string, origin: string}>
     */
    public function documentCodeLinks(Node $document): array
    {
        return $this->documents->codeLinks($document);
    }

    /**
     * @return list<array{id: int, path: string, project_name: string}>
     */
    public function linkableFiles(Node $document, string $query, int $limit = 8): array
    {
        return $this->documents->linkableFiles($document, $query, $limit);
    }

    /**
     * @return list<array{id: int, title: string, format: string, confidence: string, origin: string}>
     */
    public function documentsForFile(Project $project, string $path): array
    {
        return $this->documents->forFile($project, $path);
    }

    /**
     * @return list<array{code: string, title: string, statement: ?string, status: ?string, confidence: string, origin: string, project_slug: string}>
     */
    public function rulesDefinedBy(Node $document): array
    {
        return $this->rules->definedBy($document);
    }

    /**
     * @return list<array{code: string, title: string, statement: ?string, status: ?string, demo: bool, documents: int, files: int, verified_files: int, shadow_areas: int, total: int}>
     */
    public function rules(Project $project, ?string $status = null, ?int $limit = null, int $offset = 0): array
    {
        return $this->rules->rules($project, $status, $limit, $offset);
    }

    /**
     * @return list<array{id: int, title: string, filename: string, format: string, confidence: string, origin: string}>
     */
    public function ruleSources(Node $rule): array
    {
        return $this->rules->ruleSources($rule);
    }

    /**
     * @return list<array{path: string, label: string, type: string, confidence: string, origin: string}>
     */
    public function ruleImplementations(Node $rule): array
    {
        return $this->rules->ruleImplementations($rule);
    }

    /**
     * @return list<array{type: string, ref: string, label: string, author: ?string, date: ?string, url: ?string, state: ?string}>
     */
    public function ruleMentions(Node $rule): array
    {
        return $this->rules->mentions($rule);
    }

    /**
     * @return list<array{code: string, title: string, status: ?string, confidence: string, origin: string}>
     */
    public function rulesForFile(Project $project, string $path): array
    {
        return $this->rules->forFile($project, $path);
    }

    /**
     * @param list<string> $types
     *
     * @return list<array{id: int, type: string, label: string, path: ?string, code: string, project_slug: ?string, snippet: ?string}>
     */
    public function search(string $query, ?Project $project, array $types, int $limit): array
    {
        return $this->search->search($query, $project, $types, $limit);
    }

    /**
     * @param list<string> $types
     * @param list<int>    $exclude
     *
     * @return list<array{id: int, type: string, label: string, code: string, path: ?string, statement: ?string}>
     */
    public function related(string $text, Project $project, array $types, int $limit, array $exclude = []): array
    {
        return $this->search->related($text, $project, $types, $limit, $exclude);
    }

    /**
     * @return array{replaces: list<array{id: int, text: string, status: string}>, replaced_by: list<array{id: int, text: string, status: string}>}
     */
    public function supersession(Node $memory): array
    {
        return $this->memories->supersession($memory);
    }

    public function pendingProposals(?Project $project = null): int
    {
        return $this->proposals->countPending($project);
    }

    /**
     * @param list<int> $projectIds
     *
     * @return array<int, int>
     */
    public function pendingProposalsByProject(array $projectIds): array
    {
        return $this->proposals->countPendingByProject($projectIds);
    }
}
