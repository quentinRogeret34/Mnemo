<?php

namespace App\Forge;

use App\Connector\CredentialResolver;
use App\Entity\Connector;
use App\Enum\ConnectorMode;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ForgeClientFactory
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CredentialResolver $credentials,
    ) {
    }

    public function create(Connector $connector): ForgeClient
    {
        $http = $this->httpClient->withOptions([
            'base_uri' => rtrim($connector->getEndpoint(), '/').'/',
            'headers' => $this->credentials->forgeHeaders($connector) + ['Accept' => 'application/json', 'User-Agent' => 'mnemo'],
            'timeout' => 30,
        ]);
        $repository = trim((string) ($connector->getArguments()['repository'] ?? ''), '/ ');

        return match ($connector->getMode()) {
            ConnectorMode::GitHub => new GitHubClient($http, $repository),
            ConnectorMode::GitLab => new GitLabClient($http, $repository),
            default => throw new \LogicException(\sprintf('Connector mode "%s" is not a forge.', $connector->getMode()->value)),
        };
    }
}
