<?php

namespace App\Controller;

use App\Connector\ConnectorPresets;
use App\Entity\Connector;
use App\Entity\ConnectorRun;
use App\Entity\Project;
use App\Enum\ConnectorMode;
use App\Forge\GitHubAccount;
use App\Message\SyncConnector;
use App\Project\ProjectResolver;
use App\Security\OAuth\OAuthClient;
use App\Security\ProjectScope;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Lists the repositories of a GitHub account and connects the chosen ones: one "GitHub (code et historique)" connector
 * per repository, reading it with the token of the account, then cloned and analysed by the worker.
 */
final class GitHubRepositoryController extends AbstractController
{
    private const STATE = 'github_repositories_state';
    // Encrypted, and only until the repositories are connected: each connector then keeps its own copy.
    private const TOKEN = 'github_repositories_token';
    // The project the user came from, kept while GitHub has the hand: its callback URL takes no parameter of ours.
    private const PROJECT = 'github_repositories_project';
    private const NEW_PROJECTS = 'new';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OAuthClient $oauthClient,
        private readonly SecretBox $secretBox,
        private readonly GitHubAccount $account,
        private readonly ConnectorPresets $presets,
        private readonly MessageBusInterface $bus,
        private readonly ProjectScope $projectScope,
        private readonly ProjectResolver $projectResolver,
    ) {
    }

    #[Route('/connectors/github', name: 'github_repositories', methods: ['GET'])]
    public function index(Request $request, #[MapQueryParameter(name: 'project')] ?string $slug = null): Response
    {
        $this->checkAvailable();
        $selected = $this->projectScope->bySlug($slug);
        $token = $request->getSession()->get(self::TOKEN);
        $repositories = null;
        if (\is_string($token)) {
            try {
                $repositories = $this->account->repositories($this->secretBox->decrypt($token));
            } catch (\Throwable) {
                $request->getSession()->remove(self::TOKEN);
                $this->addFlash('error', new TranslatableMessage('flash.github.token_refused'));
            }
        }
        $connected = array_map(
            static fn (Connector $connector): string => strtolower((string) ($connector->getArguments()['repository'] ?? '')),
            $this->entityManager->getRepository(Connector::class)->findBy(['mode' => ConnectorMode::GitHub]),
        );

        return $this->render('connector/github.html.twig', [
            'repositories' => $repositories,
            'connected' => $connected,
            'projects' => $this->projectScope->projects(),
            'selected' => $selected,
            'scoped' => null !== $this->projectScope->slugs(),
            'may_create' => $this->projectResolver->mayCreate(),
        ]);
    }

    #[Route('/connectors/github/oauth', name: 'github_repositories_oauth', methods: ['POST'])]
    public function oauth(Request $request): Response
    {
        $this->checkAvailable();
        $this->checkToken($request);
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::STATE, $state);
        $request->getSession()->set(self::PROJECT, $request->getPayload()->getString('project'));

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
        $back = array_filter(['project' => (string) $request->getSession()->remove(self::PROJECT)]);
        if (!\is_string($state) || !hash_equals($state, $request->query->getString('state'))) {
            $this->addFlash('error', new TranslatableMessage('flash.github.expired'));

            return $this->redirectToRoute('github_repositories', $back);
        }

        try {
            $request->getSession()->set(self::TOKEN, $this->secretBox->encrypt($this->oauthClient->accessToken('github', $request->query->getString('code'), $this->callbackUrl())));
        } catch (\Throwable) {
            $this->addFlash('error', new TranslatableMessage('flash.github.refused'));
        }

        return $this->redirectToRoute('github_repositories', $back);
    }

    #[Route('/connectors/github', name: 'github_repositories_connect', methods: ['POST'])]
    public function connect(Request $request): Response
    {
        $this->checkAvailable();
        $this->checkToken($request);
        $back = array_filter(['project' => $request->query->getString('project')]);
        $token = $request->getSession()->get(self::TOKEN);
        if (!\is_string($token)) {
            $this->addFlash('error', new TranslatableMessage('flash.github.expired'));

            return $this->redirectToRoute('github_repositories', $back);
        }
        $perRepository = self::NEW_PROJECTS === $request->getPayload()->getString('project') && $this->projectResolver->mayCreate();
        $project = $perRepository ? null : $this->entityManager->find(Project::class, $request->getPayload()->getInt('project'));
        $repositories = array_values(array_unique(array_filter(
            array_map('strval', $request->getPayload()->all('repositories')),
            static fn (string $repository): bool => 1 === preg_match('#^[\w.-]+/[\w.-]+$#', $repository),
        )));
        if ((!$perRepository && (null === $project || $project->isArchived() || !$this->projectScope->allows($project))) || [] === $repositories) {
            $this->addFlash('error', new TranslatableMessage('flash.github.choose'));

            return $this->redirectToRoute('github_repositories', $back);
        }

        $connectors = [];
        $skipped = [];
        foreach ($repositories as $repository) {
            $target = $perRepository ? $this->projectFor($repository) : $project;
            if (null === $target) {
                $skipped[] = $repository;
                continue;
            }
            $connector = new Connector();
            $this->presets->apply('github-api', $connector);
            $connector->setName($repository);
            $connector->setCredentials([]);
            $connector->setArguments(['repository' => $repository] + $connector->getArguments());
            $connector->setOauthToken($token);
            $connector->setProject($target);
            $this->entityManager->persist($connector);
            $connectors[] = $connector;
        }
        $this->entityManager->flush();
        foreach ($connectors as $connector) {
            $this->bus->dispatch(new SyncConnector($connector->getId(), ConnectorRun::MANUAL));
        }
        $request->getSession()->remove(self::TOKEN);
        if ([] !== $connectors) {
            $this->addFlash('success', $perRepository
                ? new TranslatableMessage('flash.github.connected_each', ['count' => \count($connectors)])
                : new TranslatableMessage('flash.github.connected', ['count' => \count($connectors), 'name' => $project->getName()]));
        }
        if ([] !== $skipped) {
            $this->addFlash('error', new TranslatableMessage('flash.github.skipped', ['repositories' => implode(', ', $skipped)]));
        }

        $single = $project ?? (1 === \count($connectors) ? $connectors[0]->getProject() : null);

        // The list of all connectors is closed to a caller limited to some projects.
        return null !== $single && ([] !== $back || null !== $this->projectScope->slugs() || $perRepository)
            ? $this->redirectToRoute('project_settings', ['slug' => $single->getSlug()])
            : $this->redirectToRoute(null !== $this->projectScope->slugs() ? 'project_index' : 'connector_index');
    }

    /**
     * The project of a repository connected "one project per repository": the one already tied to its remote,
     * or a new one named after the repository.
     */
    private function projectFor(string $repository): ?Project
    {
        $remote = $this->projectResolver->normalize('github.com/'.$repository);
        $projects = $this->entityManager->getRepository(Project::class);
        if (null !== $existing = $projects->findOneBy(['repository' => $remote])) {
            return $existing->isArchived() || !$this->projectScope->allows($existing) ? null : $existing;
        }

        [$owner, $name] = explode('/', $repository);
        foreach (array_unique([$this->projectResolver->slug($name), $this->projectResolver->slug($owner.'-'.$name)]) as $slug) {
            if ('' === $slug || 'new' === $slug || null !== $projects->findOneBy(['slug' => $slug])) {
                continue;
            }
            $project = new Project($slug, $name, '');
            $project->setRepository($remote);
            $this->projectResolver->register($project);

            return $project;
        }

        return null;
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
