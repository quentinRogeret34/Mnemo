<?php

namespace App\Connector;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CredentialResolver
{
    /**
     * @param list<string> $allowedEnv
     */
    public function __construct(
        #[Autowire(env: 'csv:MCP_CONNECTORS_ENV')]
        private readonly array $allowedEnv,
    ) {
    }

    /**
     * Replaces every "env:NAME" in the values, so that "Bearer env:GITHUB_TOKEN" works as well as "env:TOKEN".
     * Only the variables listed in MCP_CONNECTORS_ENV can be read: a connector sends them to an endpoint anyone can edit.
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    public function resolve(array $values): array
    {
        return array_map(fn (string $value): string => preg_replace_callback('/env:([A-Za-z_][A-Za-z0-9_]*)/', function (array $match): string {
            if (!\in_array($match[1], $this->allowedEnv, true)) {
                throw new \RuntimeException(\sprintf('La variable d\'environnement "%s" n\'est pas autorisée. L\'ajouter à MCP_CONNECTORS_ENV dans .env.local.', $match[1]));
            }
            $resolved = $_SERVER[$match[1]] ?? $_ENV[$match[1]] ?? getenv($match[1]);

            return \is_string($resolved) && '' !== $resolved ? $resolved : throw new \RuntimeException(\sprintf('La variable d\'environnement "%s" n\'est pas définie.', $match[1]));
        }, $value), $values);
    }
}
