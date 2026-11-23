<?php

namespace App\Connector;

use App\Entity\Connector;
use App\Security\SafeUrl;
use App\Security\SecretBox;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OAuth of the MCP specification for HTTP connectors: the protected resource metadata (RFC 9728) names the
 * authorization server, whose metadata (RFC 8414) gives the endpoints; the application registers itself as a public
 * client (RFC 7591), then runs the authorization code flow with PKCE and the resource indicator (RFC 8707).
 * Tokens are kept encrypted on the connector and refreshed before they expire.
 */
final class McpAuthorization
{
    private const CLIENT_NAME = 'Mnemo';
    private const TIMEOUT = 15;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretBox $secretBox,
        private readonly Connection $connection,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * @return array{url: string, pending: array<string, string>} the URL to send the user to, and what the callback
     *                                                            needs to finish (kept in the session)
     */
    public function start(Connector $connector, string $redirectUri, string $state): array
    {
        $endpoint = SafeUrl::requireHttps($connector->getEndpoint());
        $challenge = $this->challenge($endpoint);
        $resource = $this->protectedResource($endpoint, $challenge['resource_metadata'] ?? null);
        $issuer = SafeUrl::requireHttps((string) ($resource['authorization_servers'][0] ?? $this->origin($endpoint)));
        $server = $this->authorizationServer($issuer);
        if (isset($server['code_challenge_methods_supported']) && !\in_array('S256', (array) $server['code_challenge_methods_supported'], true)) {
            throw new \RuntimeException('Le serveur d\'autorisation ne prend pas en charge PKCE (S256).');
        }
        $authorize = SafeUrl::requireHttps((string) $server['authorization_endpoint']);
        $tokenEndpoint = SafeUrl::requireHttps((string) $server['token_endpoint']);
        $client = $this->register($server, $redirectUri);
        $verifier = $this->base64Url(random_bytes(32));
        $resourceUri = \is_string($resource['resource'] ?? null) ? $resource['resource'] : $endpoint;
        $scope = $challenge['scope'] ?? (isset($resource['scopes_supported']) ? implode(' ', (array) $resource['scopes_supported']) : '');

        $query = array_filter([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'resource' => $resourceUri,
            'scope' => $scope,
        ], static fn (string $value): bool => '' !== $value);

        return [
            'url' => $authorize.(str_contains($authorize, '?') ? '&' : '?').http_build_query($query),
            'pending' => [
                'endpoint' => $endpoint,
                'resource' => $resourceUri,
                'token_endpoint' => $tokenEndpoint,
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
                'auth_method' => $client['auth_method'],
                'redirect_uri' => $redirectUri,
                'verifier' => $verifier,
            ],
        ];
    }

