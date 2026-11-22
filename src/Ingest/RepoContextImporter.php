<?php

namespace App\Ingest;

use App\Document\DocumentStorage;
use App\Entity\Folder;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Memory\MemoryStore;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Imports what a repository already says to its agents, without LLM:
 * - an AIDD memory bank (github.com/ai-driven-dev/framework, MIT): one memory per top-level bullet of
 *   aidd_docs/memory/*.md, one decision per ADR of aidd_docs/memory/internal/decisions, notes and plans as documents;
 * - agent conventions (CLAUDE.md, AGENTS.md, Copilot instructions, Claude / Cursor / Copilot / AIDD rules) as documents;
 * - the README files of the root and the Markdown files of docs/ as documents.
 * Files gone from the repository are reported, never deleted.
 */
final class RepoContextImporter
{
    private const BANK = 'aidd_docs/memory';
    private const DECISIONS = 'aidd_docs/memory/internal/decisions';
    private const CONVENTION_FILES = ['CLAUDE.md', 'AGENTS.md', '.github/copilot-instructions.md'];
    private const CONVENTION_DIRS = ['.claude/rules', '.cursor/rules', '.github/instructions', 'aidd_docs/rules'];
    private const DOCS_DIRS = ['docs', 'doc'];
    private const MAX_DOCS = 200;
    private const MAX_FILE_BYTES = 1_000_000;
    private const ADR_SECTIONS = ['context' => 'context', 'decision' => 'decision', 'alternatives' => 'alternatives', 'consequences' => 'consequences'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MemoryStore $memoryStore,
        private readonly DocumentStorage $documentStorage,
    ) {
    }

    public function import(Project $project, string $root): RepoContextImportResult
    {
        $root = rtrim($root, '/');
        $result = new RepoContextImportResult();

        $this->importBankMemories($project, $root, $result);
        $this->importDecisions($project, $root, $result);

        $notes = $this->files($root, [self::BANK.'/internal', self::BANK.'/external'], '*.md', depth: 0);
        $plans = $this->files($root, ['aidd_docs/tasks'], 'plan.md');
        $conventions = [
            ...array_values(array_filter(self::CONVENTION_FILES, static fn (string $path): bool => is_file($root.'/'.$path))),
            ...$this->files($root, self::CONVENTION_DIRS, ['*.md', '*.mdc']),
        ];
        $docs = array_values(array_diff([...array_map('basename', glob($root.'/[Rr][Ee][Aa][Dd][Mm][Ee]*.md') ?: []), ...$this->files($root, self::DOCS_DIRS, '*.md')], $conventions));
        if (\count($docs) > self::MAX_DOCS) {
            $result->skipped[] = \sprintf('%d fichiers Markdown au-delà des %d premiers', \count($docs) - self::MAX_DOCS, self::MAX_DOCS);
            $docs = \array_slice($docs, 0, self::MAX_DOCS);
        }
        $seen = [];
        foreach (['AIDD/'.$project->getSlug() => $notes, 'AIDD/'.$project->getSlug().'/Plans' => $plans, 'Conventions agents/'.$project->getSlug() => $conventions, 'Dépôt/'.$project->getSlug() => $docs] as $folderPath => $paths) {
            if ([] === $paths) {
                continue;
            }
            $targetFolder = $this->documentStorage->folder($folderPath);
            foreach ($paths as $path) {
                $seen[] = $this->importDocument($project, $root, $path, $targetFolder, $result);
            }
        }
        $this->entityManager->flush();

        $result->missing = [...$result->missing, ...$this->missingDocuments($project, array_filter($seen))];

        return $result;
    }

    private function importBankMemories(Project $project, string $root, RepoContextImportResult $result): void
    {
        $seen = [];
        foreach ($this->files($root, [self::BANK], '*.md', depth: 0) as $path) {
            if ('README.md' === basename($path)) {
                continue;
            }
            $markdown = $this->read($root, $path, $result);
            if (null === $markdown) {
                continue;
            }

            $type = 'coding-assertions.md' === basename($path) ? MemoryType::Convention : MemoryType::Fact;
            foreach ($this->bullets($markdown, basename($path, '.md')) as $text) {
                if (mb_strlen($text) > 1000) {
                    $result->skipped[] = \sprintf('%s : puce de plus de 1000 caractères', $path);
                    continue;
                }
                $key = mb_strtolower($text);
                $memory = $seen[$key] ?? $this->memoryStore->findByText($text);
                if (null === $memory) {
                    $memory = $this->memoryStore->add($text, $type, 'aidd', ['file' => $path]);
                    ++$result->memoriesCreated;
                } else {
                    ++$result->memoriesUnchanged;
                }
                $memory->addProject($project);
                $seen[$key] = $memory;
            }
        }
        $this->entityManager->flush();
    }

