<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * A private assistant conversation on one project, owned by one user (or local/admin).
 *
 * @phpstan-type ChatAction array{label: string, parameters: array<string, int|string>, url: string}
 * @phpstan-type ChatMessage array{
 *     id: string,
 *     role: 'user'|'assistant',
 *     content: string,
 *     actions?: list<ChatAction>,
 *     feedback?: 'up'|'down'|null,
 *     created_at?: string,
 * }
 */
#[ORM\Entity]
#[ORM\Table(name: 'chat_conversation')]
#[ORM\UniqueConstraint(name: 'chat_conversation_uuid_unique', columns: ['uuid'])]
#[ORM\Index(name: 'chat_conversation_project_owner_updated_idx', columns: ['project_id', 'owner_key', 'updated_at'])]
class ChatConversation
{
    public const MAX_MESSAGES = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $uuid;

    #[ORM\Column(length: 140)]
    private string $title;

    #[ORM\Column(length: 240)]
    private string $preview = '';

    /** @var list<ChatMessage> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $messages = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Project $project,
        #[ORM\Column(length: 64)]
        private string $ownerKey,
        string $firstPrompt,
        ?UuidV7 $uuid = null,
    ) {
        $this->uuid = ($uuid ?? Uuid::v7())->toRfc4122();
        $this->title = self::summarizeTitle($firstPrompt);
        $this->preview = self::summarizePreview($firstPrompt);
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): UuidV7
    {
        $uuid = Uuid::fromString($this->uuid);

        return $uuid instanceof UuidV7 ? $uuid : Uuid::v7();
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function getOwnerKey(): string
    {
        return $this->ownerKey;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPreview(): string
    {
        return $this->preview;
    }

    /**
     * @return list<ChatMessage>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param list<ChatAction> $actions
     */
    public function appendExchange(string $userContent, string $assistantContent, array $actions): void
    {
        $now = new \DateTimeImmutable();
        $iso = $now->format(\DateTimeInterface::ATOM);

        $this->messages[] = [
            'id' => self::newMessageId(),
            'role' => 'user',
            'content' => $userContent,
            'created_at' => $iso,
        ];
        $this->messages[] = [
            'id' => self::newMessageId(),
            'role' => 'assistant',
            'content' => $assistantContent,
            'actions' => $actions,
            'feedback' => null,
            'created_at' => $iso,
        ];

        $this->messages = \array_slice($this->messages, -self::MAX_MESSAGES);
        $this->preview = self::summarizePreview('' !== $assistantContent ? $assistantContent : $userContent);
        $this->updatedAt = $now;
    }

    /**
     * Returns the last user prompt and all messages that came strictly before it, for regenerating the last reply.
     *
     * @return array{prompt: string, history: list<ChatMessage>}|null
     */
    public function regenerationContext(): ?array
    {
        for ($i = \count($this->messages) - 1; $i >= 0; --$i) {
            if ('user' === $this->messages[$i]['role']) {
                return [
                    'prompt' => $this->messages[$i]['content'],
                    'history' => \array_slice($this->messages, 0, $i),
                ];
            }
        }

        return null;
    }

    /**
     * Replaces any trailing assistant turn(s) after the last user message with a fresh reply.
     *
     * @param list<ChatAction> $actions
     */
    public function replaceLastAssistantReply(string $assistantContent, array $actions): void
    {
        $lastUserIndex = null;
        for ($i = \count($this->messages) - 1; $i >= 0; --$i) {
            if ('user' === $this->messages[$i]['role']) {
                $lastUserIndex = $i;
                break;
            }
        }
        if (null === $lastUserIndex) {
            return;
        }

        $now = new \DateTimeImmutable();
        $messages = \array_slice($this->messages, 0, $lastUserIndex + 1);
        $messages[] = [
            'id' => self::newMessageId(),
            'role' => 'assistant',
            'content' => $assistantContent,
            'actions' => $actions,
            'feedback' => null,
            'created_at' => $now->format(\DateTimeInterface::ATOM),
        ];

        $this->messages = \array_slice($messages, -self::MAX_MESSAGES);
        $this->preview = self::summarizePreview('' !== $assistantContent ? $assistantContent : $this->messages[$lastUserIndex]['content']);
        $this->updatedAt = $now;
    }

    /**
     * Toggles thumbs-up / thumbs-down feedback on an assistant message. Clicking the active value clears it.
     *
     * @param 'up'|'down' $value
     */
    public function toggleFeedback(string $messageId, string $value): bool
    {
        foreach ($this->messages as $index => $message) {
            if ($message['id'] !== $messageId || 'assistant' !== $message['role']) {
                continue;
            }

            $current = $message['feedback'] ?? null;
            $this->messages[$index]['feedback'] = $current === $value ? null : $value;

            return true;
        }

        return false;
    }

    private static function newMessageId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private static function summarizeTitle(string $text): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ('' === $clean) {
            return 'Conversation';
        }
        if (mb_strlen($clean) <= 72) {
            return $clean;
        }

        return rtrim(mb_substr($clean, 0, 71)).'…';
    }

    private static function summarizePreview(string $markdown): string
    {
        $text = (string) preg_replace('/```[\s\S]*?```/u', ' ', $markdown);
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $text);
        $text = (string) preg_replace('/[`*_~#>]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= 160) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, 159)).'…';
    }
}
