<?php

namespace App\Llm;

use Symfony\Component\Translation\TranslatableMessage;

final class LlmUnavailable extends \RuntimeException
{
    public function __construct(public readonly string $platform)
    {
        parent::__construct(\sprintf('No API key for the LLM platform "%s": set it in the model settings or in .env.local.', $platform));
    }

    public function translatable(): TranslatableMessage
    {
        return new TranslatableMessage('llm.unavailable', ['platform' => $this->platform]);
    }
}
