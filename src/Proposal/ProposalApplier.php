<?php

namespace App\Proposal;

use App\Entity\Node;
use App\Entity\Proposal;
use App\Enum\ProposalStatus;
use App\Proposal\Handler\ProposalApplyResult;
use App\Proposal\Handler\ProposalHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Applies or rejects what a human decided about a proposal by delegating to the matching ProposalHandlerInterface. Flushes.
 */
final class ProposalApplier
{
    /**
     * @param iterable<ProposalHandlerInterface> $handlers
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[AutowireIterator('app.proposal_handler')]
        private readonly iterable $handlers,
    ) {
    }

    /**
     * @param array{lesson?: string, keep?: int, title?: string, statement?: string, name?: string, description?: string, content?: string} $input
     */
    public function apply(Proposal $proposal, array $input = []): ?Node
    {
        return $this->applyWithResult($proposal, $input)->node;
    }

    /**
     * @param array{lesson?: string, keep?: int, title?: string, statement?: string, name?: string, description?: string, content?: string} $input
     */
    public function applyWithResult(Proposal $proposal, array $input = []): ProposalApplyResult
    {
        if (!$proposal->isPending()) {
            throw new \InvalidArgumentException('Cette proposition a déjà été traitée.');
        }

        foreach ($this->handlers as $handler) {
            if ($handler->supports($proposal->getKind())) {
                $result = $handler->apply($proposal, $input);
                $proposal->decide(ProposalStatus::Applied);
                $this->entityManager->flush();

                return $result;
            }
        }

        throw new \LogicException(\sprintf('No proposal handler registered for kind "%s".', $proposal->getKind()->value));
    }

    public function reject(Proposal $proposal, ?string $reason = null): void
    {
        if (!$proposal->isPending()) {
            throw new \InvalidArgumentException('Cette proposition a déjà été traitée.');
        }

        $reason = null === $reason ? null : mb_substr(trim($reason), 0, 120);
        if (null !== $reason && '' !== $reason) {
            $proposal->annotatePayload(['human_feedback' => ['rejected_reason' => $reason]]);
        }

        $proposal->decide(ProposalStatus::Rejected);
        $this->entityManager->flush();
    }
}
