<?php

namespace App\Proposal;

use App\Entity\Node;
use App\Entity\Proposal;
use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
use App\Proposal\Handler\ProposalApplyResult;
use App\Proposal\Handler\ProposalHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Applies or rejects what a human decided about a proposal by delegating to the matching ProposalHandlerInterface. Flushes.
 * Learning proposals whose signal score strictly exceeds AUTO_APPLY_SCORE_THRESHOLD can be auto-applied without manual review.
 */
final class ProposalApplier
{
    public const AUTO_APPLY_SCORE_THRESHOLD = 9.0;

    /**
     * @param iterable<ProposalHandlerInterface> $handlers
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[AutowireIterator('app.proposal_handler')]
        private readonly iterable $handlers,
    ) {
    }

    public static function qualifiesForAutoApply(Proposal $proposal): bool
    {
        if (!$proposal->isPending() || ProposalKind::Learning !== $proposal->getKind()) {
            return false;
        }

        $score = $proposal->getPayload()['score'] ?? null;

        return (\is_int($score) || \is_float($score) || (\is_string($score) && is_numeric($score)))
            && (float) $score > self::AUTO_APPLY_SCORE_THRESHOLD;
    }

    /**
     * Auto-applies the proposal when its signal qualifies; returns false without throwing if ineligible or no longer applicable.
     */
    public function autoApply(Proposal $proposal): bool
    {
        if (!self::qualifiesForAutoApply($proposal)) {
            return false;
        }

        try {
            $this->applyWithResult($proposal, autoApplied: true);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
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
    public function applyWithResult(Proposal $proposal, array $input = [], bool $autoApplied = false): ProposalApplyResult
    {
        if (!$proposal->isPending()) {
            throw new \InvalidArgumentException('Cette proposition a déjà été traitée.');
        }

        foreach ($this->handlers as $handler) {
            if ($handler->supports($proposal->getKind())) {
                $result = $handler->apply($proposal, $input);
                if ($autoApplied) {
                    $proposal->annotatePayload(['auto_applied' => true]);
                }
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
