<?php

namespace App\Controller\Factory;

use App\Enum\AgentSessionStatus;
use App\Factory\AgentSessionRecorder;
use App\Factory\FactoryGuard;
use App\Repository\AgentSessionRepository;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class FinishSessionAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly AgentSessionRepository $sessionRepository,
        private readonly AgentSessionRecorder $recorder,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/sessions/{publicId}/finish', name: 'factory_session_finish', methods: ['POST'])]
    public function __invoke(string $publicId, Request $request): RedirectResponse
    {
        $this->factoryGuard->requireAccess();
        $session = $this->sessionRepository->findByPublicId($publicId);
        if (null === $session || !$this->projectScope->allows($session->getProject())) {
            throw $this->createNotFoundException('Agent session not found.');
        }
        if (!$this->isCsrfTokenValid('factory_session_finish_'.$session->getPublicId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $status = AgentSessionStatus::tryFrom($request->request->getString('status'));
        if (null === $status || !$status->isTerminal()) {
            $status = AgentSessionStatus::Succeeded;
        }
        $summary = trim($request->request->getString('summary')) ?: null;

        $this->recorder->finish($session, $status, $summary);
        $this->addFlash('notice', 'factory.flash.finished');

        return $this->redirectToRoute('factory_session_show', ['publicId' => $session->getPublicId()]);
    }
}
