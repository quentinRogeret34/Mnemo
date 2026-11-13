<?php

namespace App\Forge;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class GitHubClient implements ForgeClient
{
    private const PAGE = 100;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $repository,
    ) {
    }

    public function changeRequestPrefix(): string
    {
        return 'pr';
    }

    public function describe(): string
    {
        return 'GitHub '.$this->get('')['full_name'];
    }

    public function commits(?\DateTimeImmutable $since, int $limit): array
    {
        $rows = $this->pages('/commits', null === $since ? [] : ['since' => $since->format(\DATE_ATOM)], $limit);
        $details = $this->all(array_map(fn (array $row) => $this->http->request('GET', $this->path('/commits/'.$row['sha'])), $rows));

        return array_map(static fn (array $row, array $detail): ForgeCommit => new ForgeCommit(
            $row['sha'],
            $row['commit']['message'],
            $row['author']['login'] ?? $row['commit']['author']['name'],
            new \DateTimeImmutable($row['commit']['author']['date']),
            $row['html_url'],
            array_column($detail['files'] ?? [], 'filename'),
        ), $rows, $details);
    }

    public function changeRequests(?\DateTimeImmutable $since, int $limit): array
    {
        $rows = array_filter(
            $this->pages('/pulls', ['state' => 'all', 'sort' => 'updated', 'direction' => 'desc'], $limit),
            static fn (array $row): bool => null === $since || new \DateTimeImmutable($row['updated_at']) >= $since,
        );
        $commits = $this->all(array_map(fn (array $row) => $this->http->request('GET', $this->path('/pulls/'.$row['number'].'/commits'), ['query' => ['per_page' => self::PAGE]]), $rows));

        return array_values(array_map(fn (array $row, array $list): ForgeTicket => $this->ticket($row, true, array_column($list, 'sha')), $rows, $commits));
    }

    public function issues(?\DateTimeImmutable $since, int $limit): array
    {
        // The issues endpoint also lists pull requests, hence the wider read.
        $rows = $this->pages('/issues', ['state' => 'all', 'sort' => 'updated', 'direction' => 'desc'] + (null === $since ? [] : ['since' => $since->format(\DATE_ATOM)]), $limit * 3);

        return \array_slice(array_values(array_map(fn (array $row): ForgeTicket => $this->ticket($row, false), array_filter($rows, static fn (array $row): bool => !isset($row['pull_request'])))), 0, $limit);
    }

    public function reviewText(int $number): string
    {
        $bodies = [...array_column($this->get('/pulls/'.$number.'/reviews'), 'body'), ...array_column($this->get('/pulls/'.$number.'/comments'), 'body')];

        return implode("\n\n", array_filter(array_map('trim', array_map('strval', $bodies))));
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $commits
     */
    private function ticket(array $row, bool $changeRequest, array $commits = []): ForgeTicket
    {
        return new ForgeTicket(
            $changeRequest,
            $row['number'],
            $row['title'],
            (string) ($row['body'] ?? ''),
            null !== ($row['merged_at'] ?? null) ? 'merged' : $row['state'],
            $row['user']['login'] ?? '',
            new \DateTimeImmutable($row['updated_at']),
            $row['html_url'],
            $commits,
        );
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<array<string, mixed>>
     */
    private function pages(string $path, array $query, int $limit): array
    {
        $rows = [];
        for ($page = 1; \count($rows) < $limit; ++$page) {
            $batch = $this->get($path, $query + ['per_page' => min(self::PAGE, $limit), 'page' => $page]);
            $rows = [...$rows, ...$batch];
            if (\count($batch) < min(self::PAGE, $limit)) {
                break;
            }
        }

        return \array_slice($rows, 0, $limit);
    }

    /**
     * Decodes responses requested in parallel. Once one fails the others are cancelled: left alone, each would throw
     * when it is destroyed, long after the caller caught the first error.
     *
     * @param array<ResponseInterface> $responses
     *
     * @return array<array<mixed>>
     */
    private function all(array $responses): array
    {
        try {
            return array_map(static fn (ResponseInterface $response): array => $response->toArray(), $responses);
        } catch (\Throwable $exception) {
            foreach ($responses as $response) {
                $response->cancel();
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->http->request('GET', $this->path($path), ['query' => $query])->toArray();
    }

    private function path(string $path): string
    {
        return 'repos/'.$this->repository.$path;
    }
}
