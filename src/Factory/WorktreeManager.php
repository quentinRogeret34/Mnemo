<?php

namespace App\Factory;

use App\Entity\Project;
use App\Factory\Dto\WorktreeDiff;
use App\Factory\Dto\WorktreeHandle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Process;

/**
 * Manages isolated git worktrees for swarm workers so no worker ever writes directly onto the project's main checkout.
 */
final class WorktreeManager
{
    private const MAX_DIFF_BYTES = 12_000;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $appDir,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function isGitRepository(Project $project): bool
    {
        $repoPath = trim($project->getRepositoryPath());
        if ('' === $repoPath || !is_dir($repoPath)) {
            return false;
        }

        return is_dir($repoPath.'/.git') || is_file($repoPath.'/.git');
    }

    public function create(Project $project, string $swarmPublicId, string $workerSlug, ?string $baseBranch = null): WorktreeHandle
    {
        if (!$this->isGitRepository($project)) {
            throw new \RuntimeException(\sprintf('Le chemin local "%s" du projet "%s" n’est pas un dépôt git valide.', $project->getRepositoryPath(), $project->getSlug()));
        }

        $safeSwarm = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $swarmPublicId) ?: 'swarm';
        $safeWorker = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $workerSlug) ?: 'worker';
        $branch = \sprintf('factory/%s/%s', $safeSwarm, $safeWorker);
        $targetPath = \sprintf('%s/var/worktrees/%s/%s/%s', $this->appDir, $project->getSlug(), $safeSwarm, $safeWorker);

        $lock = $this->lockFactory->createLock('factory-worktree-'.$project->getId(), 60);
        if (!$lock->acquire(true)) {
            throw new \RuntimeException('Impossible d’acquérir le verrou git worktree.');
        }

        try {
            $fs = new Filesystem();
            $fs->mkdir(\dirname($targetPath));

            if (is_dir($targetPath)) {
                $this->runGit(['worktree', 'remove', '--force', $targetPath], $project->getRepositoryPath(), false);
                $fs->remove($targetPath);
            }

            $base = null !== $baseBranch && '' !== trim($baseBranch) ? trim($baseBranch) : 'HEAD';
            $args = ['worktree', 'add', '-B', $branch, $targetPath, $base];
            $this->runGit($args, $project->getRepositoryPath(), true);

            return new WorktreeHandle($targetPath, $branch);
        } finally {
            $lock->release();
        }
    }

    public function diffSummary(string $worktreePath): WorktreeDiff
    {
        if (!is_dir($worktreePath)) {
            return new WorktreeDiff([], '', '');
        }

        $statusOut = $this->runGit(['status', '--porcelain'], $worktreePath, false);
        $files = [];
        foreach (preg_split('/\R/', rtrim($statusOut, "\r\n")) ?: [] as $line) {
            $line = rtrim($line);
            if (\strlen($line) < 4) {
                continue;
            }
            $file = trim(substr($line, 3));
            if ('' !== $file) {
                $files[] = $file;
            }
        }

        $stat = trim($this->runGit(['diff', '--stat', 'HEAD'], $worktreePath, false));
        $diff = $this->runGit(['diff', 'HEAD'], $worktreePath, false);
        if (\strlen($diff) > self::MAX_DIFF_BYTES) {
            $diff = substr($diff, 0, self::MAX_DIFF_BYTES)."\n… (diff tronqué à 12 Ko)";
        }

        return new WorktreeDiff(array_values(array_unique($files)), $stat, trim($diff));
    }

    public function remove(Project $project, string $worktreePath): void
    {
        if ('' === trim($worktreePath)) {
            return;
        }

        $lock = $this->lockFactory->createLock('factory-worktree-'.$project->getId(), 60);
        if (!$lock->acquire(true)) {
            return;
        }

        try {
            if ($this->isGitRepository($project)) {
                $this->runGit(['worktree', 'remove', '--force', $worktreePath], $project->getRepositoryPath(), false);
            }
            (new Filesystem())->remove($worktreePath);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<string> $args
     */
    private function runGit(array $args, string $cwd, bool $mustSucceed): string
    {
        $process = new Process(['git', ...$args], $cwd, timeout: 60);
        $process->run();

        if ($mustSucceed && !$process->isSuccessful()) {
            throw new \RuntimeException(\sprintf('Commande git "%s" en échec : %s', implode(' ', $args), trim($process->getErrorOutput() ?: $process->getOutput())));
        }

        return $process->getOutput();
    }
}
