<?php

namespace App\Controller\Factory;

use App\Factory\FactoryGuard;
use App\Factory\Swarm\SwarmOrchestrator;
use App\Repository\SwarmRunRepository;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SwarmControlAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly SwarmRunRepository $swarmRepository,
        private readonly SwarmOrchestrator $orchestrator,
        private readonly ProjectScope $projectScope,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/factory/swarms/{publicId}/control', name: 'factory_swarm_control', methods: ['POST'])]
    public function __invoke(string $publicId, Request $request): Response
    {
        $this->factoryGuard->requireAccess();
        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm || !$this->projectScope->allows($swarm->getProject())) {
            throw $this->createNotFoundException('Swarm run not found.');
        }
        if (!$this->isCsrfTokenValid('factory_swarm_control_'.$swarm->getPublicId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $action = $request->request->getString('action');
        $actor = $this->getUser()?->getUserIdentifier() ?? 'human';
        $summary = trim($request->request->getString('summary')) ?: null;

        match ($action) {
            'pause' => $this->orchestrator->pause($swarm, $actor),
            'resume' => $this->orchestrator->resume($swarm, $actor),
            'complete' => $this->orchestrator->complete($swarm, $actor, $summary),
            'cancel' => $this->orchestrator->cancel($swarm, $actor),
            'run_now' => $this->orchestrator->advanceSynchronously($swarm),
            default => null,
        };

        $wantsJson = $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');
        if ($wantsJson) {
            return new JsonResponse([
                'status' => $swarm->getStatus()->value,
                'status_label' => $swarm->getStatus()->trans($this->translator),
                'is_active' => $swarm->getStatus()->isActive(),
                'review_summary' => $swarm->getReviewSummary(),
            ]);
        }

        $this->addFlash('notice', 'complete' === $action ? 'factory.hub.flash.swarm_completed' : 'factory.swarm.flash.updated');

        return $this->redirectToRoute('factory_swarm_show', ['publicId' => $swarm->getPublicId()]);
    }
}
