"""Branche Claude Code sur Mnemo, la mémoire du projet.

  session-start  SessionStart : écrit les skills, commandes et sous-agents du projet dans .claude/, puis ligne de
                 statut, consigne d'enregistrer les mémoires via `add_memory` sans attendre qu'on le demande, puis
                 mémoire du projet (conventions, décisions, règles validées)
  file-read      PostToolUse Read : injecte règles, documents et mémoires liés au fichier lu
  capture        SessionEnd, PreCompact : envoie la partie nouvelle du transcript à `learn`, en arrière-plan
  capture-run    le travail de capture lui-même, lancé détaché par `capture`

Le projet est celui que nomme MNEMO_PROJECT (son slug dans le graphe). Sans cette variable, c'est celui du dépôt
distant `origin`, sinon celui dont le chemin contient le dossier courant (même machine que le serveur seulement).
Un projet inconnu est créé par le serveur au début de la session, nommé d'après le slug, le dépôt ou le dossier.
Serveur absent ou erreur : le hook ne dit rien et sort en 0, il ne gêne jamais la session.

Les fichiers de .claude/ écrits par le plugin sont notés dans un manifeste (dossier d'état du plugin) : seuls ceux-là
sont réécrits ou retirés quand ils changent ou disparaissent dans Mnemo, un fichier local du même nom n'est jamais
touché. Ils sont listés dans .git/info/exclude, entre deux marqueurs : git les ignore sans rien commiter.

Variables, à poser dans le bloc "env" de .claude/settings.json du projet (la clé dans settings.local.json) :
  MNEMO_URL       adresse du serveur, défaut http://localhost:8080
  MNEMO_PROJECT   slug du projet dans le graphe, pour ne pas le déduire du dépôt
  MNEMO_API_KEY   clé d'API, quand le serveur en demande une
  MNEMO_DISABLED  1 coupe tout
  MNEMO_CAPTURE   0 coupe l'envoi des transcripts
Bibliothèque standard seulement.
"""

import hashlib
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

READ_TIMEOUT = 2
LEARN_TIMEOUT = 300
MAX_CHARS = 60_000
MIN_CHARS = 400

