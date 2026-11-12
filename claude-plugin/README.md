# Plugin Claude Code « graphe »

Branche Claude Code sur le graphe de connaissance projet :

- serveur MCP `graphe` (`search`, `file_context`, `add_memory`, `learn`...) ;
- `SessionStart` : mémoire du projet (conventions, décisions, règles validées) dans le contexte ;
- `PostToolUse` sur `Read` : règles, documents et mémoires liés au fichier lu ;
- `SessionEnd` et `PreCompact` : la partie nouvelle du transcript part vers `learn`, secrets masqués.
  Les apprentissages arrivent dans « À valider », rien n'est stocké sans relecture.

Dépendance : `python3` (bibliothèque standard seulement).

## Installer dans un projet

Dans `.claude/settings.json` du projet (commité, partagé avec l'équipe) :

```json
{
  "extraKnownMarketplaces": {
    "graphe": { "source": { "source": "github", "repo": "quentinRogeret34/RAG" } }
  },
  "enabledPlugins": { "graphe@graphe": true },
  "env": {
    "GRAPHE_URL": "https://graphe.exemple.com",
    "GRAPHE_PROJECT": "slug-du-projet"
  }
}
```

Dans `.claude/settings.local.json` (non commité), la clé créée dans « Clés d'API » :

```json
{ "env": { "GRAPHE_API_KEY": "gk_..." } }
```

Depuis un clone local du dépôt, remplacer la source par
`{ "source": "directory", "path": "/chemin/vers/le/depot" }`.

## Variables

| Variable | Rôle |
|---|---|
| `GRAPHE_URL` | Adresse du serveur. Défaut : `http://localhost:8080`. |
| `GRAPHE_PROJECT` | Slug du projet dans le graphe. Sans elle, le serveur retrouve le projet par le dossier de travail, ce qui ne marche que sur la même machine. |
| `GRAPHE_API_KEY` | Clé d'API, quand le serveur est en `APP_ACCESS_ROLE=ROLE_USER`. |
| `GRAPHE_DISABLED` | `1` coupe les hooks. |
| `GRAPHE_CAPTURE` | `0` coupe l'envoi des transcripts. |
