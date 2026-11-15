# graphe

```
                       _
  __ _ _ __ __ _ _ __ | |__   ___
 / _` | '__/ _` | '_ \| '_ \ / _ \
| (_| | | | (_| | |_) | | | |  __/
 \__, |_|  \__,_| .__/|_| |_|\___|
 |___/          |_|

      [code]----implemented_by----[regle RG-001]
        |                               |
     about                           defines
        |                               |
    [memoire]----linked_to----[document metier]
```

Graphe de connaissance projet, auto-hébergé en Symfony. Il relie le code, les
documents métier, les règles métier et les mémoires d'agents dans un seul graphe,
avec la provenance de chaque lien, et le restitue :

- aux humains, via un front Twig (`http://localhost:8080`) ;
- à n'importe quel agent IA (Claude Code, Cursor, Codex...), via un serveur MCP et
  une petite API HTTP.

L'idée complète, l'état des lieux et les décisions sont dans
[`IDEE-PROJET.md`](IDEE-PROJET.md).

## Principes

- **Chaque arête est justifiée** : confiance (`EXTRACTED`, `INFERRED`, `VERIFIED`),
  source (`ast`, `git`, `api`, `llm`, `human`) et preuve.
- **Déterministe d'abord, LLM ensuite** : AST et imports ne coûtent rien ; le LLM ne
  sert que pour l'extraction de mémoires, la vérification et les zones d'ombre.
- **Humain dans la boucle** : tout ce qu'un LLM propose passe par la file
  `/proposals`. Une mémoire n'est jamais supprimée automatiquement, elle est archivée.
- **Générique** : aucun couplage à un client IA particulier.

## Stack

- PHP >= 8.4 (image `dunglas/frankenphp`, PHP 8.5), Symfony 8.1
- Doctrine ORM 3 + migrations, Postgres 18 + pgvector
- Symfony AI 0.15 : plateforme OpenRouter, store Postgres, `symfony/mcp-bundle`
- Twig, AssetMapper, Stimulus, UX Live Component / Twig Component / Dropzone
- Docker : `compose.yaml` (services `php` sur le port 8080, `database` sur 5432)

## Installation

```bash
docker compose up -d --build
docker compose exec -T php composer install
docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
```

Clé LLM dans `.env.local` (git-ignoré), jamais dans `.env` :

```dotenv
OPENROUTER_API_KEY=...

LLM_PLATFORM=openrouter
LLM_MODEL=mistralai/mistral-small-2603
EMBEDDING_PLATFORM=openrouter
EMBEDDING_MODEL=mistralai/mistral-embed-2312
```

Vérifier la configuration (chat, sortie JSON structurée, embeddings) :

```bash
docker compose exec -T php bin/console app:llm:check
```

Les commandes ci-dessous s'écrivent `bin/console ...` ; dans Docker, préfixer par
`docker compose exec -T php`.

## Connexion OAuth

Désactivée par défaut (`APP_ACCESS_ROLE=PUBLIC_ACCESS`, aucun fournisseur). Pour l'activer :

1. Créer une application OAuth chez le fournisseur (GitHub : Settings → Developer settings → OAuth Apps) avec
   l'adresse de retour `https://<domaine>/oauth/github/check` (`gitlab` ou `google` pour les autres ; en local
   `http://localhost:8080/oauth/github/check`).
2. Poser dans `.env.local` (ou `.env.prod.local` / Dokploy sur le serveur) :

```dotenv
APP_ACCESS_ROLE=ROLE_USER
OAUTH_GITHUB_CLIENT_ID=...
OAUTH_GITHUB_CLIENT_SECRET=...
# Emails vérifiés créés super admin à leur première connexion ; les autres attendent une activation dans /admin/users
APP_ADMIN_EMAILS=moi@exemple.com
```

3. Recharger : `/login` propose « Continuer avec GitHub ». Le compte `admin` à mot de passe
   (`APP_ADMIN_PASSWORD_HASH`) reste disponible en secours.

La même application GitHub sert aux connecteurs de dépôt : sur la page d'un connecteur
« GitHub (code et historique) », « Connecter avec GitHub » demande l'accès aux dépôts (scope `repo`, le seul
qu'une OAuth App offre pour les dépôts privés) et enregistre le jeton chiffré en base avec une clé dérivée de
`APP_SECRET`. Il remplace alors les en-têtes saisis, pour l'API comme pour le clone, et n'est jamais envoyé
ailleurs qu'à github.com. Changer `APP_SECRET` oblige à reconnecter le compte.

## Bot de revue (GitHub App)

