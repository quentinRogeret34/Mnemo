<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Proposal;
use App\Enum\ProposalKind;
use App\Enum\ProposalStatus;
use App\Pagination\Page;
use App\Proposal\ProposalApplier;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ProposalController extends AbstractController
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectScope $projectScope,
        private readonly ProposalApplier $applier,
    ) {
    }

    #[Route('/proposals', name: 'proposal_index', methods: ['GET'])]
    public function index(#[MapQueryParameter] ?string $project = null, #[MapQueryParameter] ?string $kind = null, #[MapQueryParameter] int $page = 1): Response
    {
        $page = max(1, $page);
        $repository = $this->entityManager->getRepository(Proposal::class);
        $projectEntity = $this->projectScope->bySlug($project);
        $kindEnum = null === $kind ? null : ProposalKind::tryFrom($kind);

        $criteria = ['status' => ProposalStatus::Pending] + (null === $projectEntity ? [] : ['project' => $projectEntity]) + (null === $kindEnum ? [] : ['kind' => $kindEnum]);

        return $this->render('proposal/index.html.twig', [
            'project' => $projectEntity,
            'kind' => $kindEnum,
            'kinds' => ProposalKind::cases(),
            'proposals' => new Page($repository->findBy($criteria, ['createdAt' => 'ASC', 'id' => 'ASC'], self::PAGE_SIZE, Page::offset($page, self::PAGE_SIZE)), $repository->count($criteria), $page, self::PAGE_SIZE),
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
        ], static fn (mixed $value): bool => null !== $value);

        try {
            $memory = $this->applier->apply($proposal, $input);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->back($request);
        }

        $this->addFlash('success', null === $memory ? 'Proposition appliquée.' : \sprintf('Proposition appliquée : « %s ».', $memory->getLabel()));

        return $this->back($request);
    }

    #[Route('/proposals/{id}/reject', name: 'proposal_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(Proposal $proposal, Request $request): Response
    {
        $this->checkToken($proposal, $request);
        try {
            $this->applier->reject($proposal);
            $this->addFlash('success', 'Proposition rejetée.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->back($request);
    }

    private function checkToken(Proposal $proposal, Request $request): void
    {
        if (null !== $proposal->getProject() && !$this->projectScope->allows($proposal->getProject())) {
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
