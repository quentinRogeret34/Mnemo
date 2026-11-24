<?php

namespace App\Controller;

use App\Briefing\BusinessBriefingGenerator;
use App\Entity\Project;
use App\Llm\LlmUnavailable;
use App\Notification\WorkspaceNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;

final class BriefingController extends AbstractController
{
    public function __construct(
        private readonly BusinessBriefingGenerator $generator,
        private readonly WorkspaceNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/projects/{slug}/briefing', name: 'project_briefing', methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['slug' => 'slug'])] Project $project,
        Request $request,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_REGEXP, options: ['regexp' => '/^(7d|30d)$/'])] string $period = '7d',
    ): Response {
        $data = $this->generator->collect($project, $period);
        $narrative = $request->hasSession() ? $request->getSession()->get($this->sessionKey($project, $period)) : null;
        $narrative = \is_string($narrative) && '' !== trim($narrative) ? $narrative : null;

        return $this->render('briefing/show.html.twig', [
            'project' => $project,
            'period' => $period,
            'periods' => BusinessBriefingGenerator::PERIODS,
            'data' => $data,
            'narrative' => $narrative,
            'markdown' => $this->generator->renderMarkdown($project, $data, $narrative),
        ]);
    }

    #[Route('/projects/{slug}/briefing/generate', name: 'project_briefing_generate', methods: ['POST'])]
    public function generate(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        $this->checkToken($project, $request);
        $period = '30d' === $request->getPayload()->getString('period') ? '30d' : '7d';
        $data = $this->generator->collect($project, $period);

        try {
            $summary = $this->generator->synthesize($project, $data);
            if ('' !== $summary) {
                $request->getSession()->set($this->sessionKey($project, $period), $summary);
                $this->addFlash('success', new TranslatableMessage('flash.briefing.generated'));
            }
        } catch (LlmUnavailable|\UnexpectedValueException $exception) {
            $this->addFlash('error', $exception instanceof LlmUnavailable ? $exception->translatable() : $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->error('Briefing generation failed for project {slug}: {message}', ['slug' => $project->getSlug(), 'message' => $exception->getMessage(), 'exception' => $exception]);
            $this->addFlash('error', new TranslatableMessage('flash.llm.error', ['error' => $exception->getMessage()]));
        }

        return $this->redirectToRoute('project_briefing', ['slug' => $project->getSlug(), 'period' => $period]);
    }

    #[Route('/projects/{slug}/briefing/send', name: 'project_briefing_send', methods: ['POST'])]
    public function send(#[MapEntity(mapping: ['slug' => 'slug'])] Project $project, Request $request): Response
    {
        $this->checkToken($project, $request);
        $period = '30d' === $request->getPayload()->getString('period') ? '30d' : '7d';

        if (!$project->hasWebhook()) {
            $this->addFlash('error', new TranslatableMessage('flash.briefing.no_webhook'));

            return $this->redirectToRoute('project_briefing', ['slug' => $project->getSlug(), 'period' => $period]);
        }

        $data = $this->generator->collect($project, $period);
        $narrative = $request->hasSession() ? $request->getSession()->get($this->sessionKey($project, $period)) : null;
        $markdown = $this->generator->renderMarkdown($project, $data, \is_string($narrative) ? $narrative : null);

        try {
            $this->notifier->send(
                $project,
                WorkspaceNotifier::EVENT_BRIEFING,
                \sprintf('[%s] Briefing métier (%s)', $project->getName(), '30d' === $period ? '30 jours' : '7 jours'),
                $markdown,
                $this->generateUrl('project_briefing', ['slug' => $project->getSlug(), 'period' => $period], UrlGeneratorInterface::ABSOLUTE_URL),
                throwOnError: true,
            );
            $this->addFlash('success', new TranslatableMessage('flash.briefing.sent'));
        } catch (\RuntimeException $exception) {
            $this->addFlash('error', new TranslatableMessage('flash.project.webhook_failed', ['error' => $exception->getMessage()]));
        }

        return $this->redirectToRoute('project_briefing', ['slug' => $project->getSlug(), 'period' => $period]);
    }

    private function checkToken(Project $project, Request $request): void
    {
        if (!$this->isCsrfTokenValid('briefing-'.$project->getId(), $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function sessionKey(Project $project, string $period): string
    {
        return 'briefing.narrative.'.$project->getId().'.'.$period;
    }
}
