<?php

namespace App\Chat;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\MemoryType;
use App\Enum\MemoryVisibility;
use App\Enum\NodeType;
use App\Graph\KnowledgeReader;
use App\Memory\MemoryStore;
use App\Search\HybridSearch;
use App\Security\MemoryAccess;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tools for the global workspace assistant: reads across every project the caller may reach,
 * and writes memories or business rules when asked.
 */
#[AsTool('list_projects', 'Lists the accessible projects in the workspace: slug, name and knowledge counts.', 'listProjects')]
#[AsTool('search', 'Searches across all accessible projects (or one project slug) for memories, business documents, business rules and code files.', 'search')]
#[AsTool('list_rules', 'Lists the business rules of a project given its slug: code, title, statement and status (validated or to_review).', 'listRules')]
#[AsTool('get_rule', 'Details of a business rule in a project: statement, status, source documents, files implementing it, linked memories and blind spots.', 'getRule')]
#[AsTool('add_rule', 'Adds a business rule to a project (by slug) with the to_review status. Only when the user asks for it.', 'addRule')]
#[AsTool('add_memory', 'Saves a memory (decision, convention, task_learning, preference or fact), optionally attached to a project slug. Only when the user asks for it.', 'addMemory')]
final class WorkspaceTools
{
    /** @var list<array{label: string, parameters: array<string, int|string>, url: string}> */
    private array $actions = [];

