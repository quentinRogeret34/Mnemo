# Idée projet : graphe de connaissance projet (mémoire + RAG + règles métier)

Document de reprise. Rédigé le 2026-11-07 à partir d'une session d'exploration,
mis à jour le 2026-11-08 (connecteurs, synchronisation planifiée, liens manuels,
wiki généré, état des lieux des produits de type DeepWiki), puis le 2026-11-08 au
soir après la première session d'implémentation : ce qui existe est décrit en
§11, l'avancement par étape en §9, les décisions prises en §10.

## 1. L'idée en une phrase

Un outil générique, auto-hébergé en Symfony, qui ingère tout ce qui fait la
connaissance d'un projet ou d'un ensemble de projets (un par dépôt git, par
exemple) : code, historique git, merge requests, tickets, documents métier,
conversations, mémoires. Il les range dans un graphe relié et les restitue :

- à des humains, via un front qui répond à "quelles règles métier s'appliquent
  à ce fichier de code, pourquoi, et d'où ça vient ?" et via un wiki généré ;
- à n'importe quel agent IA (Claude Code, Cursor, Codex, agent maison...),
  via une API et un serveur MCP, sans être lié à un client particulier.

## 2. Problème visé

- Les règles métier vivent dans des docs, des tickets, des têtes. Le code les
  implémente sans y faire référence. Personne ne sait plus quel fichier porte
  quelle règle.
- Les agents IA repartent de zéro à chaque session : décisions passées,
  conventions, pièges connus sont perdus.
- La connaissance est éparpillée entre forges (GitLab, GitHub), outils de suivi
  (Jira) et wikis (Notion). Aucun endroit ne la relie au code.
- Les outils existants couvrent un morceau chacun :
  - mem0 : mémoire de conversation (faits extraits par LLM), pas de lien au code.
  - RAG classique : chunks de documents, pas de structure, pas de provenance.
  - graphify / codegraph : graphe du code, peu ou pas de métier ni de mémoire.
  - DeepWiki et équivalents : wiki généré depuis le code seul, sans règles
    métier, sans mémoire, sans validation humaine.

L'objectif est de les réunir dans un seul graphe, avec la provenance de chaque
lien.

### Produits de type "wiki généré" (état des lieux au 2026-11-08)

