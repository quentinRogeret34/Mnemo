<?php

namespace App\MessageHandler;

use App\Entity\Node;
use App\Message\IndexNode;
use App\Search\NodeIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class IndexNodeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NodeIndexer $indexer,
    ) {
    }

    public function __invoke(IndexNode $message): void
    {
        $node = $this->entityManager->find(Node::class, $message->nodeId);
        if (null !== $node) {
            $this->indexer->index($node);
        }
    }
}
