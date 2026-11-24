<?php

namespace App\Controller\Connector;

use App\Entity\Connector;
use App\Security\OAuth\OAuthClient;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/oauth/github/check/connector', name: 'connector_oauth_check', methods: ['GET'])]
final class FinishGitHubOAuthAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OAuthClient $oauthClient,
        private readonly SecretBox $secretBox,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $pending = $request->getSession()->remove(ConnectorOAuthState::GITHUB_OAUTH);
        $connector = \is_array($pending) && hash_equals((string) $pending['state'], $request->query->getString('state')) ? $this->entityManager->find(Connector::class, $pending['connector']) : null;
        if (null === $connector || !ConnectorOAuthState::isGitHubOAuthAvailable($connector, $this->oauthClient)) {
            $this->addFlash('error', new TranslatableMessage('flash.github.expired'));

            return $this->redirectToRoute('connector_index');
        }

        try {
            $connector->setOauthToken($this->secretBox->encrypt($this->oauthClient->accessToken('github', $request->query->getString('code'), $this->generateUrl('connector_oauth_check', [], UrlGeneratorInterface::ABSOLUTE_URL))));
            $this->entityManager->flush();
            $this->addFlash('success', new TranslatableMessage('flash.connector.github_connected'));
        } catch (\Throwable $exception) {
            $this->logger->warning('GitHub OAuth token exchange failed for connector {id}: {message}', ['id' => $connector->getId(), 'message' => $exception->getMessage()]);
            $this->addFlash('error', new TranslatableMessage('flash.github.refused'));
        }

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }
}
