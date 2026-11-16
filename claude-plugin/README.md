# Plugin Claude Code « mnemo »

Branche Claude Code sur Mnemo, la mémoire du projet :

- serveur MCP `mnemo` (`search`, `file_context`, `add_memory`, `learn`...) ;
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
    "mnemo": { "source": { "source": "github", "repo": "quentinRogeret34/Mnemo" } }
  },
  "enabledPlugins": { "mnemo@mnemo": true },
  "env": {
    "MNEMO_URL": "https://mnemo.exemple.com"
  }
}
```

Dans `.claude/settings.local.json` (non commité), la clé créée dans « Clés d'API » :

```json
{ "env": { "MNEMO_API_KEY": "gk_..." } }
```

Depuis un clone local du dépôt, remplacer la source par
`{ "source": "directory", "path": "/chemin/vers/le/depot" }`.

## Projet

Le serveur reconnaît le projet par `MNEMO_PROJECT`, sinon par le dépôt distant `origin`
(`hôte/propriétaire/dépôt`), sinon par le dossier de travail (même machine que le serveur seulement).
Un projet inconnu est créé à la première session : slug de `MNEMO_PROJECT`, sinon nom du dépôt, sinon nom du dossier.
Il rejoint les groupes du propriétaire de la clé. Une clé limitée à certains projets ne crée rien.

## Variables

| Variable | Rôle |
|---|---|
| `MNEMO_URL` | Adresse du serveur. Défaut : `http://localhost:8080`. |
| `MNEMO_PROJECT` | Facultative : slug du projet dans le graphe, pour ne pas le déduire du dépôt (monorepo, dépôt renommé). |
| `MNEMO_API_KEY` | Clé d'API, quand le serveur est en `APP_ACCESS_ROLE=ROLE_USER`. |
| `MNEMO_DISABLED` | `1` coupe les hooks. |
| `MNEMO_CAPTURE` | `0` coupe l'envoi des transcripts. |
