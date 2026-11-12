<?php

namespace App\Controller;

use App\Dashboard\ActivityReader;
use App\Entity\Project;
use App\Security\ProjectScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class ActivityController extends AbstractController
{
    private const PERIODS = ['1d' => '-1 day', '7d' => '-7 days', '30d' => '-30 days'];

    public function __construct(
        private readonly ActivityReader $reader,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/activity', name: 'activity', methods: ['GET'])]
    public function show(#[MapQueryParameter] ?string $project = null, #[MapQueryParameter] string $period = '7d'): Response
    {
        $projectEntity = $this->projectScope->bySlug($project);
        $period = \array_key_exists($period, self::PERIODS) ? $period : '7d';
        $from = new \DateTimeImmutable(self::PERIODS[$period]);

        return $this->render('activity/show.html.twig', [
            'project' => $projectEntity,
            'period' => $period,
            'periods' => array_keys(self::PERIODS),
            'days' => $this->reader->feed($projectEntity, $from),
            'counts' => $this->reader->counts($projectEntity, $from),
            'runs' => $this->reader->runs($projectEntity),
        ]);
    }
}
