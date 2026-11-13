<?php

namespace App\Forge;

use App\Entity\Connector;
use App\Entity\Edge;
use App\Entity\Node;
use App\Entity\Project;
use App\Enum\Confidence;
use App\Enum\EdgeOrigin;
use App\Enum\NodeType;
use App\Ingest\GraphifyImporter;
use App\Ingest\GraphifyRunner;
use App\Ingest\RepoContextImporter;
use App\Learn\Learner;
use App\Learn\SourceKind;
use App\Llm\LlmUnavailable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Brings the history of a repository into the graph, without any LLM: commits, change requests, issues and authors
 * become nodes, linked to the code files they touch and to the business rules they name (RG-042).
 * A run only fetches what changed since the connector's cursor and never duplicates a node or an edge.
 * With "code_graph", the repository is cloned first: its code graph (graphify), what it says to its agents and its
 * Markdown documentation are imported whenever its head commit changed.
 */
final class ForgeImporter
{
    private const MAX_COMMITS = 200;
    private const MAX_TICKETS = 100;
    private const MAX_TEXT = 2000;
    private const RULE_CODE = '/\bRG-\d+\b/';
    private const CLOSES = '/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s+#(\d+)/i';

    /** @var array<string, Node> */
    private array $people = [];

    public function __construct(
        private readonly ForgeClientFactory $clientFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly Learner $learner,
        private readonly RepositoryCheckout $checkout,
        private readonly GraphifyRunner $graphify,
        private readonly GraphifyImporter $graphifyImporter,
        private readonly RepoContextImporter $contextImporter,
    ) {
    }

    public function describe(Connector $connector): string
    {
        return $this->clientFactory->create($connector)->describe();
    }

    /**
     * @param array{created: int, updated: int, unchanged: int, skipped: list<string>, error: ?string} $report
     */
    public function import(Connector $connector, array &$report): void
    {
        $project = $connector->getProject() ?? throw new \RuntimeException('Un connecteur de forge doit être rattaché à un projet.');
        if (true === ($connector->getArguments()['code_graph'] ?? false)) {
            $this->importCode($connector, $project, $report);
        }
        $client = $this->clientFactory->create($connector);
        $cursor = $connector->getCursor();
        $since = static fn (string $key): ?\DateTimeImmutable => isset($cursor[$key]) ? new \DateTimeImmutable($cursor[$key]) : null;

        $commits = $client->commits($since('commits'), self::MAX_COMMITS);
        $issues = $client->issues($since('issues'), self::MAX_TICKETS);
        $changeRequests = $client->changeRequests($since('change_requests'), self::MAX_TICKETS);
        $prefix = $client->changeRequestPrefix();

        $this->people = [];
        $links = [];
        foreach ($commits as $commit) {
            $node = $this->upsert($project, NodeType::Commit, $commit->sha, strtok($commit->message, "\n") ?: $commit->sha, [
                'message' => mb_substr($commit->message, 0, self::MAX_TEXT), 'author' => $commit->author, 'date' => $commit->date->format(\DATE_ATOM), 'url' => $commit->url,
            ], $report);
            $links[] = [$node, $this->person($commit->author), 'authored_by', $commit->sha];
            foreach ($this->files($project, $commit->files) as $file) {
                $links[] = [$node, $file, 'touches', $commit->sha];
            }
            foreach ($this->rules($project, $commit->message) as $rule) {
                $links[] = [$node, $rule, 'mentions', $commit->sha];
            }
        }

        $merged = [];
        foreach ([...$issues, ...$changeRequests] as $ticket) {
            $created = $report['created'];
            $node = $this->upsert($project, NodeType::Ticket, ($ticket->changeRequest ? $prefix : 'issue').'-'.$ticket->number, $ticket->title, [
                'kind' => $ticket->changeRequest ? 'change_request' : 'issue', 'number' => $ticket->number, 'state' => $ticket->state, 'author' => $ticket->author,
                'date' => $ticket->updatedAt->format(\DATE_ATOM), 'url' => $ticket->url, 'body' => mb_substr($ticket->body, 0, self::MAX_TEXT),
            ], $report);
            if ('' !== $ticket->author) {
                $links[] = [$node, $this->person($ticket->author), 'authored_by', $ticket->url];
            }
            foreach ($this->rules($project, $ticket->title."\n".$ticket->body) as $rule) {
                $links[] = [$node, $rule, 'mentions', $ticket->url];
            }
            if (!$ticket->changeRequest) {
                continue;
            }
            foreach ($this->find($project, NodeType::Commit, $ticket->commits) as $commit) {
                $links[] = [$node, $commit, 'includes', $ticket->url];
            }
            preg_match_all(self::CLOSES, $ticket->title."\n".$ticket->body, $closed);
            foreach ($this->find($project, NodeType::Ticket, array_map(static fn (string $number): string => 'issue-'.$number, $closed[1])) as $issue) {
                $links[] = [$node, $issue, 'closes', $ticket->url];
            }
            if ('merged' === $ticket->state && $report['created'] > $created) {
                $merged[] = $ticket;
            }
        }

        $this->entityManager->flush();
        $this->link($links);

        $connector->setCursor(array_filter([
            'code_commit' => $cursor['code_commit'] ?? null,
            'commits' => ($commits[0] ?? null)?->date->format(\DATE_ATOM) ?? ($cursor['commits'] ?? null),
            'issues' => ($issues[0] ?? null)?->updatedAt->format(\DATE_ATOM) ?? ($cursor['issues'] ?? null),
            'change_requests' => ($changeRequests[0] ?? null)?->updatedAt->format(\DATE_ATOM) ?? ($cursor['change_requests'] ?? null),
        ]));
        $this->entityManager->flush();

        if (true === ($connector->getArguments()['learn_reviews'] ?? false)) {
            $this->learnReviews($client, $project, $prefix, $merged, $report);
        }
    }

