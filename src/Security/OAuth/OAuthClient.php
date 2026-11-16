<?php

namespace App\Security\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OAuth 2 authorization code flow for the three sign-in providers. A provider is offered once its client id is set.
 * Only an email the provider has verified is accepted: accounts are matched by email across providers.
 */
final class OAuthClient
{
    private const LABELS = ['github' => 'GitHub', 'gitlab' => 'GitLab', 'google' => 'Google'];

    /** @var array<string, array{id: string, secret: string, authorize: string, token: string, scope: string}> */
    private readonly array $providers;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'OAUTH_GITHUB_CLIENT_ID')] string $githubId,
        #[Autowire(env: 'OAUTH_GITHUB_CLIENT_SECRET')] string $githubSecret,
        #[Autowire(env: 'OAUTH_GITLAB_CLIENT_ID')] string $gitlabId,
        #[Autowire(env: 'OAUTH_GITLAB_CLIENT_SECRET')] string $gitlabSecret,
        #[Autowire(env: 'OAUTH_GITLAB_URL')]
        private readonly string $gitlabUrl,
        #[Autowire(env: 'OAUTH_GOOGLE_CLIENT_ID')] string $googleId,
        #[Autowire(env: 'OAUTH_GOOGLE_CLIENT_SECRET')] string $googleSecret,
    ) {
        $gitlab = rtrim($gitlabUrl, '/');
        $this->providers = array_filter([
            'github' => ['id' => $githubId, 'secret' => $githubSecret, 'authorize' => 'https://github.com/login/oauth/authorize', 'token' => 'https://github.com/login/oauth/access_token', 'scope' => 'read:user user:email'],
            'gitlab' => ['id' => $gitlabId, 'secret' => $gitlabSecret, 'authorize' => $gitlab.'/oauth/authorize', 'token' => $gitlab.'/oauth/token', 'scope' => 'read_user'],
            'google' => ['id' => $googleId, 'secret' => $googleSecret, 'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token', 'scope' => 'openid email profile'],
        ], static fn (array $provider): bool => '' !== $provider['id'] && '' !== $provider['secret']);
    }

    /**
     * @return array<string, string> provider key => label
     */
    public function enabled(): array
    {
        return array_intersect_key(self::LABELS, $this->providers);
    }

    /**
     * @param string|null $scope what to ask instead of the sign-in scope, e.g. "repo" to read the repositories
     */
    public function authorizeUrl(string $provider, string $redirectUri, string $state, ?string $scope = null): string
    {
        $config = $this->provider($provider);

        return $config['authorize'].'?'.http_build_query(['client_id' => $config['id'], 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'scope' => $scope ?? $config['scope'], 'state' => $state]);
    }

    public function accessToken(string $provider, string $code, string $redirectUri): string
    {
        $config = $this->provider($provider);

        return $this->httpClient->request('POST', $config['token'], [
            'headers' => ['Accept' => 'application/json'],
            'body' => ['client_id' => $config['id'], 'client_secret' => $config['secret'], 'code' => $code, 'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code'],
        ])->toArray()['access_token'] ?? throw new \RuntimeException('Le fournisseur n\'a pas délivré de jeton.');
    }

    public function profile(string $provider, string $code, string $redirectUri): OAuthProfile
    {
        $token = $this->accessToken($provider, $code, $redirectUri);

        return match ($provider) {
            'github' => $this->github($token),
            'gitlab' => $this->gitlab($token),
            'google' => $this->google($token),
            default => throw new \InvalidArgumentException(\sprintf('Unknown sign-in provider "%s".', $provider)),
        };
    }

    private function github(string $token): OAuthProfile
    {
        $user = $this->get('https://api.github.com/user', $token);
        $emails = array_filter($this->get('https://api.github.com/user/emails', $token), static fn (array $email): bool => ($email['primary'] ?? false) && ($email['verified'] ?? false));
        $email = array_values($emails)[0]['email'] ?? throw new \RuntimeException('Aucune adresse email vérifiée sur ce compte GitHub.');

        return new OAuthProfile('github', (string) $user['id'], $email, (string) ($user['name'] ?? $user['login']));
    }

    private function gitlab(string $token): OAuthProfile
    {
        $user = $this->get(rtrim($this->gitlabUrl, '/').'/api/v4/user', $token);
        if (null === ($user['confirmed_at'] ?? null) || '' === (string) ($user['email'] ?? '')) {
            throw new \RuntimeException('Aucune adresse email vérifiée sur ce compte GitLab.');
        }

        return new OAuthProfile('gitlab', (string) $user['id'], $user['email'], (string) ($user['name'] ?? $user['username']));
    }

    private function google(string $token): OAuthProfile
    {
        $user = $this->get('https://openidconnect.googleapis.com/v1/userinfo', $token);
        if (true !== ($user['email_verified'] ?? false)) {
            throw new \RuntimeException('Aucune adresse email vérifiée sur ce compte Google.');
        }

        return new OAuthProfile('google', (string) $user['sub'], $user['email'], (string) ($user['name'] ?? $user['email']));
    }

    /**
     * @return array<mixed>
     */
    private function get(string $url, string $token): array
    {
        return $this->httpClient->request('GET', $url, ['headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'User-Agent' => 'mnemo']])->toArray();
    }

    /**
     * @return array{id: string, secret: string, authorize: string, token: string, scope: string}
     */
    private function provider(string $provider): array
    {
        return $this->providers[$provider] ?? throw new \InvalidArgumentException(\sprintf('Sign-in provider "%s" is not configured.', $provider));
    }
}
