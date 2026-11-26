<?php

namespace App\Controller\Factory;

use App\Factory\FactoryGuard;
use App\Repository\AgentSessionRepository;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class DeleteSessionAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly AgentSessionRepository $sessionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/sessions/{publicId}/delete', name: 'factory_session_delete', methods: ['POST'])]
    public function __invoke(string $publicId, Request $request): RedirectResponse
    {
        $this->factoryGuard->requireAccess();
        $session = $this->sessionRepository->findByPublicId($publicId);
        if (null === $session || !$this->projectScope->allows($session->getProject())) {
            throw $this->createNotFoundException('Agent session not found.');
        }
        if (!$this->isCsrfTokenValid('factory_session_delete_'.$session->getPublicId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $projectSlug = $session->getProject()->getSlug();
        $wasArchived = $session->isArchived();
        $this->entityManager->remove($session);
        $this->entityManager->flush();
        $this->addFlash('notice', 'factory.flash.deleted');

        $returnScope = $request->request->getString('return_scope', 'project');
        if ('workspace' === $returnScope) {
            return $this->redirectToRoute('factory_index', $wasArchived ? ['archived' => 1] : []);
        }

        return $this->redirectToRoute('project_factory', array_filter([
            'slug' => $projectSlug,
            'archived' => $wasArchived ? 1 : null,
        ], static fn (mixed $v): bool => null !== $v));
    }
}