| Produit | Ce qu'il fait | Ce qu'on reprend |
|---|---|---|
| DeepWiki (Cognition / Devin) | Wiki auto par dépôt GitHub : plan de pages hiérarchique, pages avec diagrammes et liens vers le code. Q&A "Ask Devin" sur wiki + recherche de code. Pilotage par `.devin/wiki.json` (`repo_notes`, `pages` : titre, objectif, parent, notes), sinon plan par clustering. Serveur MCP public : `read_wiki_structure`, `read_wiki_contents`, `ask_wiki_question` (noms vérifiés le 2026-11-08, paramètre `repoName`). 30 pages max par wiki, niveaux d'effort facturés. | Fichier de pilotage, 3 tools MCP, hiérarchie de pages |
| deepwiki-open (open source, MIT) | Auto-hébergé, GitHub / GitLab / Bitbucket, dépôts privés. Clone, filtre (exclut `vendor/`, `node_modules/`, `docs/`...), embeddings, RAG. Le LLM planifie la structure (overview, architecture, features, données, front, back, déploiement, extensibilité) puis génère chaque page : bloc des fichiers sources, Mermaid, tableaux, citation `Sources: [path:début-fin]()` obligatoire. Chat RAG multi-tour, "Deep Research" (plusieurs itérations), "Codemap" : visite guidée pour une question "comment faire X", en deux appels LLM (squelette puis enrichissement). Cache du wiki en local, multi-fournisseurs LLM. | Format de citation à la ligne, codemap, recherche approfondie |
| Google Code Wiki | Wiki par dépôt régénéré après chaque changement. Diagrammes d'architecture, de classes et de séquence tenus à jour. Chat qui s'appuie sur le wiki entier. Chaque section et chaque réponse pointe vers les fichiers et définitions. Dépôts publics, extension Gemini CLI annoncée pour les dépôts privés. | Fraîcheur continue, liens vers les définitions |
| CodeAlmanac (open source, Apache-2.0) | Wiki Markdown versionné dans le dépôt (`almanac/`), relu en revue git. Capte le *pourquoi* : décisions, invariants, incidents passés. Trois agents : `build`, `ingest` (fichiers, diffs, plages de commits, PR / issues GitHub, URL, transcripts d'agents), `garden` (pages périmées, doublons, liens faibles, affirmations non sourcées). Sync des transcripts Claude / Codex toutes les 5 h, garden toutes les 24 h. Lecture sans LLM : `search`, `search --mentions <path>`, `show`, `topics`, `health`, `validate`. Un ingest sans connaissance durable ne change rien. | Jardinage, ingestion planifiée des transcripts, recherche par chemin, no-op valide |

Constat :

- DeepWiki, deepwiki-open, Code Wiki décrivent *ce que fait* le code. Seule
  source : le code. Rien sur le métier ni sur le pourquoi.
- CodeAlmanac capte le *pourquoi*, mais en Markdown libre : pas de graphe typé,
  pas de niveau de confiance, pas de lien règle métier vers symbole.
- Place de ce projet : les deux, sur un graphe où chaque affirmation a une
  provenance et un statut de validation.

## 3. Principes

1. **Générique** : aucun couplage à Claude. Les clients parlent à une API REST
   et/ou au serveur MCP. Les intégrations (hooks Claude Code, etc.) sont de
   simples adaptateurs.
2. **Graphe + vecteurs** : le graphe relie, la recherche vectorielle/hybride
   sert de point d'entrée. Les deux dans Postgres.
3. **Chaque arête est justifiée** : tag de confiance (`EXTRACTED`, `INFERRED`,
   `VERIFIED`), source (ast, git, api, llm, human) et preuve (ligne, commit,
   passage). Idée reprise de graphify.
4. **Humain dans la boucle** : les liens inférés par LLM se valident ou se
   rejettent dans le front. On peut aussi lier ou délier à la main, visuellement,
   un fichier de code ou une mémoire et un document technique. Un lien posé à la
   main est `VERIFIED` (source `human`). Un lien délié est conservé comme
   `REJECTED` pour qu'une ré-ingestion ne le recrée pas. C'est ce qui rend le
   graphe métier digne de confiance.
5. **Déterministe d'abord, LLM ensuite** : AST, git et API des connecteurs ne
   coûtent rien et sont fiables. Le LLM ne sert que là où rien d'autre ne marche
   (docs métier, extraction de mémoire, liens règle vers code, wiki).
6. **Pas de copie de code périmée** : on n'indexe pas le contenu des fichiers lus
   par un agent. Le code courant est servi par le graphe AST (re-généré), on
   stocke ce qu'on a *appris* sur un fichier, pas le fichier.
7. **Connecteurs enfichables** : GitLab, GitHub, Jira, Notion, serveurs MCP...
   Chaque connecteur implémente la même interface (lecture incrémentale depuis
   un curseur, normalisation en noeuds + arêtes). En ajouter un ne touche pas au
   coeur.

## 4. Sources d'ingestion

| Source | Méthode | ID stable | Déclencheur |
|---|---|---|---|
| Code | Graphe AST : import du `graph.json` de graphify, ou `nikic/php-parser` pour du PHP seul | chemin + symbole | CI / commande |
| Historique git local | Loader custom sur `git log` : un document par commit (message, fichiers, diff résumé) | SHA | hook `post-commit`, webhook CI, incrémental depuis dernier SHA |
| Forges (GitLab, GitHub) | Connecteur API : commits, merge / pull requests, issues, revues, auteurs | ID forge + projet | Scheduler (quotidien) + webhook |
| Outils externes (Jira, Notion...) | Connecteur API : tickets, pages. Pages découpées comme les docs métier | ID externe + date de modif | Scheduler + webhook si dispo |
| Serveurs MCP tiers | L'outil agit en client MCP : appel de tools / lecture de resources exposés par d'autres serveurs | URI de la resource | Scheduler |
| Docs métier | Loaders `ai-store` (Markdown, RST, CSV, JSON, texte) + `TextSplitTransformer`, puis extraction des règles par LLM | hash(path + index chunk), ré-indexation si hash contenu change | commande `ai:store:index`, Scheduler |
| Conversations | Pipeline mémoire façon mem0 : extraction de faits par LLM, dédup, liens | UUID du fait | API d'ingestion appelée par les clients (ex. hook `Stop` Claude Code), ou lecture planifiée des transcripts locaux (approche CodeAlmanac) |

Deux pipelines distincts à garder séparés :

- **Mémoire** : faits extraits par LLM, courts, auto-portants, datés.
- **RAG** : documents découpés en chunks, sans LLM, ré-indexables.

### Synchronisation planifiée

- `symfony/scheduler` (à installer) déclenche un message Messenger par
  connecteur et par projet.
- Chaque connecteur garde un curseur (dernière date / dernier ID vu) : on ne
  récupère que le delta.
- Cas d'usage visé : chaque soir, récupérer le travail de la journée de
  l'équipe (commits, MR fusionnées, tickets fermés, pages modifiées) et le
  relier au code et aux règles existants.
- Le webhook, quand la source le permet, sert au temps réel. Le cron rattrape
  ce que le webhook a manqué.

## 5. Modèle de graphe

Noeuds : `CodeFile`, `Symbol`, `BusinessRule`, `DocChunk`, `Memory`, `Commit`,
`MergeRequest`, `Ticket`, `Person`, `Conversation`.

```
Symbol       -[defined_in]->     CodeFile      EXTRACTED  (AST)
Symbol       -[calls|imports]->  Symbol        EXTRACTED  (AST)
Commit       -[modifies]->       CodeFile      EXTRACTED  (git)
Commit       -[authored_by]->    Person        EXTRACTED  (git / api)
Commit       -[references]->     Ticket        EXTRACTED  (regex message)
MergeRequest -[contains]->       Commit        EXTRACTED  (api)
MergeRequest -[closes]->         Ticket        EXTRACTED  (api)
DocChunk     -[defines]->        BusinessRule  INFERRED   (LLM)
BusinessRule -[implemented_by]-> Symbol        EXTRACTED | INFERRED | VERIFIED
Memory       -[about]->          CodeFile      INFERRED   (path extrait)
Memory       -[linked_to]->      Memory        INFERRED   (dédup / évolution)
CodeFile     -[documented_by]->  DocChunk      INFERRED | VERIFIED (human)
```

Champs communs d'une arête : `confidence`, `source`, `evidence`, `created_at`,
`status` (`active` / `rejected`).

Implémenté au 2026-11-08 (tables `node`, `edge`, `node_project`, `folder`) :

- Noeuds `CodeFile`, `Symbol` (import graphify), `Document`, `BusinessRule`,
  `Memory`. `Commit`, `Person`, `Ticket` sont prévus dans l'enum, pas encore
  alimentés. `DocChunk` viendra avec l'indexation vectorielle.
- Le code et les règles appartiennent à un projet. Les documents et les mémoires
  vivent au niveau de l'espace et se rattachent à 0..N projets (`node_project`).
  Les documents se rangent dans une arborescence de dossiers (`folder`).
- Arêtes en place : celles de graphify (`contains`, `imports`, `calls`...),
  `Document -[defines]-> BusinessRule`, `BusinessRule -[implemented_by]-> CodeFile`,
  `CodeFile -[documented_by]-> Document` (lien manuel, au niveau du document
  entier en attendant les chunks), `Memory -[about]-> CodeFile | Document | BusinessRule`.
- La source d'une arête s'appelle `origin` dans le code (`ast`, `git`, `api`,
  `llm`, `human`) pour ne pas la confondre avec le noeud source.
- Délier passe l'arête en `rejected` (conservée) ; la relier de nouveau la
  repasse `active` / `VERIFIED` / `human`.

Métadonnées communes d'un noeud indexé : `_text`, `source_type`, `project`,
`path`, `ref` (sha / version doc), `created_at`, `content_hash`.

### Le point dur : relier règle métier et code

Par ordre de fiabilité :

1. Lien posé à la main dans le front. `VERIFIED`.
2. Référence explicite dans le code : attribut PHP `#[BusinessRule('RG-042')]`
   ou docblock `@see RG-042`. Déterministe.
3. Via git / forge : commit ou MR qui touche le fichier et cite un ticket lié à
   la règle.
4. Co-changement : fichiers souvent modifiés ensemble pour une même règle.
5. LLM / similarité vectorielle règle vs code. Bruité, toujours `INFERRED`,
   à valider dans le front.

## 6. Restitution

### Recherche (GraphRAG)

1. Point d'entrée :
   - par fichier (path connu) : noeud `CodeFile` direct ;
   - par question : `HybridQuery` (sémantique + mots-clés, utile pour les
     identifiants de code) sur Postgres.
2. Expansion 1 à 2 sauts dans le graphe, en privilégiant `VERIFIED` et
   `EXTRACTED`, en ignorant les arêtes `rejected`.
3. Rerank (`Reranker` de `ai-store`), coupe à un budget de tokens, regroupement
   par type.

### Wiki généré (façon DeepWiki, voir état des lieux en §2)

Le wiki est une vue sur le graphe, pas une source de vérité : on corrige le
graphe (liens, règles, mémoires), le wiki suit.

Génération, sur le modèle du pipeline de deepwiki-open :

1. **Plan** : le LLM propose l'arbre des pages (sections puis pages) à partir
   des communautés du graphe, des règles métier et du README. Surchargeable par
   un fichier de pilotage versionné dans le dépôt (équivalent de
   `.devin/wiki.json` : notes globales, pages imposées avec titre, objectif,
   parent).
2. **Pages** : une par module ou communauté, une par règle métier, une vue
   d'ensemble, une page "décisions" (mémoires de type décision). Chaque page
   reçoit les noeuds de son périmètre (symboles, règles, docs, commits, MR,
   mémoires), pas des chunks pris au hasard.
3. **Citations** : chaque paragraphe, tableau ou diagramme cite ses noeuds
   (`path:début-fin`, SHA, ticket, mémoire), cliquables vers leur fiche. Une
   affirmation sans source est rejetée à la génération.
4. **Diagrammes** Mermaid construits depuis les arêtes (appels, dépendances,
   `implemented_by`), pas imaginés par le LLM. Le LLM ne fait que légender.
5. **Fraîcheur** : une page est périmée quand le `content_hash` d'un de ses
   noeuds change. Régénération par message Messenger. Mention "à jour au
   SHA x".
6. **Jardinage** (façon `garden` de CodeAlmanac) : tâche planifiée qui détecte
   pages périmées, doublons de mémoires, liens faibles, affirmations sans
   source, noeuds orphelins. Elle propose, l'humain valide.

Questions sur le projet :

- **Ask** : chat multi-tour au-dessus de la recherche GraphRAG, réponses
  citées.
- **Recherche approfondie** : plusieurs itérations recherche / lecture avant de
  répondre, pour les questions transverses.
- **Codemap** (visite guidée) : pour "comment marche X / comment faire Y",
  parcours pas à pas dans le code (étapes, fichiers, lignes) avec diagramme.
  Deux appels LLM : squelette, puis enrichissement.

Différence avec ces produits : les pages s'appuient sur les règles métier, les
mémoires et les liens validés, pas seulement sur le code.

### Interfaces

- **API REST** : `POST /ingest/...` (asynchrone, 202 + Messenger),
  `GET /search`, `GET /nodes/{id}`, `GET /files/{path}/context`,
  `POST /edges` / `DELETE /edges/{id}` (lier / délier), `GET /wiki`,
  `GET /wiki/{page}`, `POST /ask` (réponse en streaming).
- **Serveur MCP** (`symfony/mcp-bundle` 0.15, en place) : `list_projects`,
  `search` (mots-clés pour l'instant), `file_context`, `get_document`,
  `add_memory` (dédup exacte, événements `ADD` / `NONE` comme mem0),
  `import_document`. HTTP sur `/mcp` et STDIO (`bin/console mcp:server`).
  Restent à ajouter : `wiki_structure`, `wiki_page`, `ask`.
- **Client MCP** (en place) : connecteurs configurables depuis le front vers
  des serveurs MCP tiers, avec des modèles prêts à l'emploi (voir §11).
- **Adaptateurs clients** (exemple Claude Code, sur le modèle du plugin mem0) :
  - `SessionStart` / `UserPromptSubmit` : recherche, injection en contexte ;
  - `PreToolUse` sur `Read` : contexte du fichier (règles, mémoires, commits) ;
  - `Stop` / `SessionEnd` : envoi du transcript à l'API d'ingestion.

### Front

- **Écran principal : fiche d'un fichier de code** : règles métier liées, docs
  sources, mémoires, commits, MR, tickets, conversations, avec niveau de
  confiance et boutons valider / rejeter.
- **Édition des liens** : lier ou délier visuellement un fichier de code ou une
  mémoire et un document technique (glisser-déposer dans la vue graphe, ou
  recherche + ajout depuis la fiche).
- **Fiche d'une règle métier** : code qui l'implémente, historique, documents.
- **Wiki** : pages générées, navigation par section, liens vers les fiches,
  Ask, codemaps, propositions du jardinage à valider.
- **Activité** : ce que la synchro du jour a ramené (par personne, par projet).
- **Vue graphe** : voisinage local (1-2 sauts), filtres par type et confiance.
  Le graphe global type graphify est joli mais peu lisible pour répondre à une
  question précise.
- **Connecteurs** : configuration par projet, état de la dernière synchro.
- Communautés (Leiden) : reprises de graphify à l'import, ou plus tard.
- Stack : Twig + Symfony UX / Stimulus + AssetMapper + Cytoscape.js ou Sigma.js.

## 7. Choix techniques envisagés

- Postgres 18 + pgvector pour tout : tables `node` / `edge` (Doctrine ORM,
  colonne vecteur sur `node`), parcours en CTE récursive.
- `symfony/ai-store` pour indexation, recherche hybride, rerank. Les `id` du
  store = les `id` des noeuds.
- `symfony/ai-platform` pour LLM d'extraction, wiki et embeddings.
- Messenger pour toute ingestion (extraction, embeddings en batch, synchro
  connecteurs).
- Scheduler pour les synchros planifiées.
- HttpClient pour les API des connecteurs. Jetons d'accès dans le vault de
  secrets ou chiffrés en base.
- Écartés pour l'instant : Neo4j (service en plus), Apache AGE (compatibilité
  PG18 non vérifiée).
- Retenus le 2026-11-08 : `symfony/mcp-bundle` 0.15 et le SDK officiel
  `mcp/sdk` 0.9 (serveur et client), `symfony/ai-mistral-platform` et
  `symfony/ai-open-router-platform` 0.15, Symfony UX (Twig Components, Live
  Components, Dropzone), Cytoscape.js pour la vue graphe.

Ce que Symfony AI 0.15 fournit déjà (vérifié dans `vendor/`) :

- `ai-store` : loaders (Markdown, RST, CSV, JSON, texte, répertoire, RSS),
  transformers (`TextSplitTransformer`, `SummaryGeneratorTransformer`...),
  `Vectorizer`, `SourceIndexer`, `Retriever`, `Reranker`, `CombinedStore`,
  commandes `ai:store:index` / `ai:store:retrieve`.
- `ai-postgres-store` : `VectorQuery`, `TextQuery`, `HybridQuery`, option
  `where` + `params` pour filtrer sur les métadonnées, upsert par `id`.
- `ai-agent` : `MemoryProviderInterface`, `MemoryInputProcessor`,
  `EmbeddingProvider` (utile seulement si un agent Symfony AI répond lui-même ;
  à noter : `EmbeddingProvider` renvoie les métadonnées, pas le contenu).

Ce qui manque et sera à écrire : extraction de mémoire (équivalent du
`ADDITIVE_EXTRACTION_PROMPT` de mem0), dédup par LLM, scoping par utilisateur,
loader git, extraction des règles et liens règle vers code par LLM,
indexation vectorielle, wiki. Déjà écrits : graphe, scoping par projet, client
MCP, connecteurs MCP, front.

## 8. Risques à garder en tête

- **Latence** : un hook de recherche côté client a quelques secondes (8 s chez
  mem0). Embedding + recherche + rerank doivent tenir dedans.
- **Péremption** : rebase / force-push, docs modifiées ou supprimées, tickets
  supprimés côté source. Prévoir la suppression des IDs disparus, pas seulement
  l'upsert.
- **Secrets** : transcripts et diffs contiennent des tokens. Filtrer côté client
  avant envoi, et à nouveau à l'ingestion. Les jetons des connecteurs eux-mêmes
  sont des secrets à protéger.
- **Droits** : un ticket Jira ou une page Notion privée ne doit pas ressortir
  chez quelqu'un qui n'y a pas accès dans la source.
- **API tierces** : quotas, pagination, schémas qui changent. Curseurs
  idempotents, reprise après échec.
- **Prompt injection** : un doc, un ticket ou une resource MCP indexé finit dans
  le contexte d'un agent. Le présenter comme donnée non fiable.
- **Wiki trompeur** : une page générée a l'air juste même quand elle ne l'est
  pas. Citations obligatoires, diagrammes dérivés du graphe, statut visible
  (générée / relue).
- **SQL** : l'option `where` du store Postgres est du SQL brut. Toujours passer
  par `params`.
- **Coût** : embeddings sur un gros historique git, génération du wiki.
  Incrémental + batch.
- **Confiance** : sans validation humaine des liens inférés, le graphe métier
  et le wiki seront ignorés.
- **Aucune authentification** (constaté le 2026-11-08) : le front et le serveur
  MCP écrivent sans contrôle d'accès. Le contrôle d'hôte du bundle MCP ne porte
  que sur l'en-tête `Host`, et Docker publie le port 8080 sur toutes les
  interfaces. Correctif immédiat possible : publier `127.0.0.1:8080:80`.
- **Connecteurs STDIO** : ils exécutent une commande dans le conteneur. Désactivés
  par défaut (`MCP_CONNECTORS_ALLOW_STDIO=0`) tant qu'il n'y a pas d'auth.
- **Connecteurs HTTP** : le serveur appelle l'URL saisie dans le front (SSRF en
  usage partagé). Les secrets saisis sans `env:NOM` sont stockés en clair.
