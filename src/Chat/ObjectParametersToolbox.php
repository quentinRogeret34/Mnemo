<?php

namespace App\Chat;

use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Gives a tool without arguments an empty object schema: the platform sends it with no "parameters" at all, which
 * Mistral refuses (422 "Provider returned error" through OpenRouter) where Gemini or OpenAI accept it.
 */
final class ObjectParametersToolbox implements ToolboxInterface
{
    public function __construct(
        private readonly ToolboxInterface $toolbox,
    ) {
    }

    public function getTools(): array
    {
        return array_map(
            static fn (Tool $tool): Tool => null !== $tool->getParameters() ? $tool : new Tool($tool->getReference(), $tool->getName(), $tool->getDescription(), ['type' => 'object', 'properties' => new \stdClass()], $tool->getMetadata()),
            $this->toolbox->getTools(),
        );
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        return $this->toolbox->execute($toolCall);
    }
}
