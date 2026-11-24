<?php

namespace App\Controller\Connector;

use App\Connector\ConnectorRunner;
use App\Connector\ToolArgumentCoercer;
use App\Entity\Connector;
use App\Entity\Folder;
use App\Entity\Project;
use App\Enum\ConnectorMode;
use App\Enum\ConnectorSchedule;
use App\Enum\MemoryType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Interactive MCP setup wizard: discovers live tools/resources and generates typed form fields from inputSchema.
 */
#[Route('/connectors/{id}/setup', name: 'connector_setup', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
final class SetupConnectorAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConnectorRunner $runner,
        private readonly ToolArgumentCoercer $coercer,
    ) {
    }

    public function __invoke(Connector $connector, Request $request): Response
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
            if (!$this->isCsrfTokenValid('connector-'.$connector->getId(), $request->getPayload()->getString('_token'))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
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
                $connector->setArguments($this->coercer->coerce($payload->all('args'), $toolMeta['fields'] ?? []));
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
}
