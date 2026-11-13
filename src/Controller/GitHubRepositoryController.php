<?php

namespace App\Controller;

use App\Connector\ConnectorPresets;
use App\Entity\Connector;
use App\Entity\ConnectorRun;
use App\Entity\Project;
use App\Enum\ConnectorMode;
use App\Forge\GitHubAccount;
use App\Message\SyncConnector;
use App\Security\OAuth\OAuthClient;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Lists the repositories of a GitHub account and connects the chosen ones: one "GitHub (code et historique)" connector
 * per repository, reading it with the token of the account, then cloned and analysed by the worker.
 */
final class GitHubRepositoryController extends AbstractController
{
    private const STATE = 'github_repositories_state';
    // Encrypted, and only until the repositories are connected: each connector then keeps its own copy.
    private const TOKEN = 'github_repositories_token';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OAuthClient $oauthClient,
        private readonly SecretBox $secretBox,
        private readonly GitHubAccount $account,
        private readonly ConnectorPresets $presets,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/connectors/github', name: 'github_repositories', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->checkAvailable();
        $token = $request->getSession()->get(self::TOKEN);
        $repositories = null;
        if (\is_string($token)) {
            try {
                $repositories = $this->account->repositories($this->secretBox->decrypt($token));
            } catch (\Throwable) {
                $request->getSession()->remove(self::TOKEN);
                $this->addFlash('error', 'GitHub a refusé le jeton : reconnecter le compte.');
            }
        }
        $connected = array_map(
            static fn (Connector $connector): string => strtolower((string) ($connector->getArguments()['repository'] ?? '')),
            $this->entityManager->getRepository(Connector::class)->findBy(['mode' => ConnectorMode::GitHub]),
        );

        return $this->render('connector/github.html.twig', [
            'repositories' => $repositories,
            'connected' => $connected,
            'projects' => $this->entityManager->getRepository(Project::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/connectors/github/oauth', name: 'github_repositories_oauth', methods: ['POST'])]
    public function oauth(Request $request): Response
    {
        $this->checkAvailable();
        $this->checkToken($request);
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::STATE, $state);

        return $this->redirect($this->oauthClient->authorizeUrl('github', $this->callbackUrl(), $state, 'repo'));
    }

    /**
     * Under the sign-in callback path: GitHub only accepts a redirect below the callback URL of the application.
     */
    #[Route('/oauth/github/check/repositories', name: 'github_repositories_check', methods: ['GET'])]
    public function oauthCheck(Request $request): Response
    {
        $this->checkAvailable();
        $state = $request->getSession()->remove(self::STATE);
        if (!\is_string($state) || !hash_equals($state, $request->query->getString('state'))) {
            $this->addFlash('error', 'La connexion à GitHub a expiré, recommencer.');

            return $this->redirectToRoute('github_repositories');
        }

        try {
            $request->getSession()->set(self::TOKEN, $this->secretBox->encrypt($this->oauthClient->accessToken('github', $request->query->getString('code'), $this->callbackUrl())));
        } catch (\Throwable) {
            $this->addFlash('error', 'GitHub a refusé la connexion.');
        }

        return $this->redirectToRoute('github_repositories');
    }

    #[Route('/connectors/github', name: 'github_repositories_connect', methods: ['POST'])]
    public function connect(Request $request): Response
    {
        $this->checkAvailable();
        $this->checkToken($request);
        $token = $request->getSession()->get(self::TOKEN);
        if (!\is_string($token)) {
            $this->addFlash('error', 'La connexion à GitHub a expiré, recommencer.');

            return $this->redirectToRoute('github_repositories');
        }
        $project = $this->entityManager->find(Project::class, $request->getPayload()->getInt('project'));
        $repositories = array_values(array_unique(array_filter(
            array_map('strval', $request->getPayload()->all('repositories')),
            static fn (string $repository): bool => 1 === preg_match('#^[\w.-]+/[\w.-]+$#', $repository),
        )));
        if (null === $project || [] === $repositories) {
            $this->addFlash('error', 'Choisir un projet et au moins un dépôt.');

            return $this->redirectToRoute('github_repositories');
        }

        $connectors = [];
        foreach ($repositories as $repository) {
            $connector = new Connector();
            $this->presets->apply('github-api', $connector);
            $connector->setName($repository);
            $connector->setCredentials([]);
            $connector->setArguments(['repository' => $repository] + $connector->getArguments());
            $connector->setOauthToken($token);
            $connector->setProject($project);
            $this->entityManager->persist($connector);
            $connectors[] = $connector;
        }
        $this->entityManager->flush();
        foreach ($connectors as $connector) {
            $this->bus->dispatch(new SyncConnector($connector->getId(), ConnectorRun::MANUAL));
        }
        $request->getSession()->remove(self::TOKEN);
        $this->addFlash('success', \sprintf('%d dépôt(s) branché(s) sur %s : clonés et analysés en arrière-plan.', \count($connectors), $project->getName()));

        return $this->redirectToRoute('connector_index');
    }

    private function callbackUrl(): string
    {
        return $this->generateUrl('github_repositories_check', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function checkAvailable(): void
    {
        if (!isset($this->oauthClient->enabled()['github'])) {
            throw $this->createNotFoundException('GitHub OAuth is not configured (OAUTH_GITHUB_CLIENT_ID).');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('github-repositories', $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
