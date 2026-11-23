<?php

namespace App\Controller;

use App\Security\OAuth\OAuthAuthenticator;
use App\Security\OAuth\OAuthClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class OAuthController extends AbstractController
{
    #[Route('/oauth/{provider}', name: 'oauth_connect', requirements: ['provider' => 'github|gitlab|google'], methods: ['GET'])]
    public function connect(string $provider, Request $request, OAuthClient $client): Response
    {
        if (!isset($client->enabled()[$provider])) {
            throw $this->createNotFoundException(\sprintf('Sign-in provider "%s" is not configured.', $provider));
        }
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $request->getSession()->set(OAuthAuthenticator::STATE, ['state' => $state, 'provider' => $provider, 'verifier' => $verifier]);

        return $this->redirect($client->authorizeUrl($provider, $this->generateUrl('oauth_check', ['provider' => $provider], UrlGeneratorInterface::ABSOLUTE_URL), $state, codeChallenge: $challenge));
    }

    #[Route('/oauth/{provider}/check', name: 'oauth_check', requirements: ['provider' => 'github|gitlab|google'], methods: ['GET'])]
    public function check(): never
    {
        throw new \LogicException('Handled by OAuthAuthenticator.');
    }

    #[Route('/pending', name: 'pending', methods: ['GET'])]
    public function pending(): Response
    {
        return $this->isGranted('ROLE_USER') ? $this->redirectToRoute('dashboard') : $this->render('security/pending.html.twig');
    }
}