# Les transcripts contiennent des secrets : masqués avant l'envoi, le serveur les transmet ensuite au LLM.
SECRETS = [
    (re.compile(r"(?i)\b((?:api[_-]?key|token|secret|password|passwd|authorization)\w*)(\s*[:=]\s*)(['\"]?)[^\s'\",;]+"), r"\1\2\3[MASQUÉ]"),
    (re.compile(r"(?i)\bbearer\s+[A-Za-z0-9._~+/=-]{8,}"), "Bearer [MASQUÉ]"),
    (re.compile(r"\b(?:sk|pk|rk|m0|ghp|gho|ghs|github_pat|glpat|xox[abp])[-_][A-Za-z0-9_-]{12,}"), "[MASQUÉ]"),
    (re.compile(r"\bAKIA[0-9A-Z]{16}\b"), "[MASQUÉ]"),
    (re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----", re.S), "[CLÉ PRIVÉE MASQUÉE]"),
    # Longue chaîne mêlant lettres et chiffres : jeton probable. Pas de « / » pour garder les chemins, et un SHA de commit
    # (40 caractères hexadécimaux) n'est pas un secret.
    (re.compile(r"\b(?![0-9a-f]{40}\b)(?=[A-Za-z0-9+_-]*\d)(?=[A-Za-z0-9+_-]*[A-Za-z])[A-Za-z0-9+_-]{40,}={0,2}"), "[MASQUÉ]"),
]
NOISE = re.compile(r"<system-reminder>.*?</system-reminder>", re.S)
ASSET_PATH = re.compile(r"^\.claude/(?:skills/[a-z0-9-]+/SKILL|commands/[a-z0-9-]+|agents/[a-z0-9-]+)\.md$")
EXCLUDE_BEGIN, EXCLUDE_END = "# mnemo:begin", "# mnemo:end"
REMOTE = re.compile(r"^(?:[a-z][a-z0-9+.-]*://)?(?:[^@/]+@)?(?P<host>[a-z0-9][a-z0-9.-]+)(?::\d+(?=/))?[:/](?P<path>[^/?#][^?#]*?)(?:\.git)?/?$", re.I)


def base_url() -> str:
    return (os.environ.get("MNEMO_URL") or "http://localhost:8080").rstrip("/")


def scope(data: dict) -> dict:
    """Ce qui désigne le projet au serveur : le dossier de travail, le dépôt distant, et le slug s'il est configuré."""
    cwd = cwd_of(data)
    project, repository = os.environ.get("MNEMO_PROJECT"), repository_of(cwd)
    return {"cwd": cwd} | ({"repository": repository} if repository else {}) | ({"project": project} if project else {})


def request(path: str, params: dict | None = None, payload: dict | None = None, timeout: float = READ_TIMEOUT) -> tuple[int, dict]:
    url = base_url() + path + ("?" + urllib.parse.urlencode(params) if params else "")
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    headers = {"Accept": "application/json", "Content-Type": "application/json"}
    if os.environ.get("MNEMO_API_KEY"):
        headers["Authorization"] = "Bearer " + os.environ["MNEMO_API_KEY"]
    req = urllib.request.Request(url, data=data, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read() or b"{}")
        except ValueError:
            return e.code, {}
    except (OSError, ValueError):
        return 0, {}


def emit(event: str, context: str) -> None:
    if context.strip():
        out = {"hookSpecificOutput": {"hookEventName": event, "additionalContext": context}}
        sys.stdout.buffer.write(json.dumps(out, ensure_ascii=False).encode("utf-8"))


def cwd_of(data: dict) -> str:
    cwd = data.get("cwd")
    return os.environ.get("CLAUDE_PROJECT_DIR") or (cwd if isinstance(cwd, str) else "") or os.getcwd()


def git(cwd: str, *args: str) -> str:
    try:
        result = subprocess.run(["git", "-C", cwd, *args], capture_output=True, text=True, timeout=READ_TIMEOUT)
    except (OSError, subprocess.SubprocessError):
        return ""
    return result.stdout.strip() if result.returncode == 0 else ""


def branch_of(cwd: str) -> str:
    return git(cwd, "branch", "--show-current") or "-"


def repository_of(cwd: str) -> str:
    """Le dépôt distant `origin` en « hôte/propriétaire/dépôt », sans identifiants ; vide s'il n'y en a pas."""
    match = REMOTE.match(git(cwd, "remote", "get-url", "origin"))
    return f"{match['host']}/{match['path']}".lower() if match else ""


def sync_assets(data: dict) -> None:
    cwd = cwd_of(data)
    status, body = request("/api/agent-assets", scope(data))
    files = body.get("files")
    if status != 200 or not isinstance(files, list):
        return
    root = Path(git(cwd, "rev-parse", "--show-toplevel") or cwd).resolve()
    manifest = state_dir() / f"assets-{hashlib.sha1(str(root).encode('utf-8')).hexdigest()}.json"
    try:
        managed = set(json.loads(manifest.read_text(encoding="utf-8")))
    except (OSError, ValueError, TypeError):
        managed = set()

    served = {}
    for file in files:
        if isinstance(file, dict) and isinstance(file.get("path"), str) and isinstance(file.get("content"), str) and ASSET_PATH.match(file["path"]):
            served[file["path"]] = file["content"]

    kept = set()
    for path, content in served.items():
        target = root / path
        if target.exists() and path not in managed:
            continue
        if not target.exists() or target.read_text(encoding="utf-8") != content:
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(content, encoding="utf-8")
        kept.add(path)

    for path in managed - kept:
        target = root / path
        target.unlink(missing_ok=True)
        if target.parent.name not in ("commands", "agents") and target.parent.is_dir() and not any(target.parent.iterdir()):
            target.parent.rmdir()

    manifest.write_text(json.dumps(sorted(kept)), encoding="utf-8")
    exclude_assets(root, sorted(kept))


def exclude_assets(root: Path, paths: list[str]) -> None:
    git_path = git(str(root), "rev-parse", "--git-path", "info/exclude")
    if not git_path:
        return
    exclude = Path(git_path) if Path(git_path).is_absolute() else root / git_path
    text = exclude.read_text(encoding="utf-8") if exclude.exists() else ""
    text = re.sub(rf"\n?{EXCLUDE_BEGIN}\n.*?{EXCLUDE_END}\n?", "\n", text, flags=re.S).rstrip("\n")
    if paths:
        text += ("\n" if text else "") + "\n".join([EXCLUDE_BEGIN, *("/" + path for path in paths), EXCLUDE_END])
    exclude.parent.mkdir(parents=True, exist_ok=True)
    exclude.write_text(text + "\n" if text else "", encoding="utf-8")


def session_start(data: dict) -> None:
    try:
        sync_assets(data)
    except Exception:  # une sync ratée ne doit pas priver la session de sa mémoire
        pass
    cwd = cwd_of(data)
    status, body = request("/api/context", scope(data))
    project = body.get("project")
    if status != 200 or not isinstance(project, str) or not project:
        return
    line = f"Mnemo Active | project={project} | branch={branch_of(cwd)} | memories={body.get('memories', 0)}"
    if body.get("created"):
        line += " | projet créé"
    emit("SessionStart", f"""## Mnemo Active

`{line}`

IMPORTANT : dans ta PREMIÈRE réponse, affiche cette ligne de statut telle quelle en ouverture :

```
{line}
```

N'attends pas que l'utilisateur le demande : après chaque tâche, décision ou échange qui apprend quelque chose de durable sur ce projet, enregistre-le toi-même avec l'outil MCP `add_memory` du serveur mnemo, au fil de l'eau et pas seulement en fin de session.
- Toujours `projects: ["{project}"]` et `agent: "claude-code"` ; `files` : chemins relatifs au dépôt concernés.
- `type` : `decision`, `convention`, `task_learning` (bug corrigé, piège, solution), `preference` (de l'utilisateur) ou `fact`.
- Une mémoire = un fait autonome en une ou deux phrases, compréhensible sans la conversation. Jamais de secret.
- Vise 1 à 3 mémoires par échange substantiel ; rien pour une simple question ou un fait déjà présent ci-dessous.
- Avant une tâche non triviale ou quand l'utilisateur évoque un travail passé, cherche d'abord avec l'outil `search` (`project: "{project}"`).

{body.get("context") or ""}""")


def file_read(data: dict) -> None:
    tool_input = data.get("tool_input")
    path = tool_input.get("file_path") if isinstance(tool_input, dict) else None
    if not isinstance(path, str) or not path:
        return
    status, body = request("/api/file-context", scope(data) | {"path": path})
    if status == 200:
        emit("PostToolUse", str(body.get("context") or ""))


def capture(data: dict) -> None:
    """Rend la main tout de suite : l'extraction prend plusieurs secondes et ne doit pas retarder Claude."""
    if os.environ.get("MNEMO_CAPTURE") == "0":
        return
    options: dict = {"stdin": subprocess.PIPE, "stdout": subprocess.DEVNULL, "stderr": subprocess.DEVNULL}
    if os.name == "nt":
        options["creationflags"] = subprocess.DETACHED_PROCESS | subprocess.CREATE_NEW_PROCESS_GROUP
    else:
        options["start_new_session"] = True
    child = subprocess.Popen([sys.executable, __file__, "capture-run"], **options)
    child.stdin.write(json.dumps(data).encode("utf-8"))
    child.stdin.close()


def state_dir() -> Path:
    d = Path(os.environ.get("CLAUDE_PLUGIN_DATA") or Path.home() / ".cache" / "mnemo-plugin") / "state"
    d.mkdir(parents=True, exist_ok=True)
    return d


def conversation(lines: list[str]) -> str:
    """Prompts et réponses en texte ; outils, réflexion, commandes locales et rappels système écartés."""
    parts = []
    for line in lines:
        try:
            entry = json.loads(line)
        except ValueError:
            continue
        if not isinstance(entry, dict) or entry.get("type") not in ("user", "assistant") or entry.get("isMeta") or entry.get("isSidechain"):
            continue
        content = (entry.get("message") or {}).get("content")
        if isinstance(content, str):
            texts = [content]
        elif isinstance(content, list):
            texts = [b.get("text", "") for b in content if isinstance(b, dict) and b.get("type") == "text"]
        else:
            continue
        for text in texts:
            text = NOISE.sub("", text).strip()
            if text and not text.startswith(("<local-command", "<command-")):
                parts.append(("Utilisateur : " if entry["type"] == "user" else "Agent : ") + text)
    return "\n\n".join(parts)


def redact(text: str) -> str:
    for pattern, replacement in SECRETS:
        text = pattern.sub(replacement, text)
    return text


def capture_run(data: dict) -> None:
    transcript, session = data.get("transcript_path"), data.get("session_id")
    if not isinstance(transcript, str) or not isinstance(session, str) or not Path(transcript).is_file():
        return
    state = state_dir() / f"{re.sub(r'[^A-Za-z0-9_-]', '_', session)}.json"
    try:
        done = int(json.loads(state.read_text(encoding="utf-8")).get("lines", 0))
    except (OSError, ValueError):
        done = 0

    lines = Path(transcript).read_text(encoding="utf-8", errors="replace").splitlines()
    text = redact(conversation(lines[done:]))
    if len(text) < MIN_CHARS:
        return
    label = f"claude-code {session[:8]} ({data.get('hook_event_name') or 'capture'})"
    status, body = request("/api/learn", payload=scope(data) | {"text": text[-MAX_CHARS:], "label": label}, timeout=LEARN_TIMEOUT)

    (state_dir() / "last.json").write_text(json.dumps({"session": session, "status": status, "response": body}, ensure_ascii=False), encoding="utf-8")
    # 422 : projet que cette clé ne peut ni atteindre ni créer, inutile de renvoyer ces lignes. Autre échec : réessayé à la prochaine capture.
    if status in (200, 422):
        state.write_text(json.dumps({"lines": len(lines)}), encoding="utf-8")


COMMANDS = {"session-start": session_start, "file-read": file_read, "capture": capture, "capture-run": capture_run}


def main() -> int:
    if len(sys.argv) < 2 or sys.argv[1] not in COMMANDS or os.environ.get("MNEMO_DISABLED") == "1":
        return 0
    try:
        data = json.loads(sys.stdin.buffer.read() or b"{}")
    except ValueError:
        return 0
    if isinstance(data, dict):
        try:
            COMMANDS[sys.argv[1]](data)
        except Exception:  # un hook qui plante ne doit jamais casser la session
            return 0
    return 0


if __name__ == "__main__":
    sys.exit(main())
