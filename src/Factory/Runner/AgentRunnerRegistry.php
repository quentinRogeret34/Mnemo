<?php

namespace App\Factory\Runner;

use App\Enum\AgentRunnerKind;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the appropriate AgentRunnerInterface strategy for a given AgentRunnerKind and reports host availability.
 */
final class AgentRunnerRegistry
{
    /** @var list<AgentRunnerInterface> */
    private array $runners;

    /**
     * @param iterable<AgentRunnerInterface> $runners
     */
    public function __construct(
        #[AutowireIterator('app.factory.runner')]
        iterable $runners,
    ) {
        $this->runners = $runners instanceof \Traversable ? iterator_to_array($runners, false) : array_values($runners);
    }

    public function for(AgentRunnerKind $kind): AgentRunnerInterface
    {
        foreach ($this->runners as $runner) {
            if ($runner->supports($kind)) {
                return $runner;
            }
        }

        throw new \LogicException(\sprintf('No factory runner registered for "%s".', $kind->value));
    }

    /**
     * @return array<string, bool>
     */
    public function availability(): array
    {
        $map = [];
        foreach (AgentRunnerKind::cases() as $kind) {
            $available = false;
            foreach ($this->runners as $runner) {
                if ($runner->supports($kind)) {
                    $available = $runner->isAvailable($kind);
                    break;
                }
            }
            $map[$kind->value] = $available;
        }

        return $map;
    }
}
