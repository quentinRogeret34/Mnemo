<?php

namespace App\Controller\Factory;

use App\Factory\FactoryGuard;
use App\Factory\Swarm\SwarmHubManager;
use App\Repository\SwarmOrderRepository;
use App\Repository\SwarmRunRepository;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CancelSwarmOrderAction extends AbstractController
{
    public function __construct(
        private readonly FactoryGuard $factoryGuard,
        private readonly SwarmRunRepository $swarmRepository,
        private readonly SwarmOrderRepository $orderRepository,
        private readonly SwarmHubManager $hubManager,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/factory/swarms/{publicId}/orders/{orderPublicId}/cancel', name: 'factory_swarm_orders_cancel', methods: ['POST'])]
    public function __invoke(string $publicId, string $orderPublicId, Request $request): RedirectResponse
    {
        $this->factoryGuard->requireAccess();

        $swarm = $this->swarmRepository->findByPublicId($publicId);
        if (null === $swarm || !$this->projectScope->allows($swarm->getProject())) {
            throw $this->createNotFoundException('Swarm run not found.');
        }

        $order = $this->orderRepository->findByPublicId($orderPublicId);
        if (null === $order || $order->getSwarm()->getId() !== $swarm->getId()) {
            throw $this->createNotFoundException('Swarm order not found.');
        }

        if (!$this->isCsrfTokenValid('factory_swarm_order_cancel_'.$order->getPublicId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->hubManager->cancelOrder($order);
        $this->addFlash('notice', 'factory.hub.flash.order_cancelled');

        return $this->redirectToRoute('factory_swarm_show', ['publicId' => $swarm->getPublicId()]);
    }
}
