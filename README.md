# graphe

```
                       _
  __ _ _ __ __ _ _ __ | |__   ___
 / _` | '__/ _` | '_ \| '_ \ / _ \
| (_| | | | (_| | |_) | | | |  __/
 \__, |_|  \__,_| .__/|_| |_|\___|
 |___/          |_|

      [code]----implemented_by----[rule RG-001]
        |                               |
     about                           defines
        |                               |
    [memory]----linked_to----[business document]
```

**A self-hosted project knowledge graph for humans and AI agents, built with Symfony AI and the PHP MCP SDK.**

Coding agents forget everything between sessions, and they never read the spec that explains why the code is the way it is. graphe links a project's **code**, its **business documents**, the **business rules** extracted from them and the **memories** agents and humans keep (decisions, conventions, learnings) in one graph, where every edge says where it comes from and how sure it is. It gives that graph back:

- to humans, in a web interface (English and French);
- to any AI agent (Claude Code, Cursor, Codex...), through an MCP server and a small HTTP API;
- to the team, through a GitHub review bot that comments pull requests with the rules and decisions they touch.

Nothing an LLM proposes enters the graph without a human applying it.

## Quickstart

You need Docker with Compose. Ports 8080 and 5432 must be free.

```bash
git clone https://github.com/quentinRogeret34/RAG.git graphe && cd graphe
docker compose up -d --build
docker compose exec -T php composer install
docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T php bin/console app:demo
```

Open <http://localhost:8080>. The last command loads **Acme Shop**, a fictional Symfony shop: the code graph of 16 files, three business documents (discounts, order approval, returns), seven business rules linked to the code that implements them, eight memories and two proposals waiting for review.

Browsing, the code graph, the memory graph, search by keywords and the MCP server need no key. The assistant, memory extraction, verification and search by meaning need an LLM: put an [OpenRouter](https://openrouter.ai) key in **Language models** (`/settings/llm`), or in `.env.local`:

```dotenv
OPENROUTER_API_KEY=sk-or-...
```

```bash
docker compose exec -T php bin/console app:llm:check    # one real call to the chat and embedding models
docker compose exec -T php bin/console app:index        # embeddings for search by meaning
```

`app:demo --clear` removes the demo content. The interface follows the browser's language; **Appearance** switches between English and French.

## A five-minute tour

1. **Projects → Acme Shop → Business rules → RG-001 Discount cap.** The rule, its source document, the two files implementing it with the confidence of each link, and the memories about it. *Blind spots → Analyse* asks the LLM what the statement leaves the developer to guess.
2. **Code → `DiscountCalculator.php`.** Its symbols, dependencies and neighbourhood graph, plus every rule, document and memory attached to the file: what an agent gets when it opens it.
3. **Memory graph.** Each memory orbits the subject it is about. A memory linked to nothing orbits its project.
4. **To review.** A learning extracted from a pull request review, and two memories saying the same thing. Apply, edit or reject: this queue is the only way in for LLM output.
5. **Assistant** (needs a key). Ask *"What happens to an order of €600?"*, then *"Add a rule: a promo code cannot be used on gift cards."* It reads the graph with tools, cites RG-004, and the rule it writes waits for validation.
6. **Gardening.** What drifted: rules never analysed, memories never verified, documents linked to nothing.

## Connect an agent

The MCP server is at `http://localhost:8080/mcp` (streamable HTTP), or over STDIO with `bin/console mcp:server`. With Claude Code:

```bash
claude mcp add --transport http graphe http://localhost:8080/mcp
```

Then ask it *"Using graphe, what should I know before changing DiscountCalculator.php in acme-shop?"*. Each call shows on the **Dashboard**.

| Tool | What it does |
|---|---|
| `list_projects` | Projects, counters and the capabilities detected in their code |
| `search` | Keyword search, hybrid with embeddings when indexed: memories, documents, rules, code |
| `file_context` | The rules, documents, memories, dependencies and recent history of a file |
| `get_document` | A business document and its links |
| `memory_block` | The project memory as Markdown, for a session start |
| `learn` | Sends a conversation, diff, file or review; the LLM turns it into proposals to review |
| `add_memory` | Stores a memory directly, linked to files and rules |
| `import_document` | Imports a Markdown document |

The repository also ships a Claude Code plugin (`claude-plugin/`) that injects the project memory at session start, the context of each file read, and sends the session transcript to `learn` at the end. Its setup is in the in-app **Wiki**.

## What Symfony AI and the PHP MCP SDK unlocked

The whole loop runs in one Symfony app, with no Python service on the side:

- **MCP server in eight attributes.** `symfony/mcp-bundle` exposes `App\Mcp\ReadTools` and `App\Mcp\WriteTools` as MCP tools with `#[McpTool]`, typed parameters and tool annotations (read-only, destructive). The same tools serve every agent, over HTTP or STDIO, behind Symfony Security and API keys.
- **MCP client for the outside world.** Connectors use the `mcp/sdk` client (HTTP and STDIO transports) to pull content from other MCP servers (DeepWiki, Context7, mem0, Notion, Jira, Linear...) into documents or memories, including the MCP OAuth flow (protected resource metadata, dynamic client registration, PKCE).
- **An agent that reads before it answers.** The project assistant is a `symfony/ai-agent` `Agent` with a `Toolbox` of `#[AsTool]` methods (`search`, `list_rules`, `get_rule`, `add_rule`, `add_memory`), scoped to one project.
- **Structured output for extraction.** Learning extraction, memory verification, duplicate detection, blind spots and the review bot call the platform with a JSON schema and get structured objects back, through the OpenRouter bridge, so any model can be swapped from the settings page.
- **Embeddings on the same platform.** Memories, rules and documents are embedded with the platform and stored in pgvector; search merges keyword and vector ranks.
- **Symfony around it:** Messenger and Scheduler run syncs, re-indexing and reviews in a worker; Lock guards concurrent project creation; Live Components drive the chat and the editors; UX Translator shares the catalogues with Stimulus.

