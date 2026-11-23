<?php

namespace App\Search;

use App\Entity\Node;
use App\Message\IndexNode;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Queues the embedding of a memory, rule or document after flush (so uncommitted/rolled-back writes never queue messages).
 * The handler decides whether an embedding model is configured: reading the LLM settings here would build them on every write.
 */
#[AsEntityListener(event: Events::postPersist, entity: Node::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Node::class)]
#[AsDoctrineListener(event: Events::postFlush)]
final class IndexOnWriteListener implements ResetInterface
{
    /** @var array<int, true> */
    private array $pendingIds = [];

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function postPersist(Node $node): void
    {
        $this->collect($node);
    }

    public function postUpdate(Node $node): void
    {
        $this->collect($node);
    }

    public function postFlush(): void
    {
        if ([] === $this->pendingIds) {
            return;
        }
        $ids = array_keys($this->pendingIds);
        $this->pendingIds = [];
        foreach ($ids as $id) {
            $this->bus->dispatch(new IndexNode($id));
        }
    }

    public function reset(): void
    {
        $this->pendingIds = [];
    }

    private function collect(Node $node): void
    {
        $id = $node->getId();
        if (null !== $id && \in_array($node->getType(), NodeIndexer::TYPES, true)) {
            $this->pendingIds[$id] = true;
        }
    }
}
