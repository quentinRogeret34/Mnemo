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
    /** @var list<array{system: string, user: string}> */
    private array $llmCalls = [];

    /**
     * @param \Closure(string $system, string $user): array<string, mixed> $answer
     */
    private function scriptLlm(\Closure $answer): void
    {
        $platform = MockPlatformFactory::createPlatform(function (Model $model, array|string|object $input) use ($answer): string {
            $system = $input['messages'][0]['content'];
            $user = $input['messages'][1]['content'];
            $this->llmCalls[] = ['system' => $system, 'user' => $user];

            return json_encode($answer($system, $user), \JSON_THROW_ON_ERROR);
        });

        self::getContainer()->set(LlmSettings::class, new LlmSettings(
            new ServiceLocator(['mistral' => static fn () => $platform, 'openrouter' => static fn () => $platform]),
            'mistral',
            'test-model',
            'mistral',
            'test-embedding',
            'test-key',
            'test-key',
        ));
    }

    private function forbidLlm(): void
    {
        $this->scriptLlm(static fn (): never => throw new \LogicException('The LLM must not be called here.'));
    }
}
