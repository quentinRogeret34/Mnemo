<?php

namespace App\Proposal\Handler;

use App\Entity\Node;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Outcome of applying a single Proposal: the created/kept graph Node (if any) and the user-facing flash message.
 */
final readonly class ProposalApplyResult
{
    public function __construct(
        public ?Node $node,
        public TranslatableMessage $flash,
    ) {
    }
}