Le bot commente chaque pull request d'un dépôt branché avec ce que le graphe sait des fichiers modifiés, et pose
un check `graphe/rules`. Il agit sous l'identité d'une GitHub App, distincte de l'application OAuth ci-dessus.

1. Créer l'application (GitHub : Settings → Developer settings → GitHub Apps → New GitHub App) :
   - Webhook URL `https://<domaine>/webhooks/github`, avec un secret (`openssl rand -hex 32`) ;
   - permissions du dépôt : Pull requests (lecture et écriture), Checks (lecture et écriture), Contents (lecture),
     Metadata (lecture) ;
   - événements : Pull request, Push.
2. Générer une clé privée et poser dans `.env.local` (ou Dokploy) :

```dotenv
GITHUB_APP_ID=123456
# Le PEM, ou son base64 sur une ligne : base64 -i app.private-key.pem
GITHUB_APP_PRIVATE_KEY=...
GITHUB_APP_WEBHOOK_SECRET=...
# Nom de l'application dans son URL (github.com/apps/<slug>), pour le lien « installer »
GITHUB_APP_SLUG=graphe-projet
```

3. Installer l'application sur les dépôts voulus, puis sur la page de leur connecteur
   « GitHub (code et historique) » : « Activer la revue des PR » (argument `review: true`).

Ce que font les événements, traités par le worker :

