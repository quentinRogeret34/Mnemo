<?php

namespace App\Security;

use App\Entity\Project;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Keeps a caller limited to some projects inside them, for every front controller at once:
 * a project out of reach does not exist, the views across all projects open on one of the caller's projects,
 * and the workspace-wide screens (all documents, connectors) are left to the super admin.
 */
#[AsEventListener]
final class ProjectAccessListener
{
    private const ACROSS_PROJECTS = ['dashboard', 'activity', 'activity_feed', 'memory_index', 'memory_graph', 'memory_graph_data', 'proposal_index'];
    private const WORKSPACE = ['document_index', 'connector_index', 'connector_new', 'connector_edit', 'connector_show', 'connector_inspect', 'connector_sync', 'connector_review', 'connector_delete', 'connector_oauth', 'connector_oauth_check', 'connector_oauth_disconnect'];

    public function __construct(
        private readonly ProjectScope $projectScope,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        foreach ($event->getArguments() as $argument) {
            if ($argument instanceof Project && !$this->projectScope->allows($argument)) {
                throw new NotFoundHttpException('Project not found.');
            }
        }

        $slugs = $this->projectScope->slugs();
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        if (null === $slugs || !$event->isMainRequest()) {
            return;
        }
        if (\in_array($route, self::WORKSPACE, true)) {
            throw new AccessDeniedHttpException('Reserved to the super admin.');
        }
        if (\in_array($route, self::ACROSS_PROJECTS, true) && '' === $request->query->getString('project')) {
            $url = [] === $slugs ? $this->urlGenerator->generate('project_index') : $this->urlGenerator->generate($route, ['project' => $slugs[0]] + $request->query->all());
            $event->setController(static fn (): RedirectResponse => new RedirectResponse($url));
        }
    }
}
