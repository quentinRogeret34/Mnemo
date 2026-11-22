# Plugin Antigravity (`agy`) « mnemo »

Branche Antigravity (`agy` CLI, IDE et App) sur Mnemo, la mémoire du projet :

- serveur MCP `mnemo` (`search`, `file_context`, `add_memory`, `learn`...) ;
- `SessionStart` : mémoire du projet (conventions, décisions, règles validées) injectée au démarrage de session ;
- `PostToolUse` (`read_file|view_file`) + `PreInvocation` : règles, documents et mémoires liés aux fichiers lus ;
- `Stop` : la partie nouvelle du transcript part vers `learn` en arrière-plan, secrets masqués.
  Les apprentissages arrivent dans « À valider », rien n'est stocké sans relecture.

Dépendance : `python3` (bibliothèque standard seulement).

## Installer

### Option 1 : Installation globale via `agy`

Depuis un clone local du dépôt :

```bash
agy plugin install /chemin/vers/le/depot/agy-plugin
```

Ou depuis GitHub :

```bash
agy plugin install https://github.com/quentinRogeret34/Mnemo/tree/main/agy-plugin
```

### Option 2 : Partager par projet via `.agents/plugins.json`

Dans `.agents/plugins.json` à la racine du projet :

```json
{
  "entries": [
    { "path": "/chemin/vers/le/depot", "include_only": ["agy-plugin"] }
  ]
}
```

## Variables d'environnement (hooks)

Exposez ces variables dans votre shell (ou votre environnement de projet) avant de lancer `agy` :

```bash
export MNEMO_URL="https://mnemo.exemple.com"
export MNEMO_API_KEY="gk_..."
```

| Variable | Rôle |
|---|---|
| `MNEMO_URL` | Adresse du serveur. Défaut : `http://localhost:8080`. |
| `MNEMO_PROJECT` | Facultative : slug du projet dans le graphe, pour ne pas le déduire du dépôt (monorepo, dépôt renommé). |
| `MNEMO_API_KEY` | Clé d'API, quand le serveur est en `APP_ACCESS_ROLE=ROLE_USER`. |
| `MNEMO_DISABLED` | `1` coupe les hooks. |
| `MNEMO_CAPTURE` | `0` coupe l'envoi des transcripts. |

## Serveur MCP distant ou protégé par clé d'API

Par défaut, `mcp_config.json` pointe vers `http://localhost:8080/mcp`.
Antigravity ne développe pas les variables d'environnement (`${VAR}`) dans `mcp_config.json` : pour un serveur distant ou protégé par `MNEMO_API_KEY`, déclarez le serveur `mnemo` dans `~/.gemini/config/mcp_config.json` avec son URL et son jeton explicites :

```json
{
  "mcpServers": {
    "mnemo": {
      "url": "https://mnemo.exemple.com/mcp",
      "headers": {
        "Authorization": "Bearer gk_..."
      }
    }
  }
}
```

## Projet

Le serveur reconnaît le projet par `MNEMO_PROJECT`, sinon par le dépôt distant `origin`
(`hôte/propriétaire/dépôt`), sinon par le dossier de travail (même machine que le serveur seulement).
Un projet inconnu est créé à la première session : slug de `MNEMO_PROJECT`, sinon nom du dépôt, sinon nom du dossier.
Il rejoint les groupes du propriétaire de la clé. Une clé limitée à certains projets ne crée rien.
