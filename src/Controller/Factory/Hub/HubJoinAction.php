<?php

namespace App\Controller\Factory\Hub;

use App\Entity\SwarmOrder;
use App\Factory\Dto\HubJoinPayload;
use App\Factory\Hub\SwarmHubAuthenticator;
use App\Factory\Swarm\ProjectContextBuilder;
use App\Factory\Swarm\SwarmHubManager;
use App\Repository\SwarmOrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Registers an external client node onto an active Swarm Hub and returns its initial project context.
 */
final class HubJoinAction extends AbstractController
{
    public function __construct(
        private readonly SwarmHubAuthenticator $authenticator,
        private readonly SwarmHubManager $hubManager,
        private readonly ProjectContextBuilder $contextBuilder,
        private readonly SwarmOrderRepository $orderRepository,
    ) {
    }

    #[Route('/hub/swarms/{publicId}/nodes/join', name: 'hub_swarm_join', methods: ['POST'])]
    public function __invoke(
        Request $request,
        string $publicId,
        #[MapRequestPayload] HubJoinPayload $payload,
    ): Response {
        $swarm = $this->authenticator->authenticate($request, $publicId);
        $node = $this->hubManager->joinNode(
            swarm: $swarm,
            name: $payload->name,
            client: $payload->client,
            role: $payload->role,
            branch: $payload->branch,
            capabilities: $payload->capabilities,
        );

        $project = $swarm->getProject();
        $pendingOrders = $this->orderRepository->pendingForSwarm($swarm);

        $response = $this->json([
            'node_id' => $node->getPublicId(),
            'session_id' => $node->getSession()?->getPublicId(),
            'swarm_id' => $swarm->getPublicId(),
            'swarm_status' => $swarm->getStatus()->value,
            'project' => [
                'slug' => $project->getSlug(),
                'name' => $project->getName(),
            ],
            'project_context' => $this->contextBuilder->build($project, $swarm->getSourceRuleCode()),
            'pending_orders' => array_map(static fn (SwarmOrder $order): array => [
                'order_id' => $order->getPublicId(),
                'kind' => $order->getKind()->value,
                'title' => $order->getTitle(),
                'target_node_id' => $order->getTargetNode()?->getPublicId(),
                'files_hint' => $order->getFilesHint(),
                'rule_code' => $order->getRuleCode(),
            ], $pendingOrders),
        ], Response::HTTP_CREATED);

        return $this->authenticator->applySecurityHeaders($response);
    }
}
