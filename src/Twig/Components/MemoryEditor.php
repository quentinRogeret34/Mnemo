<?php

namespace App\Twig\Components;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\MemoryType;
use App\Graph\KnowledgeReader;
use App\Memory\MemoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class MemoryEditor
{
    use DefaultActionTrait;

    #[LiveProp]
    public Node $memory;

    #[LiveProp(writable: true)]
    public string $text = '';

    #[LiveProp(writable: true)]
    public string $type = '';

    #[LiveProp(writable: true)]
    public string $linkQuery = '';

    /**
     * Decision record, shown for decisions only: context, decision, alternatives, consequences.
     *
     * @var array<string, string>
     */
    #[LiveProp(writable: true)]
    public array $adr = [];

    public ?string $error = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KnowledgeReader $knowledgeReader,
        private readonly MemoryStore $memoryStore,
    ) {
    }

    public function mount(Node $memory): void
    {
        $this->memory = $memory;
        $this->text = $memory->getLabel();
        $this->type = $memory->getMetadata()['type'];
        $this->adr = $this->storedAdr();
    }

    public function isDirty(): bool
    {
        return trim($this->text) !== $this->memory->getLabel()
            || $this->type !== $this->memory->getMetadata()['type']
            || (MemoryType::Decision->value === $this->type && array_map('trim', $this->adr) !== $this->storedAdr());
    }

    /**
     * @return array<string, string> field => label, in display order
     */
    public function getAdrFields(): array
    {
        return ['context' => 'Contexte', 'decision' => 'Décision', 'alternatives' => 'Alternatives écartées', 'consequences' => 'Conséquences'];
    }

    /**
     * @return list<MemoryType>
     */
    public function getTypes(): array
    {
        return MemoryType::cases();
    }

    /**
     * @return list<Project>
     */
    public function getProjects(): array
    {
        return $this->entityManager->getRepository(Project::class)->findBy([], ['name' => 'ASC']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getLinks(): array
    {
        return $this->knowledgeReader->memoryLinks($this->memory);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getLinkables(): array
    {
        return $this->knowledgeReader->memoryLinkables($this->memory, $this->linkQuery);
    }

    /**
     * @return list<array{event: string, text: string, previous?: string, at: string}>
     */
    public function getHistory(): array
    {
        return array_reverse($this->memory->getMetadata()['history'] ?? []);
    }

    #[LiveAction]
    public function save(): void
    {
        $text = trim($this->text);
        if ('' === $text || mb_strlen($text) > 1000) {
            $this->error = 'La mémoire doit contenir entre 1 et 1000 caractères.';

            return;
        }
        $type = MemoryType::tryFrom($this->type);
        if (null === $type) {
            $this->error = 'Type de mémoire inconnu.';

            return;
        }

        $this->memoryStore->update($this->memory, $text, $type);
        if (MemoryType::Decision === $type) {
            $this->memoryStore->setAdr($this->memory, array_map('strval', $this->adr));
        }
        $this->entityManager->flush();
        $this->text = $this->memory->getLabel();
        $this->adr = $this->storedAdr();
    }

    /**
     * @return array<string, string> every field, empty when not filled
     */
    private function storedAdr(): array
    {
        return array_merge(array_fill_keys(MemoryStore::ADR_FIELDS, ''), $this->memory->getMetadata()['adr'] ?? []);
    }

    #[LiveAction]
    public function toggleProject(#[LiveArg] int $projectId): void
    {
        $project = $this->entityManager->find(Project::class, $projectId) ?? throw new \InvalidArgumentException('Unknown project.');
        $this->memory->getProjects()->contains($project) ? $this->memoryStore->detachProject($this->memory, $project) : $this->memory->addProject($project);
        $this->entityManager->flush();
    }

    #[LiveAction]
    public function link(#[LiveArg] int $nodeId): void
    {
        $target = $this->entityManager->find(Node::class, $nodeId) ?? throw new \InvalidArgumentException('Unknown node.');
        $this->memoryStore->link($this->memory, $target);
        $this->entityManager->flush();
        $this->linkQuery = '';
    }

    #[LiveAction]
    public function unlink(#[LiveArg] int $edgeId): void
    {
        $this->memoryStore->unlink($this->memory, $edgeId);
        $this->entityManager->flush();
    }
}
