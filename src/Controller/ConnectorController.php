<?php

namespace App\Controller;

use App\Connector\ConnectorPresets;
use App\Connector\ConnectorRunner;
use App\Connector\CredentialResolver;
use App\Connector\McpAuthorization;
use App\Connector\McpClientFactory;
use App\Entity\Connector;
use App\Entity\ConnectorRun;
use App\Entity\Folder;
use App\Entity\Project;
use App\Enum\ConnectorMode;
use App\Enum\ConnectorSchedule;
use App\Enum\ConnectorTransport;
use App\Enum\MemoryType;
use App\Forge\GitHubApp;
use App\Forge\RepositoryCheckout;
use App\Form\ConnectorType;
use App\Llm\LlmSettings;
use App\Llm\ModelCheck;
use App\Message\ReviewPullRequest;
use App\Message\SyncConnector;
use App\Review\PullRequestReviewer;
use App\Security\OAuth\OAuthClient;
use App\Security\SafeUrl;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConnectorController extends AbstractController
{
    private const OAUTH_STATE = 'connector_oauth';
    private const AUTHORIZATION_STATE = 'connector_authorization';

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
        private readonly CredentialResolver $credentials,
        private readonly GitHubApp $gitHubApp,
        private readonly PullRequestReviewer $reviewer,
        private readonly ModelCheck $modelCheck,
        private readonly McpAuthorization $authorization,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/connectors', name: 'connector_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('connector/index.html.twig', [
            'connectors' => $this->entityManager->getRepository(Connector::class)->findBy([], ['name' => 'ASC']),
            'llm' => $this->llmSettings,
            'presets' => $this->presets->available(),
            'github_oauth' => isset($this->oauthClient->enabled()['github']),
            'projects' => $this->entityManager->getRepository(Project::class)->findBy(['archivedAt' => null], ['name' => 'ASC']),
            'folders' => $this->entityManager->getRepository(Folder::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    /**
     * One-click guided connection for curated integrations:
     * - mcp_oauth (Atlassian, Linear): creates the connector and jumps straight into OAuth authorization,
     *   then lands on the interactive tool-discovery wizard (/connectors/{id}/setup);
     * - notion_token (Notion): stores the pasted integration token encrypted without manual headers.
     */
    #[Route('/connectors/connect/{preset}', name: 'connector_connect', methods: ['POST'])]
    public function connect(string $preset, Request $request): Response
    {
        $presetData = $this->presets->get($preset) ?? throw $this->createNotFoundException(\sprintf('Unknown preset "%s".', $preset));
        if (!$this->isCsrfTokenValid('connector-connect-'.$preset, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $payload = $request->getPayload();
        $connector = new Connector();
        $this->presets->apply($preset, $connector);

        $customName = trim($payload->getString('name'));
        if ('' !== $customName) {
            $connector->setName(mb_substr($customName, 0, 120));
        }
        $projectId = $payload->getInt('project');
        if ($projectId > 0) {
            $connector->setProject($this->entityManager->find(Project::class, $projectId));
        }
        $folderId = $payload->getInt('folder');
        if ($folderId > 0) {
            $connector->setFolder($this->entityManager->find(Folder::class, $folderId));
        }

        if (ConnectorPresets::FLOW_NOTION_TOKEN === $presetData['flow']) {
            $token = trim($payload->getString('token'));
            if ('' === $token) {
                $this->addFlash('error', new TranslatableMessage('flash.connector.notion_token_required'));

                return $this->redirectToRoute('connector_index');
            }
            $connector->setCredentials($this->credentials->seal(['Authorization' => 'Bearer '.$token], []));
            $this->entityManager->persist($connector);
            $this->entityManager->flush();
            $this->addFlash('success', new TranslatableMessage('flash.connector.guided_created', ['name' => $connector->getName()]));

            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        }

        if (ConnectorPresets::FLOW_MCP_OAUTH === $presetData['flow']) {
            $connector->setCredentials([]);
            $this->entityManager->persist($connector);
            $this->entityManager->flush();

            $state = bin2hex(random_bytes(16));
            try {
                $flow = $this->authorization->start($connector, $this->generateUrl('connector_authorize_check', [], UrlGeneratorInterface::ABSOLUTE_URL), $state);
            } catch (\Throwable $exception) {
                $this->addFlash('error', new TranslatableMessage('flash.connector.oauth_impossible', ['error' => $exception->getMessage()]));

                return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
            }
            $request->getSession()->set(self::AUTHORIZATION_STATE, [
                'state' => $state,
                'connector' => $connector->getId(),
                'flow' => $flow['pending'],
                'setup' => true,
            ]);

            return $this->redirect($flow['url']);
        }

        return $this->redirectToRoute('connector_new', ['preset' => $preset]);
    }

    /**
     * Interactive MCP setup wizard: discovers live tools and resources from the connected MCP server
     * and generates typed form fields from each tool's inputSchema so no manual JSON is required.
     */
    #[Route('/connectors/{id}/setup', name: 'connector_setup', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function setup(Connector $connector, Request $request): Response
    {
        if ($connector->getMode()->isApi()) {
            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        }

        try {
            $inspection = $this->runner->inspect($connector);
            $inspectionError = null;
        } catch (\Throwable $exception) {
            $inspection = null;
            $inspectionError = $exception->getMessage();
        }

        if ($request->isMethod('POST')) {
            $this->checkToken($connector, $request);
            $payload = $request->getPayload();
            $mode = ConnectorMode::tryFrom($payload->getString('mode')) ?? ConnectorMode::Resources;
            if ($mode->isApi()) {
                $mode = ConnectorMode::Resources;
            }
            $connector->setMode($mode);

            if (ConnectorMode::Resources === $mode) {
                $connector->setTool(null);
                $connector->setArguments([]);
            } else {
                $toolName = trim($payload->getString('tool'));
                if ('' === $toolName) {
                    $this->addFlash('error', new TranslatableMessage('flash.connector.setup_choose_tool'));

                    return $this->redirectToRoute('connector_setup', ['id' => $connector->getId()]);
                }
                $connector->setTool($toolName);
                $toolMeta = null;
                foreach ($inspection['tools'] ?? [] as $discovered) {
                    if ($discovered['name'] === $toolName) {
                        $toolMeta = $discovered;
                        break;
                    }
                }
                $rawArgs = $payload->all('args');
                $connector->setArguments($this->coerceArguments($rawArgs, $toolMeta['fields'] ?? []));
                if (ConnectorMode::ToolMemories === $mode) {
                    $connector->setItemsPath(trim($payload->getString('items_path')) ?: null);
                    $connector->setTextField(trim($payload->getString('text_field')) ?: null);
                    $connector->setMemoryType(MemoryType::tryFrom($payload->getString('memory_type')) ?? MemoryType::Fact);
                }
            }

            $projectId = $payload->getInt('project');
            $connector->setProject($projectId > 0 ? $this->entityManager->find(Project::class, $projectId) : null);
            $folderId = $payload->getInt('folder');
            $connector->setFolder($folderId > 0 ? $this->entityManager->find(Folder::class, $folderId) : null);
            $connector->setSchedule(ConnectorSchedule::tryFrom($payload->getString('schedule')) ?? $connector->getSchedule());
            $this->entityManager->flush();

            if ($payload->getBoolean('sync_now')) {
                $report = $this->runner->sync($connector);
                if (null === $report['error']) {
                    $this->addFlash('success', new TranslatableMessage('flash.connector.imported', ['created' => $report['created'], 'updated' => $report['updated'], 'unchanged' => $report['unchanged'], 'skipped' => \count($report['skipped'])]));
                }
            } else {
                $this->addFlash('success', new TranslatableMessage('flash.connector.setup_saved', ['name' => $connector->getName()]));
            }

            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        }

        return $this->render('connector/setup.html.twig', [
            'connector' => $connector,
            'inspection' => $inspection,
            'inspection_error' => $inspectionError,
            'projects' => $this->entityManager->getRepository(Project::class)->findBy(['archivedAt' => null], ['name' => 'ASC']),
            'folders' => $this->entityManager->getRepository(Folder::class)->findBy([], ['name' => 'ASC']),
            'schedules' => ConnectorSchedule::cases(),
            'memory_types' => MemoryType::cases(),
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
        $previousCredentials = $connector->getCredentials();
        $previousOrigin = SafeUrl::origin($connector->getEndpoint());
        $form = $this->createForm(ConnectorType::class, $connector);
        $form->handleRequest($request);

        if ($form->isSubmitted() && ConnectorTransport::Stdio === $connector->getTransport() && !$this->clientFactory->stdioAllowed()) {
            $form->get('transport')->addError(new FormError($this->translator->trans('connector.stdio_disabled')));
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $originChanged = null !== $previousOrigin && $previousOrigin !== SafeUrl::origin($connector->getEndpoint());
            $connector->setCredentials($this->credentials->seal($connector->getCredentials(), $originChanged ? [] : $previousCredentials));
            $this->entityManager->persist($connector);
            $this->entityManager->flush();

            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        }

        return $this->render('connector/edit.html.twig', ['connector' => $connector, 'form' => $form, 'preset' => $presetData, 'preset_key' => $preset], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/connectors/{id}', name: 'connector_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Connector $connector): Response
    {
        return $this->render('connector/show.html.twig', ['connector' => $connector, 'inspection' => null, 'inspection_error' => null, 'oauth' => $this->oauthAvailable($connector), 'github_app' => $this->gitHubApp, 'default_review_model' => $this->llmSettings->chatModel($connector->getProject())]);
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

    #[Route('/connectors/{id}/oauth/disconnect', name: 'connector_oauth_disconnect', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function oauthDisconnect(Connector $connector, Request $request): Response
    {
        $this->checkToken($connector, $request);
        $connector->setOauthToken(null);
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage('flash.connector.github_disconnected'));

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }

    /**
     * Sends the user to the authorization server of an MCP server that asks for OAuth (Notion, Atlassian...).
     */
    #[Route('/connectors/{id}/authorize', name: 'connector_authorize', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function authorize(Connector $connector, Request $request): Response
    {
        $this->checkToken($connector, $request);
        if (ConnectorTransport::Http !== $connector->getTransport() || $connector->getMode()->isApi()) {
            throw $this->createNotFoundException('Only an HTTP MCP connector is authorized by OAuth.');
        }
        $state = bin2hex(random_bytes(16));
        try {
            $flow = $this->authorization->start($connector, $this->generateUrl('connector_authorize_check', [], UrlGeneratorInterface::ABSOLUTE_URL), $state);
        } catch (\Throwable $exception) {
            $this->addFlash('error', new TranslatableMessage('flash.connector.oauth_impossible', ['error' => $exception->getMessage()]));

            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        }
        $request->getSession()->set(self::AUTHORIZATION_STATE, ['state' => $state, 'connector' => $connector->getId(), 'flow' => $flow['pending']]);

        return $this->redirect($flow['url']);
    }

    #[Route('/connectors/authorize/check', name: 'connector_authorize_check', methods: ['GET'])]
    public function authorizeCheck(Request $request): Response
    {
        $pending = $request->getSession()->remove(self::AUTHORIZATION_STATE);
        $connector = \is_array($pending) && hash_equals((string) $pending['state'], $request->query->getString('state')) ? $this->entityManager->find(Connector::class, $pending['connector']) : null;
        if (null === $connector) {
            $this->addFlash('error', new TranslatableMessage('flash.connector.oauth_expired'));

            return $this->redirectToRoute('connector_index');
        }

        if ($request->query->has('error')) {
            $this->addFlash('error', new TranslatableMessage('flash.connector.oauth_denied', ['error' => $request->query->getString('error_description') ?: $request->query->getString('error')]));
        } else {
            try {
                $this->authorization->finish($connector, $pending['flow'], $request->query->getString('code'));
                $this->entityManager->flush();
                $this->addFlash('success', new TranslatableMessage('flash.connector.oauth_authorized'));
                if (true === ($pending['setup'] ?? false)) {
                    return $this->redirectToRoute('connector_setup', ['id' => $connector->getId()]);
                }
            } catch (\Throwable $exception) {
                $this->addFlash('error', new TranslatableMessage('flash.connector.oauth_refused', ['error' => $exception->getMessage()]));
            }
        }

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }

    #[Route('/connectors/{id}/authorize/revoke', name: 'connector_authorize_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function authorizeRevoke(Connector $connector, Request $request): Response
    {
        $this->checkToken($connector, $request);
        $connector->setMcpAuthorization(null);
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage('flash.connector.oauth_removed'));

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

        return $this->render('connector/show.html.twig', ['connector' => $connector, 'inspection' => $inspection, 'inspection_error' => $error, 'oauth' => $this->oauthAvailable($connector), 'github_app' => $this->gitHubApp, 'default_review_model' => $this->llmSettings->chatModel($connector->getProject())]);
    }

    #[Route('/connectors/{id}/sync', name: 'connector_sync', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sync(Connector $connector, Request $request): Response
    {
        $this->checkToken($connector, $request);
        // Cloning a repository and building its graph takes minutes, as reading a Notion workspace at three requests a second: the worker does it.
        if (ConnectorMode::Notion === $connector->getMode() || ($connector->getMode()->isForge() && true === ($connector->getArguments()['code_graph'] ?? false))) {
            $this->bus->dispatch(new SyncConnector($connector->getId(), ConnectorRun::MANUAL));
            $this->addFlash('success', new TranslatableMessage('flash.connector.sync_started'));

            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        }
        $report = $this->runner->sync($connector);
        if (null === $report['error']) {
            $this->addFlash('success', new TranslatableMessage('flash.connector.imported', ['created' => $report['created'], 'updated' => $report['updated'], 'unchanged' => $report['unchanged'], 'skipped' => \count($report['skipped'])]));
        }

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }

    /**
     * Turns the review of the pull requests by the GitHub App on or off for this repository.
     */
    #[Route('/connectors/{id}/review', name: 'connector_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function review(Connector $connector, Request $request): Response
    {
        $this->checkToken($connector, $request);
        if (ConnectorMode::GitHub !== $connector->getMode()) {
            throw $this->createNotFoundException('Only a GitHub connector reviews pull requests.');
        }
        $enabled = true !== ($connector->getArguments()['review'] ?? false);
        $connector->setArguments(['review' => $enabled] + $connector->getArguments());
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage($enabled ? 'flash.connector.review_enabled' : 'flash.connector.review_disabled'));

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }

    /**
     * The model that reads the diff of the pull requests of this repository; empty, the model of the project.
     */
    #[Route('/connectors/{id}/review-model', name: 'connector_review_model', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reviewModel(Connector $connector, Request $request): Response
    {
        $this->checkToken($connector, $request);
        if (ConnectorMode::GitHub !== $connector->getMode()) {
            throw $this->createNotFoundException('Only a GitHub connector reviews pull requests.');
        }
        $model = trim($request->getPayload()->getString('model'));
        $arguments = $connector->getArguments();
        unset($arguments['review_model']);
        if ('' === $model) {
            $this->addFlash('success', new TranslatableMessage('flash.connector.review_model_default', ['model' => $this->llmSettings->chatModel($connector->getProject())]));
        } elseif (mb_strlen($model) > 150 || null !== $error = $this->modelCheck->chat($model)) {
            $this->addFlash('error', new TranslatableMessage('flash.connector.review_model_refused', ['model' => mb_substr($model, 0, 150), 'error' => $error ?? new TranslatableMessage('flash.connector.model_too_long')]));

            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        } else {
            $arguments['review_model'] = $model;
            $this->addFlash('success', new TranslatableMessage('flash.connector.review_model', ['model' => $model]));
        }
        $connector->setArguments($arguments);
        $this->entityManager->flush();

        return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
    }

    #[Route('/connectors/{id}/pull-requests', name: 'connector_pull_requests', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pullRequests(Connector $connector): Response
    {
        if (ConnectorMode::GitHub !== $connector->getMode()) {
            throw $this->createNotFoundException('Only a GitHub connector reviews pull requests.');
        }
        try {
            $pullRequests = $this->reviewer->openPullRequests($connector);
            $error = null;
        } catch (\Throwable $exception) {
            $pullRequests = [];
            $error = $exception->getMessage();
        }

        return $this->render('connector/pull_requests.html.twig', ['connector' => $connector, 'pull_requests' => $pullRequests, 'error' => $error]);
    }

    #[Route('/connectors/{id}/pull-requests/{number}/review', name: 'connector_pull_request_review', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['POST'])]
    public function reviewPullRequest(Connector $connector, int $number, Request $request): Response
    {
        $this->checkToken($connector, $request);
        if (ConnectorMode::GitHub !== $connector->getMode()) {
            throw $this->createNotFoundException('Only a GitHub connector reviews pull requests.');
        }
        $this->bus->dispatch(new ReviewPullRequest($connector->getId(), $number));
        $this->addFlash('success', new TranslatableMessage('flash.connector.review_started', ['number' => $number]));

        return $this->redirectToRoute('connector_pull_requests', ['id' => $connector->getId()]);
    }

    #[Route('/connectors/{id}/delete', name: 'connector_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Connector $connector, Request $request): Response
    {
        $this->checkToken($connector, $request);
        $this->checkout->remove($connector);
        $this->entityManager->remove($connector);
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage('flash.connector.deleted'));

        return $this->redirectToRoute('connector_index');
    }

    /**
     * Converts submitted form values into typed JSON arguments matching the discovered tool schema.
     *
     * @param array<int|string, mixed>                                                                         $rawArgs
     * @param list<array{name: string, type: string, description: string, required: bool, enum: list<string>}> $fields
     *
     * @return array<string, mixed>
     */
    private function coerceArguments(array $rawArgs, array $fields): array
    {
        $byName = [];
        foreach ($fields as $field) {
            $byName[$field['name']] = $field['type'];
        }

        $result = [];
        foreach ($rawArgs as $name => $value) {
            if (!\is_string($name) || '' === $name) {
                continue;
            }
            $str = is_scalar($value) ? trim((string) $value) : '';
            $type = $byName[$name] ?? 'string';

            if ('boolean' === $type) {
                if ('true' === $str || '1' === $str) {
                    $result[$name] = true;
                } elseif ('false' === $str || '0' === $str) {
                    $result[$name] = false;
                }
                continue;
            }
            if ('' === $str) {
                continue;
            }
            if ('integer' === $type && is_numeric($str)) {
                $result[$name] = (int) $str;
            } elseif ('number' === $type && is_numeric($str)) {
                $result[$name] = (float) $str;
            } elseif (\in_array($type, ['array', 'object'], true)) {
                $decoded = json_decode($str, true);
                $result[$name] = \JSON_ERROR_NONE === json_last_error() ? $decoded : $str;
            } else {
                $result[$name] = $str;
            }
        }

        return $result;
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
