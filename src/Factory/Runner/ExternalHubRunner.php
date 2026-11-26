<?php

namespace App\Factory\Runner;

use App\Entity\Project;
use App\Enum\AgentRunnerKind;
use App\Factory\Dto\WorkerRunResult;
use App\Factory\Dto\WorkerTask;

/**
 * Marker runner for the Interactive Swarm Hub: external clients connect via MCP or signed Markdown URLs,
 * so the runner is always available on the host without any local CLI dependency.
 */
final class ExternalHubRunner implements AgentRunnerInterface
{
    public function supports(AgentRunnerKind $kind): bool
    {
        return AgentRunnerKind::ExternalHub === $kind;
    }

    public function isAvailable(AgentRunnerKind $kind): bool
    {
        return AgentRunnerKind::ExternalHub === $kind;
    }

    public function run(Project $project, AgentRunnerKind $kind, WorkerTask $task): WorkerRunResult
    {
        return new WorkerRunResult(
            succeeded: true,
            summary: \sprintf('Tâche "%s" déléguée au Hub interactif pour prise en charge par un client externe.', $task->title),
            output: $task->instructions,
            filesTouched: $task->filesHint,
            exitCode: 0,
        );
    }
}
