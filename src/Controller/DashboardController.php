<?php

namespace App\Controller;

use App\Dashboard\DashboardReader;
use App\Entity\Project;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    private const PERIODS = ['24h' => '-24 hours', '7d' => '-7 days', '30d' => '-30 days', '90d' => '-90 days', 'all' => null];

    public function __construct(
        private readonly DashboardReader $reader,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function show(#[MapQueryParameter] ?string $project = null, #[MapQueryParameter] string $period = '7d'): Response
    {
        $projectEntity = $this->projectScope->bySlug($project);
        $period = \array_key_exists($period, self::PERIODS) ? $period : '7d';
        $from = null === self::PERIODS[$period] ? null : new \DateTimeImmutable(self::PERIODS[$period]);
        $seriesFrom = $from ?? $this->reader->firstActivity() ?? new \DateTimeImmutable('-7 days');
        $hourly = '24h' === $period;

        return $this->render('dashboard/show.html.twig', [
            'project' => $projectEntity,
            'period' => $period,
            'periods' => array_keys(self::PERIODS),
            'hourly' => $hourly,
            'summary' => $this->reader->summary($projectEntity, $from),
            'requests' => $this->reader->requestSeries($projectEntity, $seriesFrom, $hourly),
            'memories' => $this->reader->memorySeries($projectEntity, $seriesFrom, $hourly),
            'memory_types' => $this->reader->memoryTypes($projectEntity),
            'tools' => $this->reader->tools($projectEntity, $from),
            'search_types' => $this->reader->searchTypes($projectEntity, $from),
            'search_modes' => $this->reader->searchModes($projectEntity, $from),
            'recent' => $this->reader->recentCalls($projectEntity),
        ]);
    }
}
