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
    public function __construct(
        #[Autowire(env: 'bool:MCP_CONNECTORS_ALLOW_STDIO')]
        private readonly bool $allowStdio,
        private readonly CredentialResolver $credentials,
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
            ConnectorTransport::Http => new HttpTransport($connector->getEndpoint(), $this->credentials->resolve($connector->getCredentials())),
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

        return new StdioTransport(array_shift($parts), $parts, $this->projectDir, $this->credentials->resolve($connector->getCredentials()) + ['PATH' => (string) getenv('PATH')]);
    }
}
