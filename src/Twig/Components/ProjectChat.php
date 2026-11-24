<?php

namespace App\Twig\Components;

use App\Chat\ChatHistory;
use App\Chat\ProjectAssistant;
use App\Entity\ChatConversation;
use App\Entity\Project;
use App\Llm\LlmSettings;
use App\Llm\LlmUnavailable;
use App\Security\ProjectScope;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

/**
 * Project assistant workspace with persistent per-user conversation history.
 */
#[AsLiveComponent]
final class ProjectChat
{
    use DefaultActionTrait;

    #[LiveProp]
    public ?Project $project = null;

    #[LiveProp(url: new UrlMapping(as: 'c'))]
    public ?int $conversationId = null;

    #[LiveProp(writable: true)]
    public string $message = '';

    #[LiveProp(writable: true)]
    public string $search = '';

    /** @var list<int|string> */
    #[LiveProp(writable: true)]
    public array $selectedIds = [];

    public ?string $error = null;

    private ?ChatConversation $cachedActive = null;
    private bool $activeResolved = false;

    public function __construct(
        private readonly ProjectAssistant $assistant,
        private readonly ChatHistory $chatHistory,
        private readonly ProjectScope $projectScope,
        private readonly LlmSettings $llmSettings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * A live action rebuilds the component from the project id sent by the browser: checked again each time.
     */
    #[PostHydrate]
    public function guard(): void
    {
        if (null !== $this->project && !$this->projectScope->allows($this->project)) {
            throw new NotFoundHttpException('Project not found.');
        }
        $this->activeResolved = false;
        $this->cachedActive = null;
    }

    public function getActiveConversation(): ?ChatConversation
    {
        if (!$this->activeResolved) {
            $this->cachedActive = null !== $this->conversationId
                ? $this->chatHistory->find($this->project, $this->conversationId)
                : null;
            if (null === $this->cachedActive) {
                $this->conversationId = null;
            }
            $this->activeResolved = true;
        }

        return $this->cachedActive;
    }

    /**
     * @return list<array{id: string, role: 'user'|'assistant', content: string, actions?: list<array{label: string, parameters: array<string, int|string>, url: string}>, feedback?: 'up'|'down'|null, created_at?: string}>
     */
    public function getMessages(): array
    {
        return $this->getActiveConversation()?->getMessages() ?? [];
    }

    /**
     * @return list<ChatConversation>
     */
    public function getConversations(): array
    {
        return $this->chatHistory->list($this->project, $this->search);
    }

    public function getTotalConversations(): int
    {
        return $this->chatHistory->count($this->project);
    }

    public function getMaxConversations(): int
    {
        return ChatHistory::MAX_CONVERSATIONS;
    }

    public function getSelectedCount(): int
    {
        return \count($this->normalizedSelectedIds());
    }

    public function getModelLabel(): string
    {
        $model = $this->llmSettings->chatModel($this->project);
        $slash = strrpos($model, '/');

        return false !== $slash ? substr($model, $slash + 1) : $model;
    }

    #[LiveAction]
    public function send(): void
    {
        $message = trim($this->message);
        if ('' === $message || mb_strlen($message) > 4000) {
            return;
        }

        $conversation = $this->getActiveConversation();
        $history = $conversation?->getMessages() ?? [];
        $traceId = $conversation?->getUuid() ?? Uuid::v7();

        try {
            $reply = $this->assistant->reply($this->project, $history, $message, $traceId);
        } catch (\Throwable $exception) {
            $this->error = $exception instanceof LlmUnavailable
                ? $exception->translatable()->trans($this->translator)
                : $exception->getMessage();

            return;
        }

        if (null === $conversation) {
            $conversation = $this->chatHistory->create($this->project, $message, $traceId);
        }

        $conversation->appendExchange($message, $reply['answer'], $reply['actions']);
        $this->chatHistory->save();

        $this->conversationId = $conversation->getId();
        $this->cachedActive = $conversation;
        $this->activeResolved = true;
        $this->message = '';
        $this->error = null;
    }

    #[LiveAction]
    public function regenerate(): void
    {
        $conversation = $this->getActiveConversation();
        $context = $conversation?->regenerationContext();
        if (null === $conversation || null === $context) {
            return;
        }

        try {
            $reply = $this->assistant->reply(
                $this->project,
                $context['history'],
                $context['prompt'],
                $conversation->getUuid(),
            );
        } catch (\Throwable $exception) {
            $this->error = $exception instanceof LlmUnavailable
                ? $exception->translatable()->trans($this->translator)
                : $exception->getMessage();

            return;
        }

        $conversation->replaceLastAssistantReply($reply['answer'], $reply['actions']);
        $this->chatHistory->save();
        $this->error = null;
    }

    #[LiveAction]
    public function rate(#[LiveArg] string $messageId, #[LiveArg] string $value): void
    {
        if (!\in_array($value, ['up', 'down'], true)) {
            return;
        }
        $conversation = $this->getActiveConversation();
        if (null === $conversation) {
            return;
        }
        if ($conversation->toggleFeedback($messageId, $value)) {
            $this->chatHistory->save();
        }
    }

    #[LiveAction]
    public function selectConversation(#[LiveArg] int $id): void
    {
        $conversation = $this->chatHistory->find($this->project, $id);
        if (null === $conversation) {
            return;
        }
        $this->conversationId = $conversation->getId();
        $this->cachedActive = $conversation;
        $this->activeResolved = true;
        $this->error = null;
    }

    #[LiveAction]
    public function newConversation(): void
    {
        $this->conversationId = null;
        $this->cachedActive = null;
        $this->activeResolved = true;
        $this->message = '';
        $this->error = null;
    }

    #[LiveAction]
    public function reset(): void
    {
        $this->newConversation();
    }

    #[LiveAction]
    public function deleteConversation(#[LiveArg] int $id): void
    {
        $this->chatHistory->delete($this->project, $id);
        $this->selectedIds = array_values(array_filter(
            $this->normalizedSelectedIds(),
            static fn (int $selected): bool => $selected !== $id,
        ));
        if ($this->conversationId === $id) {
            $this->newConversation();
        }
    }

    #[LiveAction]
    public function deleteSelected(): void
    {
        $ids = $this->normalizedSelectedIds();
        if ([] === $ids) {
            return;
        }
        $this->chatHistory->deleteMany($this->project, $ids);
        if (null !== $this->conversationId && \in_array($this->conversationId, $ids, true)) {
            $this->newConversation();
        }
        $this->selectedIds = [];
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    #[LiveAction]
    public function clearHistory(): void
    {
        $this->chatHistory->clear($this->project);
        $this->selectedIds = [];
        $this->search = '';
        $this->newConversation();
    }

    /**
     * @return list<int>
     */
    private function normalizedSelectedIds(): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (int|string $id): int => (int) $id, $this->selectedIds),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
