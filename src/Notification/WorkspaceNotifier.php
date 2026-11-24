<?php

namespace App\Notification;

use App\Entity\Node;
use App\Entity\Project;
use App\Enum\WebhookFormat;
use App\Security\SafeUrl;
use App\Security\SecretBox;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Dispatches outbound Slack, Discord, Microsoft Teams or generic JSON webhook notifications
 * for high-signal events (blocking business questions, PR rule contradictions, business briefings).
 */
final class WorkspaceNotifier
{
    public const EVENT_SHADOW_BLOCKER = 'shadow_blocker';
    public const EVENT_PR_CONTRADICTION = 'pr_contradiction';
    public const EVENT_BRIEFING = 'briefing';

    public const EVENTS = [
        self::EVENT_SHADOW_BLOCKER,
        self::EVENT_PR_CONTRADICTION,
        self::EVENT_BRIEFING,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretBox $secretBox,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array{category: string, gap: string, question: string, severity: string}> $blockers
     */
    public function notifyShadowBlockers(Node $rule, array $blockers): bool
    {
        $project = $rule->getProject();
        if (null === $project || [] === $blockers || !$project->notifiesOn(self::EVENT_SHADOW_BLOCKER)) {
            return false;
        }

        $lines = array_map(
            static fn (array $area): string => '• '.($area['question'] ?: $area['gap']),
            \array_slice($blockers, 0, 5),
        );
        $url = $this->safeUrl('rule_show', ['slug' => $project->getSlug(), 'code' => $rule->getExternalId()]);

        return $this->send(
            $project,
            self::EVENT_SHADOW_BLOCKER,
            \sprintf('[%s] %d question(s) métier bloquante(s) sur %s · %s', $project->getName(), \count($blockers), $rule->getExternalId(), $rule->getLabel()),
            implode("\n", $lines),
            $url,
        );
    }

    /**
     * @param list<array{source: string, path: string, line: int|null, remark: string, severity: string}> $remarks
     */
    public function notifyPullRequestRemarks(Project $project, int $number, string $prTitle, ?string $prUrl, array $remarks): bool
    {
        if ([] === $remarks || !$project->notifiesOn(self::EVENT_PR_CONTRADICTION)) {
            return false;
        }

        $lines = array_map(
            static fn (array $remark): string => \sprintf('• [%s] %s (%s) : %s', $remark['severity'], $remark['path'], $remark['source'], mb_strimwidth($remark['remark'], 0, 180, '…')),
            \array_slice($remarks, 0, 5),
        );

        return $this->send(
            $project,
            self::EVENT_PR_CONTRADICTION,
            \sprintf('[%s] PR #%d : %d contradiction(s) détectée(s) — %s', $project->getName(), $number, \count($remarks), $prTitle),
            implode("\n", $lines),
            $prUrl,
        );
    }

    public function test(Project $project): void
    {
        if (!$project->hasWebhook()) {
            throw new \RuntimeException('Aucun webhook n\'est configuré sur ce projet.');
        }

        $this->send(
            $project,
            'test',
            \sprintf('[%s] Test de connexion Mnemo', $project->getName()),
            'Ce projet enverra ici les alertes métier, contradictions de PR et briefings partagés.',
            $this->safeUrl('project_briefing', ['slug' => $project->getSlug()]),
            throwOnError: true,
        );
    }

    public function send(Project $project, string $event, string $title, string $body, ?string $url = null, bool $throwOnError = false): bool
    {
        $encrypted = $project->getWebhookUrlEncrypted();
        if (null === $encrypted || '' === $encrypted) {
            return false;
        }
        if (!\in_array($event, ['test', self::EVENT_BRIEFING], true) && !$project->notifiesOn($event)) {
            return false;
        }

        try {
            $endpoint = SafeUrl::requireHttps($this->secretBox->decrypt($encrypted));
            $payload = $this->formatPayload($project, $event, $title, $body, $url);
            $response = $this->httpClient->request('POST', $endpoint, [
                'json' => $payload,
                'timeout' => 5.0,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new \RuntimeException(\sprintf('Le serveur webhook a répondu HTTP %d.', $status));
            }

            return true;
        } catch (\Throwable $exception) {
            $this->logger->warning('Outbound webhook notification failed for project {slug} ({event}): {message}', [
                'slug' => $project->getSlug(),
                'event' => $event,
                'message' => $exception->getMessage(),
            ]);
            if ($throwOnError) {
                throw new \RuntimeException($exception->getMessage(), 0, $exception);
            }

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPayload(Project $project, string $event, string $title, string $body, ?string $url): array
    {
        $linkSuffix = null !== $url && '' !== $url ? "\n\n".$url : '';

        return match ($project->getWebhookFormat()) {
            WebhookFormat::Slack => [
                'text' => "*{$title}*\n{$body}{$linkSuffix}",
            ],
            WebhookFormat::Discord => [
                'content' => mb_substr("**{$title}**\n{$body}{$linkSuffix}", 0, 1950),
            ],
            WebhookFormat::Teams => [
                '@type' => 'MessageCard',
                '@context' => 'https://schema.org/extensions',
                'summary' => $title,
                'title' => $title,
                'text' => str_replace("\n", "\n\n", $body.$linkSuffix),
            ],
            WebhookFormat::Generic => [
                'event' => $event,
                'project' => $project->getSlug(),
                'project_name' => $project->getName(),
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'sent_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ],
        };
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function safeUrl(string $route, array $parameters): ?string
    {
        try {
            return $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return null;
        }
    }
}
