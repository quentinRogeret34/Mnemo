<?php

namespace App\Grooming;

use App\Document\DocumentTextExtractor;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\NodeType;
use App\Grooming\Dto\GroomingContext;
use App\Repository\DocumentRepository;
use App\Repository\MemoryRepository;
use App\Repository\RuleRepository;
use App\Search\HybridSearch;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Collects Mnemo's real knowledge graph context (rules, memories, documents, code files) for a project and topic,
 * and suggests grooming topics derived from existing rules, documents, and folders.
 */
final readonly class GroomingContextCollector
{
    private const DOC_EXCERPT_CHARS = 1500;
    private const MAX_RULES = 20;
    private const MAX_MEMORIES = 15;
    private const MAX_DOCUMENTS = 5;
    private const MAX_CODE_FILES = 15;

    public function __construct(
        private HybridSearch $hybridSearch,
        private RuleRepository $ruleRepository,
        private MemoryRepository $memoryRepository,
        private DocumentRepository $documentRepository,
        private DocumentTextExtractor $documentTextExtractor,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function collect(Project $project, string $topic, ?string $extraContext = null): GroomingContext
    {
        $query = trim($topic.(null !== $extraContext && '' !== trim($extraContext) ? ' '.mb_substr(trim($extraContext), 0, 200) : ''));
        $search = $this->hybridSearch->search(
            $query,
            $project,
            [
                NodeType::BusinessRule->value,
                NodeType::Memory->value,
                NodeType::Document->value,
                NodeType::CodeFile->value,
            ],
            24,
        );

        $matchedRuleCodes = [];
        $matchedMemoryIds = [];
        $matchedDocumentIds = [];
        $codeFilesByPath = [];

        foreach ($search['results'] as $row) {
            if (NodeType::BusinessRule->value === $row['type']) {
                $matchedRuleCodes[$row['code']] = true;
            } elseif (NodeType::Memory->value === $row['type']) {
                $matchedMemoryIds[$row['id']] = true;
            } elseif (NodeType::Document->value === $row['type']) {
                $matchedDocumentIds[$row['id']] = true;
            } elseif (NodeType::CodeFile->value === $row['type'] && null !== $row['path'] && '' !== $row['path']) {
                $codeFilesByPath[$row['path']] = [
                    'path' => $row['path'],
                    'label' => $row['label'],
                ];
            }
        }

        // Rules: prioritize topic-matched rules, then fill with existing project rules so the swarm sees coverage.
        $allRules = $this->ruleRepository->rules($project, null, 50);
        $prioritizedRules = [];
        $otherRules = [];
        foreach ($allRules as $rule) {
            $entry = [
                'code' => $rule['code'],
                'title' => $rule['title'],
                'statement' => $rule['statement'],
                'status' => $rule['status'],
                'shadow_areas' => $rule['shadow_areas'],
                'files' => $rule['files'],
            ];
            if (isset($matchedRuleCodes[$rule['code']]) || $this->matchesTopic($topic, $rule['title'].' '.($rule['statement'] ?? ''))) {
                $prioritizedRules[] = $entry;
            } else {
                $otherRules[] = $entry;
            }
        }
        $rules = \array_slice([...$prioritizedRules, ...$otherRules], 0, self::MAX_RULES);

        // Memories: topic-filtered memories + matched search IDs + recent project memories.
        $memoriesById = [];
        foreach ($this->memoryRepository->findFiltered($project, query: $topic, limit: self::MAX_MEMORIES) as $mem) {
            $memoriesById[$mem['id']] = [
                'id' => $mem['id'],
                'text' => $mem['text'],
                'type' => $mem['type'],
                'verdict' => $mem['verdict'],
            ];
        }
        foreach ($this->memoryRepository->findFiltered($project, limit: self::MAX_MEMORIES) as $mem) {
            if (\count($memoriesById) >= self::MAX_MEMORIES && !isset($matchedMemoryIds[$mem['id']])) {
                continue;
            }
            $memoriesById[$mem['id']] ??= [
                'id' => $mem['id'],
                'text' => $mem['text'],
                'type' => $mem['type'],
                'verdict' => $mem['verdict'],
            ];
        }
        $memories = \array_slice(array_values($memoriesById), 0, self::MAX_MEMORIES);

        // Documents: prioritize search-matched documents, then project documents, with extracted text excerpts.
        $projectDocs = $this->documentRepository->findPaginated($project, limit: 15);
        $prioritizedDocs = [];
        $otherDocs = [];
        foreach ($projectDocs as $doc) {
            if (isset($matchedDocumentIds[$doc['id']]) || $this->matchesTopic($topic, $doc['title'])) {
                $prioritizedDocs[] = $doc;
            } else {
                $otherDocs[] = $doc;
            }
        }
        $selectedDocs = \array_slice([...$prioritizedDocs, ...$otherDocs], 0, self::MAX_DOCUMENTS);
        $documents = [];
        foreach ($selectedDocs as $doc) {
            $excerpt = '';
            $node = $this->entityManager->find(Node::class, $doc['id']);
            if (null !== $node && NodeType::Document === $node->getType()) {
                $excerpt = mb_substr(trim($this->documentTextExtractor->extract($node)), 0, self::DOC_EXCERPT_CHARS);
            }
            $documents[] = [
                'id' => $doc['id'],
                'title' => $doc['title'],
                'format' => $doc['format'],
                'excerpt' => $excerpt,
            ];
        }

        // Code files: search results supplemented with project files if few matched.
        if (\count($codeFilesByPath) < self::MAX_CODE_FILES) {
            $fallbackFiles = $this->entityManager->getRepository(Node::class)->findBy(
                ['project' => $project, 'type' => NodeType::CodeFile],
                ['path' => 'ASC'],
                self::MAX_CODE_FILES,
            );
            foreach ($fallbackFiles as $fileNode) {
                $path = $fileNode->getPath();
                if (null === $path || '' === $path || isset($codeFilesByPath[$path])) {
                    continue;
                }
                $codeFilesByPath[$path] = [
                    'path' => $path,
                    'label' => $fileNode->getLabel(),
                ];
                if (\count($codeFilesByPath) >= self::MAX_CODE_FILES) {
                    break;
                }
            }
        }

        return new GroomingContext(
            topic: trim($topic),
            extraContext: null === $extraContext || '' === trim($extraContext) ? null : trim($extraContext),
            rules: $rules,
            memories: $memories,
            documents: $documents,
            codeFiles: \array_slice(array_values($codeFilesByPath), 0, self::MAX_CODE_FILES),
            searchMode: $search['mode'],
        );
    }

    /**
     * Suggests grooming topics derived from the project's existing rules, documents, and folders.
     *
     * @return list<string>
     */
    public function suggestTopics(Project $project, int $limit = 8): array
    {
        $suggestions = [];
        $seen = [];

        $add = static function (string $label) use (&$suggestions, &$seen, $limit): void {
            $clean = trim($label);
            if ('' === $clean || \count($suggestions) >= $limit) {
                return;
            }
            $key = mb_strtolower($clean);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $suggestions[] = mb_substr($clean, 0, 120);
        };

        $rules = $this->ruleRepository->rules($project, null, 20);
        // Rules with shadow areas or needing review make the best grooming topics first.
        usort($rules, static fn (array $a, array $b): int => ($b['shadow_areas'] <=> $a['shadow_areas']) ?: (('validated' !== $b['status']) <=> ('validated' !== $a['status'])));
        foreach ($rules as $rule) {
            $add($rule['title']);
        }

        foreach ($this->documentRepository->findPaginated($project, limit: 10) as $doc) {
            $add($doc['title']);
        }

        foreach ($this->documentRepository->folderTree() as $folder) {
            if ($folder['documents'] > 0) {
                $add($folder['name']);
            }
        }

        return $suggestions;
    }

    private function matchesTopic(string $topic, string $haystack): bool
    {
        $haystackLower = mb_strtolower($haystack);
        $tokens = preg_split('/[\s,;:\/\-_]+/u', mb_strtolower(trim($topic))) ?: [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 3 && str_contains($haystackLower, $token)) {
                return true;
            }
        }

        return false;
    }
}
