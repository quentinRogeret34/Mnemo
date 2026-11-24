<?php

namespace App\Controller\Factory;

use App\Enum\AgentSessionEventKind;
use App\Factory\FactoryGuard;
use App\Repository\AgentSessionRepository;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowSessionAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly AgentSessionRepository $sessionRepository,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/sessions/{publicId}', name: 'factory_session_show', methods: ['GET'])]
    public function __invoke(string $publicId): Response
    {
        $this->factoryGuard->requireAccess();

        $session = $this->sessionRepository->findByPublicId($publicId);
        if (null === $session || !$this->projectScope->allows($session->getProject())) {
            throw $this->createNotFoundException('Agent session not found.');
        }

        return $this->render('factory/show.html.twig', [
            'project' => $session->getProject(),
            'session' => $session,
            'events' => $this->sessionRepository->eventsFor($session),
            'children' => $this->sessionRepository->childrenOf($session),
            'event_kinds' => [
                AgentSessionEventKind::Step,
                AgentSessionEventKind::Decision,
                AgentSessionEventKind::FileChanged,
                AgentSessionEventKind::Handoff,
                AgentSessionEventKind::Log,
                AgentSessionEventKind::Error,
            ],
        ]);
    }
}
