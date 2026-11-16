<?php

namespace App\Forge;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The GitHub App the review bot acts as: it signs in with the private key of the application, then works on a
 * repository with a token of the installation that covers it.
 */
final class GitHubApp
{
    private const API = 'https://api.github.com/';
    private const HEADERS = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'mnemo', 'X-GitHub-Api-Version' => '2022-11-28'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        #[Autowire(env: 'GITHUB_APP_ID')]
        private readonly string $appId,
        #[Autowire(env: 'GITHUB_APP_PRIVATE_KEY')]
        #[\SensitiveParameter]
        private readonly string $privateKey,
        #[Autowire(env: 'GITHUB_APP_WEBHOOK_SECRET')]
        #[\SensitiveParameter]
        private readonly string $webhookSecret,
        #[Autowire(env: 'GITHUB_APP_SLUG')]
        private readonly string $slug,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->appId && '' !== $this->privateKey && '' !== $this->webhookSecret;
    }

    public function installUrl(): ?string
    {
        return '' === $this->slug ? null : 'https://github.com/apps/'.rawurlencode($this->slug).'/installations/new';
    }

    /**
     * Whether the body of a webhook was signed with the secret of the application ("X-Hub-Signature-256" header).
     */
    public function isSignedBy(string $body, string $signature): bool
    {
        return '' !== $this->webhookSecret && hash_equals('sha256='.hash_hmac('sha256', $body, $this->webhookSecret), $signature);
    }

    /**
     * A client for the REST API, acting as the application on this repository; fails when it is not installed there.
     */
    public function client(string $repository): HttpClientInterface
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('L\'application GitHub n\'est pas configurée (GITHUB_APP_ID, GITHUB_APP_PRIVATE_KEY, GITHUB_APP_WEBHOOK_SECRET).');
        }

        // An installation token lives one hour.
        $token = $this->cache->get('github_app_token_'.hash('xxh128', strtolower($repository)), function (ItemInterface $item) use ($repository): string {
            $item->expiresAfter(3000);
            $app = $this->httpClient->withOptions(['base_uri' => self::API, 'headers' => self::HEADERS + ['Authorization' => 'Bearer '.$this->jwt()], 'timeout' => 30]);
            $installation = $app->request('GET', 'repos/'.$repository.'/installation')->toArray();

            return $app->request('POST', 'app/installations/'.$installation['id'].'/access_tokens')->toArray()['token'];
        });

        return $this->httpClient->withOptions(['base_uri' => self::API, 'headers' => self::HEADERS + ['Authorization' => 'Bearer '.$token], 'timeout' => 30]);
    }

    private function jwt(): string
    {
        // The key comes as a PEM, as a PEM whose line breaks were written "\n", or as its base64.
        $pem = str_contains($this->privateKey, '-----BEGIN') ? str_replace('\n', "\n", $this->privateKey) : (string) base64_decode($this->privateKey, true);
        $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        // Issued a minute ago: GitHub refuses a token from the future when the clocks drift.
        $unsigned = $encode('{"alg":"RS256","typ":"JWT"}').'.'.$encode(json_encode(['iat' => time() - 60, 'exp' => time() + 540, 'iss' => $this->appId], \JSON_THROW_ON_ERROR));
        if (!openssl_sign($unsigned, $signature, $pem, \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Clé privée de l\'application GitHub illisible (GITHUB_APP_PRIVATE_KEY).');
        }

        return $unsigned.'.'.$encode($signature);
    }
}
