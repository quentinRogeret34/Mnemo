<?php

namespace App\Controller;

use App\Dashboard\ActivityReader;
use App\Pagination\Page;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class ActivityController extends AbstractController
{
    private const FEED_SIZE = 50;
    private const RUNS_SIZE = 20;
    private const PERIODS = ['1d' => '-1 day', '7d' => '-7 days', '30d' => '-30 days'];

    public function __construct(
        private readonly ActivityReader $reader,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/activity', name: 'activity', methods: ['GET'])]
    public function show(#[MapQueryParameter] ?string $project = null, #[MapQueryParameter] string $period = '7d', #[MapQueryParameter] int $page = 1, #[MapQueryParameter] int $runs = 1): Response
    {
        $projectEntity = $this->projectScope->bySlug($project);
        $period = \array_key_exists($period, self::PERIODS) ? $period : '7d';
        $from = new \DateTimeImmutable(self::PERIODS[$period]);
        $counts = $this->reader->counts($projectEntity, $from);
        $page = max(1, $page);
        $runs = max(1, $runs);

        return $this->render('activity/show.html.twig', [
            'project' => $projectEntity,
            'period' => $period,
            'periods' => array_keys(self::PERIODS),
            'feed' => new Page($this->reader->feed($projectEntity, $from, self::FEED_SIZE, Page::offset($page, self::FEED_SIZE)), array_sum(array_column($counts, 'value')), $page, self::FEED_SIZE),
            'counts' => $counts,
            'runs' => Page::ofRows($this->reader->runs($projectEntity, self::RUNS_SIZE, Page::offset($runs, self::RUNS_SIZE)), $runs, self::RUNS_SIZE),
        ]);
    }
}
