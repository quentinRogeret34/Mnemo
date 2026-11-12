<?php

namespace App\Forge;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GitLabClient implements ForgeClient
{
    private const PAGE = 100;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $repository,
    ) {
    }

    public function changeRequestPrefix(): string
    {
        return 'mr';
    }

    public function describe(): string
    {
        return 'GitLab '.$this->get('')['path_with_namespace'];
    }

    public function commits(?\DateTimeImmutable $since, int $limit): array
    {
        $rows = $this->pages('/repository/commits', null === $since ? [] : ['since' => $since->format(\DATE_ATOM)], $limit);
        $diffs = array_map(fn (array $row) => $this->http->request('GET', $this->path('/repository/commits/'.$row['id'].'/diff'), ['query' => ['per_page' => self::PAGE]]), $rows);

        return array_map(static fn (array $row, $diff): ForgeCommit => new ForgeCommit(
            $row['id'],
            $row['message'],
            $row['author_name'],
            new \DateTimeImmutable($row['committed_date']),
            $row['web_url'],
            array_values(array_unique(array_column($diff->toArray(), 'new_path'))),
        ), $rows, $diffs);
    }

    public function changeRequests(?\DateTimeImmutable $since, int $limit): array
    {
        $rows = $this->pages('/merge_requests', $this->updated($since), $limit);
        $commits = array_map(fn (array $row) => $this->http->request('GET', $this->path('/merge_requests/'.$row['iid'].'/commits'), ['query' => ['per_page' => self::PAGE]]), $rows);

        return array_map(fn (array $row, $list): ForgeTicket => $this->ticket($row, true, array_column($list->toArray(), 'id')), $rows, $commits);
    }

    public function issues(?\DateTimeImmutable $since, int $limit): array
    {
        return array_map(fn (array $row): ForgeTicket => $this->ticket($row, false), $this->pages('/issues', $this->updated($since), $limit));
    }

    public function reviewText(int $number): string
    {
        $notes = array_filter($this->get('/merge_requests/'.$number.'/notes', ['per_page' => self::PAGE]), static fn (array $note): bool => !($note['system'] ?? false));

        return implode("\n\n", array_filter(array_map('trim', array_column($notes, 'body'))));
    }

    /**
     * @return array<string, string>
     */
    private function updated(?\DateTimeImmutable $since): array
    {
        return ['state' => 'all', 'order_by' => 'updated_at', 'sort' => 'desc'] + (null === $since ? [] : ['updated_after' => $since->format(\DATE_ATOM)]);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $commits
     */
    private function ticket(array $row, bool $changeRequest, array $commits = []): ForgeTicket
    {
        return new ForgeTicket(
            $changeRequest,
            $row['iid'],
            $row['title'],
            (string) ($row['description'] ?? ''),
            $row['state'],
            $row['author']['username'] ?? '',
            new \DateTimeImmutable($row['updated_at']),
            $row['web_url'],
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
        return 'projects/'.rawurlencode($this->repository).$path;
    }
}
