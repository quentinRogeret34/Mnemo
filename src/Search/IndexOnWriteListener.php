<?php

namespace App\Search;

use App\Entity\Node;
use App\Message\IndexNode;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the embedding of a memory, rule or document each time it is written.
 * The handler decides whether an embedding model is configured: reading the LLM settings here would build them on every write.
 */
#[AsEntityListener(event: Events::postPersist, entity: Node::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Node::class)]
final class IndexOnWriteListener
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function postPersist(Node $node): void
    {
        $this->queue($node);
    }

    public function postUpdate(Node $node): void
    {
        $this->queue($node);
    }

    private function queue(Node $node): void
    {
        if (\in_array($node->getType(), NodeIndexer::TYPES, true)) {
            $this->bus->dispatch(new IndexNode($node->getId()));
        }
    }
}
