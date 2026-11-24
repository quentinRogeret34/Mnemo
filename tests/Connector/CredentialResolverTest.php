<?php

namespace App\Tests\Connector;

use App\Connector\CredentialResolver;
use App\Entity\Connector;
use App\Enum\ConnectorMode;
use App\Forge\GitHubApp;
use App\Security\OAuth\OAuthClient;
use App\Security\OAuth\OAuthTokenBundle;
use App\Security\SecretBox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class CredentialResolverTest extends TestCase
{
    public function testKeepsLegacyPlainOauthTokenBackwardsCompatible(): void
    {
        $box = new SecretBox('test-secret');
        $connector = $this->githubConnector();
        $connector->setOauthToken($box->encrypt('gho_legacy_token'));

        $resolver = new CredentialResolver([], $box);
        self::assertSame(['Authorization' => 'Bearer gho_legacy_token'], $resolver->forgeHeaders($connector));
    }

    public function testRefreshesExpiringGitHubOauthTokenAutomatically(): void
    {
        $box = new SecretBox('test-secret');
        $connector = $this->githubConnector();
        $expired = new OAuthTokenBundle('ghu_expired', 'ghr_valid_refresh', time() - 60);
        $connector->setOauthToken($box->encrypt($expired->toStoragePayload()));

        $http = new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse([
            'access_token' => 'ghu_fresh_token',
            'refresh_token' => 'ghr_next_refresh',
            'expires_in' => 28800,
        ]));
        $oauthClient = new OAuthClient($http, 'gh-client', 'gh-secret', '', '', 'https://gitlab.com', '', '');

        $resolver = new CredentialResolver([], $box, $oauthClient);
        $headers = $resolver->forgeHeaders($connector);

        self::assertSame(['Authorization' => 'Bearer ghu_fresh_token'], $headers);
        $stored = OAuthTokenBundle::fromStoragePayload($box->decrypt((string) $connector->getOauthToken()));
        self::assertSame('ghu_fresh_token', $stored->accessToken);
        self::assertSame('ghr_next_refresh', $stored->refreshToken);
        self::assertFalse($stored->isExpired());
    }

    public function testFallsBackToGitHubAppInstallationTokenWhenNoOauthToken(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $pem = '';
        openssl_pkey_export($key, $pem);

        $http = new MockHttpClient([
            new JsonMockResponse(['id' => 999]),
            new JsonMockResponse(['token' => 'ghs_installation_token']),
        ]);
        $gitHubApp = new GitHubApp($http, new ArrayAdapter(), '12345', $pem, 'wh-secret', 'mnemo-bot');

        $connector = $this->githubConnector();
        $resolver = new CredentialResolver([], new SecretBox('test-secret'), null, $gitHubApp);

        self::assertSame(['Authorization' => 'Bearer ghs_installation_token'], $resolver->forgeHeaders($connector));
    }

    private function githubConnector(): Connector
    {
        $connector = new Connector();
        $connector->setName('Mnemo');
        $connector->setMode(ConnectorMode::GitHub);
        $connector->setEndpoint('https://api.github.com');
        $connector->setArguments(['repository' => 'acme/mnemo']);

        return $connector;
    }
}
