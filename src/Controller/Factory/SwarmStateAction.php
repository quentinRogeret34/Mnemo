<?php

namespace App\Controller\Factory;

use App\Enum\SwarmOrderStatus;
use App\Factory\FactoryGuard;
use App\Factory\Swarm\SwarmBriefGenerator;
use App\Factory\Swarm\SwarmHubManager;
use App\Repository\SwarmNodeRepository;
use App\Repository\SwarmOrderRepository;
use App\Repository\SwarmRunRepository;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SwarmStateAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly SwarmRunRepository $swarmRepository,
        private readonly SwarmNodeRepository $nodeRepository,
        private readonly SwarmOrderRepository $orderRepository,
        private readonly SwarmHubManager $hubManager,
        private readonly SwarmBriefGenerator $briefGenerator,
        private readonly ProjectScope $projectScope,
        private readonly TranslatorInterface $translator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/factory/swarms/{publicId}/state', name: 'factory_swarm_state', methods: ['GET'])]
    public function __invoke(string $publicId): JsonResponse
    {
        $this->factoryGuard->requireAccess();

        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm || !$this->projectScope->allows($swarm->getProject())) {
            throw $this->createNotFoundException('Swarm run not found.');
        }

        $now = new \DateTimeImmutable();
        $hubToken = $this->hubManager->resolveHubToken($swarm);
        $nodes = $this->nodeRepository->forSwarm($swarm);
        $orders = $this->orderRepository->forSwarm($swarm);
        $messages = $this->swarmRepository->messagesFor($swarm);

        $nodesOnline = 0;
        $nodeRows = [];
        foreach ($nodes as $node) {
            $effectiveStatus = $node->effectiveStatus($now);
            $isOnline = $node->isOnline($now);
            if ($isOnline) {
                ++$nodesOnline;
            }

            $nodeRows[] = [
                'public_id' => $node->getPublicId(),
                'name' => $node->getName(),
                'client' => $node->getClient(),
                'role' => $node->getRole(),
                'branch' => $node->getBranch(),
                'status' => $effectiveStatus,
                'status_label' => $this->translator->trans('enum.swarm_node_status.'.$effectiveStatus),
                'is_online' => $isOnline,
                'last_heartbeat_at' => $node->getLastHeartbeatAt()->format(\DateTimeInterface::ATOM),
                'last_heartbeat_display' => $node->getLastHeartbeatAt()->format('H:i:s'),
                'connected_at' => $node->getConnectedAt()->format(\DateTimeInterface::ATOM),
                'session_public_id' => $node->getSession()?->getPublicId(),
            ];
        }

        $ordersPending = 0;
        $ordersRunning = 0;
        $ordersCompleted = 0;
        $orderRows = [];
        foreach ($orders as $order) {
            $status = $order->getStatus();
            if (SwarmOrderStatus::Pending === $status) {
                ++$ordersPending;
            } elseif (SwarmOrderStatus::Claimed === $status || SwarmOrderStatus::Running === $status) {
                ++$ordersRunning;
            } elseif (SwarmOrderStatus::Completed === $status) {
                ++$ordersCompleted;
            }

            $targetNode = $order->getTargetNode();
            $claimedByNode = $order->getClaimedByNode();

            $orderRows[] = [
                'public_id' => $order->getPublicId(),
                'kind' => $order->getKind()->value,
                'kind_label' => $order->getKind()->trans($this->translator),
                'status' => $status->value,
                'status_label' => $status->trans($this->translator),
                'is_active' => $status->isActive(),
                'title' => $order->getTitle(),
                'instructions' => $order->getInstructions(),
                'target_node' => null !== $targetNode ? [
                    'public_id' => $targetNode->getPublicId(),
                    'name' => $targetNode->getName(),
                ] : null,
                'claimed_by_node' => null !== $claimedByNode ? [
                    'public_id' => $claimedByNode->getPublicId(),
                    'name' => $claimedByNode->getName(),
                ] : null,
                'files_hint' => $order->getFilesHint(),
                'files_touched' => $order->getFilesTouched(),
                'rule_code' => $order->getRuleCode(),
                'branch' => $order->getBranch(),
                'result_summary' => $order->getResultSummary(),
                'result_output' => $order->getResultOutput(),
                'created_by' => $order->getCreatedBy(),
                'created_at' => $order->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'created_at_display' => $order->getCreatedAt()->format('d/m H:i:s'),
                'finished_at' => $order->getFinishedAt()?->format(\DateTimeInterface::ATOM),
                'direct_url' => null !== $hubToken ? $this->briefGenerator->orderDirectUrl($swarm, $order, $hubToken) : null,
                'cancel_url' => $this->generateUrl('factory_swarm_orders_cancel', [
                    'publicId' => $swarm->getPublicId(),
                    'orderPublicId' => $order->getPublicId(),
                ]),
                'cancel_csrf' => SwarmOrderStatus::Pending === $status && $swarm->getStatus()->isActive()
                    ? $this->csrfTokenManager->getToken('factory_swarm_order_cancel_'.$order->getPublicId())->getValue()
                    : null,
            ];
        }

        $messageRows = [];
        foreach ($messages as $message) {
            $messageRows[] = [
                'id' => $message->getId(),
                'author' => $message->getAuthor(),
                'kind' => $message->getKind()->value,
                'kind_label' => $message->getKind()->trans($this->translator),
                'body' => $message->getBody(),
                'created_at' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'created_at_display' => $message->getCreatedAt()->format('d/m/Y H:i:s'),
            ];
        }

        return new JsonResponse([
            'status' => $swarm->getStatus()->value,
            'status_label' => $swarm->getStatus()->trans($this->translator),
            'is_active' => $swarm->getStatus()->isActive(),
            'nodes' => $nodeRows,
            'orders' => $orderRows,
            'messages' => $messageRows,
            'counts' => [
                'nodes_online' => $nodesOnline,
                'orders_pending' => $ordersPending,
                'orders_running' => $ordersRunning,
                'orders_completed' => $ordersCompleted,
            ],
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