- **SDK MCP expérimental** : `mcp/sdk` est en 0.x, son API peut changer.
- **Dimension des embeddings** : le store attend 1536 par défaut ; `mistral-embed`
  en produit moins (à mesurer avec `app:llm:check`). À trancher avant d'indexer.

## 9. Feuille de route

1. **Graphe du code + git + fiche fichier.** Import graphify ou php-parser,
   ingestion des commits, page fiche fichier. Aucun LLM.
2. **Docs métier.** Extraction des règles par LLM, liens explicites `RG-xxx`,
   écran de validation et d'édition des liens (lier / délier).
3. **Mémoire.** API d'ingestion de conversations, extraction, dédup, arêtes
   `about` vers les fichiers.
4. **Clients.** API de recherche, serveur MCP, adaptateur Claude Code (hooks).
5. **Connecteurs + synchro planifiée.** GitLab / GitHub d'abord, puis Jira,
   Notion, serveurs MCP tiers. Écran d'activité.
6. **Wiki généré** (plan, pages citées, Ask, codemap, jardinage), vue graphe,
   communautés.

### Avancement au 2026-11-08

| Étape | Fait | Reste |
|---|---|---|
| 1. Code + git | Import graphify (`app:ingest:code`), fiche fichier (symboles, dépendances, voisinage, règles, docs, mémoires), recherche live | Ingestion git (`git log`), commits sur la fiche. Bloqué : le conteneur ne voit pas les dépôts de l'hôte (monter un dossier en lecture seule ou lancer `git log` côté hôte) |
| 2. Docs métier | Dépôt md / pdf / docx, dossiers, rattachement à N projets, lecture Markdown et PDF, règles `RG-xxx` (démo), lien document ↔ code éditable | Extraction du texte pdf / docx, extraction des règles par LLM, liens explicites `RG-xxx` dans le code, validation / rejet des liens inférés |
| 3. Mémoire | Onglet Mémoires façon mem0 (types, historique ADD / UPDATE, liens vers code / docs / règles), `add_memory` MCP, import depuis mem0 par connecteur | Extraction par LLM depuis les transcripts, dédup par LLM, liens `linked_to` entre mémoires |
| 4. Clients | Serveur MCP (6 outils), HTTP + STDIO | API REST, recherche sémantique, adaptateur Claude Code (hooks), authentification |
| 5. Connecteurs | Connecteurs MCP génériques (ressources → documents, outil → document ou mémoires), modèles DeepWiki, Context7, mem0, GitHub, Linear, `app:connector:sync` | Scheduler + Messenger, suppression des éléments disparus, OAuth (Notion, Atlassian, GitLab), connecteurs API directs, écran d'activité |
| 6. Wiki | Vue graphe de voisinage (1 ou 2 sauts) | Wiki, communautés dans le front, Ask, codemap, jardinage |

