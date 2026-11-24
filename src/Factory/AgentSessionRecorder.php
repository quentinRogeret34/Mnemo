<?php

namespace App\Factory;

use App\Entity\AgentSession;
use App\Entity\AgentSessionEvent;
use App\Entity\Project;
use App\Enum\AgentRunnerKind;
use App\Enum\AgentSessionEventKind;
use App\Enum\AgentSessionStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single write entry point for starting, logging and closing agent sessions.
 */
final class AgentSessionRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function start(
        Project $project,
        string $title,
        AgentRunnerKind $runner = AgentRunnerKind::ExternalMcp,
        string $role = 'developer',
        ?string $goal = null,
        ?string $client = null,
        ?string $branch = null,
        ?string $worktreePath = null,
        ?string $sourceRuleCode = null,
        ?string $startedBy = null,
        ?AgentSession $parentSession = null,
        AgentSessionStatus $initialStatus = AgentSessionStatus::Running,
        array $metadata = [],
    ): AgentSession {
        $session = new AgentSession($project, $title, $runner, $role, $initialStatus);
        $session->setGoal($goal);
        $session->setClient($client);
        $session->setBranch($branch);
        $session->setWorktreePath($worktreePath);
        $session->setSourceRuleCode($sourceRuleCode);
        $session->setStartedBy($startedBy);
        $session->setParentSession($parentSession);
        $session->mergeMetadata($metadata);

        $this->entityManager->persist($session);

        $event = new AgentSessionEvent(
            $session,
            AgentSessionEventKind::Started,
            \sprintf('Session démarrée (%s · %s)', $runner->value, $session->getRole()),
            array_filter([
                'client' => $session->getClient(),
                'branch' => $session->getBranch(),
                'rule_code' => $session->getSourceRuleCode(),
                'parent_session_id' => $parentSession?->getPublicId(),
            ], static fn (mixed $v): bool => null !== $v && '' !== $v),
        );
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * @param list<string>         $files
     * @param array<string, mixed> $payload
     */
    public function recordEvent(
        AgentSession $session,
        AgentSessionEventKind $kind,
        string $message,
        array $files = [],
        array $payload = [],
    ): AgentSessionEvent {
        $session->touch();
        $session->mergeFilesTouched($files);

        $eventPayload = $payload;
        if ([] !== $files && !isset($eventPayload['files'])) {
            $eventPayload['files'] = $files;
        }

        $event = new AgentSessionEvent($session, $kind, $message, $eventPayload);
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    /**
     * @param list<string>         $filesTouched
     * @param array<string, mixed> $metadata
     */
    public function finish(
        AgentSession $session,
        AgentSessionStatus $status,
        ?string $summary = null,
        array $filesTouched = [],
        array $metadata = [],
    ): AgentSession {
        $session->finish($status, $summary, $filesTouched);
        $session->mergeMetadata($metadata);

        $kind = AgentSessionStatus::Failed === $status ? AgentSessionEventKind::Error : AgentSessionEventKind::Completed;
        $message = null !== $summary && '' !== trim($summary)
            ? trim($summary)
            : \sprintf('Session terminée (%s).', $status->value);

        $event = new AgentSessionEvent($session, $kind, $message, [
            'status' => $status->value,
            'files_count' => \count($session->getFilesTouched()),
            'duration_seconds' => $session->durationSeconds(),
        ]);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $session;
    }
}
