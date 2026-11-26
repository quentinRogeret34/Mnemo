<?php

namespace App\Controller;

use App\Agent\SkillEvolutionAdvisor;
use App\Entity\AgentAsset;
use App\Entity\Project;
use App\Enum\AgentAssetKind;
use App\Enum\ProposalKind;
use App\Form\AgentAssetDraftType;
use App\Form\AgentAssetType;
use App\Llm\LlmUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Skills, slash commands and subagents of a project: edited here, written by the plugin into the ".claude"
 * directory of every Claude Code connected to the project at the start of its sessions.
 */
final class AgentAssetController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SkillEvolutionAdvisor $skillEvolutionAdvisor,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/projects/{slug}/agent-assets', name: 'agent_asset_index', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project): Response
    {
        return $this->renderIndex($project, $this->draftForm($project));
    }

    #[Route('/projects/{slug}/agent-assets/draft', name: 'agent_asset_draft', methods: ['POST'])]
    public function draft(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        $form = $this->draftForm($project);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderIndex($project, $form, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        try {
            $proposed = $this->skillEvolutionAdvisor->draft($project, $form->get('request')->getData(), $form->get('kind')->getData());
        } catch (LlmUnavailable|\UnexpectedValueException $exception) {
            $this->addFlash('error', $exception instanceof LlmUnavailable ? $exception->translatable() : $exception->getMessage());

            return $this->redirectToRoute('agent_asset_index', ['slug' => $project->getSlug()]);
        } catch (\Throwable $exception) {
            $this->logger->error('Skill draft failed for project {slug}: {message}', ['slug' => $project->getSlug(), 'message' => $exception->getMessage(), 'exception' => $exception]);
            $this->addFlash('error', new TranslatableMessage('flash.llm.error', ['error' => $exception->getMessage()]));

            return $this->redirectToRoute('agent_asset_index', ['slug' => $project->getSlug()]);
        }

        if ($proposed > 0) {
            $this->addFlash('success', new TranslatableMessage('flash.agent_asset.drafted'));

            return $this->redirectToRoute('proposal_index', ['project' => $project->getSlug(), 'kind' => ProposalKind::SkillUpdate->value]);
        }

        $this->addFlash('error', new TranslatableMessage('flash.agent_asset.drafted_none'));

        return $this->redirectToRoute('agent_asset_index', ['slug' => $project->getSlug()]);
    }

    #[Route('/projects/{slug}/agent-assets/new', name: 'agent_asset_new', methods: ['GET', 'POST'])]
    #[Route('/projects/{slug}/agent-assets/{id}', name: 'agent_asset_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request, ?int $id = null): Response
    {
        $asset = null === $id ? new AgentAsset($project) : $this->find($project, $id);
        $form = $this->createForm(AgentAssetType::class, $asset);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($asset);
            $this->entityManager->flush();
            $this->addFlash('success', new TranslatableMessage('flash.agent_asset.saved', ['kind' => $asset->getKind(), 'name' => $asset->getName()]));

            return $this->redirectToRoute('agent_asset_index', ['slug' => $project->getSlug()]);
        }

        return $this->render('agent/edit.html.twig', ['project' => $project, 'asset' => $asset, 'form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/projects/{slug}/agent-assets/propose', name: 'agent_asset_propose', methods: ['POST'])]
    public function propose(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('agent-asset-propose-'.$project->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        try {
            $report = $this->skillEvolutionAdvisor->advise($project);
        } catch (LlmUnavailable|\UnexpectedValueException $exception) {
            $this->addFlash('error', $exception instanceof LlmUnavailable ? $exception->translatable() : $exception->getMessage());

            return $this->redirectToRoute('agent_asset_index', ['slug' => $project->getSlug()]);
        } catch (\Throwable $exception) {
            $this->logger->error('Skill evolution proposal failed for project {slug}: {message}', ['slug' => $project->getSlug(), 'message' => $exception->getMessage(), 'exception' => $exception]);
            $this->addFlash('error', new TranslatableMessage('flash.llm.error', ['error' => $exception->getMessage()]));

            return $this->redirectToRoute('agent_asset_index', ['slug' => $project->getSlug()]);
        }

        if ($report['proposed'] > 0) {
            $this->addFlash('success', new TranslatableMessage('flash.agent_asset.proposed', ['count' => $report['proposed']]));

            return $this->redirectToRoute('proposal_index', ['project' => $project->getSlug(), 'kind' => ProposalKind::SkillUpdate->value]);
        }

        $this->addFlash('success', new TranslatableMessage('flash.agent_asset.proposed_none'));

        return $this->redirectToRoute('agent_asset_index', ['slug' => $project->getSlug()]);
    }

    #[Route('/projects/{slug}/agent-assets/{id}/delete', name: 'agent_asset_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, int $id, Request $request): Response
    {
        $asset = $this->find($project, $id);
        if (!$this->isCsrfTokenValid('agent-asset-'.$asset->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $this->entityManager->remove($asset);
        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage('flash.agent_asset.deleted', ['name' => $asset->getName()]));

        return $this->redirectToRoute('agent_asset_index', ['slug' => $project->getSlug()]);
    }

    /**
     * @return FormInterface<array{request: string, kind: AgentAssetKind}>
     */
    private function draftForm(Project $project): FormInterface
    {
        return $this->createForm(AgentAssetDraftType::class, options: ['action' => $this->generateUrl('agent_asset_draft', ['slug' => $project->getSlug()])]);
    }

    /**
     * @param FormInterface<array{request: string, kind: AgentAssetKind}> $draft
     */
    private function renderIndex(Project $project, FormInterface $draft, ?Response $response = null): Response
    {
        return $this->render('agent/index.html.twig', [
            'project' => $project,
            'assets' => $this->entityManager->getRepository(AgentAsset::class)->findBy(['project' => $project], ['kind' => 'ASC', 'name' => 'ASC']),
            'draft' => $draft,
        ], $response);
    }

    private function find(Project $project, int $id): AgentAsset
    {
        return $this->entityManager->getRepository(AgentAsset::class)->findOneBy(['id' => $id, 'project' => $project]) ?? throw $this->createNotFoundException('Agent asset not found.');
    }
}
