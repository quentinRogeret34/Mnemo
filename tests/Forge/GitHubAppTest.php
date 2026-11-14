<?php

namespace App\Tests\Forge;

use App\Forge\GitHubApp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class GitHubAppTest extends TestCase
{
    public function testItSignsInWithItsKeyThenWorksWithACachedInstallationToken(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): JsonMockResponse {
            $authorization = implode('', preg_grep('/^Authorization:/i', $options['headers']));
            $requests[] = [$method, parse_url($url, \PHP_URL_PATH), substr($authorization, \strlen('Authorization: Bearer '))];

            return new JsonMockResponse(match (parse_url($url, \PHP_URL_PATH)) {
                '/repos/acme/shop/installation' => ['id' => 7],
                '/app/installations/7/access_tokens' => ['token' => 'installation-token'],
                default => ['full_name' => 'acme/shop'],
            });
        });
        // One line, as a host that takes no line break would store it.
        $app = new GitHubApp($http, new ArrayAdapter(), '42', base64_encode($pem), 'webhook-secret', 'graphe-projet');

        $app->client('acme/shop')->request('GET', 'repos/acme/shop')->toArray();
        $app->client('Acme/Shop')->request('GET', 'repos/acme/shop')->toArray();

        self::assertSame(
            [['GET', '/repos/acme/shop/installation'], ['POST', '/app/installations/7/access_tokens'], ['GET', '/repos/acme/shop'], ['GET', '/repos/acme/shop']],
            array_map(static fn (array $request): array => [$request[0], $request[1]], $requests),
        );
        self::assertSame(['installation-token', 'installation-token'], [$requests[2][2], $requests[3][2]]);

        [$header, $claims, $signature] = explode('.', $requests[0][2]);
        $decode = static fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'), true);
        self::assertSame(1, openssl_verify($header.'.'.$claims, $decode($signature), openssl_pkey_get_details($key)['key'], \OPENSSL_ALGO_SHA256));
        self::assertSame('42', json_decode($decode($claims), true)['iss']);
        self::assertSame('https://github.com/apps/graphe-projet/installations/new', $app->installUrl());
    }

    public function testAWebhookIsOnlyTrustedWithTheSignatureOfTheSecret(): void
    {
        $app = new GitHubApp(new MockHttpClient(), new ArrayAdapter(), '42', 'key', 'webhook-secret', '');

        self::assertTrue($app->isSignedBy('{"zen":1}', 'sha256='.hash_hmac('sha256', '{"zen":1}', 'webhook-secret')));
        self::assertFalse($app->isSignedBy('{"zen":2}', 'sha256='.hash_hmac('sha256', '{"zen":1}', 'webhook-secret')));
        self::assertFalse($app->isSignedBy('{"zen":1}', ''));
        self::assertFalse((new GitHubApp(new MockHttpClient(), new ArrayAdapter(), '', '', '', ''))->isSignedBy('', 'sha256='.hash_hmac('sha256', '', '')));
        self::assertNull($app->installUrl());
    }
}
