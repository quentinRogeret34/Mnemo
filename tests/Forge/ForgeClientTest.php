<?php

namespace App\Tests\Forge;

use App\Forge\ForgeClient;
use App\Forge\GitHubClient;
use App\Forge\GitLabClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ForgeClientTest extends TestCase
{
    /**
     * Details are requested in parallel: once one fails, the others must not fail later, out of reach of the caller.
     *
     * @param \Closure(HttpClientInterface): ForgeClient $create
     * @param list<array<string, mixed>>                 $commits
     */
    #[DataProvider('forges')]
    public function testARefusedDetailRequestFailsOnce(\Closure $create, array $commits): void
    {
        $http = new MockHttpClient(static fn (string $method, string $url): JsonMockResponse => str_ends_with((string) parse_url($url, \PHP_URL_PATH), '/commits')
            ? new JsonMockResponse($commits)
            : new JsonMockResponse(['message' => 'API rate limit exceeded'], ['http_code' => 403]), 'https://forge.example.com/');
        $client = $create($http);

        try {
            $client->commits(null, 10);
            self::fail('The refused request must be reported.');
        } catch (ClientExceptionInterface $exception) {
            self::assertSame(403, $exception->getResponse()->getStatusCode());
        }
        unset($client, $http, $exception);
        gc_collect_cycles();
    }

    /**
     * @return iterable<string, array{\Closure(HttpClientInterface): ForgeClient, list<array<string, mixed>>}>
     */
    public static function forges(): iterable
    {
        $github = static fn (string $sha): array => ['sha' => $sha, 'html_url' => 'https://forge.example.com/'.$sha, 'commit' => ['message' => 'Change', 'author' => ['name' => 'Alice', 'date' => '2026-11-02T10:00:00Z']]];
        $gitlab = static fn (string $id): array => ['id' => $id, 'web_url' => 'https://forge.example.com/'.$id, 'message' => 'Change', 'author_name' => 'Alice', 'committed_date' => '2026-11-02T10:00:00Z'];

        yield 'github' => [static fn (HttpClientInterface $http): ForgeClient => new GitHubClient($http, 'acme/shop'), array_map($github, ['a', 'b', 'c'])];
        yield 'gitlab' => [static fn (HttpClientInterface $http): ForgeClient => new GitLabClient($http, 'acme/shop'), array_map($gitlab, ['a', 'b', 'c'])];
    }
}
