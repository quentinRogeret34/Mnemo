<?php

namespace App\Proposal\Handler;

use App\Entity\Proposal;
use App\Enum\ProposalKind;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Strategy contract for applying one or more ProposalKind values.
 * Adding a new ProposalKind only requires adding a tagged implementation — ProposalApplier stays closed to modification.
 */
#[AutoconfigureTag('app.proposal_handler')]
interface ProposalHandlerInterface
{
    public function supports(ProposalKind $kind): bool;

    /**
     * @param array{lesson?: string, keep?: int, title?: string, statement?: string, name?: string, description?: string, content?: string} $input
     */
    public function apply(Proposal $proposal, array $input): ProposalApplyResult;
}
