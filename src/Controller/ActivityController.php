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
    private const PREVIEW_SIZE = 10;
    private const FEED_SIZE = 50;
    private const RUNS_SIZE = 20;
    private const PERIODS = ['1d' => '-1 day', '7d' => '-7 days', '30d' => '-30 days'];
    private const FEED_PERIODS = self::PERIODS + ['all' => '@0'];

    public function __construct(
        private readonly ActivityReader $reader,
        private readonly ProjectScope $projectScope,
    ) {
    }

    #[Route('/activity', name: 'activity', methods: ['GET'])]
    public function show(#[MapQueryParameter] ?string $project = null, #[MapQueryParameter] string $period = '7d', #[MapQueryParameter] int $runs = 1): Response
    {
        $projectEntity = $this->projectScope->bySlug($project);
        $period = \array_key_exists($period, self::PERIODS) ? $period : '7d';
        $from = new \DateTimeImmutable(self::PERIODS[$period]);
        $counts = $this->reader->counts($projectEntity, $from);
        $runs = max(1, $runs);

        return $this->render('activity/show.html.twig', [
            'project' => $projectEntity,
            'period' => $period,
            'periods' => array_keys(self::PERIODS),
            'feed' => $this->reader->feed($projectEntity, $from, self::PREVIEW_SIZE),
            'counts' => $counts,
            'runs' => Page::ofRows($this->reader->runs($projectEntity, self::RUNS_SIZE, Page::offset($runs, self::RUNS_SIZE)), $runs, self::RUNS_SIZE),
        ]);
    }

    #[Route('/activity/feed', name: 'activity_feed', methods: ['GET'])]
    public function feed(#[MapQueryParameter] ?string $project = null, #[MapQueryParameter] string $period = 'all', #[MapQueryParameter] ?string $type = null, #[MapQueryParameter] string $q = '', #[MapQueryParameter] int $page = 1): Response
    {
        $projectEntity = $this->projectScope->bySlug($project);
        $period = \array_key_exists($period, self::FEED_PERIODS) ? $period : 'all';
        $type = \in_array($type, ActivityReader::TYPES, true) ? $type : null;
        $page = max(1, $page);
        $feed = $this->reader->feed($projectEntity, new \DateTimeImmutable(self::FEED_PERIODS[$period]), self::FEED_SIZE, Page::offset($page, self::FEED_SIZE), $type, $q);

        return $this->render('activity/feed.html.twig', [
            'project' => $projectEntity,
            'projects' => $this->projectScope->projects(),
            'period' => $period,
            'periods' => array_keys(self::FEED_PERIODS),
            'type' => $type,
            'types' => ActivityReader::TYPES,
            'q' => $q,
            'feed' => new Page($feed['days'], $feed['total'], $page, self::FEED_SIZE),
        ]);
    }
}