Before, this needed an MCP server in another language, a separate agent framework and glue between them. Here a Symfony developer reads one codebase.

## How it works

```
  1. project      created by the plugin at the first session, or app:project:create
        |
  2. ingest       code graph (graphify), business documents, agent conventions,
        |         GitHub / GitLab history, other MCP servers
        |
  3. learn        conversations, diffs, reviews -> LLM -> proposals
        |
  4. review       /proposals: apply, edit or reject
        |
  5. tend         gardening, verification against the graph, blind spots of rules
        |
  6. give back    MCP tools, /api hooks, memory block in CLAUDE.md / AGENTS.md, PR review bot
```

- **Every edge is justified:** confidence (`EXTRACTED`, `INFERRED`, `VERIFIED`), origin (`ast`, `git`, `api`, `llm`, `human`) and evidence.
- **Deterministic first:** the AST and the git history cost nothing; the LLM is only used for extraction, verification, blind spots and review.
- **Human in the loop:** what an LLM proposes goes through `/proposals`. A memory is never deleted automatically, it is archived.
- **Data, not instructions:** whatever the graph returns to an agent is marked as data.

## Commands

Prefix with `docker compose exec -T php` when running in Docker.

| Command | What it does | LLM |
|---|---|---|
| `app:demo [slug] [--clear]` | Load or remove the demo (the Acme Shop project by default) | no |
| `app:project:create <slug> <name> <path>` | Declare a project | no |
| `app:ingest:code <slug> <graph.json>` | Import a graphify code graph | no |
| `app:ingest:context <slug> <path>` | Import an AIDD memory bank and agent conventions | no |
| `app:connector:sync [id]` | Sync one or all connectors (MCP, GitHub, GitLab) | no |
| `app:learn <slug> <kind> <label> [--file=]` | Extract learnings into proposals | yes |
| `app:garden <slug> [--llm]` | Drift report; with `--llm`, duplicates and contradictions | option |
| `app:verify <slug>` | Verify memories against the graph | yes |
| `app:rule:shadow <slug> [code]` | Blind spots of business rules | yes |
| `app:review <slug> <number> [--dry-run]` | Review a pull request as the bot | option |
| `app:export:memory-block <slug> [--write=]` | Print or write the memory block | no |
| `app:index` | Embed memories, rules and documents | yes |
| `app:llm:check` | Check the chat and embedding models | yes |

## Going further

The in-app **Wiki** explains every screen and the production deployment (`compose.prod.yaml`, FrankenPHP with automatic HTTPS). In short:

- **Sign-in.** Open by default (`APP_ACCESS_ROLE=PUBLIC_ACCESS`). With `APP_ACCESS_ROLE=ROLE_USER`, users sign in with GitHub, GitLab or Google (`OAUTH_*` variables, callback `/oauth/<provider>/check`), accounts wait for a super admin (`APP_ADMIN_EMAILS`), groups scope projects, and agents send `Authorization: Bearer <key>` with a key created in **API keys**.
- **GitHub and GitLab connectors.** With `"code_graph": true`, the worker clones the repository, builds its code graph with graphify, imports its README, docs and agent conventions, then commits, pull requests and issues, linked to the files and rules they touch. **Connectors → GitHub repositories** connects several repositories at once through GitHub OAuth.
- **Review bot.** A GitHub App (`GITHUB_APP_ID`, `GITHUB_APP_PRIVATE_KEY`, `GITHUB_APP_WEBHOOK_SECRET`, `GITHUB_APP_SLUG`, webhook `/webhooks/github`) comments each pull request with the business rules it touches, their blind spots, the linked memories and what the diff contradicts, posts remarks on the lines concerned, and sets a `graphe/rules` check. Only memories shared with the project appear in it.
- **Agent skills.** A project keeps skills, commands and subagents that the plugin writes into `.claude/` on every connected machine.

## Stack

PHP 8.4+ (FrankenPHP, PHP 8.5 in the image), Symfony 8.1, Symfony AI 1.0.0-BETA1 (platform with the OpenRouter bridge, agent, MCP bundle) and `mcp/sdk`, Doctrine ORM 3 with migrations, PostgreSQL 18 with pgvector, Messenger and Scheduler, Twig, AssetMapper, Stimulus, UX Live Component, UX Translator.

## Development

```bash
docker compose exec -T php bin/phpunit                  # the LLM is mocked (tests/LlmTestTrait.php)
docker compose exec -T php vendor/bin/phpstan analyse   # level 6
docker compose exec -T php vendor/bin/php-cs-fixer fix  # @Symfony
```

## Limits

- PDF and Word documents are stored and shown, but their text is not extracted yet: rules come from Markdown.
- STDIO connectors (`MCP_CONNECTORS_ALLOW_STDIO=1`) run a command in the container: only for trusted users.
- With sign-in open (the default), anyone reaching port 8080 can read and write: keep it on `127.0.0.1` or turn on `ROLE_USER`.
