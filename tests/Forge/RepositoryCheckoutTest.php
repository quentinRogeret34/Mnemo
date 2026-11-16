<?php

namespace App\Tests\Forge;

use App\Connector\CredentialResolver;
use App\Entity\Connector;
use App\Enum\ConnectorMode;
use App\Forge\RepositoryCheckout;
use App\Security\SecretBox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class RepositoryCheckoutTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mnemo-checkout-'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($this->directory.'/origin/README.md', '# Shop');
        $this->git('init', '--quiet', '--initial-branch=main');
        $this->commit('first');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testAShallowCloneFollowsTheHeadOfTheRepository(): void
    {
        $checkout = new RepositoryCheckout(new CredentialResolver([], new SecretBox('test')), $this->directory.'/clones');
        $clone = $this->directory.'/clones/1';

        self::assertSame($this->git('rev-parse', 'HEAD'), $checkout->fetch('file://'.$this->directory.'/origin', $clone));
        self::assertFileExists($clone.'/README.md');

        (new Filesystem())->dumpFile($this->directory.'/origin/src/main.ts', 'export {};');
        $this->commit('second');
        (new Filesystem())->dumpFile($clone.'/leftover.txt', 'x');

        self::assertSame($this->git('rev-parse', 'HEAD'), $checkout->fetch('file://'.$this->directory.'/origin', $clone, 'main', ['http.https://example.com/.extraHeader' => 'Authorization: Basic c2VjcmV0']));
        self::assertFileExists($clone.'/src/main.ts');
        self::assertFileDoesNotExist($clone.'/leftover.txt');
        self::assertStringNotContainsString('c2VjcmV0', (string) file_get_contents($clone.'/.git/config'));
    }

    public function testTheCloneUrlComesFromTheEndpointOfTheForge(): void
    {
        $checkout = new RepositoryCheckout(new CredentialResolver([], new SecretBox('test')), $this->directory.'/clones');
        $url = static function (ConnectorMode $mode, string $endpoint, string $repository) use ($checkout): string {
            $connector = new Connector();
            $connector->setMode($mode);
            $connector->setEndpoint($endpoint);
            $connector->setArguments(['repository' => $repository]);

            return $checkout->cloneUrl($connector);
        };

        self::assertSame('https://github.com/acme/shop.git', $url(ConnectorMode::GitHub, 'https://api.github.com', 'acme/shop'));
        self::assertSame('https://ghe.example.com/acme/shop.git', $url(ConnectorMode::GitHub, 'https://ghe.example.com/api/v3/', '/acme/shop/'));
        self::assertSame('https://gitlab.example.com/group/sub/shop.git', $url(ConnectorMode::GitLab, 'https://gitlab.example.com/api/v4', 'group/sub/shop'));

        foreach ([['https://api.github.com', '--upload-pack=touch/x'], ['file:///srv', 'acme/shop'], ['ext::sh -c id', 'acme/shop'], ['/srv/git', 'acme/shop']] as [$endpoint, $repository]) {
            try {
                $url(ConnectorMode::GitHub, $endpoint, $repository);
                self::fail(\sprintf('"%s" "%s" must be refused.', $endpoint, $repository));
            } catch (\RuntimeException $exception) {
                self::assertMatchesRegularExpression('/invalide/', $exception->getMessage());
            }
        }
    }

    private function commit(string $message): void
    {
        $this->git('add', '-A');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', $message);
    }

    private function git(string ...$arguments): string
    {
        return trim((new Process(['git', '-C', $this->directory.'/origin', ...$arguments]))->mustRun()->getOutput());
    }
}
