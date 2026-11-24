<?php

namespace App\Proposal\Handler;

use App\Entity\AgentAsset;
use App\Entity\Proposal;
use App\Enum\AgentAssetKind;
use App\Enum\ProposalKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class SkillUpdateProposalHandler implements ProposalHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(ProposalKind $kind): bool
    {
        return ProposalKind::SkillUpdate === $kind;
    }

    public function apply(Proposal $proposal, array $input): ProposalApplyResult
    {
        $project = $proposal->getProject();
        if (null === $project) {
            throw new \InvalidArgumentException('Cette proposition de skill n\'est rattachée à aucun projet.');
        }

        $payload = $proposal->getPayload();
        $kind = AgentAssetKind::tryFrom((string) ($payload['kind'] ?? 'skill')) ?? AgentAssetKind::Skill;
        $originalName = trim((string) ($payload['name'] ?? ''));
        $originalDescription = trim((string) ($payload['description'] ?? ''));
        $originalContent = trim((string) ($payload['content'] ?? ''));

        $name = trim($input['name'] ?? $originalName);
        $description = trim($input['description'] ?? $originalDescription);
        $content = trim($input['content'] ?? $originalContent);

        if ('' === $name || mb_strlen($name) > 64 || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name)) {
            throw new \InvalidArgumentException('Le nom du skill doit être en minuscules, chiffres et tirets (64 caractères max).');
        }
        if ('' === $description || mb_strlen($description) > 500 || '' === $content || mb_strlen($content) > 100000) {
            throw new \InvalidArgumentException('La description (500 max) et les instructions sont obligatoires.');
        }

        $repository = $this->entityManager->getRepository(AgentAsset::class);
        $asset = null;
        if (isset($payload['asset_id']) && (int) $payload['asset_id'] > 0) {
            $asset = $repository->findOneBy(['id' => (int) $payload['asset_id'], 'project' => $project]);
        }
        $asset ??= $repository->findOneBy(['project' => $project, 'kind' => $kind, 'name' => $name]);
        if (null === $asset) {
            $asset = new AgentAsset($project);
            $asset->setKind($kind);
            $this->entityManager->persist($asset);
        }

        $asset->setName($name);
        $asset->setDescription($description);
        $asset->setContent($content);

        $wasEdited = $name !== $originalName || $description !== $originalDescription || $content !== $originalContent;
        $proposal->annotatePayload([
            'human_feedback' => $wasEdited
                ? ['edited' => true, 'applied_name' => $name]
                : ['edited' => false],
            'applied_asset_name' => $name,
        ]);

        return new ProposalApplyResult(null, new TranslatableMessage('flash.proposal.applied_skill', ['name' => $name]));
    }
}
