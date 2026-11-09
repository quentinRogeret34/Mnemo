<?php

namespace App\Connector;

use App\Entity\Connector;
use App\Enum\ConnectorTransport;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\StdioTransport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class McpClientFactory
{
    /**
     * @param list<string> $allowedEnv
     */
    public function __construct(
        #[Autowire(env: 'bool:MCP_CONNECTORS_ALLOW_STDIO')]
        private readonly bool $allowStdio,
        #[Autowire(env: 'csv:MCP_CONNECTORS_ENV')]
        private readonly array $allowedEnv,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function stdioAllowed(): bool
    {
        return $this->allowStdio;
    }

    /**
     * Connected client; the caller must disconnect it.
     */
    public function connect(Connector $connector): Client
    {
        $client = Client::builder()
            ->setClientInfo('graphe-projet', '0.1.0')
            ->setInitTimeout(15)
            ->setRequestTimeout(60)
            ->setMaxRetries(0)
            ->build();

        $client->connect(match ($connector->getTransport()) {
            ConnectorTransport::Http => new HttpTransport($connector->getEndpoint(), $this->resolve($connector->getCredentials())),
            ConnectorTransport::Stdio => $this->stdio($connector),
        });

        return $client;
    }

    private function stdio(Connector $connector): StdioTransport
    {
        // STDIO runs a command inside the application container: anyone who can edit connectors could run anything.
        if (!$this->allowStdio) {
            throw new \RuntimeException('Les connecteurs STDIO sont désactivés. Définir MCP_CONNECTORS_ALLOW_STDIO=1 dans .env.local pour les autoriser.');
        }

        $parts = array_values(array_filter(str_getcsv($connector->getEndpoint(), ' ', '"', '\\'), static fn (?string $part): bool => null !== $part && '' !== $part));
        if ([] === $parts) {
            throw new \RuntimeException('Commande STDIO vide.');
        }

        return new StdioTransport(array_shift($parts), $parts, $this->projectDir, $this->resolve($connector->getCredentials()) + ['PATH' => (string) getenv('PATH')]);
    }

    /**
     * Replaces every "env:NAME" in the values, so that "Bearer env:GITHUB_TOKEN" works as well as "env:TOKEN".
     * Only the variables listed in MCP_CONNECTORS_ENV can be read: a connector sends them to an endpoint anyone can edit.
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function resolve(array $values): array
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
