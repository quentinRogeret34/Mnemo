<?php

namespace App\Connector;

use App\Entity\Connector;
use App\Enum\ConnectorMode;
use App\Enum\ConnectorTransport;
use App\Enum\MemoryType;

/**
 * Ready-made settings for well-known MCP providers. They only prefill the connector form: everything stays editable.
 * Tool names marked "checked" were listed live on 2026-11-08; the others come from the providers' documentation.
 */
final class ConnectorPresets
{
    /**
     * @var array<string, array{label: string, description: string, docs: string, auth: string, checked: bool, endpoint: string, credentials: array<string, string>, mode: ConnectorMode, tool: ?string, arguments: array<string, mixed>, items_path?: string, text_field?: string, memory_type?: MemoryType, notes: list<string>}>
     */
    private const AVAILABLE = [
        'deepwiki' => [
            'label' => 'DeepWiki',
            'description' => 'Wiki généré pour un dépôt GitHub public, importé en document.',
            'docs' => 'https://docs.devin.ai/work-with-devin/deepwiki-mcp',
            'auth' => 'Aucune',
            'checked' => true,
            'endpoint' => 'https://mcp.deepwiki.com/mcp',
            'credentials' => [],
            'mode' => ConnectorMode::ToolDocument,
            'tool' => 'read_wiki_contents',
            'arguments' => ['repoName' => 'owner/repo'],
            'notes' => [
                'Remplacer « owner/repo » par le dépôt, ex. symfony/ai.',
                'Dépôts publics uniquement, déjà indexés sur deepwiki.com. Le wiki peut être long.',
                'Autres outils : read_wiki_structure (plan des pages), ask_wiki_question.',
            ],
        ],
        'context7' => [
            'label' => 'Context7',
            'description' => 'Documentation à jour d\'une bibliothèque (Symfony, Doctrine...), importée en document.',
            'docs' => 'https://context7.com/docs/resources/all-clients',
            'auth' => 'Aucune ; clé facultative',
            'checked' => true,
            'endpoint' => 'https://mcp.context7.com/mcp',
            'credentials' => [],
            'mode' => ConnectorMode::ToolDocument,
            'tool' => 'query-docs',
            'arguments' => ['libraryId' => '/org/project', 'query' => 'Sujet à documenter'],
            'notes' => [
                'libraryId s\'obtient avec l\'outil resolve-library-id (format /org/project).',
                'Avec une clé (context7.com/dashboard) : ajouter « CONTEXT7_API_KEY: env:CONTEXT7_API_KEY » et la variable dans .env.local.',
            ],
        ],
        'mem0' => [
            'label' => 'mem0',
            'description' => 'Mémoires existantes de mem0, importées en mémoires sans doublon.',
            'docs' => 'https://docs.mem0.ai',
            'auth' => 'Clé API mem0',
            'checked' => true,
            'endpoint' => 'https://mcp.mem0.ai/mcp/',
            'credentials' => ['Authorization' => 'Token env:MEM0_API_KEY'],
            'mode' => ConnectorMode::ToolMemories,
            'tool' => 'get_memories',
            'arguments' => ['page' => 1, 'page_size' => 100],
            'items_path' => 'results',
            'text_field' => 'memory',
            'memory_type' => MemoryType::Fact,
            'notes' => [
                'Définir MEM0_API_KEY dans .env.local.',
                'Une page par import : augmenter « page » pour les suivantes. Filtrer avec {"filters": {"AND": [{"app_id": "mon-app"}]}}.',
                'Toutes les mémoires reçoivent le même type ; le type mem0 d\'origine n\'est pas repris.',
            ],
        ],
        'github-api' => [
            'label' => 'GitHub (code et historique)',
            'description' => 'Graphe du code, docs, commits, pull requests et issues d\'un dépôt, reliés aux règles du projet.',
            'docs' => 'https://docs.github.com/en/rest',
            'auth' => 'Personal access token (lecture seule)',
            'checked' => false,
            'endpoint' => 'https://api.github.com',
            'credentials' => ['Authorization' => 'Bearer env:GITHUB_TOKEN'],
            'mode' => ConnectorMode::GitHub,
            'tool' => null,
            'arguments' => ['repository' => 'owner/repo', 'code_graph' => true, 'learn_reviews' => false, 'review' => false],
            'notes' => [
                'Remplacer « owner/repo » et choisir le projet : c\'est son graphe de code qui reçoit l\'historique.',
                'Définir GITHUB_TOKEN dans .env.local et l\'ajouter à MCP_CONNECTORS_ENV. Un dépôt public se lit sans jeton : vider le champ des en-têtes.',
                '« code_graph: true » clone le dépôt sur le serveur (branche par défaut, ou « branch »), calcule son graphe avec graphify sans LLM et importe README, docs/ et conventions d\'agents. Le jeton doit pouvoir lire le contenu.',
                'Premier passage : 200 commits, 100 pull requests et 100 issues les plus récents. Les suivants ne lisent que le delta.',
                '« learn_reviews: true » envoie les revues des pull requests fusionnées au LLM ; les apprentissages arrivent dans À valider.',
                '« review: true » fait commenter chaque pull request par le bot (application GitHub installée sur le dépôt) : règles métier touchées, décisions liées, ce que le diff contredit. « review_llm: false » retire la lecture du diff par le LLM, « review_check: "fail" » fait échouer le check graphe/rules au lieu d\'avertir.',
            ],
        ],
        'gitlab-api' => [
            'label' => 'GitLab (code et historique)',
            'description' => 'Graphe du code, docs, commits, merge requests et issues d\'un projet GitLab, reliés aux règles.',
            'docs' => 'https://docs.gitlab.com/api/rest/',
            'auth' => 'Access token (read_api)',
            'checked' => false,
            'endpoint' => 'https://gitlab.com/api/v4',
            'credentials' => ['PRIVATE-TOKEN' => 'env:GITLAB_TOKEN'],
            'mode' => ConnectorMode::GitLab,
            'tool' => null,
            'arguments' => ['repository' => 'groupe/projet', 'code_graph' => true, 'learn_reviews' => false],
            'notes' => [
                'Remplacer « groupe/projet » par le chemin du projet. Instance auto-hébergée : changer l\'URL en https://gitlab.exemple.com/api/v4.',
                'Définir GITLAB_TOKEN dans .env.local et l\'ajouter à MCP_CONNECTORS_ENV.',
                '« code_graph: true » clone le dépôt sur le serveur et calcule son graphe avec graphify ; le jeton a alors besoin de read_repository.',
                'Premier passage : 200 commits, 100 merge requests et 100 issues les plus récents. Les suivants ne lisent que le delta.',
            ],
        ],
        'github' => [
            'label' => 'GitHub',
            'description' => 'Issues d\'un dépôt, importées en document.',
            'docs' => 'https://github.com/github/github-mcp-server',
            'auth' => 'Personal access token',
            'checked' => false,
            'endpoint' => 'https://api.githubcopilot.com/mcp/',
            'credentials' => ['Authorization' => 'Bearer env:GITHUB_MCP_TOKEN'],
            'mode' => ConnectorMode::ToolDocument,
            'tool' => 'list_issues',
            'arguments' => ['owner' => 'owner', 'repo' => 'repo'],
            'notes' => [
                'Définir GITHUB_MCP_TOKEN dans .env.local (token avec accès en lecture au dépôt).',
                'Nom et arguments de l\'outil non vérifiés sans token : utiliser « Tester la connexion » puis ajuster.',
            ],
        ],
        'notion' => [
            'label' => 'Notion',
            'description' => 'Page ou base Notion, importée en document.',
            'docs' => 'https://developers.notion.com/docs/mcp',
            'auth' => 'OAuth',
            'checked' => false,
            'endpoint' => 'https://mcp.notion.com/mcp',
            'credentials' => [],
            'mode' => ConnectorMode::ToolDocument,
            'tool' => 'notion-fetch',
            'arguments' => ['id' => 'https://www.notion.so/page'],
            'notes' => [
                'Enregistrer, puis « Connecter par OAuth » sur la page du connecteur et choisir les pages partagées.',
                'Remplacer l\'URL par celle de la page ou de la base à importer.',
                'Autre outil : notion-search ({"query": "..."}). Vérifier les noms avec « Tester la connexion ».',
            ],
        ],
        'atlassian' => [
            'label' => 'Jira / Confluence',
            'description' => 'Tickets Jira (JQL) ou pages Confluence, importés en document.',
            'docs' => 'https://support.atlassian.com/rovo/docs/getting-started-with-the-atlassian-remote-mcp-server/',
            'auth' => 'OAuth',
            'checked' => false,
            'endpoint' => 'https://mcp.atlassian.com/v1/mcp',
            'credentials' => [],
            'mode' => ConnectorMode::ToolDocument,
            'tool' => 'searchJiraIssuesUsingJql',
            'arguments' => ['cloudId' => 'exemple.atlassian.net', 'jql' => 'project = ABC ORDER BY updated DESC'],
            'notes' => [
                'Enregistrer, puis « Connecter par OAuth » sur la page du connecteur avec un compte du site Atlassian.',
                'cloudId : le site (exemple.atlassian.net) ou l\'identifiant donné par l\'outil getAccessibleAtlassianResources.',
                'Confluence : outil getConfluencePage ({"cloudId": "...", "pageId": "..."}). Vérifier les noms avec « Tester la connexion ».',
                'Un administrateur du site peut devoir autoriser le domaine de l\'application.',
            ],
        ],
        'linear' => [
            'label' => 'Linear',
            'description' => 'Tickets et projets Linear.',
            'docs' => 'https://linear.app/docs/mcp',
            'auth' => 'Clé API Linear',
            'checked' => false,
            'endpoint' => 'https://mcp.linear.app/mcp',
            'credentials' => ['Authorization' => 'Bearer env:LINEAR_API_KEY'],
            'mode' => ConnectorMode::Resources,
            'tool' => null,
            'arguments' => [],
            'notes' => [
                'Définir LINEAR_API_KEY dans .env.local (Settings → Security & access).',
                'Ou sans clé : vider les en-têtes, enregistrer, puis « Connecter par OAuth » sur la page du connecteur.',
                'Tester la connexion, puis choisir l\'outil à appeler.',
            ],
        ],
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function available(): array
    {
        return self::AVAILABLE;
    }

    /**
     * @return array<string, mixed>|null
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
        $connector->setItemsPath($preset['items_path'] ?? null);
        $connector->setTextField($preset['text_field'] ?? null);
        $connector->setMemoryType($preset['memory_type'] ?? MemoryType::Fact);
    }
}
