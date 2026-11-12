<?php

namespace App\Mcp;

use App\Document\DocumentStorage;
use App\Entity\Project;
use App\Entity\UserGroup;
use App\Enum\MemoryType;
use App\Enum\MemoryVisibility;
use App\Learn\Learner;
use App\Learn\SourceKind;
use App\Llm\LlmUnavailable;
use App\Memory\MemoryStore;
use App\Security\MemoryAccess;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class WriteTools
{
    private const MAX_DOCUMENT_BYTES = 1_000_000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectScope $projectScope,
        private readonly MemoryAccess $memoryAccess,
        private readonly MemoryStore $memoryStore,
        private readonly DocumentStorage $documentStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Learner $learner,
    ) {
    }

    /**
     * @param string $text    the source: a conversation transcript, a file, a diff or a code review
     * @param string $kind    kind of source
     * @param string $label   stable label shown with the proposals, e.g. a branch range or a transcript name
     * @param string $project slug of the project the learnings belong to
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'learn', description: 'Send a conversation, diff, file or code review to extract the durable learnings (decisions, conventions, pitfalls). Learnings are scored and compared to existing memories, then queued as proposals that a human validates: nothing is stored directly. Call it at the end of a session or after a merge.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false))]
    public function learn(
        string $text,
        #[Schema(enum: ['conversation', 'file', 'diff', 'review'])]
        string $kind,
        #[Schema(minLength: 1, maxLength: 255)]
        string $label,
        string $project,
    ): array {
        if ('' === trim($text) || \strlen($text) > self::MAX_DOCUMENT_BYTES) {
            throw new ToolCallException('The source must contain between 1 byte and 1 MB.');
        }
        $sourceKind = SourceKind::tryFrom($kind) ?? throw new ToolCallException(\sprintf('Unknown kind "%s".', $kind));
        $label = mb_substr(trim($label), 0, 255);

        try {
            $report = $this->learner->learn($sourceKind, '' === $label ? $kind : $label, $text, $this->project($project));
        } catch (LlmUnavailable $exception) {
            throw new ToolCallException($exception->getMessage());
        }

        return $report + ['proposals_url' => $this->urlGenerator->generate('proposal_index', ['project' => $project], UrlGeneratorInterface::ABSOLUTE_URL)];
    }

    /**
     * @param string       $text     one short, self-contained fact, decision, convention or learning
     * @param string       $type     kind of memory
     * @param list<string> $projects slugs of the projects the memory is about
     * @param list<string> $files    code file paths, in those projects, the memory is about
     * @param list<string> $rules    business rule codes, in those projects, the memory is about (e.g. RG-001)
     * @param string|null  $agent    name of the calling agent, e.g. claude-code
     * @param string       $visibility who sees the memory: project (everyone on its projects), private (the key's owner only) or group
     * @param string|null  $group    name of the group, for the group visibility
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'add_memory', description: 'Store a memory in the knowledge graph (mem0 style) and link it to projects, code files and business rules. Shared with the project by default; use visibility "private" for a personal preference of the user. An identical memory is not duplicated: event NONE is returned and the new links are added to it.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true))]
    public function addMemory(
        #[Schema(minLength: 1, maxLength: 1000)]
        string $text,
        #[Schema(enum: ['decision', 'convention', 'task_learning', 'preference', 'fact'])]
        string $type = 'fact',
        #[Schema(items: ['type' => 'string'])]
        array $projects = [],
        #[Schema(items: ['type' => 'string'])]
        array $files = [],
        #[Schema(items: ['type' => 'string'])]
        array $rules = [],
        ?string $agent = null,
        #[Schema(enum: ['project', 'private', 'group'])]
        string $visibility = 'project',
        ?string $group = null,
    ): array {
        $text = trim($text);
        if ('' === $text || mb_strlen($text) > 1000) {
            throw new ToolCallException('The memory text must contain between 1 and 1000 characters.');
        }
        $memoryType = MemoryType::tryFrom($type) ?? throw new ToolCallException(\sprintf('Unknown type "%s". Use one of: %s.', $type, implode(', ', array_column(MemoryType::cases(), 'value'))));
        $this->requireProject(array_values($projects));
        $projectEntities = array_map($this->project(...), $projects);

        $memory = $this->memoryStore->findByText($text);
        $event = null === $memory ? 'ADD' : 'NONE';
        try {
            $access = $this->memoryAccess->metadataFor(
                MemoryVisibility::tryFrom($visibility) ?? throw new ToolCallException(\sprintf('Unknown visibility "%s". Use project, private or group.', $visibility)),
                null === $group ? null : $this->entityManager->getRepository(UserGroup::class)->findOneBy(['name' => $group])?->getId(),
            );
        } catch (\InvalidArgumentException $exception) {
            throw new ToolCallException($exception->getMessage());
        }
        $memory ??= $this->memoryStore->add($text, $memoryType, 'mcp', (null === $agent ? [] : ['agent' => $agent]) + $access);

        foreach ($projectEntities as $project) {
            $memory->addProject($project);
        }
        $this->entityManager->flush();

        $notFound = $this->memoryStore->linkReferences($memory, $projectEntities, array_values($files), array_values($rules));
        $this->entityManager->flush();

        return [
            'id' => $memory->getId(),
            'event' => $event,
            'url' => $this->urlGenerator->generate('memory_show', ['id' => $memory->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            'not_found' => $notFound,
        ];
    }

    /**
     * @param string       $title    document title
     * @param string       $content  document body in Markdown
     * @param string|null  $folder   folder path, created if missing, e.g. "Spécifications/Facturation"
     * @param list<string> $projects slugs of the projects the document belongs to
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'import_document', description: 'Import a business document written in Markdown (specification, meeting notes, page exported from Notion or a wiki) into the documents space, in a folder, attached to projects.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false))]
    public function importDocument(
        #[Schema(minLength: 1, maxLength: 255)]
        string $title,
        string $content,
        ?string $folder = null,
        #[Schema(items: ['type' => 'string'])]
        array $projects = [],
    ): array {
        $title = trim($title);
        if ('' === $title || mb_strlen($title) > 255) {
            throw new ToolCallException('The title must contain between 1 and 255 characters.');
        }
        if (\strlen($content) > self::MAX_DOCUMENT_BYTES) {
            throw new ToolCallException('The content exceeds 1 MB.');
        }
        $this->requireProject(array_values($projects));
        $projectEntities = array_map($this->project(...), $projects);

        $document = $this->documentStorage->storeContent($this->documentStorage->folder($folder), '# '.$title."\n\n".preg_replace('/\A\s*#\s+.+\R/', '', $content, 1), $title, 'md', ['source' => 'mcp']);
        foreach ($projectEntities as $project) {
            $document->addProject($project);
        }
        $this->entityManager->flush();

        return [
            'id' => $document->getId(),
            'url' => $this->urlGenerator->generate('document_show', ['id' => $document->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            'folder' => $document->getFolder()?->getName(),
        ];
    }

    /**
     * A key limited to some projects cannot read or write outside a project.
     *
     * @param list<string> $projects
     */
    private function requireProject(array $projects): void
    {
        $slugs = $this->projectScope->slugs();
        if (null !== $slugs && [] === $projects) {
            throw new ToolCallException(\sprintf('This API key is limited to the projects: %s. Name one of them.', implode(', ', $slugs)));
        }
    }

    private function project(string $slug): Project
    {
        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        if (null === $project || !$this->projectScope->allows($project)) {
            throw new ToolCallException(\sprintf('Unknown project "%s". Call list_projects to see the available slugs.', $slug));
        }

        return $project;
    }
}
