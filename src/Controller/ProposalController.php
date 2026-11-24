<?php

namespace App\Controller;

use App\Entity\Proposal;
use App\Enum\ProposalKind;
use App\Pagination\Page;
use App\Proposal\ProposalApplier;
use App\Repository\ProposalRepository;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

final class ProposalController extends AbstractController
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly ProposalRepository $proposalRepository,
        private readonly ProjectScope $projectScope,
        private readonly ProposalApplier $applier,
    ) {
    }

    #[Route('/proposals', name: 'proposal_index', methods: ['GET'])]
    public function index(#[MapQueryParameter] ?string $project = null, #[MapQueryParameter] ?string $kind = null, #[MapQueryParameter] int $page = 1): Response
    {
        $page = max(1, $page);
        $projectEntity = $this->projectScope->bySlug($project);
        $kindEnum = null === $kind ? null : ProposalKind::tryFrom($kind);
        $kindCounts = $this->proposalRepository->countPendingByKind($projectEntity);

        return $this->render('proposal/index.html.twig', [
            'project' => $projectEntity,
            'projects' => $this->projectScope->projects(),
            'kind' => $kindEnum,
            'kinds' => ProposalKind::cases(),
            'kind_counts' => $kindCounts,
            'all_count' => array_sum($kindCounts),
            'proposals' => new Page(
                $this->proposalRepository->findPending($projectEntity, $kindEnum, self::PAGE_SIZE, Page::offset($page, self::PAGE_SIZE)),
                $this->proposalRepository->countPending($projectEntity, $kindEnum),
                $page,
                self::PAGE_SIZE,
            ),
        ]);
    }

    #[Route('/proposals/{id}/apply', name: 'proposal_apply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function apply(Proposal $proposal, Request $request): Response
    {
        $this->checkToken($proposal, $request);
        $payload = $request->getPayload();
        $input = array_filter([
            'lesson' => $payload->has('lesson') ? $payload->getString('lesson') : null,
            'keep' => $payload->has('keep') ? $payload->getInt('keep') : null,
            'title' => $payload->has('title') ? $payload->getString('title') : null,
            'statement' => $payload->has('statement') ? $payload->getString('statement') : null,
            'name' => $payload->has('name') ? $payload->getString('name') : null,
            'description' => $payload->has('description') ? $payload->getString('description') : null,
            'content' => $payload->has('content') ? $payload->getString('content') : null,
        ], static fn (mixed $value): bool => null !== $value);

        try {
            $result = $this->applier->applyWithResult($proposal, $input);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->back($request);
        }

        $this->addFlash('success', $result->flash);

        return $this->back($request);
    }

    #[Route('/proposals/{id}/reject', name: 'proposal_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(Proposal $proposal, Request $request): Response
    {
        $this->checkToken($proposal, $request);
        $reason = trim($request->getPayload()->getString('reason'));
        try {
            $this->applier->reject($proposal, '' === $reason ? null : $reason);
            $this->addFlash('success', new TranslatableMessage('flash.proposal.rejected'));
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->back($request);
    }

    private function checkToken(Proposal $proposal, Request $request): void
    {
        $allowed = null === $proposal->getProject()
            ? null === $this->projectScope->slugs()
            : $this->projectScope->allows($proposal->getProject());
        if (!$allowed) {
            throw $this->createNotFoundException('Proposal not found.');
        }
        if (!$this->isCsrfTokenValid('proposal-'.$proposal->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    /**
     * Back to the list with the same filters.
     */
    private function back(Request $request): Response
    {
        $filters = array_filter(['project' => $request->query->get('project'), 'kind' => $request->query->get('kind')]);

        return $this->redirectToRoute('proposal_index', $filters);
    }
}
