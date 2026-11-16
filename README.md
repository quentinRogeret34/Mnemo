<div align="center">

# Mnemo

**The memory of your project, for humans and AI agents.**

Code, business documents, business rules and typed memories in one self-hosted graph,<br>
served to any agent harness over MCP and reviewed by humans.

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![Symfony 8.1](https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white)](https://symfony.com)
[![Symfony AI 1.0](https://img.shields.io/badge/Symfony%20AI-1.0-a78bfa)](https://github.com/symfony/ai)
[![MCP](https://img.shields.io/badge/MCP-server%20%26%20client-a78bfa)](https://modelcontextprotocol.io)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

</div>

---

Coding agents forget everything between two sessions, and they never read the spec that explains why the code is the way it is. **Mnemo** keeps that knowledge in one graph:

- the **code** of each project (files, symbols, dependencies, history);
- its **business documents** and the **business rules** they define, linked to the code that implements them;
- **typed memories** written by agents and humans: decisions, conventions, learnings, preferences, facts.

Every link says where it comes from and how sure it is. Nothing an LLM proposes enters the graph until a human applies it.

## Features

- **One memory for every agent harness.** Claude Code, Cursor, Codex, VS Code or any MCP client reads the graph and writes typed memories through the same MCP server.
- **Business rules linked to code.** Each rule keeps its source document, the files implementing it with a confidence level, and the memories about it. Before editing a file, an agent gets the rules it must respect.
- **A project assistant.** A Symfony AI agent answers questions from the graph with tools, cites the rules and memories it used, and can add rules and memories that wait for review.
- **Human in the loop.** Learnings extracted from conversations, diffs and reviews, duplicates and contradictions land in a review queue: apply, edit or reject.
- **A pull request review bot.** A GitHub App comments each pull request with the business rules it touches, the decisions it may contradict and remarks on the lines concerned, and sets a `mnemo/rules` check.
- **Connectors.** GitHub and GitLab (code graph, README, docs, commits, pull requests, issues) and any other MCP server (DeepWiki, Context7, Notion, Linear...), with the MCP OAuth flow.
- **Gardening.** Drift reports, verification of memories against the graph, blind spots of business rules.
- **A web interface** in English and French, with a memory graph, a code explorer, an activity feed and a dashboard of agent calls.

## Quickstart

Requirements: Docker with Compose, ports 8080 and 5432 free.

```bash
git clone https://github.com/quentinRogeret34/Mnemo.git mnemo && cd mnemo
docker compose up -d --build
docker compose exec -T php composer install
docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T php bin/console app:demo
```

Open <http://localhost:8080>. The last command loads **Acme Shop**, a fictional Symfony shop: the code graph of 16 files, three business documents (discounts, order approval, returns), seven business rules linked to the code that implements them, eight memories and two proposals waiting for review.

Browsing, the graphs, keyword search and the MCP server work without any key. The assistant, learning extraction, verification and semantic search need an LLM: add an [OpenRouter](https://openrouter.ai) key in **Language models** (`/settings/llm`) or in `.env.local`, then check it and index the content:

```bash
echo 'OPENROUTER_API_KEY=sk-or-...' >> .env.local
docker compose exec -T php bin/console app:llm:check
docker compose exec -T php bin/console app:index
```

`app:demo --clear` removes the demo content.

### A five-minute tour

1. **Projects → Acme Shop → Business rules → RG-001 Discount cap**: the rule, its source document, the two files implementing it and the memories about it.
2. **Code → `DiscountCalculator.php`**: what an agent gets before editing the file.
3. **Memory graph**: each memory orbits the subject it is about.
4. **To review**: a learning extracted from a pull request review and two duplicate memories.
5. **Assistant** (needs a key): ask *"What happens to an order of €600?"*, then *"Add a rule: a promo code cannot be used on gift cards."*
6. **Gardening**: rules never analysed, memories never verified, documents linked to nothing.

## Connect an agent

The MCP server listens on `http://localhost:8080/mcp` (streamable HTTP).

```bash
# Claude Code
claude mcp add --transport http mnemo http://localhost:8080/mcp

# Any client over STDIO
docker compose exec -T php bin/console mcp:server
```

Then ask your agent: *"Using mnemo, what should I know before changing DiscountCalculator.php in acme-shop?"* Each call shows on the **Dashboard**.

| Tool | What it does |
|---|---|
| `list_projects` | Projects, counters and the capabilities detected in their code |
| `search` | Keyword search, hybrid with embeddings once indexed: memories, documents, rules, code |
| `file_context` | The rules, documents, memories, dependencies and recent history of a file |
| `get_document` | A business document and its links |
| `memory_block` | The project memory as Markdown, for the start of a session |
| `learn` | Sends a conversation, diff, file or review; the LLM turns it into proposals to review |
| `add_memory` | Stores a typed memory, linked to files and rules |
| `import_document` | Imports a Markdown business document |

### Claude Code plugin

[`claude-plugin/`](claude-plugin) wires Claude Code to Mnemo automatically: the project memory at session start, the context of each file read, and the session transcript sent to `learn` at the end. Its setup is described in the in-app **Wiki**.

## Built with Symfony AI and the PHP MCP SDK

The whole loop runs in one Symfony application, with no Python service on the side.

| Piece | Package | Where |
|---|---|---|
| MCP server: eight tools declared with `#[McpTool]`, typed parameters and annotations, over HTTP and STDIO | `symfony/mcp-bundle` | [`src/Mcp`](src/Mcp) |
| MCP client: connectors to other MCP servers, HTTP and STDIO transports, OAuth (protected resource metadata, dynamic client registration, PKCE) | `mcp/sdk` | [`src/Connector`](src/Connector) |
| Project assistant: an `Agent` with a `Toolbox` of `#[AsTool]` methods scoped to one project | `symfony/ai-agent` | [`src/Chat`](src/Chat) |
| Structured output for extraction, verification, duplicates, blind spots and reviews, any model through OpenRouter | `symfony/ai-open-router-platform` | [`src/Llm`](src/Llm), [`src/Review`](src/Review) |
| Embeddings stored in pgvector, merged with keyword ranks | `symfony/ai-postgres-store` | [`src/Search`](src/Search) |

Around it: Messenger and Scheduler for syncs, re-indexing and reviews; Lock for concurrent project creation; Live Components for the assistant and the editors; UX Translator to share the catalogues with Stimulus.

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

- **Every link is justified**: confidence (`EXTRACTED`, `INFERRED`, `VERIFIED`), origin (`ast`, `git`, `api`, `llm`, `human`) and evidence.
- **Deterministic first**: the AST and the git history cost nothing; the LLM only extracts, verifies and reviews.
- **Nothing is lost**: a superseded or refuted memory is archived, never deleted.
- **Data, not instructions**: everything the graph returns to an agent is marked as data.

## Commands

Prefix with `docker compose exec -T php` when running in Docker.

| Command | What it does | LLM |
|---|---|---|
| `app:demo [slug] [--clear]` | Load or remove the demo (Acme Shop by default) | no |
| `app:project:create <slug> <name> <path>` | Declare a project | no |
| `app:ingest:code <slug> <graph.json>` | Import a graphify code graph | no |
| `app:ingest:context <slug> <path>` | Import an AIDD memory bank and agent conventions | no |
| `app:connector:sync [id]` | Sync one or all connectors (MCP, GitHub, GitLab) | no |
| `app:learn <slug> <kind> <label> [--file=]` | Extract learnings into proposals | yes |
| `app:garden <slug> [--llm]` | Drift report; with `--llm`, duplicates and contradictions | optional |
| `app:verify <slug>` | Verify memories against the graph | yes |
| `app:rule:shadow <slug> [code]` | Blind spots of business rules | yes |
| `app:review <slug> <number> [--dry-run]` | Review a pull request as the bot | optional |
| `app:export:memory-block <slug> [--write=]` | Print or write the memory block | no |
| `app:index` | Embed memories, rules and documents | yes |
| `app:llm:check` | Check the chat and embedding models | yes |

## Deployment

[`docker/compose.prod.yaml`](docker/compose.prod.yaml) runs the application, a worker and PostgreSQL behind a reverse proxy that terminates HTTPS (Traefik, Dokploy, Caddy...). Copy [`.env.prod.dist`](.env.prod.dist) to set the domain and the secrets. The in-app **Wiki** covers the details:

- **Sign-in.** Open by default (`APP_ACCESS_ROLE=PUBLIC_ACCESS`). With `ROLE_USER`, users sign in with GitHub, GitLab or Google, accounts wait for a super admin (`APP_ADMIN_EMAILS`), groups scope projects, and agents authenticate with `Authorization: Bearer <key>`.
- **GitHub and GitLab.** With `"code_graph": true`, the worker clones the repository, builds its code graph and imports its docs and history. **Connectors → GitHub repositories** connects several repositories at once.
- **Review bot.** Create a GitHub App (`GITHUB_APP_ID`, `GITHUB_APP_PRIVATE_KEY`, `GITHUB_APP_WEBHOOK_SECRET`, `GITHUB_APP_SLUG`) with the webhook `/webhooks/github`. Only memories shared with the project appear in its comments.
- **Agent skills.** A project keeps skills, commands and subagents that the plugin writes into `.claude/` on every connected machine.

## Development

```bash
docker compose exec -T php bin/phpunit                  # the LLM is mocked (tests/LlmTestTrait.php)
docker compose exec -T php vendor/bin/phpstan analyse   # level 6
docker compose exec -T php vendor/bin/php-cs-fixer fix  # @Symfony coding standard
```

**Stack:** PHP 8.4+ (PHP 8.5 in the FrankenPHP image), Symfony 8.1, Symfony AI 1.0, `mcp/sdk`, Doctrine ORM 3, PostgreSQL 18 with pgvector, Messenger, Scheduler, Twig, AssetMapper, Stimulus, UX Live Component, UX Translator.

## Limitations

- Business rules are added by the assistant or the demo and linked to their source documents; they are not extracted automatically from an imported document yet.
- PDF and Word documents are stored and displayed, but their text is not extracted yet.
- STDIO connectors (`MCP_CONNECTORS_ALLOW_STDIO=1`) run a command inside the container: enable them for trusted users only.
- With sign-in open (the default), anyone reaching port 8080 can read and write: keep it bound to `127.0.0.1` or switch to `ROLE_USER`.

## License

[MIT](LICENSE) © 2026 Quentin Rogeret. Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon).
