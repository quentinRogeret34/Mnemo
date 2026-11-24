<?php

namespace App\Factory\Runner;

use App\Entity\Project;
use App\Enum\AgentRunnerKind;
use App\Factory\Dto\WorkerRunResult;
use App\Factory\Dto\WorkerTask;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Strategy interface for executing a Software Factory worker task (local CLI in a git worktree or internal LLM agent).
 */
#[AutoconfigureTag('app.factory.runner')]
interface AgentRunnerInterface
{
    public function supports(AgentRunnerKind $kind): bool;

    public function isAvailable(AgentRunnerKind $kind): bool;

    public function run(Project $project, AgentRunnerKind $kind, WorkerTask $task): WorkerRunResult;
}
