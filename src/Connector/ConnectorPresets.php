<?php

namespace App\Connector;

use App\Entity\Connector;
use App\Enum\ConnectorMode;
use App\Enum\ConnectorTransport;
use App\Enum\MemoryType;

/**
 * Curated guided integrations shown on /connectors: each offers either a one-click OAuth flow
 * (GitHub, Atlassian, Linear) or a guided single-token setup (Notion) with zero manual JSON.
 * Advanced custom MCP connectors remain available via /connectors/new.
 */
final class ConnectorPresets
{
    public const FLOW_GITHUB_OAUTH = 'github_oauth';
    public const FLOW_MCP_OAUTH = 'mcp_oauth';
    public const FLOW_NOTION_TOKEN = 'notion_token';

    /**
     * @var array<string, array{label: string, description: string, docs: string, auth: string, flow: string, checked: bool, endpoint: string, credentials: array<string, string>, mode: ConnectorMode, tool: ?string, arguments: array<string, mixed>, items_path?: string, text_field?: string, memory_type?: MemoryType, notes: list<string>}>
     */
    private const AVAILABLE = [
        'github-api' => [
            'label' => 'GitHub',
            'description' => 'Graphe du code, docs, commits, pull requests et issues de vos dépôts, reliés aux règles du projet.',
            'docs' => 'https://docs.github.com/en/rest',
            'auth' => 'OAuth GitHub (1 clic)',
            'flow' => self::FLOW_GITHUB_OAUTH,
            'checked' => true,
            'endpoint' => 'https://api.github.com',
            'credentials' => [],
            'mode' => ConnectorMode::GitHub,
            'tool' => null,
            'arguments' => ['repository' => 'owner/repo', 'code_graph' => true, 'learn_reviews' => false, 'review' => false],
            'notes' => [
                'Choisissez vos dépôts directement après la connexion OAuth GitHub, sans créer de jeton à la main.',
                '« code_graph: true » clone le dépôt sur le serveur, calcule son graphe avec graphify sans LLM et importe README, docs/ et conventions d\'agents.',
                'Vous pouvez activer la revue automatique des pull requests depuis la fiche du connecteur.',
            ],
        ],
        'atlassian' => [
            'label' => 'Jira / Confluence',
            'description' => 'Tickets Jira et pages Confluence via le serveur MCP officiel Atlassian, configuré interactivement après OAuth.',
            'docs' => 'https://support.atlassian.com/rovo/docs/getting-started-with-the-atlassian-remote-mcp-server/',
            'auth' => 'OAuth Atlassian (1 clic)',
            'flow' => self::FLOW_MCP_OAUTH,
            'checked' => true,
            'endpoint' => 'https://mcp.atlassian.com/v1/mcp',
            'credentials' => [],
            'mode' => ConnectorMode::Resources,
            'tool' => null,
            'arguments' => [],
            'notes' => [
                'Cliquez sur « Connecter » pour autoriser votre site Atlassian par OAuth, puis choisissez vos outils et paramètres sans JSON.',
                'Un administrateur du site Atlassian peut devoir autoriser le domaine de l\'application.',
            ],
        ],
        'linear' => [
            'label' => 'Linear',
            'description' => 'Tickets, cycles et projets Linear via le serveur MCP officiel Linear, configuré interactivement après OAuth.',
            'docs' => 'https://linear.app/docs/mcp',
            'auth' => 'OAuth Linear (1 clic)',
            'flow' => self::FLOW_MCP_OAUTH,
            'checked' => true,
            'endpoint' => 'https://mcp.linear.app/mcp',
            'credentials' => [],
            'mode' => ConnectorMode::Resources,
            'tool' => null,
            'arguments' => [],
            'notes' => [
                'Cliquez sur « Connecter » pour autoriser votre espace Linear par OAuth, puis sélectionnez les ressources ou outils à synchroniser.',
            ],
        ],
        'notion' => [
            'label' => 'Notion',
            'description' => 'Pages partagées avec votre intégration Notion, importées automatiquement en documents Markdown.',
            'docs' => 'https://developers.notion.com/docs/create-a-notion-integration',
            'auth' => 'Jeton d\'intégration (guidé)',
            'flow' => self::FLOW_NOTION_TOKEN,
            'checked' => true,
            'endpoint' => 'https://api.notion.com/v1',
            'credentials' => ['Authorization' => 'Bearer COLLER_LE_JETON'],
            'mode' => ConnectorMode::Notion,
            'tool' => null,
            'arguments' => [],
            'notes' => [
                'Créez une intégration interne sur notion.so/profile/integrations (capacité « Lire le contenu ») et collez son jeton : il est chiffré en base.',
                'Partagez dans Notion les pages à importer (… → Connexions) : leurs sous-pages suivent automatiquement.',
                'Chaque page devient un document Markdown mis à jour de façon incrémentale.',
            ],
        ],
    ];

    /**
     * @return array<string, array{label: string, description: string, docs: string, auth: string, flow: string, checked: bool, endpoint: string, credentials: array<string, string>, mode: ConnectorMode, tool: ?string, arguments: array<string, mixed>, items_path?: string, text_field?: string, memory_type?: MemoryType, notes: list<string>}>
     */
    public function available(): array
    {
        return self::AVAILABLE;
    }

    /**
     * @return array{label: string, description: string, docs: string, auth: string, flow: string, checked: bool, endpoint: string, credentials: array<string, string>, mode: ConnectorMode, tool: ?string, arguments: array<string, mixed>, items_path?: string, text_field?: string, memory_type?: MemoryType, notes: list<string>}|null
     */
    public function get(string $key): ?array
    {
        return self::AVAILABLE[$key] ?? null;
    }

    public function apply(string $key, Connector $connector): void
    {
        $preset = self::AVAILABLE[$key] ?? throw new \InvalidArgumentException(\sprintf('Unknown connector preset "%s".', $key));

        $connector->setName($preset['label']);
        $connector->setTransport(ConnectorTransport::Http);
        $connector->setEndpoint($preset['endpoint']);
        $connector->setCredentials($preset['credentials']);
        $connector->setMode($preset['mode']);
        $connector->setTool($preset['tool']);
        $connector->setArguments($preset['arguments']);
        $connector->setItemsPath(null);
        $connector->setTextField(null);
        $connector->setMemoryType(MemoryType::Fact);
    }
}
