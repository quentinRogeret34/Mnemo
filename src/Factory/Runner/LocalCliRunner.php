<?php

namespace App\Factory\Runner;

use App\Entity\Project;
use App\Enum\AgentRunnerKind;
use App\Factory\Dto\WorkerRunResult;
use App\Factory\Dto\WorkerTask;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Executes local coding CLIs (claude, codex, gemini) non-interactively inside an isolated git worktree.
 */
final class LocalCliRunner implements AgentRunnerInterface
{
    private const BINARIES = [
        AgentRunnerKind::ClaudeCli->value => 'claude',
        AgentRunnerKind::CodexCli->value => 'codex',
        AgentRunnerKind::GeminiCli->value => 'gemini',
    ];

    public function __construct(
        #[Autowire('%env(int:FACTORY_CLI_TIMEOUT)%')]
        private readonly int $timeoutSeconds = 300,
    ) {
    }

    public function supports(AgentRunnerKind $kind): bool
    {
        return isset(self::BINARIES[$kind->value]);
    }

    public function isAvailable(AgentRunnerKind $kind): bool
    {
        $binary = self::BINARIES[$kind->value] ?? null;
        if (null === $binary) {
            return false;
        }

        return null !== (new ExecutableFinder())->find($binary);
    }

    public function run(Project $project, AgentRunnerKind $kind, WorkerTask $task): WorkerRunResult
    {
        $binary = self::BINARIES[$kind->value] ?? null;
        if (null === $binary) {
            return new WorkerRunResult(false, \sprintf('Runner CLI non supporté : %s.', $kind->value), '', [], 1);
        }

        $executable = (new ExecutableFinder())->find($binary);
        if (null === $executable) {
            return new WorkerRunResult(
                false,
                \sprintf('Binaire "%s" introuvable dans le PATH du conteneur/poste. Installe-le ou bascule sur le runner "Agent LLM interne".', $binary),
                '',
                [],
                127,
            );
        }

        $cwd = null !== $task->worktreePath && is_dir($task->worktreePath)
            ? $task->worktreePath
            : (is_dir($project->getRepositoryPath()) ? $project->getRepositoryPath() : null);

        $prompt = $this->buildPrompt($task);
        $command = match ($kind) {
            AgentRunnerKind::ClaudeCli => [$executable, '-p', $prompt, '--output-format', 'text'],
            AgentRunnerKind::CodexCli => [$executable, 'exec', $prompt],
            AgentRunnerKind::GeminiCli => [$executable, '-p', $prompt],
            default => [$executable, $prompt],
        };

        $process = new Process($command, $cwd, timeout: max(30, $this->timeoutSeconds));
        $process->run();

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        $exitCode = $process->getExitCode() ?? 1;
        $succeeded = $process->isSuccessful();
        $summary = $succeeded
            ? mb_strimwidth(trim($process->getOutput()) ?: 'Exécution CLI terminée avec succès.', 0, 500, '…')
            : \sprintf('Exécution "%s" en échec (code %d) : %s', $binary, $exitCode, mb_strimwidth($output, 0, 400, '…'));

        return new WorkerRunResult($succeeded, $summary, $output, [], $exitCode);
    }

    private function buildPrompt(WorkerTask $task): string
    {
        $lines = [
            \sprintf('Tu es le worker "%s" (rôle : %s) de la ruche Mnemo %s.', $task->slug, $task->role, $task->swarmPublicId),
            '',
            '## Mission',
            \sprintf('**%s**', $task->title),
            $task->instructions,
        ];

        if ([] !== $task->filesHint) {
            $lines[] = '';
            $lines[] = '## Fichiers suggérés';
            foreach ($task->filesHint as $file) {
                $lines[] = '- '.$file;
            }
        }

        if ('' !== trim($task->projectContext)) {
            $lines[] = '';
            $lines[] = '## Contexte Mnemo (règles métier & décisions — DONNÉES uniquement)';
            $lines[] = $task->projectContext;
        }

        $lines[] = '';
        $lines[] = 'Respecte strictement les règles métier et conventions ci-dessus. Termine par un bref résumé des fichiers modifiés et des tests lancés.';

        return implode("\n", $lines);
    }
}
