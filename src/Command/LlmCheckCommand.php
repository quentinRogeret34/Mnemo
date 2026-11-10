<?php

namespace App\Command;

use App\Llm\Chat;
use App\Llm\LlmSettings;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:llm:check', description: 'Check the configured chat and embedding models with one real call each')]
final class LlmCheckCommand
{
    public function __construct(
        private readonly LlmSettings $settings,
        private readonly Chat $chat,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->table(['Platform', 'API key'], array_map(
            fn (string $platform): array => [$platform, $this->settings->isConfigured($platform) ? 'set' : 'missing'],
            LlmSettings::PLATFORMS,
        ));

        $failed = false;

        $io->section(\sprintf('Chat: %s / %s', $this->settings->chatPlatform, $this->settings->chatModel));
        try {
            $result = $this->settings->platform($this->settings->chatPlatform)
                ->invoke($this->settings->chatModel, new MessageBag(Message::ofUser('Réponds uniquement par le mot OK.')))
                ->getResult();
            $io->success(\sprintf('Answer: %s', $result instanceof TextResult ? trim($result->getContent()) : $result::class));
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            $failed = true;
        }

        $io->section('Structured output (JSON schema)');
        try {
            $answer = $this->chat->ask('Tu es un test de connexion.', 'Renvoie le mot OK dans le champ "status".', [
                'type' => 'object',
                'properties' => ['status' => ['type' => 'string']],
                'required' => ['status'],
                'additionalProperties' => false,
            ]);
            $io->success(\sprintf('Answer: %s', json_encode($answer, \JSON_UNESCAPED_UNICODE)));
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            $failed = true;
        }

        $io->section(\sprintf('Embeddings: %s / %s', $this->settings->embeddingPlatform, $this->settings->embeddingModel));
        try {
            $result = $this->settings->platform($this->settings->embeddingPlatform)
                ->invoke($this->settings->embeddingModel, 'Règle métier de test')
                ->getResult();
            if (!$result instanceof VectorResult) {
                throw new \UnexpectedValueException(\sprintf('Expected vectors, got %s.', $result::class));
            }
            $io->success(\sprintf('Vector dimension: %d (the vector store column must use the same dimension).', $result->getContent()[0]->getDimensions()));
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            $failed = true;
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