    /**
     * The code files must be in the graph before the history, which links commits to them.
     *
     * @param array{created: int, updated: int, unchanged: int, skipped: list<string>, error: ?string} $report
     */
    private function importCode(Connector $connector, Project $project, array &$report): void
    {
        $checkout = $this->checkout->checkout($connector);
        $repository = strtolower((string) preg_replace('#^\w+://|\.git$#', '', $this->checkout->cloneUrl($connector)));
        if (null === $project->getRepository() && null === $this->entityManager->getRepository(Project::class)->findOneBy(['repository' => $repository])) {
            $project->setRepository($repository);
        }
        if ($checkout['commit'] === ($connector->getCursor()['code_commit'] ?? null)) {
            return;
        }

        $graph = $this->graphifyImporter->import($project, $this->graphify->extract($checkout['path'], $checkout['path'].'-graph'));
        $context = $this->contextImporter->import($project, $checkout['path']);
        $report['created'] += $graph->nodesCreated + $context->memoriesCreated + $context->decisionsCreated + $context->documentsCreated;
        $report['updated'] += $graph->nodesUpdated + $context->decisionsUpdated + $context->documentsUpdated;
        $report['skipped'] = [...$report['skipped'], ...$context->skipped];

        $connector->setCursor(['code_commit' => $checkout['commit']] + $connector->getCursor());
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed>                                                                    $metadata
     * @param array{created: int, updated: int, unchanged: int, skipped: list<string>, error: ?string} $report
     */
    private function upsert(Project $project, NodeType $type, string $externalId, string $label, array $metadata, array &$report): Node
    {
        $node = $this->entityManager->getRepository(Node::class)->findOneBy(['project' => $project, 'type' => $type, 'externalId' => $externalId]);
        if (null === $node) {
            $node = new Node($project, $type, $externalId, $label, null, null, $metadata);
            $this->entityManager->persist($node);
            ++$report['created'];

            return $node;
        }
        ++$report[$node->update($label, null, null, $metadata) ? 'updated' : 'unchanged'];

        return $node;
    }

    /**
     * People are shared by all projects.
     */
    private function person(string $login): Node
    {
        if (isset($this->people[$login])) {
            return $this->people[$login];
        }
        $person = $this->entityManager->getRepository(Node::class)->findOneBy(['project' => null, 'type' => NodeType::Person, 'externalId' => $login]);
        if (null === $person) {
            $person = new Node(null, NodeType::Person, $login, $login);
            $this->entityManager->persist($person);
        }

        return $this->people[$login] = $person;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<Node>
     */
    private function files(Project $project, array $paths): array
    {
        return [] === $paths ? [] : $this->entityManager->getRepository(Node::class)->findBy(['project' => $project, 'type' => NodeType::CodeFile, 'path' => $paths]);
    }

    /**
     * @return list<Node>
     */
    private function rules(Project $project, string $text): array
    {
        preg_match_all(self::RULE_CODE, $text, $codes);

        return $this->find($project, NodeType::BusinessRule, array_values(array_unique($codes[0])));
    }

    /**
     * Nodes already flushed or pending in this run.
     *
     * @param list<string> $externalIds
     *
     * @return list<Node>
     */
    private function find(Project $project, NodeType $type, array $externalIds): array
    {
        if ([] === $externalIds) {
            return [];
        }
        $found = $this->entityManager->getRepository(Node::class)->findBy(['project' => $project, 'type' => $type, 'externalId' => $externalIds]);
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof Node && $pending->getProject() === $project && $pending->getType() === $type && \in_array($pending->getExternalId(), $externalIds, true)) {
                $found[] = $pending;
            }
        }

        return $found;
    }

    /**
     * @param list<array{0: Node, 1: Node, 2: string, 3: string}> $links source, target, relation, evidence
     */
    private function link(array $links): void
    {
        if ([] === $links) {
            return;
        }
        $existing = array_flip($this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT source_id || ':' || target_id || ':' || relation FROM edge WHERE source_id IN (:sources)",
            ['sources' => array_values(array_unique(array_map(static fn (array $link): int => $link[0]->getId(), $links)))],
            ['sources' => ArrayParameterType::INTEGER],
        ));
        foreach ($links as [$source, $target, $relation, $evidence]) {
            $key = $source->getId().':'.$target->getId().':'.$relation;
            if (!isset($existing[$key])) {
                $this->entityManager->persist(new Edge($source, $target, $relation, Confidence::Extracted, EdgeOrigin::Api, $evidence));
                $existing[$key] = true;
            }
        }
    }

    /**
     * @param list<ForgeTicket>                                                                       $merged
     * @param array{created: int, updated: int, unchanged: int, skipped: list<string>, error: ?string} $report
     */
    private function learnReviews(ForgeClient $client, Project $project, string $prefix, array $merged, array &$report): void
    {
        foreach ($merged as $ticket) {
            $label = \sprintf('%s-%d %s', $prefix, $ticket->number, $ticket->title);
            $review = $client->reviewText($ticket->number);
            if ('' === $review) {
                continue;
            }
            try {
                $this->learner->learn(SourceKind::Review, mb_substr($label, 0, 255), $ticket->title."\n\n".$ticket->body."\n\n".$review, $project);
            } catch (LlmUnavailable $exception) {
                $report['skipped'][] = \sprintf('Revue %s non analysée : %s', $label, $exception->getMessage());

                return;
            }
        }
    }
}