    public function __construct(
        private readonly HybridSearch $hybridSearch,
        private readonly KnowledgeReader $knowledgeReader,
        private readonly MemoryStore $memoryStore,
        private readonly MemoryAccess $memoryAccess,
        private readonly ProjectScope $projectScope,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function fresh(): self
    {
        $tools = clone $this;
        $tools->actions = [];

        return $tools;
    }

    /**
     * @return list<array{label: string, parameters: array<string, int|string>, url: string}> what was written since fresh()
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * @return list<array{slug: string, name: string, documents: int, memories: int, rules: int, files: int}>
     */
    public function listProjects(): array
    {
        $projects = $this->projectScope->projects();
        $ids = array_values(array_filter(array_map(static fn (Project $p): ?int => $p->getId(), $projects)));
        $counts = $this->knowledgeReader->countsByProject($ids);

        return array_map(static function (Project $project) use ($counts): array {
            $c = $counts[$project->getId()] ?? ['documents' => 0, 'memories' => 0, 'rules' => 0, 'files' => 0];

            return [
                'slug' => $project->getSlug(),
                'name' => $project->getName(),
                'documents' => $c['documents'],
                'memories' => $c['memories'],
                'rules' => $c['rules'],
                'files' => $c['files'],
            ];
        }, $projects);
    }

    /**
     * @param string      $query   keywords or a short question
     * @param string|null $project optional project slug to narrow the search
     *
     * @return list<array<string, mixed>>|array{error: string}
     */
    public function search(string $query, ?string $project = null): array
    {
        $target = null;
        if (null !== $project && '' !== trim($project)) {
            $target = $this->findAllowedProject($project);
            if (null === $target) {
                return ['error' => \sprintf('Unknown or inaccessible project "%s": call list_projects.', $project)];
            }
        }

        return array_map(
            static fn (array $result): array => array_filter([
                'type' => $result['type'],
                'id' => $result['id'],
                'code' => $result['code'],
                'project' => $result['project_slug'],
                'label' => $result['label'],
                'path' => $result['path'],
                'snippet' => $result['snippet'],
            ]),
            $this->hybridSearch->search($query, $target, ['memory', 'document', 'business_rule', 'code_file'], 14)['results'],
        );
    }

    /**
     * @param string $project slug of the project
     *
     * @return list<array{code: string, title: string, statement: ?string, status: ?string}>|array{error: string}
     */
    public function listRules(string $project): array
    {
        $target = $this->findAllowedProject($project);
        if (null === $target) {
            return ['error' => \sprintf('Unknown or inaccessible project "%s": call list_projects.', $project)];
        }

        return array_map(
            static fn (array $rule): array => ['code' => $rule['code'], 'title' => $rule['title'], 'statement' => $rule['statement'], 'status' => $rule['status']],
            $this->knowledgeReader->rules($target),
        );
    }

    /**
     * @param string $project slug of the project
     * @param string $code    code of the rule, e.g. RG-042
     *
     * @return array<string, mixed>
     */
    public function getRule(string $project, string $code): array
    {
        $target = $this->findAllowedProject($project);
        if (null === $target) {
            return ['error' => \sprintf('Unknown or inaccessible project "%s": call list_projects.', $project)];
        }
        $rule = $this->entityManager->getRepository(Node::class)->findOneBy([
            'project' => $target,
            'type' => NodeType::BusinessRule,
            'externalId' => trim($code),
        ]);
        if (null === $rule) {
            return ['error' => \sprintf('No rule "%s" in project "%s": call list_rules.', $code, $target->getSlug())];
        }
        $metadata = $rule->getMetadata();

        return [
            'project' => $target->getSlug(),
            'code' => $rule->getExternalId(),
            'title' => $rule->getLabel(),
            'statement' => $metadata['statement'] ?? null,
            'status' => $metadata['status'] ?? null,
            'sources' => array_column($this->knowledgeReader->ruleSources($rule), 'title'),
            'implemented_by' => array_column($this->knowledgeReader->ruleImplementations($rule), 'path'),
            'memories' => array_map(static fn (array $memory): array => ['id' => $memory['id'], 'type' => $memory['type'], 'text' => $memory['text']], $this->knowledgeReader->memoriesAbout($rule->getId())),
            'shadow_areas' => $metadata['shadow_areas'] ?? [],
        ];
    }

    /**
     * @param string $project   slug of the target project
     * @param string $title     short title of the rule
     * @param string $statement full statement, one or two checkable sentences
     *
     * @return array<string, mixed>
     */
    public function addRule(string $project, string $title, string $statement): array
    {
        $target = $this->findAllowedProject($project);
        if (null === $target) {
            return ['error' => \sprintf('Unknown or inaccessible project "%s": call list_projects.', $project)];
        }
        $title = trim($title);
        $statement = trim($statement);
        if ('' === $title || mb_strlen($title) > 255 || '' === $statement || mb_strlen($statement) > 2000) {
            return ['error' => 'The title (255 characters at most) and the statement (2000 at most) are required.'];
        }
        $lock = $this->lockFactory->createLock('rule-code-'.$target->getId(), 15);
        $lock->acquire(true);
        try {
            $last = (int) $this->entityManager->getConnection()->fetchOne(
                "SELECT COALESCE(MAX(substring(external_id FROM 4)::int), 0) FROM node WHERE project_id = :project AND type = :rule AND external_id ~ '^RG-[0-9]+$'",
                ['project' => $target->getId(), 'rule' => NodeType::BusinessRule->value],
            );
            $rule = new Node($target, NodeType::BusinessRule, \sprintf('RG-%03d', $last + 1), $title, null, null, ['statement' => $statement, 'status' => 'to_review', 'source' => 'chat']);
            $this->entityManager->persist($rule);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
        $this->actions[] = [
            'label' => 'chat.action.rule',
            'parameters' => ['code' => $rule->getExternalId()],
            'url' => $this->urlGenerator->generate('rule_show', ['slug' => $target->getSlug(), 'code' => $rule->getExternalId()]),
        ];

        return ['project' => $target->getSlug(), 'code' => $rule->getExternalId(), 'status' => 'to_review', 'note' => 'Created with the to_review status: a human validates it on its page.'];
    }

    /**
     * @param string      $text    one or two self-contained sentences, understandable without the conversation
     * @param string      $type    decision, convention, task_learning, preference or fact
     * @param string|null $project optional project slug to attach the memory to (required for scoped users)
     *
     * @return array<string, mixed>
     */
    public function addMemory(string $text, string $type = 'fact', ?string $project = null): array
    {
        $text = trim($text);
        $memoryType = MemoryType::tryFrom($type);
        if ('' === $text || mb_strlen($text) > 1000 || null === $memoryType) {
            return ['error' => 'Text of 1 to 1000 characters and type among decision, convention, task_learning, preference, fact.'];
        }

        $target = null;
        if (null !== $project && '' !== trim($project)) {
            $target = $this->findAllowedProject($project);
            if (null === $target) {
                return ['error' => \sprintf('Unknown or inaccessible project "%s": call list_projects.', $project)];
            }
        } elseif (null !== $this->projectScope->slugs()) {
            return ['error' => 'Specify a project slug (call list_projects to see accessible projects).'];
        }

        $projects = null === $target ? [] : [$target];
        $memory = $this->memoryStore->findByText($text, $projects, MemoryVisibility::Project);
        $created = null === $memory;
        $memory ??= $this->memoryStore->add($text, $memoryType, 'chat', $this->memoryAccess->metadataFor(MemoryVisibility::Project));
        if (null !== $target) {
            $memory->addProject($target);
        }
        $this->entityManager->flush();

        if ($created) {
            $this->actions[] = [
                'label' => 'chat.action.memory',
                'parameters' => ['id' => $memory->getId()],
                'url' => $this->urlGenerator->generate('memory_show', ['id' => $memory->getId()]),
            ];
        }

        return ['id' => $memory->getId(), 'event' => $created ? 'ADD' : 'NONE'];
    }

    private function findAllowedProject(string $slug): ?Project
    {
        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['slug' => trim($slug)]);

        return null !== $project && $this->projectScope->allows($project) ? $project : null;
    }
}
