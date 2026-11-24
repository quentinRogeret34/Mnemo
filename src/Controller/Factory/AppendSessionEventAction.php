<?php

namespace App\Controller\Factory;

use App\Enum\AgentSessionEventKind;
use App\Factory\AgentSessionRecorder;
use App\Factory\FactoryGuard;
use App\Repository\AgentSessionRepository;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AppendSessionEventAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly AgentSessionRepository $sessionRepository,
        private readonly AgentSessionRecorder $recorder,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/sessions/{publicId}/events', name: 'factory_session_event', methods: ['POST'])]
    public function __invoke(string $publicId, Request $request): RedirectResponse
    {
        $this->factoryGuard->requireAccess();
        $session = $this->sessionRepository->findByPublicId($publicId);
        if (null === $session || !$this->projectScope->allows($session->getProject())) {
            throw $this->createNotFoundException('Agent session not found.');
        }
        if (!$this->isCsrfTokenValid('factory_session_event_'.$session->getPublicId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $message = trim($request->request->getString('message'));
        if ('' === $message) {
            $this->addFlash('error', 'factory.flash.message_required');

            return $this->redirectToRoute('factory_session_show', ['publicId' => $session->getPublicId()]);
        }

        $kind = AgentSessionEventKind::tryFrom($request->request->getString('kind')) ?? AgentSessionEventKind::Step;
        $rawFiles = trim($request->request->getString('files'));
        $files = '' === $rawFiles ? [] : array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $rawFiles) ?: []), static fn (string $v): bool => '' !== $v));

        $this->recorder->recordEvent($session, $kind, $message, $files);
        $this->addFlash('notice', 'factory.flash.event_added');

        return $this->redirectToRoute('factory_session_show', ['publicId' => $session->getPublicId()]);
    }
}
