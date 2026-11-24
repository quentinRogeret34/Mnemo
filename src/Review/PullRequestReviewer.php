<?php

namespace App\Review;

use App\Entity\Connector;
use App\Entity\Project;
use App\Enum\MemoryType;
use App\Forge\GitHubApp;
use App\Llm\LlmUnavailable;
use App\Notification\WorkspaceNotifier;
use App\Security\SafeUrl;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reviews a pull request as the GitHub App: one comment, rewritten at every push, tells the reviewer which business
 * rules the diff touches, what the project decided about these files and what the diff contradicts; a check
 * "mnemo/rules" warns or fails when a validated rule is touched with neither a test nor a mention.
 */
final class PullRequestReviewer
{
    public const CHECK = 'mnemo/rules';
    private const MARKER = '<!-- mnemo-review -->';
    private const LINE_MARKER = '<!-- mnemo-review-line -->';
    private const PAGE = 100;
    private const MAX_PAGES = 3;
    private const MAX_MEMORIES = 15;
    private const TEST_PATH = '#(^|/)(tests?|specs?|__tests__)/|[._-](test|spec)\.\w+$|Test\.\w+$#i';
    private const SEVERITIES = ['blocker' => 'bloquant', 'major' => 'majeur', 'minor' => 'mineur'];

    public function __construct(
        private readonly GitHubApp $app,
        private readonly ReviewKnowledge $knowledge,
        private readonly ReviewAdvisor $advisor,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ?LockFactory $lockFactory = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?WorkspaceNotifier $notifier = null,
    ) {
    }

    /**
     * @return string the comment, whether or not it was published
     */
    public function review(Connector $connector, int $number, bool $publish = true): string
    {
        $lock = $publish && null !== $this->lockFactory ? $this->lockFactory->createLock(\sprintf('pr-review-%d-%d', (int) $connector->getId(), $number), 120) : null;
        if (null !== $lock && !$lock->acquire()) {
            return '';
        }

        try {
            return $this->doReview($connector, $number, $publish);
        } finally {
            $lock?->release();
        }
    }

    private function doReview(Connector $connector, int $number, bool $publish): string
    {
        $project = $connector->getProject() ?? throw new \RuntimeException('Un connecteur de forge doit être rattaché à un projet.');
        $arguments = $connector->getArguments();
        $repository = trim((string) ($arguments['repository'] ?? ''), '/ ');
        $http = $this->app->client($repository);
        $base = 'repos/'.$repository;

        $pullRequest = $http->request('GET', $base.'/pulls/'.$number)->toArray();
        $files = $this->pages($http, $base.'/pulls/'.$number.'/files');
        $paths = array_values(array_unique(array_filter([...array_column($files, 'filename'), ...array_column($files, 'previous_filename')])));

        $rules = $this->knowledge->rules($project, $paths);
        $memories = $this->knowledge->linkedMemories($project, $paths, array_column($rules, 'code'));
        $history = $this->knowledge->history($project, $paths);

        $text = $pullRequest['title']."\n".($pullRequest['body'] ?? '')."\n".implode("\n", array_map(
            static fn (array $commit): string => $commit['commit']['message'],
            [] === $rules ? [] : $this->pages($http, $base.'/pulls/'.$number.'/commits'),
        ));
        $tested = [] !== preg_grep(self::TEST_PATH, $paths);
        $unguarded = array_values(array_filter(
            $rules,
            static fn (array $rule): bool => 'validated' === $rule['status'] && !$tested && !preg_match('/\b'.preg_quote($rule['code'], '/').'\b/', $text),
        ));

        $remarks = [];
        $note = null;
        if (false !== ($arguments['review_llm'] ?? true)) {
            try {
                $remarks = $this->advisor->remarks(
                    $project,
                    $pullRequest['title'],
                    (string) ($pullRequest['body'] ?? ''),
                    array_filter(array_column($files, 'patch', 'filename')),
                    array_values(array_column([...$this->knowledge->projectMemories($project), ...$memories], null, 'id')),
                    $rules,
                    '' === ($arguments['review_model'] ?? '') ? null : (string) $arguments['review_model'],
                );
            } catch (\Throwable $exception) {
                $this->logger?->warning('PR review LLM analysis failed for {repo}#{number}: {message}', ['repo' => $repository, 'number' => $number, 'message' => $exception->getMessage()]);
                $note = $exception instanceof LlmUnavailable
                    ? 'Lecture du diff par le LLM indisponible : '.$exception->getMessage()
                    : 'Lecture du diff par le LLM temporairement indisponible.';
            }
        }

        $body = $this->render($project, $pullRequest, $rules, $unguarded, $remarks, $memories, $history, $note);
        if ($publish) {
            $this->comment($http, $base, $number, $body, [] !== $rules || [] !== $memories || [] !== $remarks);
            $this->check($http, $base, $pullRequest['head']['sha'], $rules, $unguarded, 'fail' === ($arguments['review_check'] ?? 'warn'));
            $this->lineComments($http, $base, $number, $pullRequest['head']['sha'], $project, array_values(array_filter($remarks, static fn (array $remark): bool => null !== $remark['line'])));
            if ([] !== $remarks) {
                $this->notifier?->notifyPullRequestRemarks($project, $number, (string) $pullRequest['title'], SafeUrl::http((string) ($pullRequest['html_url'] ?? '')), $remarks);
            }
        }

        return $body;
    }

