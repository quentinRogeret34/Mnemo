<?php

namespace App\Controller;

use App\Connector\ConnectorPresets;
use App\Connector\ConnectorRunner;
use App\Connector\McpClientFactory;
use App\Entity\Connector;
use App\Enum\ConnectorTransport;
use App\Form\ConnectorType;
use App\Llm\LlmSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ConnectorController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConnectorRunner $runner,
        private readonly McpClientFactory $clientFactory,
        private readonly LlmSettings $llmSettings,
        private readonly ConnectorPresets $presets,
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
        return $this->render('connector/show.html.twig', ['connector' => $connector, 'inspection' => null, 'inspection_error' => null]);
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

        return $this->render('connector/show.html.twig', ['connector' => $connector, 'inspection' => $inspection, 'inspection_error' => $error]);
    }

    #[Route('/connectors/{id}/sync', name: 'connector_sync', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sync(Connector $connector, Request $request): Response
    {
        $this->checkToken($connector, $request);
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
        $this->entityManager->remove($connector);
        $this->entityManager->flush();
        $this->addFlash('success', 'Connecteur supprimé. Les documents et mémoires importés sont conservés.');

        return $this->redirectToRoute('connector_index');
    }

    private function checkToken(Connector $connector, Request $request): void
    {
        if (!$this->isCsrfTokenValid('connector-'.$connector->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
