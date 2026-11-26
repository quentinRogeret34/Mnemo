<?php

namespace App\Factory\Swarm;

use App\Entity\AgentSession;
use App\Entity\Project;
use App\Entity\SwarmMessage;
use App\Entity\SwarmRun;
use App\Enum\AgentRunnerKind;
use App\Enum\AgentSessionEventKind;
use App\Enum\AgentSessionStatus;
use App\Enum\SwarmMessageKind;
use App\Enum\SwarmStatus;
use App\Enum\SwarmStrategy;
use App\Factory\AgentSessionRecorder;
use App\Factory\Dto\WorkerTask;
use App\Factory\Runner\AgentRunnerRegistry;
use App\Factory\WorktreeManager;
use App\Message\PlanSwarm;
use App\Message\ReviewSwarm;
use App\Message\RunSwarmWorker;
use App\Repository\AgentSessionRepository;
use App\Repository\SwarmOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Coordinates the full lifecycle of a multi-agent SwarmRun:
 * Plan (internal LLM) -> Optional Human Approval -> Isolated Workers (CLI worktree / Internal LLM / Interactive Hub) -> Review (internal LLM).
 */
final class SwarmOrchestrator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentSessionRecorder $sessionRecorder,
        private readonly AgentSessionRepository $sessionRepository,
        private readonly ProjectContextBuilder $contextBuilder,
        private readonly SwarmPlanner $planner,
        private readonly SwarmReviewer $reviewer,
        private readonly AgentRunnerRegistry $runnerRegistry,
        private readonly WorktreeManager $worktreeManager,
        private readonly LockFactory $lockFactory,
        private readonly MessageBusInterface $messageBus,
        private readonly SwarmHubManager $hubManager,
        private readonly SwarmOrderRepository $orderRepository,
    ) {
    }

    public function launch(
        Project $project,
        string $title,
        string $goal,
        SwarmStrategy $strategy = SwarmStrategy::Parallel,
        AgentRunnerKind $workerRunner = AgentRunnerKind::ClaudeCli,
        bool $requirePlanApproval = true,
        ?string $sourceRuleCode = null,
        ?string $baseBranch = null,
        ?string $startedBy = null,
        bool $skipInitialPlan = false,
    ): SwarmRun {
        $coordinator = $this->sessionRecorder->start(
            project: $project,
            title: 'Ruche · '.$title,
            runner: AgentRunnerKind::InternalLlm,
            role: 'coordinator',
            goal: $goal,
            client: 'mnemo-factory',
            branch: $baseBranch,
            sourceRuleCode: $sourceRuleCode,
            startedBy: $startedBy,
        );

        $isHubMode = AgentRunnerKind::ExternalHub === $workerRunner || $skipInitialPlan;

        $swarm = new SwarmRun(
            project: $project,
            title: $title,
            goal: $goal,
            strategy: $strategy,
            workerRunner: $workerRunner,
            requirePlanApproval: $requirePlanApproval,
            hubMode: $isHubMode,
        );
        $swarm->setCoordinatorSession($coordinator);
        $swarm->setSourceRuleCode($sourceRuleCode);
        $swarm->setBaseBranch($baseBranch);
        $swarm->setCreatedBy($startedBy);

        if ($skipInitialPlan) {
            $swarm->markRunning();
        }

        $this->entityManager->persist($swarm);
        $this->entityManager->flush();

        if ($swarm->isHubMode()) {
            $this->hubManager->enableHubToken($swarm);
        }

        $this->postMessage(
            $swarm,
            $startedBy ?: 'coordinator',
            SwarmMessageKind::Brief,
            $goal,
            $coordinator,
        );

        if ($skipInitialPlan) {
            $this->postMessage(
                $swarm,
                'coordinator',
                SwarmMessageKind::System,
                'Hub interactif ouvert en direct : connecte un ou plusieurs clients externes et envoie tes ordres.',
                $coordinator,
            );

            return $swarm;
        }

        $swarmId = $swarm->getId();
        if (null !== $swarmId) {
            $this->messageBus->dispatch(new PlanSwarm($swarmId));
        }

        return $swarm;
    }

    public function plan(SwarmRun $swarm): void
    {
        if (SwarmStatus::Planning !== $swarm->getStatus()) {
            return;
        }

        $lock = $this->lockFactory->createLock('factory-swarm-plan-'.$swarm->getId(), 120);
        if (!$lock->acquire()) {
            return;
        }

        try {
            $context = $this->contextBuilder->build($swarm->getProject(), $swarm->getSourceRuleCode());
            $tasks = $this->planner->plan($swarm, $context);
            $swarm->setPlan($tasks);

            $planLines = [];
            foreach ($tasks as $idx => $task) {
                $planLines[] = \sprintf('%d. **%s** (`%s` · %s) — %s', $idx + 1, $task['title'], $task['slug'], $task['role'], $task['instructions']);
            }
            $this->postMessage(
                $swarm,
                'planner',
                SwarmMessageKind::Plan,
                implode("\n", $planLines),
                $swarm->getCoordinatorSession(),
            );

            if (null !== $swarm->getCoordinatorSession()) {
                $this->sessionRecorder->recordEvent(
                    $swarm->getCoordinatorSession(),
                    AgentSessionEventKind::Decision,
                    \sprintf('Plan généré : %d tâche(s) worker.', \count($tasks)),
                    payload: ['tasks' => array_column($tasks, 'slug')],
                );
            }

            if ($swarm->requiresPlanApproval()) {
                $swarm->markAwaitingApproval();
                $swarm->getCoordinatorSession()?->markWaitingHuman();
                $this->entityManager->flush();

                return;
            }

            $this->entityManager->flush();
            $this->approveAndDispatch($swarm);
        } finally {
            $lock->release();
        }
    }

    public function approveAndDispatch(SwarmRun $swarm): void
    {
        if (!\in_array($swarm->getStatus(), [SwarmStatus::Planning, SwarmStatus::AwaitingApproval, SwarmStatus::Paused], true)) {
            return;
        }

        if ($swarm->isHubMode() && [] === $swarm->getPlan() && SwarmStatus::Paused === $swarm->getStatus()) {
            $swarm->markRunning();
            $swarm->getCoordinatorSession()?->markRunning();
            if (!$swarm->hasHubToken()) {
                $this->hubManager->enableHubToken($swarm);
            }
            $this->entityManager->flush();

            return;
        }

        $plan = $swarm->getPlan();
        if ([] === $plan) {
            $this->plan($swarm);
            $plan = $swarm->getPlan();
        }
        if ([] === $plan) {
            $swarm->fail('Aucune tâche planifiée à exécuter.');
            $this->entityManager->flush();

            return;
        }

        $swarm->markRunning();
        $swarm->getCoordinatorSession()?->markRunning();
        $this->entityManager->flush();

        $coordinator = $swarm->getCoordinatorSession();

        if (AgentRunnerKind::ExternalHub === $swarm->getWorkerRunner()) {
            $swarm->setHubMode(true);
            if (!$swarm->hasHubToken()) {
                $this->hubManager->enableHubToken($swarm);
            }
            $this->entityManager->flush();

            if ([] === $this->orderRepository->forSwarm($swarm)) {
                $orders = $this->hubManager->publishPlanAsOrders($swarm);
                $this->postMessage(
                    $swarm,
                    'coordinator',
                    SwarmMessageKind::Handoff,
                    \sprintf('Plan approuvé : %d ordre(s) publié(s) dans le Hub interactif.', \count($orders)),
                    $coordinator,
                );
            }

            return;
        }

        $existingChildren = null !== $coordinator ? $this->sessionRepository->childrenOf($coordinator) : [];
        if ([] !== $existingChildren) {
            // Resuming a previously dispatched swarm: re-enqueue only queued/running workers.
            foreach ($existingChildren as $index => $child) {
                if ($child->getStatus()->isActive() && null !== $swarm->getId() && null !== $child->getId()) {
                    $this->messageBus->dispatch(new RunSwarmWorker($swarm->getId(), $child->getId(), $index));
                }
            }

            return;
        }

        $this->postMessage(
            $swarm,
            'coordinator',
            SwarmMessageKind::Handoff,
            \sprintf('Plan approuvé : lancement de %d worker(s) (%s · %s).', \count($plan), $swarm->getStrategy()->value, $swarm->getWorkerRunner()->value),
            $coordinator,
        );

        foreach ($plan as $index => $task) {
            $workerSession = $this->sessionRecorder->start(
                project: $swarm->getProject(),
                title: $task['title'],
                runner: $swarm->getWorkerRunner(),
                role: $task['role'],
                goal: $task['instructions'],
                client: 'mnemo-swarm',
                sourceRuleCode: $swarm->getSourceRuleCode(),
                startedBy: $swarm->getCreatedBy() ?? 'coordinator',
                parentSession: $coordinator,
                initialStatus: AgentSessionStatus::Queued,
                metadata: ['swarm_public_id' => $swarm->getPublicId(), 'worker_slug' => $task['slug'], 'task_index' => $index],
            );

            if (null !== $swarm->getId() && null !== $workerSession->getId()) {
                $this->messageBus->dispatch(new RunSwarmWorker($swarm->getId(), $workerSession->getId(), $index));
            }
        }
    }

    public function executeWorker(SwarmRun $swarm, AgentSession $workerSession, int $taskIndex): void
    {
        if (SwarmStatus::Running !== $swarm->getStatus() || $workerSession->getStatus()->isTerminal()) {
            return;
        }

        $taskSpec = $swarm->getPlan()[$taskIndex] ?? null;
        if (null === $taskSpec) {
            $this->sessionRecorder->finish($workerSession, AgentSessionStatus::Failed, 'Tâche introuvable dans le plan de la ruche.');

            return;
        }

        $lock = $this->lockFactory->createLock('factory-worker-'.$workerSession->getId(), 600);
        if (!$lock->acquire()) {
            return;
        }

        try {
            $workerSession->markRunning();
            $worktreePath = null;
            $branch = null;
            $runnerKind = $swarm->getWorkerRunner();
            $needsWorktree = \in_array($runnerKind, [AgentRunnerKind::ClaudeCli, AgentRunnerKind::CodexCli, AgentRunnerKind::GeminiCli], true);

            if ($needsWorktree && $this->worktreeManager->isGitRepository($swarm->getProject())) {
                $handle = $this->worktreeManager->create(
                    $swarm->getProject(),
                    $swarm->getPublicId(),
                    $taskSpec['slug'],
                    $swarm->getBaseBranch(),
                );
                $worktreePath = $handle->path;
                $branch = $handle->branch;
                $workerSession->setWorktreePath($worktreePath);
                $workerSession->setBranch($branch);
            }
            $this->entityManager->flush();

            $this->sessionRecorder->recordEvent(
                $workerSession,
                AgentSessionEventKind::Step,
                \sprintf('Exécution du worker "%s" via %s%s.', $taskSpec['slug'], $runnerKind->value, null !== $branch ? ' (branche '.$branch.')' : ''),
            );

            $context = $this->contextBuilder->build($swarm->getProject(), $swarm->getSourceRuleCode());
            $task = new WorkerTask(
                swarmPublicId: $swarm->getPublicId(),
                slug: $taskSpec['slug'],
                title: $taskSpec['title'],
                role: $taskSpec['role'],
                instructions: $taskSpec['instructions'],
                projectContext: $context,
                worktreePath: $worktreePath,
                branch: $branch,
                filesHint: $taskSpec['files_hint'],
            );

            $result = $this->runnerRegistry->for($runnerKind)->run($swarm->getProject(), $runnerKind, $task);

            $filesTouched = $result->filesTouched;
            $metadata = ['exit_code' => $result->exitCode];
            if (null !== $worktreePath) {
                $diff = $this->worktreeManager->diffSummary($worktreePath);
                $filesTouched = array_values(array_unique([...$filesTouched, ...$diff->filesChanged]));
                if ('' !== $diff->stat) {
                    $metadata['diff_stat'] = $diff->stat;
                }
            }

            if ('' !== trim($result->output)) {
                $this->sessionRecorder->recordEvent(
                    $workerSession,
                    AgentSessionEventKind::Log,
                    mb_strimwidth($result->output, 0, 8000, "\n… (tronqué)"),
                    $filesTouched,
                );
            }

            $finalStatus = $result->succeeded ? AgentSessionStatus::Succeeded : AgentSessionStatus::Failed;
            $this->sessionRecorder->finish($workerSession, $finalStatus, $result->summary, $filesTouched, $metadata);

            $this->postMessage(
                $swarm,
                $taskSpec['slug'],
                SwarmMessageKind::Progress,
                \sprintf('[%s] %s%s', $result->succeeded ? 'OK' : 'ÉCHEC', $result->summary, [] !== $filesTouched ? "\nFichiers : ".implode(', ', $filesTouched) : ''),
                $workerSession,
            );

            $this->maybeTriggerReview($swarm);
        } catch (\Throwable $exception) {
            $this->sessionRecorder->finish($workerSession, AgentSessionStatus::Failed, $exception->getMessage());
            $this->postMessage($swarm, $taskSpec['slug'], SwarmMessageKind::System, 'Erreur worker : '.$exception->getMessage(), $workerSession);
            $this->maybeTriggerReview($swarm);
        } finally {
            $lock->release();
        }
    }

    public function review(SwarmRun $swarm): void
    {
        if (!\in_array($swarm->getStatus(), [SwarmStatus::Running, SwarmStatus::Reviewing], true)) {
            return;
        }

        $lock = $this->lockFactory->createLock('factory-swarm-review-'.$swarm->getId(), 180);
        if (!$lock->acquire()) {
            return;
        }

        try {
            $swarm->markReviewing();
            $this->entityManager->flush();

            $coordinator = $swarm->getCoordinatorSession();
            $workers = null !== $coordinator ? $this->sessionRepository->childrenOf($coordinator) : [];
            $context = $this->contextBuilder->build($swarm->getProject(), $swarm->getSourceRuleCode());
            $summary = $this->reviewer->review($swarm, $workers, $context);

            $this->postMessage($swarm, 'reviewer', SwarmMessageKind::Review, $summary, $coordinator);

            $anySucceeded = array_any($workers, static fn (AgentSession $s): bool => AgentSessionStatus::Succeeded === $s->getStatus());
            $allFiles = [];
            foreach ($workers as $worker) {
                $allFiles = [...$allFiles, ...$worker->getFilesTouched()];
            }
            $allFiles = array_values(array_unique($allFiles));

            if ($anySucceeded || [] === $workers) {
                $swarm->complete($summary);
                $swarm->clearHubToken();
                $this->hubManager->cancelPendingOrders($swarm);
                if (null !== $coordinator && $coordinator->getStatus()->isActive()) {
                    $this->sessionRecorder->finish($coordinator, AgentSessionStatus::Succeeded, $summary, $allFiles);
                }
            } else {
                $swarm->fail($summary);
                $swarm->clearHubToken();
                $this->hubManager->cancelPendingOrders($swarm);
                if ($coordinator->getStatus()->isActive()) {
                    $this->sessionRecorder->finish($coordinator, AgentSessionStatus::Failed, $summary, $allFiles);
                }
            }

            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
    }

    /**
     * Advances a swarm synchronously in dev mode when no `messenger:consume` worker is running in the background.
     */
    public function advanceSynchronously(SwarmRun $swarm): void
    {
        if (SwarmStatus::Planning === $swarm->getStatus()) {
            $this->plan($swarm);

            return;
        }

        if (SwarmStatus::Running === $swarm->getStatus()) {
            if ($swarm->isHubMode() || AgentRunnerKind::ExternalHub === $swarm->getWorkerRunner()) {
                return;
            }

            $coordinator = $swarm->getCoordinatorSession();
            $workers = null !== $coordinator ? $this->sessionRepository->childrenOf($coordinator) : [];
            foreach ($workers as $index => $worker) {
                if ($worker->getStatus()->isActive()) {
                    $taskIndex = is_numeric($worker->getMetadata()['task_index'] ?? null)
                        ? (int) $worker->getMetadata()['task_index']
                        : $index;
                    $this->executeWorker($swarm, $worker, $taskIndex);
                }
            }
            if (SwarmStatus::Reviewing === $swarm->getStatus() || SwarmStatus::Running === $swarm->getStatus()) {
                $this->review($swarm);
            }

            return;
        }

        if (SwarmStatus::Reviewing === $swarm->getStatus()) {
            $this->review($swarm);
        }
    }

    public function pause(SwarmRun $swarm, string $actor = 'human'): void
    {
        if (!$swarm->getStatus()->isActive()) {
            return;
        }
        $swarm->pause();
        $swarm->getCoordinatorSession()?->markWaitingHuman();
        $this->postMessage($swarm, $actor, SwarmMessageKind::System, 'Ruche mise en pause.');
        $this->entityManager->flush();
    }

    public function resume(SwarmRun $swarm, string $actor = 'human'): void
    {
        if (SwarmStatus::Paused !== $swarm->getStatus()) {
            return;
        }
        $this->postMessage($swarm, $actor, SwarmMessageKind::System, 'Reprise de la ruche demandée.');
        $this->approveAndDispatch($swarm);
    }

    public function complete(SwarmRun $swarm, string $actor = 'human', ?string $summary = null): void
    {
        if ($swarm->getStatus()->isTerminal()) {
            return;
        }

        $finalSummary = null !== $summary && '' !== trim($summary)
            ? trim($summary)
            : 'Session clôturée par l’opérateur (/end).';

        $swarm->complete($finalSummary);
        $this->hubManager->cancelPendingOrders($swarm);
        $this->hubManager->disconnectAllNodes($swarm);

        $coordinator = $swarm->getCoordinatorSession();
        if (null !== $coordinator) {
            foreach ($this->sessionRepository->childrenOf($coordinator) as $child) {
                if ($child->getStatus()->isActive()) {
                    $this->sessionRecorder->finish($child, AgentSessionStatus::Succeeded, 'Terminé avec la ruche parente.');
                }
            }
            if ($coordinator->getStatus()->isActive()) {
                $this->sessionRecorder->finish($coordinator, AgentSessionStatus::Succeeded, $finalSummary);
            }
        }

        $this->postMessage($swarm, $actor, SwarmMessageKind::System, $finalSummary);
        $this->entityManager->flush();
    }

    public function cancel(SwarmRun $swarm, string $actor = 'human'): void
    {
        if ($swarm->getStatus()->isTerminal()) {
            return;
        }
        $swarm->cancel('Ruche annulée manuellement.');
        $swarm->clearHubToken();
        $this->hubManager->cancelPendingOrders($swarm);
        $this->hubManager->disconnectAllNodes($swarm);
        $coordinator = $swarm->getCoordinatorSession();
        if (null !== $coordinator) {
            foreach ($this->sessionRepository->childrenOf($coordinator) as $child) {
                if ($child->getStatus()->isActive()) {
                    $this->sessionRecorder->finish($child, AgentSessionStatus::Cancelled, 'Annulé avec la ruche parente.');
                }
            }
            if ($coordinator->getStatus()->isActive()) {
                $this->sessionRecorder->finish($coordinator, AgentSessionStatus::Cancelled, 'Ruche annulée.');
            }
        }
        $this->postMessage($swarm, $actor, SwarmMessageKind::System, 'Ruche annulée.');
        $this->entityManager->flush();
    }

    public function archive(SwarmRun $swarm, string $actor = 'human'): void
    {
        if ($swarm->getStatus()->isActive()) {
            $this->cancel($swarm, $actor);
        }

        $swarm->archive();
        $coordinator = $swarm->getCoordinatorSession();
        if (null !== $coordinator) {
            $coordinator->archive();
            foreach ($this->sessionRepository->childrenOf($coordinator) as $child) {
                $child->archive();
            }
        }

        $this->entityManager->flush();
    }

    public function unarchive(SwarmRun $swarm): void
    {
        $swarm->unarchive();
        $coordinator = $swarm->getCoordinatorSession();
        if (null !== $coordinator) {
            $coordinator->unarchive();
            foreach ($this->sessionRepository->childrenOf($coordinator) as $child) {
                $child->unarchive();
            }
        }

        $this->entityManager->flush();
    }

    public function delete(SwarmRun $swarm): void
    {
        $coordinator = $swarm->getCoordinatorSession();
        $children = null !== $coordinator ? $this->sessionRepository->childrenOf($coordinator) : [];

        $this->entityManager->remove($swarm);
        $this->entityManager->flush();

        foreach ($children as $child) {
            $this->entityManager->remove($child);
        }
        if (null !== $coordinator) {
            $this->entityManager->remove($coordinator);
        }
        $this->entityManager->flush();
    }

    public function postMessage(
        SwarmRun $swarm,
        string $author,
        SwarmMessageKind $kind,
        string $body,
        ?AgentSession $session = null,
    ): SwarmMessage {
        $message = new SwarmMessage($swarm, $author, $kind, $body, $session);
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        return $message;
    }

    private function maybeTriggerReview(SwarmRun $swarm): void
    {
        $coordinator = $swarm->getCoordinatorSession();
        if (null === $coordinator || SwarmStatus::Running !== $swarm->getStatus()) {
            return;
        }
        $workers = $this->sessionRepository->childrenOf($coordinator);
        if ([] === $workers) {
            return;
        }
        $stillActive = array_any($workers, static fn (AgentSession $s): bool => $s->getStatus()->isActive());
        if (!$stillActive && null !== $swarm->getId()) {
            $swarm->markReviewing();
            $this->entityManager->flush();
            $this->messageBus->dispatch(new ReviewSwarm($swarm->getId()));
        }
    }
}
