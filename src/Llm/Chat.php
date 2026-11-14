<?php

namespace App\Llm;

use App\Entity\Project;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\TextResult;

/**
 * One chat call that answers with a JSON object, on the configured platform and model.
 */
final class Chat
{
    public function __construct(
        private readonly LlmSettings $settings,
    ) {
    }

    /**
     * The schema is sent as the provider's structured output format; the answer is still decoded and must be
     * validated by the caller, since not every model enforces it.
     *
     * @param array<string, mixed> $schema  JSON schema of the expected object
     * @param Project|null         $project the project the call is for, which may name its own model
     *
     * @return array<string, mixed>
     */
    public function ask(string $system, string $user, array $schema, ?Project $project = null): array
    {
        if (!$this->settings->isConfigured($this->settings->chatPlatform)) {
            throw new LlmUnavailable(\sprintf('Clé API absente pour la plateforme LLM "%s" : la renseigner dans les réglages des modèles ou dans .env.local.', $this->settings->chatPlatform));
        }

        $result = $this->settings->platform($this->settings->chatPlatform)->invoke(
            $this->settings->chatModel($project),
            new MessageBag(
                Message::forSystem($system."\n\nRéponds uniquement par un objet JSON conforme au schéma demandé, sans texte autour."),
                Message::ofUser($user),
            ),
            [
                'temperature' => 0,
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'answer', 'strict' => true, 'schema' => $schema]],
            ],
        )->getResult();

        // The platform's structured output subscriber already decodes the answer; a platform without it returns the text.
        if ($result instanceof ObjectResult) {
            $data = $result->getContent();
        } elseif ($result instanceof TextResult) {
            $data = json_decode(preg_replace('/\A\s*```(?:json)?\s*|\s*```\s*\z/', '', $result->getContent()) ?? '', true);
        } else {
            throw new \UnexpectedValueException(\sprintf('Réponse du LLM inattendue : %s.', $result::class));
        }
        if (!\is_array($data)) {
            throw new \UnexpectedValueException('Réponse du LLM illisible : un objet JSON était attendu.');
        }

        return $data;
    }
}
