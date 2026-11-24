<?php

namespace App\Controller\Connector;

use App\Entity\Connector;
use App\Enum\ConnectorMode;
use App\Security\OAuth\OAuthClient;

final class ConnectorOAuthState
{
    public const GITHUB_OAUTH = 'connector_oauth';
    public const MCP_AUTHORIZATION = 'connector_authorization';

    public static function isGitHubOAuthAvailable(Connector $connector, OAuthClient $oauthClient): bool
    {
        return ConnectorMode::GitHub === $connector->getMode()
            && 'api.github.com' === parse_url($connector->getEndpoint(), \PHP_URL_HOST)
            && isset($oauthClient->enabled()['github']);
    }
}
