<?php

namespace App\Forge;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GitHubAccount
{
    private const PAGE = 100;
    private const MAX_PAGES = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Repositories the token can read: its owner's, those it collaborates on and those of its organizations, most
     * recently pushed first.
     *
     * @return list<array<string, mixed>>
     */
    public function repositories(string $token): array
    {
        $rows = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $batch = $this->httpClient->request('GET', 'https://api.github.com/user/repos', [
                'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'graphe-projet'],
                'query' => ['per_page' => self::PAGE, 'page' => $page, 'sort' => 'pushed', 'affiliation' => 'owner,collaborator,organization_member'],
                'timeout' => 30,
            ])->toArray();
            $rows = [...$rows, ...$batch];
            if (\count($batch) < self::PAGE) {
                break;
            }
        }

        return $rows;
    }
}
