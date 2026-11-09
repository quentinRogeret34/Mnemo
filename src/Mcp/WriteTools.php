<?php

namespace App\Mcp;

use App\Document\DocumentStorage;
use App\Entity\Folder;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\MemoryType;
use App\Enum\NodeType;
use App\Memory\MemoryStore;
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
        private readonly MemoryStore $memoryStore,
        private readonly DocumentStorage $documentStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param string       $text     one short, self-contained fact, decision, convention or learning
     * @param string       $type     kind of memory
     * @param list<string> $projects slugs of the projects the memory is about
     * @param list<string> $files    code file paths, in those projects, the memory is about
     * @param list<string> $rules    business rule codes, in those projects, the memory is about (e.g. RG-001)
     * @param string|null  $agent    name of the calling agent, e.g. claude-code
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'add_memory', description: 'Store a memory in the knowledge graph (mem0 style) and link it to projects, code files and business rules. An identical memory is not duplicated: event NONE is returned and the new links are added to it.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true))]
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
    ): array {
        $text = trim($text);
        if ('' === $text || mb_strlen($text) > 1000) {
            throw new ToolCallException('The memory text must contain between 1 and 1000 characters.');
        }
        $memoryType = MemoryType::tryFrom($type) ?? throw new ToolCallException(\sprintf('Unknown type "%s". Use one of: %s.', $type, implode(', ', array_column(MemoryType::cases(), 'value'))));
        $projectEntities = array_map($this->project(...), $projects);

        $memory = $this->memoryStore->findByText($text);
        $event = null === $memory ? 'ADD' : 'NONE';
        $memory ??= $this->memoryStore->add($text, $memoryType, 'mcp', null === $agent ? [] : ['agent' => $agent]);

        foreach ($projectEntities as $project) {
            $memory->addProject($project);
        }
        $this->entityManager->flush();

        $notFound = [];
        $nodes = $this->entityManager->getRepository(Node::class);
        foreach ($files as $path) {
            $file = [] === $projectEntities ? null : $nodes->findOneBy(['project' => $projectEntities, 'type' => NodeType::CodeFile, 'path' => $path]);
            null === $file ? $notFound[] = $path : $this->memoryStore->link($memory, $file);
        }
        foreach ($rules as $code) {
            $rule = [] === $projectEntities ? null : $nodes->findOneBy(['project' => $projectEntities, 'type' => NodeType::BusinessRule, 'externalId' => $code]);
            null === $rule ? $notFound[] = $code : $this->memoryStore->link($memory, $rule);
        }
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
        $projectEntities = array_map($this->project(...), $projects);

        $document = $this->documentStorage->storeContent($this->folder($folder), '# '.$title."\n\n".preg_replace('/\A\s*#\s+.+\R/', '', $content, 1), $title, 'md', ['source' => 'mcp']);
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

    private function project(string $slug): Project
    {
        return $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => $slug])
            ?? throw new ToolCallException(\sprintf('Unknown project "%s". Call list_projects to see the available slugs.', $slug));
    }

    private function folder(?string $path): ?Folder
    {
        $folder = null;
        foreach (array_filter(array_map('trim', explode('/', (string) $path)), 'strlen') as $name) {
            // A folder created in this loop is not flushed yet and cannot have children to look up.
            $child = null !== $folder && null === $folder->getId() ? null : $this->entityManager->getRepository(Folder::class)->findOneBy(['name' => $name, 'parent' => $folder]);
            if (null === $child) {
                $child = new Folder($name, $folder);
                $this->entityManager->persist($child);
            }
            $folder = $child;
        }

        return $folder;
    }
}
