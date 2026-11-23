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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the assistant of a project may read and write. Everything it writes is recorded, so the chat can show it:
 * a rule it adds waits for a human validation, a memory is shared with the project like any other.
 */
#[AsTool('search', 'Searches the project graph: memories, business documents, business rules and code files.', 'search')]
#[AsTool('list_rules', 'Lists the business rules of the project: code, title, statement and status (validated or to_review).', 'listRules')]
#[AsTool('get_rule', 'Details of a business rule: statement, status, source documents, files implementing it, linked memories and blind spots.', 'getRule')]
#[AsTool('add_rule', 'Adds a business rule to the project, with the to_review status. Only when the user asks for it.', 'addRule')]
#[AsTool('add_memory', 'Saves a project memory: decision, convention, learning, preference or fact. Only when the user asks for it.', 'addMemory')]
final class ProjectTools
{
    private ?Project $project = null;

    /** @var list<array{label: string, parameters: array<string, int|string>, url: string}> */
    private array $actions = [];

    public function __construct(
        private readonly HybridSearch $hybridSearch,
        private readonly KnowledgeReader $knowledgeReader,
        private readonly MemoryStore $memoryStore,
        private readonly MemoryAccess $memoryAccess,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function for(Project $project): self
    {
        $tools = clone $this;
        $tools->project = $project;
        $tools->actions = [];

        return $tools;
    }

    /**
     * @return list<array{label: string, parameters: array<string, int|string>, url: string}> what was written since for()
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * @param string $query keywords or a short question
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        return array_map(
            static fn (array $result): array => array_filter(['type' => $result['type'], 'id' => $result['id'], 'code' => $result['code'], 'label' => $result['label'], 'path' => $result['path'], 'snippet' => $result['snippet']]),
            $this->hybridSearch->search($query, $this->project(), ['memory', 'document', 'business_rule', 'code_file'], 12)['results'],
        );
    }

    /**
     * @return list<array{code: string, title: string, statement: ?string, status: ?string}>
     */
    public function listRules(): array
    {
        return array_map(
            static fn (array $rule): array => ['code' => $rule['code'], 'title' => $rule['title'], 'statement' => $rule['statement'], 'status' => $rule['status']],
            $this->knowledgeReader->rules($this->project()),
        );
    }

    /**
     * @param string $code code of the rule, e.g. RG-042
     *
     * @return array<string, mixed>
     */
    public function getRule(string $code): array
    {
        $rule = $this->entityManager->getRepository(Node::class)->findOneBy(['project' => $this->project(), 'type' => NodeType::BusinessRule, 'externalId' => trim($code)]);
        if (null === $rule) {
            return ['error' => \sprintf('No rule "%s" in this project: call list_rules.', $code)];
        }
        $metadata = $rule->getMetadata();

        return [
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
     * @param string $title     short title of the rule
     * @param string $statement full statement, one or two checkable sentences
     *
     * @return array<string, mixed>
     */
    public function addRule(string $title, string $statement): array
    {
        $title = trim($title);
        $statement = trim($statement);
        if ('' === $title || mb_strlen($title) > 255 || '' === $statement || mb_strlen($statement) > 2000) {
            return ['error' => 'The title (255 characters at most) and the statement (2000 at most) are required.'];
        }
        $project = $this->project();
        $lock = $this->lockFactory->createLock('rule-code-'.$project->getId(), 15);
        $lock->acquire(true);
        try {
            $last = (int) $this->entityManager->getConnection()->fetchOne(
                "SELECT COALESCE(MAX(substring(external_id FROM 4)::int), 0) FROM node WHERE project_id = :project AND type = :rule AND external_id ~ '^RG-[0-9]+$'",
                ['project' => $project->getId(), 'rule' => NodeType::BusinessRule->value],
            );
            $rule = new Node($project, NodeType::BusinessRule, \sprintf('RG-%03d', $last + 1), $title, null, null, ['statement' => $statement, 'status' => 'to_review', 'source' => 'chat']);
            $this->entityManager->persist($rule);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
        $this->actions[] = [
            'label' => 'chat.action.rule', 'parameters' => ['code' => $rule->getExternalId()],
            'url' => $this->urlGenerator->generate('rule_show', ['slug' => $project->getSlug(), 'code' => $rule->getExternalId()]),
        ];

        return ['code' => $rule->getExternalId(), 'status' => 'to_review', 'note' => 'Created with the to_review status: a human validates it on its page.'];
    }

    /**
     * @param string $text one or two self-contained sentences, understandable without the conversation
     * @param string $type decision, convention, task_learning, preference or fact
     *
     * @return array<string, mixed>
     */
    public function addMemory(string $text, string $type = 'fact'): array
    {
        $text = trim($text);
        $memoryType = MemoryType::tryFrom($type);
        if ('' === $text || mb_strlen($text) > 1000 || null === $memoryType) {
            return ['error' => 'Text of 1 to 1000 characters and type among decision, convention, task_learning, preference, fact.'];
        }
        $memory = $this->memoryStore->findByText($text, [$this->project()], MemoryVisibility::Project);
        $created = null === $memory;
        $memory ??= $this->memoryStore->add($text, $memoryType, 'chat', $this->memoryAccess->metadataFor(MemoryVisibility::Project));
        $memory->addProject($this->project());
        $this->entityManager->flush();
        if ($created) {
            $this->actions[] = ['label' => 'chat.action.memory', 'parameters' => ['id' => $memory->getId()], 'url' => $this->urlGenerator->generate('memory_show', ['id' => $memory->getId()])];
        }

        return ['id' => $memory->getId(), 'event' => $created ? 'ADD' : 'NONE'];
    }

    private function project(): Project
    {
        return $this->project ?? throw new \LogicException('Call for() first.');
    }
}
