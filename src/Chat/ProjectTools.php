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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the assistant of a project may read and write. Everything it writes is recorded, so the chat can show it:
 * a rule it adds waits for a human validation, a memory is shared with the project like any other.
 */
#[AsTool('search', 'Cherche dans le graphe du projet : mémoires, documents métier, règles métier et fichiers de code.', 'search')]
#[AsTool('list_rules', 'Liste les règles métier du projet : code, titre, énoncé et statut (validated ou to_review).', 'listRules')]
#[AsTool('get_rule', 'Détail d\'une règle métier : énoncé, statut, documents sources, fichiers qui l\'implémentent, mémoires liées et zones d\'ombre.', 'getRule')]
#[AsTool('add_rule', 'Ajoute une règle métier au projet, au statut à valider. Seulement quand l\'utilisateur le demande.', 'addRule')]
#[AsTool('add_memory', 'Enregistre une mémoire du projet : décision, convention, apprentissage, préférence ou fait. Seulement quand l\'utilisateur le demande.', 'addMemory')]
final class ProjectTools
{
    private ?Project $project = null;

    /** @var list<array{label: string, url: string}> */
    private array $actions = [];

    public function __construct(
        private readonly HybridSearch $hybridSearch,
        private readonly KnowledgeReader $knowledgeReader,
        private readonly MemoryStore $memoryStore,
        private readonly MemoryAccess $memoryAccess,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
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
     * @return list<array{label: string, url: string}> what was written since for()
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * @param string $query mots-clés ou question courte
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
     * @param string $code code de la règle, ex. RG-042
     *
     * @return array<string, mixed>
     */
    public function getRule(string $code): array
    {
        $rule = $this->entityManager->getRepository(Node::class)->findOneBy(['project' => $this->project(), 'type' => NodeType::BusinessRule, 'externalId' => trim($code)]);
        if (null === $rule) {
            return ['error' => \sprintf('Aucune règle "%s" dans ce projet : appeler list_rules.', $code)];
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
     * @param string $title     titre court de la règle
     * @param string $statement énoncé complet, une ou deux phrases vérifiables
     *
     * @return array<string, mixed>
     */
    public function addRule(string $title, string $statement): array
    {
        $title = trim($title);
        $statement = trim($statement);
        if ('' === $title || mb_strlen($title) > 255 || '' === $statement || mb_strlen($statement) > 2000) {
            return ['error' => 'Le titre (255 caractères au plus) et l\'énoncé (2000 au plus) sont obligatoires.'];
        }
        $project = $this->project();
        $last = (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COALESCE(MAX(substring(external_id FROM 4)::int), 0) FROM node WHERE project_id = :project AND type = :rule AND external_id ~ '^RG-[0-9]+$'",
            ['project' => $project->getId(), 'rule' => NodeType::BusinessRule->value],
        );
        $rule = new Node($project, NodeType::BusinessRule, \sprintf('RG-%03d', $last + 1), $title, null, null, ['statement' => $statement, 'status' => 'to_review', 'source' => 'chat']);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();
        $this->actions[] = [
            'label' => \sprintf('Règle %s créée, à valider', $rule->getExternalId()),
            'url' => $this->urlGenerator->generate('rule_show', ['slug' => $project->getSlug(), 'code' => $rule->getExternalId()]),
        ];

        return ['code' => $rule->getExternalId(), 'status' => 'to_review', 'note' => 'Créée au statut à valider : un humain la valide sur sa page.'];
    }

    /**
     * @param string $text une ou deux phrases autonomes, compréhensibles sans la conversation
     * @param string $type decision, convention, task_learning, preference ou fact
     *
     * @return array<string, mixed>
     */
    public function addMemory(string $text, string $type = 'fact'): array
    {
        $text = trim($text);
        $memoryType = MemoryType::tryFrom($type);
        if ('' === $text || mb_strlen($text) > 1000 || null === $memoryType) {
            return ['error' => 'Texte de 1 à 1000 caractères et type parmi decision, convention, task_learning, preference, fact.'];
        }
        $memory = $this->memoryStore->findByText($text);
        $created = null === $memory;
        $memory ??= $this->memoryStore->add($text, $memoryType, 'chat', $this->memoryAccess->metadataFor(MemoryVisibility::Project));
        $memory->addProject($this->project());
        $this->entityManager->flush();
        if ($created) {
            $this->actions[] = ['label' => \sprintf('Mémoire #%d enregistrée', $memory->getId()), 'url' => $this->urlGenerator->generate('memory_show', ['id' => $memory->getId()])];
        }

        return ['id' => $memory->getId(), 'event' => $created ? 'ADD' : 'NONE'];
    }

    private function project(): Project
    {
        return $this->project ?? throw new \LogicException('Call for() first.');
    }
}