- push sur la branche par défaut : synchronisation du connecteur (déclencheur « webhook » dans l'activité). La
  synchronisation planifiée reste utile pour rattraper un événement manqué.
- pull request ouverte, mise à jour ou modifiée (hors brouillon) : un commentaire unique, réécrit à chaque push,
  liste les règles métier touchées et leurs zones d'ombre, les mémoires liées aux fichiers, l'historique récent, et
  ce que le diff contredit d'après le LLM (chaque remarque cite sa mémoire ou sa règle ; `review_llm: false` pour
  s'en passer). Une pull request dont le graphe ne sait rien ne reçoit pas de commentaire.
- chaque remarque du LLM qui vise une ligne ajoutée est aussi posée sur cette ligne, dans une review « Comment » qui
  ne bloque jamais la fusion. À chaque push, les remarques en ligne précédentes du bot sont effacées, sauf celles
  auxquelles quelqu'un a répondu.
- le check `graphe/rules` avertit quand une règle validée est touchée sans fichier de test dans le diff ni mention
  de son code (RG-042) dans le titre, la description ou un commit ; `review_check: "fail"` le fait échouer.

Le commentaire est visible de tous ceux qui lisent la pull request : seules les mémoires partagées avec le projet
y figurent, jamais les mémoires privées ou de groupe. Sur un dépôt public, activer la revue rend ces mémoires
publiques. `bin/console app:review <slug> <numéro> --dry-run` affiche le commentaire sans rien publier.

## Workflow

```
  1. projet        app:project:create, ou créé par le plugin à la première session
        |
  2. ingestion     app:ingest:code      (graphe AST graphify)
        |          app:ingest:context   (banque AIDD, CLAUDE.md, AGENTS.md...)
        |          front /documents     (md, pdf, docx)
        |          app:connector:sync   (connecteurs MCP : DeepWiki, Context7, mem0...)
        |          connecteur GitHub / GitLab (clone + graphify + docs + historique, dans le worker)
        |
  3. apprentissage app:learn / outil MCP learn / POST /api/learn
        |
  4. validation    /proposals           (appliquer ou rejeter)
        |
  5. entretien     app:garden, app:verify, app:rule:shadow
        |
  6. restitution   app:export:memory-block, serveur MCP, /api/context
```

### 1. Déclarer un projet

Un projet = un dépôt git. Le chemin sert à retrouver le projet depuis le dossier
de travail d'un agent.

```bash
bin/console app:project:create <slug> "<Nom>" <chemin-du-depot>
```

Avec le plugin Claude Code, la commande est facultative : un projet inconnu est
créé à la première session. Il est reconnu par le dépôt distant `origin`
(`hôte/propriétaire/dépôt`), sinon par le nom du dossier ; `GRAPHE_PROJECT`
impose un slug. Le projet rejoint les groupes du propriétaire de la clé ; une
clé limitée à certains projets ne crée rien.

### 2. Ingérer

```bash
# Graphe du code (sans LLM), depuis graphify
graphify extract --code-only
bin/console app:ingest:code <slug> graphify-out/graph.json

# Banque de mémoire AIDD et conventions d'agents (sans LLM, ré-import sans effet)
bin/console app:ingest:context <slug> <chemin-du-depot>

# Connecteurs MCP configurés dans /connectors
bin/console app:connector:sync [id]
```

Sur un serveur, le connecteur « GitHub / GitLab (code et historique) » fait les deux
premières étapes tout seul : avec `"code_graph": true` dans ses arguments, le
worker clone le dépôt (clone superficiel dans `var/repos/`, branche par défaut ou
`"branch"`), lance `graphify extract --code-only`, importe le graphe, les
conventions d'agents, les `README*.md` et `docs/**/*.md`, puis l'historique. Tant
que le commit de tête n'a pas changé, l'étape code est sautée. Le jeton du
connecteur sert aussi au clone ; il n'est écrit ni dans l'URL ni dans le clone.

Les documents métier se déposent dans le front (`/documents`), avec dossiers et
rattachement à plusieurs projets. Pour explorer sans données réelles :
`bin/console app:demo <slug>` (`--clear` pour retirer).

### 3. Extraire des mémoires

Le LLM extrait les apprentissages durables d'une source, les note sur 4 axes
(durabilité, réutilisation, adéquation, risque), écarte ce qui est sous 4 et compare
le reste aux mémoires proches (`new`, `covered`, `updates`, `supersedes`, `retracts`).
Rien n'est écrit directement : tout devient une proposition.

```bash
bin/console app:learn <slug> <conversation|file|diff|review> <label> --file=<chemin>
git diff main...feature | bin/console app:learn <slug> diff "main...feature"
```

### 4. Valider

Ouvrir `/proposals` : appliquer (texte modifiable) ou rejeter chaque proposition.

### 5. Entretenir

```bash
# Rapport de dérive (ne modifie rien). --llm : propose doublons et contradictions
bin/console app:garden <slug> [--llm]

# Vérifie les mémoires contre le graphe ; les réfutées sont proposées au retrait
bin/console app:verify <slug> [--limit=20]

# Zones d'ombre des règles métier (une question à poser au métier par trou)
bin/console app:rule:shadow <slug> [RG-001]
```

Équivalents dans le front : `/projects/<slug>/garden`, bouton "Vérifier" sur une
mémoire, bouton "Zones d'ombre" sur une règle.

### 6. Restituer aux agents

Bloc mémoire (conventions, décisions, règles validées) écrit entre marqueurs dans
les fichiers de contexte ; seul le texte entre marqueurs est réécrit :

```bash
bin/console app:export:memory-block <slug> --write=../repo/CLAUDE.md --write=../repo/AGENTS.md
```

## Commandes

| Commande | Rôle | LLM |
|---|---|---|
| `app:project:create <slug> <nom> <chemin>` | Déclarer un projet | non |
| `app:ingest:code <slug> <graph.json>` | Importer le graphe graphify | non |
| `app:ingest:context <slug> <chemin>` | Importer banque AIDD et conventions d'agents | non |
| `app:connector:sync [id]` | Synchroniser un ou tous les connecteurs (MCP, GitHub, GitLab) | non |
| `app:demo <slug> [--clear]` | Charger / retirer des données de démo | non |
| `app:learn <slug> <kind> <label> [--file=]` | Extraire des mémoires en propositions | oui |
| `app:garden <slug> [--llm]` | Rapport de dérive, doublons et contradictions | option |
| `app:verify <slug> [--limit=20]` | Vérifier les mémoires contre le graphe | oui |
| `app:rule:shadow <slug> [code]` | Zones d'ombre des règles | oui |
| `app:review <slug> <numéro> [--dry-run]` | Revue d'une pull request par le bot (commentaire et check) | option |
| `app:export:memory-block <slug> [--write=]` | Afficher ou écrire le bloc mémoire | non |
| `app:llm:check` | Tester chat et embeddings | oui |
| `mcp:server` | Serveur MCP en STDIO | - |

## Brancher un agent

### Serveur MCP

- HTTP : `http://localhost:8080/mcp`
- STDIO : `docker compose exec -T php bin/console mcp:server`

| Outil | Rôle |
|---|---|
| `list_projects` | Projets, compteurs et capacités détectées |
| `search` | Recherche par mots-clés : mémoires, documents, règles, code |
| `file_context` | Règles, documents, mémoires et dépendances d'un fichier |
| `get_document` | Lire un document métier et ses liens |
| `memory_block` | Bloc mémoire Markdown du projet |
| `learn` | Envoyer une source à l'extraction (propositions à valider) |
| `add_memory` | Écrire une mémoire directement, liée au code et aux règles |
| `import_document` | Importer un document Markdown |

### API HTTP (hooks clients)

Le projet est trouvé par le dossier de travail (`cwd`) de l'agent.

| Endpoint | Rôle |
|---|---|
| `GET /api/context?cwd=...` | Bloc mémoire du projet, à injecter au démarrage de session |
| `GET /api/file-context?cwd=...&path=...` | Contexte d'un fichier, à injecter à chaque lecture |
| `POST /api/learn` (`cwd`, `kind`, `label`, `text`) | Envoyer un transcript ou un diff à l'extraction |
| `GET /api/agent-assets?cwd=...` | Skills, commandes et sous-agents du projet, en fichiers à écrire dans `.claude/` |

Exemple d'adaptateur : hooks Claude Code `SessionStart` (contexte), `PostToolUse`
sur `Read` (contexte du fichier), `SessionEnd` / `PreCompact` (transcript vers `learn`).

### Skills, commandes et sous-agents du projet

La page « Skills des agents » d'un projet garde des skills, des commandes (`/nom`) et des sous-agents. Au début
de chaque session, le plugin les écrit dans le dépôt de l'agent connecté :
`.claude/skills/<nom>/SKILL.md`, `.claude/commands/<nom>.md`, `.claude/agents/<nom>.md`. Un fichier supprimé sur
le serveur est retiré du poste ; un fichier local du même nom, que le serveur n'a pas écrit, n'est jamais touché
(le plugin tient la liste de ce qu'il gère dans `.claude/graphe-assets.json`). Le serveur ne peut faire écrire que
ces trois chemins, jamais un réglage ni un hook. `GRAPHE_SYNC=0` coupe la synchronisation. Ces fichiers n'ont pas
à être commités : les ajouter au `.gitignore` du dépôt si besoin.

Qui peut modifier ces fichiers sur le serveur donne des instructions aux agents connectés au projet.

## Clé et modèles de langage

« Modèles de langage » (`/settings/llm`) règle la clé OpenRouter et les modèles de chat et d'embeddings de toute
l'instance, sans toucher au `.env`. La clé est chiffrée en base avec une clé dérivée de `APP_SECRET` et n'est plus
jamais affichée ; ce qui est saisi là l'emporte sur `OPENROUTER_API_KEY`, `LLM_MODEL` et `EMBEDDING_MODEL`, qui
restent les valeurs par défaut. La page est réservée au super admin dès que le serveur demande une connexion.

Un projet peut nommer ses propres modèles dans ses réglages ; la clé reste celle de l'instance. Chaque
enregistrement essaie la clé et les modèles par un appel court, et refuse ce qu'OpenRouter refuse.

Le modèle d'embeddings se choisit parmi ceux que le pont OpenRouter de Symfony AI connaît (liste proposée dans le
champ). En changer réindexe le contenu en arrière-plan, par le worker. Un contenu garde un vecteur par modèle
utile : celui de l'instance, et celui de chacun de ses projets qui en nomme un autre. La recherche dans un projet
utilise le modèle du projet, la recherche sur tous les projets celui de l'instance.

## Assistant du projet

« Assistant », dans le menu d'un projet, est un chat qui répond à partir du graphe (règles, mémoires, documents,
code) en citant ses sources, et qui écrit quand on le lui demande : une règle métier, créée « à valider » et
validée ensuite sur sa page, ou une mémoire du projet. Il demande un LLM configuré qui sait appeler des outils.
La conversation vit dans la session du navigateur : elle n'est ni partagée ni conservée après la déconnexion.

