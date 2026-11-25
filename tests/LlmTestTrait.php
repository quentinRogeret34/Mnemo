<?php

namespace App\Tests;

use App\Llm\LlmSettings;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Replaces the configured LLM platform with a scripted one. Call it before the first service using the LLM is built;
 * in a WebTestCase, disable the kernel reboot so the replacement survives between requests.
 */
trait LlmTestTrait
{
    /** @var list<array{system: string, user: string, model: string}> */
    private array $llmCalls = [];

    /**
     * @param \Closure(string $system, string $user): array<string, mixed> $answer
     */
    private function scriptLlm(\Closure $answer): void
    {
        $platform = MockPlatformFactory::createPlatform(function (Model $model, array|string|object $input) use ($answer): string {
            \assert(\is_array($input));
            $system = $input['messages'][0]['content'];
            $user = $input['messages'][1]['content'];
            $this->llmCalls[] = ['system' => $system, 'user' => $user, 'model' => $model->getName()];

            return json_encode($answer($system, $user), \JSON_THROW_ON_ERROR);
        });

        self::getContainer()->set(LlmSettings::class, new LlmSettings(
            new ServiceLocator(['openrouter' => static fn () => $platform]),
            'openrouter',
            'test-model',
            'openrouter',
            'test-embedding',
            'test-key',
        ));
    }

    private function forbidLlm(): void
    {
        $this->scriptLlm(static fn (): never => throw new \LogicException('The LLM must not be called here.'));
    }
}
