<?php

namespace App\Controller\Connector;

use App\Entity\Connector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/connectors/{id}/oauth/disconnect', name: 'connector_oauth_disconnect', requirements: ['id' => '\d+'], methods: ['POST'])]
final class DisconnectGitHubOAuthAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(Connector $connector, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('connector-'.$connector->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $connector->setOauthToken(null);
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage('flash.connector.github_disconnected'));

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }
}