    /**
     * The open pull requests of the repository, most recently updated first, read as the GitHub App.
     *
     * @return list<array{number: int, title: string, author: string, draft: bool, url: string, updated_at: string}>
     */
    public function openPullRequests(Connector $connector): array
    {
        $repository = trim((string) ($connector->getArguments()['repository'] ?? ''), '/ ');
        $pullRequests = $this->app->client($repository)->request('GET', 'repos/'.$repository.'/pulls', ['query' => ['state' => 'open', 'sort' => 'updated', 'direction' => 'desc', 'per_page' => self::PAGE]])->toArray();

        return array_map(static fn (array $pullRequest): array => [
            'number' => (int) $pullRequest['number'],
            'title' => (string) $pullRequest['title'],
            'author' => (string) ($pullRequest['user']['login'] ?? ''),
            'draft' => (bool) ($pullRequest['draft'] ?? false),
            'url' => SafeUrl::http((string) ($pullRequest['html_url'] ?? '')) ?? '',
            'updated_at' => (string) $pullRequest['updated_at'],
        ], $pullRequests);
    }

    /**
     * @param array<string, mixed>                                                                        $pullRequest
     * @param list<array<string, mixed>>                                                                  $rules
     * @param list<array<string, mixed>>                                                                  $unguarded
     * @param list<array{source: string, path: string, line: int|null, remark: string, severity: string}> $remarks
     * @param list<array<string, mixed>>                                                                  $memories
     * @param array<string, list<array<string, mixed>>>                                                   $history
     */
    private function render(Project $project, array $pullRequest, array $rules, array $unguarded, array $remarks, array $memories, array $history, ?string $note): string
    {
        $slug = $project->getSlug();
        $ruleLink = fn (string $code): string => \sprintf('[%s](%s)', $code, $this->url('rule_show', ['slug' => $slug, 'code' => $code]));
        $memoryLink = fn (int $id): string => \sprintf('[mémoire #%d](%s)', $id, $this->url('memory_show', ['id' => $id]));
        $unguardedCodes = array_column($unguarded, 'code');

        $lines = [self::MARKER, '## Mnemo · contexte de la revue', ''];
        if ([] === $rules && [] === $memories && [] === $remarks) {
            $lines[] = 'Rien de connu dans le graphe sur les fichiers de cette pull request : ni règle métier, ni décision, ni convention.';
        } else {
            $lines[] = \sprintf('**%d règle(s) métier touchée(s), %d remarque(s), %d mémoire(s) liée(s)** dans le projet [%s](%s).', \count($rules), \count($remarks), \count($memories), self::inline($project->getName()), $this->url('project_show', ['slug' => $slug]));
        }

        if ([] !== $rules) {
            array_push($lines, '', '### Règles métier touchées', '');
            foreach ($rules as $rule) {
                $lines[] = \sprintf('- **%s %s** (%s) dans %s', $ruleLink($rule['code']), self::inline($rule['title']), 'validated' === $rule['status'] ? 'validée' : 'à valider', self::paths($rule['paths']));
                if (null !== $rule['statement']) {
                    $lines[] = '  > '.self::inline($rule['statement']);
                }
                if (\in_array($rule['code'], $unguardedCodes, true)) {
                    $lines[] = \sprintf('  - **À vérifier** : règle validée touchée sans test dans le diff ni mention de %s dans la pull request.', $rule['code']);
                }
                foreach ($rule['shadow_areas'] as $area) {
                    $lines[] = \sprintf('  - Zone d\'ombre (%s) : %s', self::SEVERITIES[$area['severity'] ?? 'minor'] ?? 'mineur', self::inline($area['question'] ?: $area['gap']));
                }
            }
        }

        if ([] !== $remarks) {
            array_push($lines, '', '### Ce que le diff contredit', '');
            foreach ($remarks as $remark) {
                $source = str_starts_with($remark['source'], 'M') ? $memoryLink((int) substr($remark['source'], 1)) : $ruleLink($remark['source']);
                $lines[] = \sprintf('- **%s** · `%s` : %s (source : %s)', self::SEVERITIES[$remark['severity']], $remark['path'].(null === $remark['line'] ? '' : ':'.$remark['line']), self::inline($remark['remark'], 500), $source);
            }
        }

        if ([] !== $memories) {
            array_push($lines, '', '### Décisions, conventions et apprentissages liés', '');
            foreach (\array_slice($memories, 0, self::MAX_MEMORIES) as $memory) {
                $lines[] = \sprintf('- %s · %s : %s (%s)', MemoryType::tryFrom($memory['type'])?->label() ?? 'Mémoire', self::paths($memory['about']), self::inline($memory['text']), $memoryLink($memory['id']));
            }
            if (\count($memories) > self::MAX_MEMORIES) {
                $lines[] = \sprintf('- ... et %d autre(s).', \count($memories) - self::MAX_MEMORIES);
            }
        }

        if ([] !== $history) {
            array_push($lines, '', '<details><summary>Derniers changements des fichiers modifiés</summary>', '');
            foreach ($history as $path => $commits) {
                $lines[] = \sprintf('- `%s` : %s', $path, implode(' ; ', array_map(
                    static fn (array $commit): string => \sprintf('%s par %s%s', self::inline($commit['label'], 80), self::inline($commit['author'] ?? 'inconnu'), [] === $commit['tickets'] ? '' : ' ('.implode(', ', array_column($commit['tickets'], 'ref')).')'),
                    $commits,
                )));
            }
            array_push($lines, '', '</details>');
        }

        if (null !== $note) {
            array_push($lines, '', '_'.self::inline($note).'_');
        }
        array_push($lines, '', \sprintf('<sub>Généré depuis le graphe de connaissance pour le commit %s. Chaque remarque cite sa source ; rien ici ne remplace une revue.</sub>', substr($pullRequest['head']['sha'], 0, 7)));

        return implode("\n", $lines)."\n";
    }

