<?php

namespace App\Twig\Components;

use App\Chat\ProjectAssistant;
use App\Entity\Project;
use App\Llm\LlmUnavailable;
use App\Security\ProjectScope;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Conversation with the assistant of a project. It lives in the session: private to who talks, gone at sign-out.
 */
#[AsLiveComponent]
final class ProjectChat
{
    use DefaultActionTrait;

    private const MAX_MESSAGES = 40;

    #[LiveProp]
    public Project $project;

    #[LiveProp(writable: true)]
    public string $message = '';

    public ?string $error = null;

    public function __construct(
        private readonly ProjectAssistant $assistant,
        private readonly ProjectScope $projectScope,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * A live action rebuilds the component from the project id sent by the browser: checked again each time.
     */
    #[PostHydrate]
    public function guard(): void
    {
        if (!$this->projectScope->allows($this->project)) {
            throw new NotFoundHttpException('Project not found.');
        }
    }

    /**
     * @return list<array{role: string, content: string, actions?: list<array{label: string, parameters: array<string, int|string>, url: string}>}>
     */
    public function getMessages(): array
    {
        return $this->requestStack->getSession()->get($this->key(), []);
    }

    #[LiveAction]
    public function send(): void
    {
        $message = trim($this->message);
        if ('' === $message || mb_strlen($message) > 4000) {
            return;
        }
        $messages = $this->getMessages();

        try {
            $reply = $this->assistant->reply($this->project, $messages, $message);
        } catch (\Throwable $exception) {
            $this->error = $exception instanceof LlmUnavailable ? $exception->translatable()->trans($this->translator) : $exception->getMessage();

            return;
        }

        $messages[] = ['role' => 'user', 'content' => $message];
        $messages[] = ['role' => 'assistant', 'content' => $reply['answer'], 'actions' => $reply['actions']];
        $this->requestStack->getSession()->set($this->key(), \array_slice($messages, -self::MAX_MESSAGES));
        $this->message = '';
    }

    #[LiveAction]
    public function reset(): void
    {
        $this->requestStack->getSession()->remove($this->key());
    }

    private function key(): string
    {
        return 'project_chat_'.$this->project->getId();
    }
}
