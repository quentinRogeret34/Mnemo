<?php

namespace App\Forge;

use App\Connector\CredentialResolver;
use App\Entity\Connector;
use App\Enum\ConnectorMode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Keeps a shallow clone of the repository of a forge connector, one directory per connector.
 * The token of the connector reaches git through the environment of the process: it is never written in the URL,
 * in the command line or in the configuration of the clone.
 */
class RepositoryCheckout
{
    private const TIMEOUT = 600;

    public function __construct(
        private readonly CredentialResolver $credentials,
        #[Autowire('%kernel.project_dir%/var/repos')]
        private readonly string $directory,
    ) {
    }

    /**
     * @return array{path: string, commit: string}
     */
    public function checkout(Connector $connector): array
    {
        $url = $this->cloneUrl($connector);
        $path = $this->directory.'/'.$connector->getId();
        $branch = (string) ($connector->getArguments()['branch'] ?? '');
        if ('' !== $branch && !preg_match('#^\w[\w./-]*$#', $branch)) {
            throw new \RuntimeException(\sprintf('Nom de branche invalide : "%s".', $branch));
        }

        $protocols = ['protocol.allow' => 'never', 'protocol.https.allow' => 'always', 'protocol.http.allow' => 'always'];

        return ['path' => $path, 'commit' => $this->fetch($url, $path, '' === $branch ? null : $branch, $protocols + $this->authentication($connector, $url))];
    }

    public function remove(Connector $connector): void
    {
        if (null !== $connector->getId()) {
            $this->removeById($connector->getId());
        }
    }

    public function removeById(int $connectorId): void
    {
        (new Filesystem())->remove([$this->directory.'/'.$connectorId, $this->directory.'/'.$connectorId.'-graph']);
    }

    public function cloneUrl(Connector $connector): string
    {
        $repository = trim((string) ($connector->getArguments()['repository'] ?? ''), '/ ');
        if (!preg_match('#^\w[\w.-]*(/\w[\w.-]*)+$#', $repository)) {
            throw new \RuntimeException(\sprintf('Dépôt invalide : "%s", attendu « propriétaire/dépôt ».', $repository));
        }
        $base = preg_replace('#/api/v\d+/?$#', '', rtrim($connector->getEndpoint(), '/'));
        // The endpoint is typed in a form: never a local path nor a transport that runs a command (file://, ext::).
        if (!\in_array(parse_url($base, \PHP_URL_SCHEME), ['https', 'http'], true) || !\is_string(parse_url($base, \PHP_URL_HOST))) {
            throw new \RuntimeException(\sprintf('Adresse de forge invalide : "%s", attendu une URL http(s).', $connector->getEndpoint()));
        }
        if ('api.github.com' === parse_url($base, \PHP_URL_HOST)) {
            $base = 'https://github.com';
        }

        return $base.'/'.$repository.'.git';
    }

    /**
     * Clones the repository, or brings an existing clone to the tip of the branch.
     *
     * @param array<string, string> $config git configuration of this run only, never written in the clone
     *
     * @return string the commit checked out
     */
    public function fetch(string $url, string $path, ?string $branch = null, array $config = []): string
    {
        // The clone may belong to another user than the worker (volume mounted from the host).
        $config += ['safe.directory' => $path];
        $environment = ['GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_COUNT' => (string) \count($config)];
        foreach (array_keys($config) as $index => $key) {
            $environment += ['GIT_CONFIG_KEY_'.$index => $key, 'GIT_CONFIG_VALUE_'.$index => $config[$key]];
        }

        if (is_dir($path.'/.git')) {
            $this->git(['-C', $path, 'remote', 'set-url', 'origin', $url], $environment);
            $this->git(['-C', $path, 'fetch', '--depth', '1', 'origin', $branch ?? 'HEAD'], $environment);
            $this->git(['-C', $path, 'reset', '--hard', 'FETCH_HEAD'], $environment);
            $this->git(['-C', $path, 'clean', '-fdx'], $environment);
        } else {
            (new Filesystem())->remove($path);
            (new Filesystem())->mkdir(\dirname($path));
            $this->git(['clone', '--depth', '1', '--single-branch', ...(null === $branch ? [] : ['--branch', $branch]), '--', $url, $path], $environment);
        }

        return trim($this->git(['-C', $path, 'rev-parse', 'HEAD'], $environment));
    }

    /**
     * The token of the connector as an HTTP header that git only sends to the host of the repository.
     *
     * @return array<string, string>
     */
    private function authentication(Connector $connector, string $url): array
    {
        $headers = array_change_key_case($this->credentials->forgeHeaders($connector));
        $token = $headers['private-token'] ?? preg_replace('/^\S+\s+/', '', $headers['authorization'] ?? '');
        if ('' === $token) {
            return [];
        }
        $user = ConnectorMode::GitLab === $connector->getMode() ? 'oauth2' : 'x-access-token';

        return [\sprintf('http.%s://%s/.extraHeader', parse_url($url, \PHP_URL_SCHEME), parse_url($url, \PHP_URL_HOST)) => 'Authorization: Basic '.base64_encode($user.':'.$token)];
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     */
    private function git(array $arguments, array $environment): string
    {
        $process = new Process(['git', ...$arguments], null, $environment, null, self::TIMEOUT);
        $process->run();
        if (!$process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput()) ?: 'code '.$process->getExitCode();
            if (str_contains($stderr, 'could not read Username')) {
                $stderr .= ' — Jeton GitHub expiré, révoqué ou absent : reconnecter le compte GitHub sur la page du connecteur ou configurer une GitHub App / un PAT durable.';
            }

            throw new \RuntimeException(\sprintf('git %s a échoué : %s', '-C' === $arguments[0] ? $arguments[2] : $arguments[0], $stderr));
        }

        return $process->getOutput();
    }
}