    /**
     * Rewrites the comment of the bot, or adds it. A pull request the graph knows nothing about gets no comment.
     */
    private function comment(HttpClientInterface $http, string $base, int $number, string $body, bool $worthIt): void
    {
        foreach ($this->pages($http, $base.'/issues/'.$number.'/comments') as $comment) {
            if ('Bot' === ($comment['user']['type'] ?? null) && str_contains((string) $comment['body'], self::MARKER)) {
                $http->request('PATCH', $base.'/issues/comments/'.$comment['id'], ['json' => ['body' => $body]])->toArray();

                return;
            }
        }
        if ($worthIt) {
            $http->request('POST', $base.'/issues/'.$number.'/comments', ['json' => ['body' => $body]])->toArray();
        }
    }

    /**
     * Puts each remark that names a line of the diff next to that line, in one review that never blocks the merge.
     * The remarks of the previous runs go away first, unless someone answered them: the discussion stays.
     *
     * @param list<array{source: string, path: string, line: int, remark: string, severity: string}> $remarks
     */
    private function lineComments(HttpClientInterface $http, string $base, int $number, string $sha, Project $project, array $remarks): void
    {
        $previous = $this->pages($http, $base.'/pulls/'.$number.'/comments');
        $answered = array_column($previous, 'in_reply_to_id', 'in_reply_to_id');
        $kept = [];
        foreach ($previous as $comment) {
            if ('Bot' !== ($comment['user']['type'] ?? null) || !str_contains((string) $comment['body'], self::LINE_MARKER)) {
                continue;
            }
            if (isset($answered[$comment['id']])) {
                $kept[] = $comment['path']."\n".$comment['body'];
            } else {
                $http->request('DELETE', $base.'/pulls/comments/'.$comment['id'])->getStatusCode();
            }
        }

        $comments = [];
        foreach ($remarks as $remark) {
            $source = str_starts_with($remark['source'], 'M')
                ? \sprintf('[mémoire #%d](%s)', (int) substr($remark['source'], 1), $this->url('memory_show', ['id' => (int) substr($remark['source'], 1)]))
                : \sprintf('[%s](%s)', $remark['source'], $this->url('rule_show', ['slug' => $project->getSlug(), 'code' => $remark['source']]));
            $body = \sprintf("%s\n**%s** : %s\n\nSource : %s", self::LINE_MARKER, ucfirst(self::SEVERITIES[$remark['severity']]), self::inline($remark['remark'], 500), $source);
            if (!\in_array($remark['path']."\n".$body, $kept, true)) {
                $comments[] = ['path' => $remark['path'], 'line' => $remark['line'], 'side' => 'RIGHT', 'body' => $body];
            }
        }
        if ([] === $comments) {
            return;
        }

        $http->request('POST', $base.'/pulls/'.$number.'/reviews', ['json' => [
            'commit_id' => $sha,
            'event' => 'COMMENT',
            'body' => \sprintf('Mnemo : %d remarque(s) sur les lignes du diff, d\'après la mémoire du projet.', \count($comments)),
            'comments' => $comments,
        ]])->toArray();
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param list<array<string, mixed>> $unguarded
     */
    private function check(HttpClientInterface $http, string $base, string $sha, array $rules, array $unguarded, bool $failing): void
    {
        $http->request('POST', $base.'/check-runs', ['json' => [
            'name' => self::CHECK,
            'head_sha' => $sha,
            'status' => 'completed',
            'conclusion' => [] === $unguarded ? 'success' : ($failing ? 'failure' : 'neutral'),
            'output' => [
                'title' => [] === $unguarded ? ([] === $rules ? 'Aucune règle métier touchée' : \sprintf('%d règle(s) métier touchée(s), toutes couvertes', \count($rules))) : \sprintf('%d règle(s) validée(s) touchée(s) sans test ni mention', \count($unguarded)),
                'summary' => [] === $unguarded
                    ? 'Aucune règle métier validée n\'est touchée sans test dans le diff ni mention dans la pull request.'
                    : "Règles validées touchées sans test dans le diff ni mention dans la pull request :\n\n".implode("\n", array_map(
                        static fn (array $rule): string => \sprintf('- **%s** %s dans %s', $rule['code'], self::inline($rule['title']), self::paths($rule['paths'])),
                        $unguarded,
                    ))."\n\nAjouter un test, ou citer le code de la règle dans le titre, la description ou un commit pour dire que le changement est voulu.",
            ],
        ]])->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pages(HttpClientInterface $http, string $path): array
    {
        $rows = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $batch = $http->request('GET', $path, ['query' => ['per_page' => self::PAGE, 'page' => $page]])->toArray();
            $rows = [...$rows, ...$batch];
            if (\count($batch) < self::PAGE) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function url(string $route, array $parameters): string
    {
        return $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * @param list<string> $paths
     */
    private static function paths(array $paths): string
    {
        return implode(', ', array_map(static fn (string $path): string => '`'.str_replace('`', '', $path).'`', \array_slice($paths, 0, 5))).(\count($paths) > 5 ? ', ...' : '');
    }

    /**
     * Text of the graph or of the LLM on one line of Markdown: no mention that would notify someone, no HTML, no link.
     */
    private static function inline(string $text, int $max = 300): string
    {
        return str_replace(['<', '@', ']('], ['&lt;', '&#64;', '] ('], mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', $text)), 0, $max, '...'));
    }
}
