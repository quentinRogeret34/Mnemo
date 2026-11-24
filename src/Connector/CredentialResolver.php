<?php

namespace App\Connector;

use App\Entity\Connector;
use App\Forge\GitHubApp;
use App\Security\OAuth\OAuthClient;
use App\Security\OAuth\OAuthTokenBundle;
use App\Security\SecretBox;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CredentialResolver
{
    /**
     * Shown in the form instead of a value stored encrypted; submitted back unchanged, it keeps that value.
     */
    public const MASK = '••••••';
    private const SEALED = 'secret:';

    /**
     * @param list<string> $allowedEnv
     */
    public function __construct(
        #[Autowire(env: 'csv:MCP_CONNECTORS_ENV')]
        private readonly array $allowedEnv,
        private readonly SecretBox $secretBox,
        private readonly ?OAuthClient $oauthClient = null,
        private readonly ?GitHubApp $gitHubApp = null,
        private readonly ?Connection $connection = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Headers a forge connector sends. The token of an account connected by OAuth wins over the typed credentials,
     * and only ever goes to github.com: the endpoint of a connector can be edited after the account was connected.
     * Expiring GitHub user tokens (`ghu_...`) are transparently refreshed using their stored refresh token; when
     * GitHub App credentials are configured, installation tokens are also used as a durable fallback.
     *
     * @return array<string, string>
     */
    public function forgeHeaders(Connector $connector): array
    {
        $isGitHubApi = 'api.github.com' === parse_url($connector->getEndpoint(), \PHP_URL_HOST);

        if ($isGitHubApi && null !== $connector->getOauthToken()) {
            $bundle = OAuthTokenBundle::fromStoragePayload($this->secretBox->decrypt($connector->getOauthToken()));
            if ($bundle->isExpiringSoon() && null !== $bundle->refreshToken && null !== $this->oauthClient) {
                try {
                    $refreshed = $this->oauthClient->refreshAccessToken('github', $bundle->refreshToken);
                    $encrypted = $this->secretBox->encrypt($refreshed->toStoragePayload());
                    $connector->setOauthToken($encrypted);
                    if (null !== $this->connection && null !== $connector->getId()) {
                        $this->connection->executeStatement('UPDATE connector SET oauth_token = :token WHERE id = :id', [
                            'token' => $encrypted,
                            'id' => $connector->getId(),
                        ]);
                    }
                    $bundle = $refreshed;
                } catch (\Throwable $exception) {
                    $this->logger?->warning('GitHub OAuth token refresh failed for connector {id}: {message}', [
                        'id' => $connector->getId(),
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            if (!$bundle->isExpired()) {
                return ['Authorization' => 'Bearer '.$bundle->accessToken];
            }

            // Expired OAuth token with no refresh: fall back to GitHub App installation token if installed on this repo.
            if (null !== ($appHeaders = $this->gitHubAppHeaders($connector))) {
                return $appHeaders;
            }

            return ['Authorization' => 'Bearer '.$bundle->accessToken];
        }

        $resolved = $this->resolve($connector->getCredentials());
        if ([] !== $resolved) {
            return $resolved;
        }

        if ($isGitHubApi && null !== ($appHeaders = $this->gitHubAppHeaders($connector))) {
            return $appHeaders;
        }

        return [];
    }

    /**
     * @return array<string, string>|null
     */
    private function gitHubAppHeaders(Connector $connector): ?array
    {
        if (null === $this->gitHubApp || !$this->gitHubApp->isConfigured()) {
            return null;
        }
        $repository = trim((string) ($connector->getArguments()['repository'] ?? ''), '/ ');
        if ('' === $repository) {
            return null;
        }

        try {
            return ['Authorization' => 'Bearer '.$this->gitHubApp->installationToken($repository)];
        } catch (\Throwable $exception) {
            $this->logger?->warning('GitHub App installation token unavailable for {repo}: {message}', [
                'repo' => $repository,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Encrypts the values typed in clear before they are stored, so that a token can be pasted in the form.
     * A value reading "env:NAME" stays as typed: the secret lives in the environment.
     *
     * @param array<string, string> $values
     * @param array<string, string> $previous the values stored before the form was submitted
     *
     * @return array<string, string>
     */
    public function seal(array $values, array $previous): array
    {
        $sealed = [];
        foreach ($values as $name => $value) {
            $sealed[$name] = match (true) {
                self::MASK === $value => $previous[$name] ?? '',
                1 === preg_match('/\benv:[A-Za-z_][A-Za-z0-9_]*/', $value) => $value,
                default => self::SEALED.$this->secretBox->encrypt($value),
            };
        }

        return array_filter($sealed, static fn (string $value): bool => '' !== $value);
    }

    public static function display(string $value): string
    {
        return str_starts_with($value, self::SEALED) ? self::MASK : $value;
    }

    /**
     * Decrypts the values stored encrypted, and replaces every "env:NAME" in the others, so that "Bearer env:GITHUB_TOKEN" works as well as "env:TOKEN".
     * Only the variables listed in MCP_CONNECTORS_ENV can be read: a connector sends them to an endpoint anyone can edit.
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    public function resolve(array $values): array
    {
        return array_map(fn (string $value): string => str_starts_with($value, self::SEALED) ? $this->secretBox->decrypt(substr($value, \strlen(self::SEALED))) : preg_replace_callback('/env:([A-Za-z_][A-Za-z0-9_]*)/', function (array $match): string {
            if (!\in_array($match[1], $this->allowedEnv, true)) {
                throw new \RuntimeException(\sprintf('La variable d\'environnement "%s" n\'est pas autorisée. L\'ajouter à MCP_CONNECTORS_ENV dans .env.local.', $match[1]));
            }
            $resolved = $_SERVER[$match[1]] ?? $_ENV[$match[1]] ?? getenv($match[1]);

            return \is_string($resolved) && '' !== $resolved ? $resolved : throw new \RuntimeException(\sprintf('La variable d\'environnement "%s" n\'est pas définie.', $match[1]));
        }, $value), $values);
    }
}