    private function importDecisions(Project $project, string $root, RepoContextImportResult $result): void
    {
        $decisions = [];
        $links = [];
        foreach ($this->files($root, [self::DECISIONS], '*.md', depth: 0) as $path) {
            $markdown = $this->read($root, $path, $result);
            if (null === $markdown) {
                continue;
            }
            $adr = $this->parseAdr($markdown);
            if (null === $adr) {
                $result->skipped[] = \sprintf('%s : pas de titre', $path);
                continue;
            }

            $origin = 'aidd:'.$project->getSlug().':'.$path;
            $memory = $this->findByOrigin($origin);
            if (null === $memory) {
                $memory = $this->memoryStore->add($adr['title'], MemoryType::Decision, 'aidd', ['origin' => $origin, 'file' => $path]);
                $this->memoryStore->setAdr($memory, $adr['record']);
                ++$result->decisionsCreated;
            } else {
                $before = [$memory->getLabel(), $memory->getMetadata()['adr'] ?? []];
                $this->memoryStore->update($memory, $adr['title'], MemoryType::Decision);
                $this->memoryStore->setAdr($memory, $adr['record']);
                $before === [$memory->getLabel(), $memory->getMetadata()['adr'] ?? []] ? ++$result->decisionsUnchanged : ++$result->decisionsUpdated;
            }
            $memory->addProject($project);
            $decisions[basename($path, '.md')] = $memory;
            $links[basename($path, '.md')] = $adr['links'];
        }
        $this->entityManager->flush();

        foreach ($links as $name => $link) {
            $pairs = [
                ...array_map(static fn (string $old): array => [$old, $name], $link['supersedes']),
                ...array_map(static fn (string $new): array => [$name, $new], $link['superseded_by']),
            ];
            foreach ($pairs as [$old, $new]) {
                if (!isset($decisions[$old], $decisions[$new])) {
                    $result->skipped[] = \sprintf('%s : décision liée "%s" introuvable', $name, $old === $name ? $new : $old);
                    continue;
                }
                $this->memoryStore->archive($decisions[$old], MemoryStore::STATUS_SUPERSEDED, $decisions[$new]);
            }
        }
        $this->entityManager->flush();

        $known = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT metadata->>'file' FROM node WHERE type = :memory AND metadata->>'origin' LIKE :prefix ORDER BY 1",
            ['memory' => NodeType::Memory->value, 'prefix' => addcslashes('aidd:'.$project->getSlug().':', '\\%_').'%'],
        );
        $result->missing = array_values(array_filter($known, static fn (string $file): bool => !isset($decisions[basename($file, '.md')])));
    }

    /**
     * @return string|null the document origin, null when the file was skipped
     */
    private function importDocument(Project $project, string $root, string $path, ?Folder $folder, RepoContextImportResult $result): ?string
    {
        $content = $this->read($root, $path, $result);
        if (null === $content) {
            return null;
        }

        $origin = 'repo:'.$project->getSlug().':'.$path;
        [$document, $status] = $this->documentStorage->upsertContent($origin, $folder, $content, $path, 'md', ['source' => 'repo', 'file' => $path]);
        // A repository file is known by its path: a heading such as "# Instructions" says nothing in a list.
        if ($path !== $document->getLabel()) {
            $document->update($path, $document->getPath(), null, $document->getMetadata());
        }
        $document->addProject($project);
        match ($status) {
            'created' => ++$result->documentsCreated,
            'updated' => ++$result->documentsUpdated,
            'unchanged' => ++$result->documentsUnchanged,
        };

        return $origin;
    }

    /**
     * @param list<string> $origins
     *
     * @return list<string>
     */
    private function missingDocuments(Project $project, array $origins): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT metadata->>'file' FROM node WHERE type = :document AND metadata->>'origin' LIKE :prefix AND metadata->>'origin' NOT IN (:seen) ORDER BY 1",
            ['document' => NodeType::Document->value, 'prefix' => addcslashes('repo:'.$project->getSlug().':', '\\%_').'%', 'seen' => ['', ...$origins]],
            ['seen' => ArrayParameterType::STRING],
        );
    }

    /**
     * Top-level bullets, each prefixed with the file title and its section so that it reads on its own.
     *
     * @return list<string>
     */
    private function bullets(string $markdown, string $fallbackTitle): array
    {
        $title = $fallbackTitle;
        $section = null;
        $bullets = [];
        $current = null;
        $inCode = false;
        $flush = static function () use (&$current, &$bullets, &$title, &$section): void {
            /** @var string|null $current */
            if ('' !== (string) $current) {
                $bullets[] = implode(' / ', array_filter([$title, $section])).' : '.$current;
            }
            $current = null;
        };

        foreach (preg_split('/\R/', $markdown) as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $inCode = !$inCode;
                $flush();
                continue;
            }
            if ($inCode || str_starts_with(ltrim($line), '<!--')) {
                continue;
            }
            if (preg_match('/^#\s+(.+)$/', $line, $match)) {
                $flush();
                $title = trim($match[1]);
                $section = null;
            } elseif (preg_match('/^#{2,6}\s+(.+)$/', $line, $match)) {
                $flush();
                $section = trim($match[1]);
            } elseif (preg_match('/^[-*]\s+(.+)$/', $line, $match)) {
                $flush();
                $text = trim($match[1]);
                $current = preg_match('/^<[^>]*>$/', $text) ? null : $text;
            } elseif (null !== $current && preg_match('/^\s{2,}(?:[-*]\s+)?(.+)$/', $line, $match)) {
                $current .= (preg_match('/^\s{2,}[-*]\s/', $line) ? ' ; ' : ' ').trim($match[1]);
            } else {
                $flush();
            }
        }
        $flush();

        return $bullets;
    }

    /**
     * @return array{title: string, record: array<string, string>, links: array{supersedes: list<string>, superseded_by: list<string>}}|null
     */
    private function parseAdr(string $markdown): ?array
    {
        if (!preg_match('/^#\s+(.+)$/m', $markdown, $title)) {
            return null;
        }

        $links = ['supersedes' => [], 'superseded_by' => []];
        if (preg_match_all('/^[-*]\s+(Supersedes|Superseded by)\s*:\s*(.+)$/mi', $markdown, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as [, $key, $value]) {
                foreach (preg_split('/[,;]/', $value) as $reference) {
                    $name = basename(trim($reference, " \t`[]()<>"), '.md');
                    if ('' !== $name && !str_contains($name, ' ')) {
                        $links['superseded by' === strtolower($key) ? 'superseded_by' : 'supersedes'][] = $name;
                    }
                }
            }
        }

        $record = [];
        foreach (preg_split('/^##\s+/m', $markdown) as $index => $chunk) {
            $heading = strtolower(trim(strtok($chunk, "\n")));
            if (0 !== $index && isset(self::ADR_SECTIONS[$heading])) {
                $record[self::ADR_SECTIONS[$heading]] = trim(substr($chunk, \strlen(strtok($chunk, "\n"))));
            }
        }

        return ['title' => mb_substr(trim($title[1]), 0, 1000), 'record' => $record, 'links' => $links];
    }

    private function findByOrigin(string $origin): ?Node
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            "SELECT id FROM node WHERE type = :memory AND metadata->>'origin' = :origin",
            ['memory' => NodeType::Memory->value, 'origin' => $origin],
        );

        return false === $id ? null : $this->entityManager->find(Node::class, $id);
    }

    private function read(string $root, string $path, RepoContextImportResult $result): ?string
    {
        // A cloned repository is not trusted: a link could point at any file of the server.
        $real = realpath($root.'/'.$path);
        if (false === $real || !str_starts_with($real, realpath($root).'/')) {
            $result->skipped[] = \sprintf('%s : lien hors du dépôt', $path);

            return null;
        }
        if (filesize($root.'/'.$path) > self::MAX_FILE_BYTES) {
            $result->skipped[] = \sprintf('%s : plus de 1 Mo', $path);

            return null;
        }

        return (string) file_get_contents($root.'/'.$path);
    }

    /**
     * Paths relative to the root, sorted, of the matching files under the existing directories. Symlinks are not followed.
     *
     * @param list<string>        $dirs
     * @param string|list<string> $names
     *
     * @return list<string>
     */
    private function files(string $root, array $dirs, string|array $names, ?int $depth = null): array
    {
        $dirs = array_values(array_filter(array_map(static fn (string $dir): string => $root.'/'.$dir, $dirs), 'is_dir'));
        if ([] === $dirs) {
            return [];
        }

        $finder = (new Finder())->files()->in($dirs)->name($names)->ignoreDotFiles(false)->sortByName();
        if (null !== $depth) {
            $finder->depth($depth);
        }

        return array_map(static fn (\SplFileInfo $file): string => substr($file->getPathname(), \strlen($root) + 1), iterator_to_array($finder, false));
    }
}
