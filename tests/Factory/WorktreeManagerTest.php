<?php

namespace App\Tests\Factory;

use App\Entity\Project;
use App\Factory\WorktreeManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\Process;

final class WorktreeManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/mnemo-wt-test-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->tempDir.'/repo');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    public function testCreatesIsolatedWorktreeComputesDiffAndRemovesCleanly(): void
    {
        $repo = $this->tempDir.'/repo';
        $this->git(['init', '-b', 'main'], $repo);
        $this->git(['config', 'user.email', 'test@mnemo.local'], $repo);
        $this->git(['config', 'user.name', 'Mnemo Test'], $repo);
        file_put_contents($repo.'/README.md', "# Demo\n");
        $this->git(['add', 'README.md'], $repo);
        $this->git(['commit', '-m', 'Initial commit'], $repo);

        $project = new Project('demo', 'Demo', $repo);
        $manager = new WorktreeManager($this->tempDir.'/app', new LockFactory(new FlockStore($this->tempDir)));

        self::assertTrue($manager->isGitRepository($project));

        $handle = $manager->create($project, 'swarm_abc123', 'worker-backend', 'main');
        self::assertSame('factory/swarm_abc123/worker-backend', $handle->branch);
        self::assertDirectoryExists($handle->path);
        self::assertFileExists($handle->path.'/README.md');

        // Modify a file inside the isolated worktree without touching main checkout
        file_put_contents($handle->path.'/README.md', "# Demo\nUpdated by worker\n");
        $diff = $manager->diffSummary($handle->path);
        self::assertSame(['README.md'], $diff->filesChanged);
        self::assertStringContainsString('Updated by worker', $diff->diff);
        self::assertSame("# Demo\n", file_get_contents($repo.'/README.md'));

        $manager->remove($project, $handle->path);
        self::assertDirectoryDoesNotExist($handle->path);
    }

    /**
     * @param list<string> $args
     */
    private function git(array $args, string $cwd): void
    {
        $process = new Process(['git', ...$args], $cwd);
        $process->mustRun();
    }
}
