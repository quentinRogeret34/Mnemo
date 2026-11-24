<?php

namespace App\Controller\Connector;

use App\Entity\Connector;
use App\Forge\RepositoryCheckout;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/connectors/{id}/delete', name: 'connector_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
final class DeleteConnectorAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RepositoryCheckout $checkout,
    ) {
    }

    public function __invoke(Connector $connector, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('connector-'.$connector->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $this->checkout->remove($connector);
        $this->entityManager->remove($connector);
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage('flash.connector.deleted'));

        return $this->redirectToRoute('connector_index');
    }
}