Prochaines étapes proposées, dans l'ordre :

1. Authentification minimale (jeton d'API pour MCP, login pour le front) et port
   publié en `127.0.0.1`.
2. Clés Mistral / OpenRouter, choix du modèle d'embedding et de la dimension,
   indexation vectorielle des documents et mémoires (`search` sémantique).
3. Extraction des règles depuis les documents et des mémoires depuis les
   transcripts, avec écran de validation.
4. Ingestion git, puis Scheduler pour les connecteurs.

## 10. Décisions

### Prises le 2026-11-08

- Persistance : Doctrine ORM, migrations. La table `default` du store IA est
  exclue du schéma Doctrine (`schema_filter`).
- Interface : Twig + Symfony UX, style Linear / Notion. Serveur MCP pour les
  agents ; API REST plus tard.
- Multi-projet dès le départ. Documents et mémoires partagés entre N projets.
- Graphe du code : import graphify (tree-sitter, multi-langage, sans LLM) plutôt
  que php-parser (PHP seul).
- Client MCP : SDK officiel `mcp/sdk` piloté par le code, car la config
  `clients` de `symfony/mcp-bundle` est statique.
- LLM : Mistral et / ou OpenRouter, choisis par variables d'environnement
  (`LLM_PLATFORM`, `LLM_MODEL`, `EMBEDDING_PLATFORM`, `EMBEDDING_MODEL`).
- Secrets des connecteurs : références `env:NOM` lues dans `.env.local`.

### Encore ouvertes

- Auth : SecurityBundle est installé (exigé par ai-bundle) mais rien n'est
  protégé. Jeton d'API pour MCP, et quoi pour le front ?
- Multi-utilisateur : scoping des mémoires par utilisateur (comme `user_id` de mem0) ?
- Modèle d'embedding et dimension de la colonne vecteur.
- Premier connecteur API direct : GitLab ou GitHub ?
- OAuth pour les connecteurs MCP (Notion, Atlassian, GitLab) : à implémenter ou
  passer par des serveurs locaux ?
- Dédup des mémoires : seulement exacte aujourd'hui ; LLM comme mem0 ?
- Wiki : généré en tâche planifiée ou à la demande ?
- Wiki : stocké en base seulement, ou exporté aussi en Markdown dans le dépôt
  pour relecture en revue git (approche CodeAlmanac) ?

## 11. État actuel du repo (2026-11-08)

Socle :

- Symfony 8.1, PHP 8.5 (image `dunglas/frankenphp:1-php8.5`), Docker.
  Commandes dans le conteneur : `docker compose exec -T php bin/console ...`.
- Postgres 18 + pgvector. Doctrine ORM 3, 3 migrations.
- `symfony/ai-bundle` 0.15, store `postgres.default` (pas encore alimenté),
  plateformes Mistral et OpenRouter configurées, clés absentes.
- Symfony UX (Twig / Live Components, Stimulus, AssetMapper, Dropzone), Form,
  Validator, SecurityBundle (sans règle d'accès), `symfony/mcp-bundle`.
- 36 tests (PHPUnit, `bin/phpunit`) ; base de test `app_test`.

Front (`http://localhost:8080`) :

- Sidebar : Tous les documents, Toutes les mémoires, Connexions, puis par projet
  Documents, Règles métier, Mémoires, Code.
- Documents : arborescence de dossiers, dépôt md / pdf / docx (32 Mo), page
  façon Notion avec propriétés éditables (projets, dossier, code lié).
- Règles métier : liste filtrable, page règle (énoncé, sources, code).
- Mémoires : création, filtres, édition avec historique, liens.
- Code : recherche live, fiche fichier, vue graphe (Cytoscape.js).
- Connexions : connecteurs MCP, modèles de fournisseurs, état des LLM.

Commandes :

- `app:project:create`, `app:ingest:code` (graph.json de graphify, `--no-debug`
  sur les gros dépôts).
- `app:demo <projet>` / `--clear` : documents, règles et mémoires fictifs.
- `app:connector:sync [id]`, `app:llm:check`, `mcp:server` (STDIO).

Données de dev : projet `claude-strava-coach` (218 fichiers, 2222 symboles,
4802 arêtes) et sa démo chargée.

## 12. Références

- mem0 : https://github.com/mem0ai/mem0 (prompts dans `mem0/configs/prompts.py`,
  pipeline dans `mem0/memory/main.py`)
- graphify : https://github.com/Graphify-Labs/graphify
- Symfony AI : https://github.com/symfony/ai (tag `v0.15.0`)
- Plugin mem0 Claude Code (exemple d'adaptateur client) :
  `~/.claude/plugins/cache/mem0-plugins/mem0/0.2.15/hooks.json`
- MCP : SDK PHP https://github.com/modelcontextprotocol/php-sdk,
  `symfony/mcp-bundle` (dépôt Symfony AI).
- Serveurs MCP des modèles de connecteurs : DeepWiki
  https://docs.devin.ai/work-with-devin/deepwiki-mcp, Context7
  https://context7.com/docs/resources/all-clients, GitHub
  https://github.com/github/github-mcp-server, mem0 `https://mcp.mem0.ai/mcp/`
  (config du plugin mem0), Linear https://linear.app/docs/mcp.
- Wikis générés depuis un dépôt :
  - DeepWiki : https://deepwiki.com/, doc https://docs.devin.ai/work-with-devin/deepwiki,
    MCP https://docs.devin.ai/work-with-devin/deepwiki-mcp
  - deepwiki-open (open source, auto-hébergeable) :
    https://github.com/AsyncFuncAI/deepwiki-open (prompts dans
    `api/services/wiki/prompts.py`, codemap dans `api/services/codemap.py`)
  - Google Code Wiki : https://codewiki.google,
    https://developers.googleblog.com/introducing-code-wiki-accelerating-your-code-understanding/
  - CodeAlmanac : https://www.codealmanac.com/,
    https://github.com/AlmanacCode/codealmanac