## Front

| URL | Contenu |
|---|---|
| `/` | Projets |
| `/documents` | Documents métier, dossiers, dépôt de fichiers |
| `/memories` | Mémoires : création, filtres, historique, liens |
| `/proposals` | File de validation des propositions LLM |
| `/projects/<slug>/rules` | Règles métier, sources, code lié, zones d'ombre |
| `/projects/<slug>/code` | Recherche de code, fiche fichier, vue graphe |
| `/projects/<slug>/garden` | Jardinage |
| `/connectors` | Connecteurs MCP |

## Tests

```bash
docker compose exec -T php bin/phpunit
```

Base de test `app_test`. Le LLM est simulé (`tests/LlmTestTrait.php`).

## Limites actuelles

- **Aucune authentification** : front, `/api` et `/mcp` sont ouverts. Garder le
  port sur `127.0.0.1` et ne pas exposer l'application.
- Recherche par mots-clés uniquement (pas encore d'indexation vectorielle).
- Texte des PDF et docx non extrait.
- Pas encore d'ingestion git, ni de Scheduler pour les connecteurs.
- Les connecteurs STDIO (`MCP_CONNECTORS_ALLOW_STDIO=1`) exécutent une commande dans
  le conteneur : à n'activer que si tous les utilisateurs du front sont de confiance.
