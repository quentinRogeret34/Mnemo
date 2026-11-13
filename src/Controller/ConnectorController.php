<?php

namespace App\Controller;

use App\Connector\ConnectorPresets;
use App\Connector\ConnectorRunner;
use App\Connector\McpClientFactory;
use App\Entity\Connector;
use App\Entity\ConnectorRun;
use App\Enum\ConnectorMode;
use App\Enum\ConnectorTransport;
use App\Forge\RepositoryCheckout;
use App\Form\ConnectorType;
use App\Llm\LlmSettings;
use App\Message\SyncConnector;
use App\Security\OAuth\OAuthClient;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ConnectorController extends AbstractController
{
    private const OAUTH_STATE = 'connector_oauth';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConnectorRunner $runner,
        private readonly McpClientFactory $clientFactory,
        private readonly LlmSettings $llmSettings,
        private readonly ConnectorPresets $presets,
        private readonly MessageBusInterface $bus,
        private readonly RepositoryCheckout $checkout,
        private readonly OAuthClient $oauthClient,
        private readonly SecretBox $secretBox,
    ) {
    }

    #[Route('/connectors', name: 'connector_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('connector/index.html.twig', [
            'connectors' => $this->entityManager->getRepository(Connector::class)->findBy([], ['name' => 'ASC']),
            'llm' => $this->llmSettings,
            'presets' => $this->presets->available(),
            'unavailable_presets' => $this->presets->unavailable(),
            'github_oauth' => isset($this->oauthClient->enabled()['github']),
        ]);
    }

    #[Route('/connectors/new', name: 'connector_new', methods: ['GET', 'POST'])]
    #[Route('/connectors/{id}/edit', name: 'connector_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, ?Connector $connector = null, #[MapQueryParameter] ?string $preset = null): Response
    {
        $presetData = null === $connector && null !== $preset ? ($this->presets->get($preset) ?? throw $this->createNotFoundException(\sprintf('Unknown preset "%s".', $preset))) : null;
        if (null === $connector) {
            $connector = new Connector();
            if (null !== $presetData) {
                $this->presets->apply($preset, $connector);
            }
        }
        $form = $this->createForm(ConnectorType::class, $connector);
        $form->handleRequest($request);

        if ($form->isSubmitted() && ConnectorTransport::Stdio === $connector->getTransport() && !$this->clientFactory->stdioAllowed()) {
            $form->get('transport')->addError(new FormError('Les connecteurs STDIO sont désactivés (MCP_CONNECTORS_ALLOW_STDIO=0) : ils exécutent une commande dans le conteneur.'));
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($connector);
            $this->entityManager->flush();

            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        }

        return $this->render('connector/edit.html.twig', ['connector' => $connector, 'form' => $form, 'preset' => $presetData], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/connectors/{id}', name: 'connector_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Connector $connector): Response
    {
        return $this->render('connector/show.html.twig', ['connector' => $connector, 'inspection' => null, 'inspection_error' => null, 'oauth' => $this->oauthAvailable($connector)]);
    }

    /**
     * Sends the user to GitHub to let this connector read their repositories ("repo" is the narrowest scope an OAuth
     * application has for private repositories).
     */
    #[Route('/connectors/{id}/oauth', name: 'connector_oauth', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function oauth(Connector $connector, Request $request): Response
    {
        $this->checkToken($connector, $request);
        if (!$this->oauthAvailable($connector)) {
            throw $this->createNotFoundException('GitHub OAuth is not available for this connector.');
        }
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::OAUTH_STATE, ['state' => $state, 'connector' => $connector->getId()]);

        return $this->redirect($this->oauthClient->authorizeUrl('github', $this->generateUrl('connector_oauth_check', [], UrlGeneratorInterface::ABSOLUTE_URL), $state, 'repo'));
    }

    /**
     * Under the sign-in callback path: GitHub only accepts a redirect below the callback URL of the application.
     */
    #[Route('/oauth/github/check/connector', name: 'connector_oauth_check', methods: ['GET'])]
    public function oauthCheck(Request $request): Response
    {
        $pending = $request->getSession()->remove(self::OAUTH_STATE);
        $connector = \is_array($pending) && hash_equals((string) $pending['state'], $request->query->getString('state')) ? $this->entityManager->find(Connector::class, $pending['connector']) : null;
        if (null === $connector || !$this->oauthAvailable($connector)) {
            $this->addFlash('error', 'La connexion à GitHub a expiré, recommencer.');

            return $this->redirectToRoute('connector_index');
        }

        try {
            $connector->setOauthToken($this->secretBox->encrypt($this->oauthClient->accessToken('github', $request->query->getString('code'), $this->generateUrl('connector_oauth_check', [], UrlGeneratorInterface::ABSOLUTE_URL))));
            $this->entityManager->flush();
            $this->addFlash('success', 'Compte GitHub connecté : ce connecteur lit le dépôt avec son jeton.');
        } catch (\Throwable) {
            $this->addFlash('error', 'GitHub a refusé la connexion.');
        }

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }

    #[Route('/connectors/{id}/oauth/disconnect', name: 'connector_oauth_disconnect', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function oauthDisconnect(Connector $connector, Request $request): Response
    {
        $this->checkToken($connector, $request);
        $connector->setOauthToken(null);
        $this->entityManager->flush();
        $this->addFlash('success', 'Compte GitHub déconnecté. Le jeton reste valide chez GitHub tant qu\'il n\'y est pas révoqué.');

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }

    #[Route('/connectors/{id}/inspect', name: 'connector_inspect', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function inspect(Connector $connector, Request $request): Response
    {
        $this->checkToken($connector, $request);
        try {
            $inspection = $this->runner->inspect($connector);
            $error = null;
        } catch (\Throwable $exception) {
            $inspection = null;
            $error = $exception->getMessage();
        }

        return $this->render('connector/show.html.twig', ['connector' => $connector, 'inspection' => $inspection, 'inspection_error' => $error, 'oauth' => $this->oauthAvailable($connector)]);
    }

    #[Route('/connectors/{id}/sync', name: 'connector_sync', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sync(Connector $connector, Request $request): Response
    {
        $this->checkToken($connector, $request);
        // Cloning a repository and building its graph takes minutes: the worker does it.
        if ($connector->getMode()->isForge() && true === ($connector->getArguments()['code_graph'] ?? false)) {
            $this->bus->dispatch(new SyncConnector($connector->getId(), ConnectorRun::MANUAL));
            $this->addFlash('success', 'Synchronisation lancée : le dépôt est cloné et analysé en arrière-plan, le résultat apparaît dans l\'historique.');

            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        }
        $report = $this->runner->sync($connector);
        if (null === $report['error']) {
            $this->addFlash('success', \sprintf('Import terminé : %d créés, %d mis à jour, %d inchangés, %d ignorés.', $report['created'], $report['updated'], $report['unchanged'], \count($report['skipped'])));
        }

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }

    #[Route('/connectors/{id}/delete', name: 'connector_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Connector $connector, Request $request): Response
    {
        $this->checkToken($connector, $request);
        $this->checkout->remove($connector);
        $this->entityManager->remove($connector);
        $this->entityManager->flush();
        $this->addFlash('success', 'Connecteur supprimé. Les documents et mémoires importés sont conservés.');

        return $this->redirectToRoute('connector_index');
    }

    private function oauthAvailable(Connector $connector): bool
    {
        return ConnectorMode::GitHub === $connector->getMode() && 'api.github.com' === parse_url($connector->getEndpoint(), \PHP_URL_HOST) && isset($this->oauthClient->enabled()['github']);
    }

    private function checkToken(Connector $connector, Request $request): void
    {
        if (!$this->isCsrfTokenValid('connector-'.$connector->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