    /**
     * @param array<string, string> $pending what start() returned
     */
    public function finish(Connector $connector, array $pending, string $code): void
    {
        $tokens = $this->token($pending, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $pending['redirect_uri'], 'code_verifier' => $pending['verifier']]);
        unset($pending['verifier'], $pending['redirect_uri']);
        $connector->setMcpAuthorization($this->store($connector, $pending + $tokens));
    }

    /**
     * Access token to send to the MCP server, refreshed when it is about to expire; null when the connector is not
     * authorized, or when its URL changed since: the token is only meant for the server that was authorized.
     */
    public function accessToken(Connector $connector): ?string
    {
        $authorization = $this->read($connector->getMcpAuthorization());
        if (null === $authorization || $authorization['endpoint'] !== $connector->getEndpoint()) {
            return null;
        }
        if (!$this->expiresSoon($authorization)) {
            return $authorization['access_token'];
        }

        // The web and the worker may refresh at the same time, and a refresh token is often valid only once.
        $lock = $this->lockFactory->createLock('connector-authorization-'.$connector->getId(), 30);
        $lock->acquire(true);
        try {
            $stored = $this->connection->fetchOne('SELECT mcp_authorization FROM connector WHERE id = ?', [$connector->getId()]);
            $authorization = \is_string($stored) ? $this->read($stored) : $authorization;
            if (!$this->expiresSoon($authorization)) {
                $connector->setMcpAuthorization($stored);

                return $authorization['access_token'];
            }
            if ('' === $authorization['refresh_token']) {
                throw new \RuntimeException('L\'autorisation OAuth a expiré : reconnecter le connecteur (« Connecter par OAuth »).');
            }
            try {
                $tokens = $this->token($authorization, ['grant_type' => 'refresh_token', 'refresh_token' => $authorization['refresh_token']]);
            } catch (\Throwable $exception) {
                throw new \RuntimeException('L\'autorisation OAuth a expiré ou a été révoquée : reconnecter le connecteur (« Connecter par OAuth »).', 0, $exception);
            }
            $tokens['refresh_token'] = '' === $tokens['refresh_token'] ? $authorization['refresh_token'] : $tokens['refresh_token'];
            $connector->setMcpAuthorization($this->store($connector, $tokens + $authorization));

            return $tokens['access_token'];
        } finally {
            $lock->release();
        }
    }

    /**
     * Asks the MCP server without a token: its 401 answer points to the protected resource metadata, and may say
     * which scope to ask for.
     *
     * @return array<string, string>
     */
    private function challenge(string $endpoint): array
    {
        $response = $this->httpClient->request('POST', $endpoint, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json, text/event-stream'],
            'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'mnemo', 'version' => '0.1.0']]],
        ]);
        $status = $response->getStatusCode();
        $header = implode(', ', $response->getHeaders(false)['www-authenticate'] ?? []);
        $response->cancel();
        if (401 !== $status) {
            throw new \RuntimeException(\sprintf('Le serveur répond %d sans jeton : il ne demande pas d\'autorisation OAuth.', $status));
        }
        preg_match_all('/(\w+)="([^"]*)"/', $header, $matches, \PREG_SET_ORDER);

        return array_column($matches, 2, 1);
    }

    /**
     * @return array<string, mixed> empty when the server publishes no metadata (older servers, which are their own
     *                              authorization server)
     */
    private function protectedResource(string $endpoint, ?string $metadataUrl): array
    {
        $path = rtrim((string) parse_url($endpoint, \PHP_URL_PATH), '/');
        $candidates = null !== $metadataUrl ? [SafeUrl::requireHttps($metadataUrl)] : array_unique([
            $this->origin($endpoint).'/.well-known/oauth-protected-resource'.$path,
            $this->origin($endpoint).'/.well-known/oauth-protected-resource',
        ]);

        foreach ($candidates as $url) {
            $metadata = $this->json($url);
            if (null !== $metadata && [] !== (array) ($metadata['authorization_servers'] ?? [])) {
                return $metadata;
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizationServer(string $issuer): array
    {
        $origin = $this->origin($issuer);
        $path = rtrim((string) parse_url($issuer, \PHP_URL_PATH), '/');
        $candidates = '' === $path
            ? [$origin.'/.well-known/oauth-authorization-server', $origin.'/.well-known/openid-configuration']
            : [$origin.'/.well-known/oauth-authorization-server'.$path, $origin.'/.well-known/openid-configuration'.$path, $origin.$path.'/.well-known/openid-configuration'];

        foreach ($candidates as $url) {
            $metadata = $this->json($url);
            if (\is_string($metadata['authorization_endpoint'] ?? null) && \is_string($metadata['token_endpoint'] ?? null)) {
                return $metadata;
            }
        }

        // Default endpoints of the 2025-03-26 revision, for servers that publish no metadata.
        return ['authorization_endpoint' => $origin.'/authorize', 'token_endpoint' => $origin.'/token', 'registration_endpoint' => $origin.'/register'];
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array{client_id: string, client_secret: string, auth_method: string}
     */
    private function register(array $server, string $redirectUri): array
    {
        if (!\is_string($server['registration_endpoint'] ?? null)) {
            throw new \RuntimeException('Le serveur d\'autorisation ne permet pas l\'enregistrement dynamique de client : il n\'est pas pris en charge.');
        }
        $endpoint = SafeUrl::requireHttps($server['registration_endpoint']);
        $client = $this->httpClient->request('POST', $endpoint, [
            'timeout' => self::TIMEOUT,
            'json' => [
                'client_name' => self::CLIENT_NAME,
                'redirect_uris' => [$redirectUri],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'token_endpoint_auth_method' => 'none',
            ],
        ])->toArray();

        return [
            'client_id' => \is_string($client['client_id'] ?? null) ? $client['client_id'] : throw new \RuntimeException('Le serveur d\'autorisation n\'a pas délivré d\'identifiant de client.'),
            'client_secret' => (string) ($client['client_secret'] ?? ''),
            'auth_method' => (string) ($client['token_endpoint_auth_method'] ?? 'none'),
        ];
    }

    /**
     * @param array<string, string> $client
     * @param array<string, string> $grant
     *
     * @return array{access_token: string, refresh_token: string, expires_at: string}
     */
    private function token(array $client, array $grant): array
    {
        $endpoint = SafeUrl::requireHttps($client['token_endpoint']);
        $body = $grant + ['client_id' => $client['client_id'], 'resource' => $client['resource']];
        $options = ['timeout' => self::TIMEOUT, 'headers' => ['Accept' => 'application/json']];
        if ('' !== $client['client_secret']) {
            if ('client_secret_post' === $client['auth_method']) {
                $body['client_secret'] = $client['client_secret'];
            } else {
                $options['auth_basic'] = [$client['client_id'], $client['client_secret']];
            }
        }
        $tokens = $this->httpClient->request('POST', $endpoint, $options + ['body' => $body])->toArray();

        return [
            'access_token' => \is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : throw new \RuntimeException('Le serveur d\'autorisation n\'a pas délivré de jeton.'),
            'refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
            'expires_at' => isset($tokens['expires_in']) ? (string) (time() + (int) $tokens['expires_in']) : '',
        ];
    }

    /**
     * @param array<string, string> $authorization
     */
    private function store(Connector $connector, array $authorization): string
    {
        $encrypted = $this->secretBox->encrypt(json_encode($authorization, \JSON_THROW_ON_ERROR));
        if (null !== $connector->getId()) {
            $this->connection->executeStatement('UPDATE connector SET mcp_authorization = ? WHERE id = ?', [$encrypted, $connector->getId()]);
        }

        return $encrypted;
    }

    /**
     * @return array<string, string>|null
     */
    private function read(?string $encrypted): ?array
    {
        return null === $encrypted ? null : json_decode($this->secretBox->decrypt($encrypted), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $authorization
     */
    private function expiresSoon(array $authorization): bool
    {
        return '' !== $authorization['expires_at'] && (int) $authorization['expires_at'] < time() + 60;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(string $url): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => self::TIMEOUT, 'headers' => ['Accept' => 'application/json']]);

            return 200 === $response->getStatusCode() ? $response->toArray() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException(\sprintf('URL invalide : %s', $url));
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
