<?php

namespace App\Controller\Factory;

use App\Entity\SwarmOrder;
use App\Enum\SwarmOrderKind;
use App\Factory\FactoryGuard;
use App\Factory\Swarm\SwarmHubManager;
use App\Repository\SwarmNodeRepository;
use App\Repository\SwarmRunRepository;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DispatchSwarmOrderAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly SwarmRunRepository $swarmRepository,
        private readonly SwarmNodeRepository $nodeRepository,
        private readonly SwarmHubManager $hubManager,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/swarms/{publicId}/orders', name: 'factory_swarm_orders_create', methods: ['POST'])]
    public function __invoke(string $publicId, Request $request): Response
    {
        $this->factoryGuard->requireAccess();

        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm || !$this->projectScope->allows($swarm->getProject())) {
            throw $this->createNotFoundException('Swarm run not found.');
        }

        if (!$this->isCsrfTokenValid('factory_swarm_order_'.$swarm->getPublicId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $wantsJson = $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');

        if (!$swarm->getStatus()->isActive()) {
            if ($wantsJson) {
                return new JsonResponse(['error' => 'Swarm is not active.'], Response::HTTP_CONFLICT);
            }
            $this->addFlash('error', 'factory.hub.flash.swarm_closed');

            return $this->redirectToRoute('factory_swarm_show', ['publicId' => $swarm->getPublicId()]);
        }

        $kind = SwarmOrderKind::tryFrom($request->request->getString('kind')) ?? SwarmOrderKind::Custom;
        $title = trim($request->request->getString('title'));
        $instructions = trim($request->request->getString('instructions'));
        if ('' === $title || '' === $instructions) {
            if ($wantsJson) {
                return new JsonResponse(['error' => 'Title and instructions are required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('error', 'factory.hub.flash.order_required');

            return $this->redirectToRoute('factory_swarm_show', ['publicId' => $swarm->getPublicId()]);
        }

        $target = trim($request->request->getString('target'));
        if ('' === $target) {
            $target = 'any';
        }

        $filesHintRaw = $request->request->getString('files_hint');
        $filesHint = array_values(array_filter(
            array_map('trim', explode(',', $filesHintRaw)),
            static fn (string $file): bool => '' !== $file,
        ));

        $ruleCode = trim($request->request->getString('rule_code')) ?: null;
        $createdBy = $this->getUser()?->getUserIdentifier() ?? 'human';

        if ('all' === $target) {
            $orders = $this->hubManager->broadcastOrder(
                swarm: $swarm,
                kind: $kind,
                title: $title,
                instructions: $instructions,
                filesHint: $filesHint,
                ruleCode: $ruleCode,
                createdBy: $createdBy,
            );
        } else {
            $targetNode = null;
            if (str_starts_with($target, 'node_')) {
                $candidate = $this->nodeRepository->findByPublicId($target);
                if (null === $candidate || $candidate->getSwarm()->getId() !== $swarm->getId()) {
                    if ($wantsJson) {
                        return new JsonResponse(['error' => 'Target node not found in this swarm.'], Response::HTTP_NOT_FOUND);
                    }
                    $this->addFlash('error', 'factory.hub.flash.node_not_found');

                    return $this->redirectToRoute('factory_swarm_show', ['publicId' => $swarm->getPublicId()]);
                }
                $targetNode = $candidate;
            }

            $orders = [
                $this->hubManager->createOrder(
                    swarm: $swarm,
                    kind: $kind,
                    title: $title,
                    instructions: $instructions,
                    targetNode: $targetNode,
                    filesHint: $filesHint,
                    ruleCode: $ruleCode,
                    createdBy: $createdBy,
                ),
            ];
        }

        if ($wantsJson) {
            return new JsonResponse([
                'orders' => array_map(
                    static fn (SwarmOrder $order): array => [
                        'public_id' => $order->getPublicId(),
                        'kind' => $order->getKind()->value,
                        'status' => $order->getStatus()->value,
                        'title' => $order->getTitle(),
                        'target_node' => $order->getTargetNode()?->getPublicId(),
                    ],
                    $orders,
                ),
            ], Response::HTTP_CREATED);
        }

        $this->addFlash(
            'notice',
            \count($orders) > 1 ? 'factory.hub.flash.orders_broadcast' : 'factory.hub.flash.order_created',
        );

        return $this->redirectToRoute('factory_swarm_show', ['publicId' => $swarm->getPublicId()]);
    }
}
