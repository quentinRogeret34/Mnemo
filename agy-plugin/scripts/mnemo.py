"""Branche Antigravity (agy) sur Mnemo, la mémoire du projet.

  session-start   SessionStart : ligne de statut, consignes de recherche (`search`, `file_context`) et
                  d'enregistrement (`add_memory`), puis mémoire du projet (conventions, décisions, règles validées)
  file-read       PostToolUse (read/view/edit/write) : récupère règles, documents et mémoires liés au fichier
                  et les met en file pour le prochain appel modèle
  pre-invocation  PreInvocation : interroge `/api/prompt-context` une fois par nouveau prompt utilisateur et
                  injecte ce contexte ainsi que les contextes de fichiers mis en file par `file-read`
  capture         Stop : rend la main aussitôt et lance `capture-run` en arrière-plan pour envoyer la partie
                  nouvelle du transcript à `learn`
  capture-run     le travail de capture lui-même, lancé détaché par `capture`

Le projet est celui que nomme MNEMO_PROJECT (son slug dans le graphe). Sans cette variable, c'est celui du dépôt
distant `origin`, sinon celui dont le chemin contient le dossier courant (même machine que le serveur seulement).
Un projet inconnu est créé par le serveur au début de la session, nommé d'après le slug, le dépôt ou le dossier.
Serveur absent ou erreur : le hook sort proprement en 0 avec un JSON valide, il ne gêne jamais la session.

Variables d'environnement (avec repli automatique sur `../mcp_config.json` pour l'URL et le jeton Bearer) :
  MNEMO_URL       adresse du serveur, défaut tiré de mcp_config.json sinon http://localhost:8080
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
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

PLUGIN_VERSION = "0.2.0"
READ_TIMEOUT = 5
PROMPT_TIMEOUT = 5
LEARN_TIMEOUT = 300
MAX_CHARS = 60_000
MIN_CHARS = 400
MIN_PROMPT_CHARS = 12
MAX_PROMPT_CHARS = 20_000

# Les transcripts contiennent des secrets : masqués avant l'envoi, le serveur les transmet ensuite au LLM.
SECRETS = [
    (re.compile(r"(?i)\bbearer\s+[A-Za-z0-9._~+/=-]{8,}"), "Bearer [MASQUÉ]"),
    (re.compile(r"(?i)\b((?:api[_-]?key|token|secret|password|passwd|authorization)\w*)(\s*[:=]\s*)(['\"]?)[^\s'\",;]+"), r"\1\2\3[MASQUÉ]"),
    (re.compile(r"\b(?:sk|pk|rk|m0|gk|ghp|gho|ghs|github_pat|glpat|xox[abp])[-_][A-Za-z0-9_-]{12,}"), "[MASQUÉ]"),
    (re.compile(r"\bAKIA[0-9A-Z]{16}\b"), "[MASQUÉ]"),
    (re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----", re.S), "[CLÉ PRIVÉE MASQUÉE]"),
    # Longue chaîne mêlant lettres et chiffres : jeton probable. Pas de « / » pour garder les chemins, et un SHA de commit
    # (40 caractères hexadécimaux) n'est pas un secret.
    (re.compile(r"\b(?![0-9a-f]{40}\b)(?=[A-Za-z0-9+_-]*\d)(?=[A-Za-z0-9+_-]*[A-Za-z])[A-Za-z0-9+_-]{40,}={0,2}"), "[MASQUÉ]"),
]
USER_REQUEST_RE = re.compile(r"<USER_REQUEST>\s*(.*?)\s*</USER_REQUEST>", re.S)
NOISE_RE = re.compile(r"<(?:ADDITIONAL_METADATA|USER_SETTINGS_CHANGE|SYSTEM_MESSAGE|system-reminder)>.*?</(?:ADDITIONAL_METADATA|USER_SETTINGS_CHANGE|SYSTEM_MESSAGE|system-reminder)>", re.S)
REMOTE = re.compile(r"^(?:[a-z][a-z0-9+.-]*://)?(?:[^@/]+@)?(?P<host>[a-z0-9][a-z0-9.-]+)(?::\d+(?=/))?[:/](?P<path>[^/?#][^?#]*?)(?:\.git)?/?$", re.I)

_MCP_CFG_CACHE: dict | None = None


def mcp_config() -> dict:
    """Lit `../mcp_config.json` du plugin comme repli quand l'environnement shell n'expose pas MNEMO_URL/MNEMO_API_KEY."""
    global _MCP_CFG_CACHE
    if _MCP_CFG_CACHE is not None:
        return _MCP_CFG_CACHE
    try:
        cfg_path = Path(__file__).resolve().parent.parent / "mcp_config.json"
        raw = json.loads(cfg_path.read_text(encoding="utf-8"))
        server = (raw.get("mcpServers") or {}).get("mnemo")
        _MCP_CFG_CACHE = server if isinstance(server, dict) else {}
    except (OSError, ValueError, TypeError):
        _MCP_CFG_CACHE = {}
    return _MCP_CFG_CACHE


def base_url() -> str:
    env_url = (os.environ.get("MNEMO_URL") or "").strip()
    if env_url:
        return env_url.rstrip("/")
    cfg_url = mcp_config().get("url") or mcp_config().get("serverUrl")
    if isinstance(cfg_url, str) and cfg_url.strip():
        url = cfg_url.strip().rstrip("/")
        if url.endswith("/mcp"):
            url = url[:-4]
        return url.rstrip("/")
    return "http://localhost:8080"


def api_key() -> str:
    env_key = (os.environ.get("MNEMO_API_KEY") or "").strip()
    if env_key:
        return env_key
    headers = mcp_config().get("headers")
    if isinstance(headers, dict):
        for name, value in headers.items():
            if isinstance(name, str) and name.lower() == "authorization" and isinstance(value, str):
                auth = value.strip()
                if auth.lower().startswith("bearer "):
                    return auth[7:].strip()
                return auth
    return ""


def record_error(path: str, status: int, detail: str) -> None:
    try:
        payload = {
            "at": int(time.time()),
            "url": base_url() + path,
            "status": status,
            "detail": detail[:500],
        }
        (state_dir() / "last_error.json").write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    except OSError:
        pass


def scope(data: dict, prefer_repo_root: bool = False) -> dict:
    """Ce qui désigne le projet au serveur : le dossier de travail, le dépôt distant, et le slug s'il est configuré."""
    cwd = cwd_of(data)
    project, repository = os.environ.get("MNEMO_PROJECT"), repository_of(cwd)
    if prefer_repo_root and (repository or project):
        cwd = git(cwd, "rev-parse", "--show-toplevel") or cwd
    return {"cwd": cwd} | ({"repository": repository} if repository else {}) | ({"project": project} if project else {})


def request(path: str, params: dict | None = None, payload: dict | None = None, timeout: float = READ_TIMEOUT) -> tuple[int, dict]:
    url = base_url() + path + ("?" + urllib.parse.urlencode(params) if params else "")
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "User-Agent": f"mnemo-agy-plugin/{PLUGIN_VERSION}",
        "X-Mnemo-Client": "antigravity",
    }
    token = api_key()
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(url, data=data, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as e:
        raw = e.read() or b"{}"
        record_error(path, e.code, raw.decode("utf-8", errors="replace"))
        try:
            return e.code, json.loads(raw)
        except ValueError:
            return e.code, {}
    except (OSError, ValueError) as e:
        record_error(path, 0, str(e))
        return 0, {}


def emit_json(payload: dict) -> None:
    sys.stdout.buffer.write(json.dumps(payload, ensure_ascii=False).encode("utf-8"))


def emit_system_message(context: str) -> None:
    if context.strip():
        emit_json({"injectSteps": [{"systemMessage": {"systemMessage": context}}]})
    else:
        emit_json({})


def cwd_of(data: dict) -> str:
    cwd = data.get("cwd")
    if isinstance(cwd, str) and cwd:
        return cwd
    workspaces = data.get("workspacePaths")
    if isinstance(workspaces, list) and workspaces and isinstance(workspaces[0], str) and workspaces[0]:
        return workspaces[0]
    return os.getcwd()


def conversation_id_of(data: dict) -> str:
    cid = data.get("conversationId")
    return cid if isinstance(cid, str) and cid else ""


def safe_id(raw: str) -> str:
    return re.sub(r"[^A-Za-z0-9_-]", "_", raw)


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


def state_dir() -> Path:
    d = Path(os.environ.get("PLUGIN_DATA") or Path.home() / ".cache" / "mnemo-agy-plugin") / "state"
    d.mkdir(parents=True, exist_ok=True)
    return d


def session_start(data: dict) -> None:
    cwd = cwd_of(data)
    status, body = request("/api/context", scope(data))
    project = body.get("project")
    if status != 200 or not isinstance(project, str) or not project:
        emit_json({})
        return
    line = f"Mnemo Active | project={project} | branch={branch_of(cwd)} | memories={body.get('memories', 0)}"
    if body.get("created"):
        line += " | projet créé"
    emit_system_message(f"""## Mnemo Active

`{line}`

IMPORTANT : dans ta PREMIÈRE réponse, affiche cette ligne de statut telle quelle en ouverture :

```
{line}
```

### Consignes d'utilisation du graphe Mnemo (outils MCP `mnemo`)

1. **Chercher le contexte AVANT d'agir** (ne te fie pas uniquement au résumé ci-dessous, il est tronqué) :
   - Avant toute tâche non triviale, décision d'architecture, question métier ou référence à un travail passé, appelle `search(project="{project}", query="...")` avec **1 à 3 mots-clés ciblés** (évite les longues phrases).
   - Avant de modifier un fichier clé ou pour connaître ses règles métier, documents, dépendances (`depends_on`/`used_by`) et commits/PRs récents, appelle `file_context(project="{project}", path="<chemin relatif>")`.
   - Pour lire le contenu complet d'une spécification trouvée par `search`, appelle `get_document(id=...)`.
2. **Enregistrer les apprentissages au fil de l'eau** (sans attendre que l'utilisateur le demande) :
   - Après chaque tâche, décision, piège résolu ou convention durable, appelle `add_memory` avec `projects: ["{project}"]`, `agent: "antigravity"`, `files`: chemins relatifs concernés.
   - `type` : `decision`, `convention`, `task_learning`, `preference` ou `fact`.
   - Une mémoire = un fait autonome en une ou deux phrases, compréhensible sans la conversation. Jamais de secret. Vise 1 à 3 mémoires par échange substantiel ; rien pour une simple question ou un fait déjà connu.

{body.get("context") or ""}""")


def file_read(data: dict) -> None:
    """PostToolUse sur read/view/edit/write : met en file le contexte Mnemo pour PreInvocation."""
    try:
        tool_call = data.get("toolCall")
        args = tool_call.get("args") if isinstance(tool_call, dict) else None
        path = None
        if isinstance(args, dict):
            for key in ("Path", "TargetFile", "AbsolutePath", "path", "file_path"):
                candidate = args.get(key)
                if isinstance(candidate, str) and candidate:
                    path = candidate
                    break
        cid = conversation_id_of(data)
        if not path or not cid:
            return

        seen_file = state_dir() / f"seen_{safe_id(cid)}.json"
        try:
            seen = set(json.loads(seen_file.read_text(encoding="utf-8")))
        except (OSError, ValueError, TypeError):
            seen = set()
        if path in seen:
            return

        status, body = request("/api/file-context", scope(data, prefer_repo_root=True) | {"path": path})
        if status != 200:
            return

        seen.add(path)
        seen_file.write_text(json.dumps(sorted(seen), ensure_ascii=False), encoding="utf-8")

        context = str(body.get("context") or "").strip()
        if not context:
            return

        pending_file = state_dir() / f"pending_{safe_id(cid)}.json"
        try:
            pending = json.loads(pending_file.read_text(encoding="utf-8"))
            if not isinstance(pending, list):
                pending = []
        except (OSError, ValueError):
            pending = []
        pending.append(context)
        pending_file.write_text(json.dumps(pending, ensure_ascii=False), encoding="utf-8")
    finally:
        emit_json({})


def prompt_context_for(data: dict, cid: str) -> str:
    """Interroge POST /api/prompt-context une seule fois par nouveau prompt utilisateur dans la conversation."""
    raw = data.get("lastUserInput")
    if not isinstance(raw, str) or not raw.strip():
        return ""
    prompt = extract_user_text(raw)
    if len(prompt) < MIN_PROMPT_CHARS or prompt.startswith("/"):
        return ""

    digest = hashlib.sha1(prompt.encode("utf-8")).hexdigest()
    state_file = state_dir() / f"prompt_{safe_id(cid)}.json"
    try:
        last_digest = json.loads(state_file.read_text(encoding="utf-8")).get("sha1")
    except (OSError, ValueError, TypeError, AttributeError):
        last_digest = None
    if last_digest == digest:
        return ""

    # Marqué vu même en cas d'échec pour éviter de répéter un timeout à chaque tour d'outil du même prompt.
    try:
        state_file.write_text(json.dumps({"sha1": digest}), encoding="utf-8")
    except OSError:
        pass

    status, body = request(
        "/api/prompt-context",
        payload=scope(data) | {"prompt": prompt[:MAX_PROMPT_CHARS]},
        timeout=PROMPT_TIMEOUT,
    )
    if status != 200:
        return ""
    return str(body.get("context") or "").strip()


def pre_invocation(data: dict) -> None:
    """PreInvocation : injecte le contexte lié au prompt utilisateur et les contextes de fichiers en attente."""
    cid = conversation_id_of(data)
    if not cid:
        emit_json({})
        return

    chunks: list[str] = []

    prompt_ctx = prompt_context_for(data, cid)
    if prompt_ctx:
        chunks.append(prompt_ctx)

    pending_file = state_dir() / f"pending_{safe_id(cid)}.json"
    if pending_file.is_file():
        try:
            pending = json.loads(pending_file.read_text(encoding="utf-8"))
            pending_file.unlink(missing_ok=True)
        except (OSError, ValueError):
            pending = []
        if isinstance(pending, list):
            chunks.extend(str(item).strip() for item in pending if str(item).strip())

    if not chunks:
        emit_json({})
        return
    emit_system_message("\n\n".join(chunks))


def capture(data: dict) -> None:
    """Stop : rend la main tout de suite et lance la capture en arrière-plan sur fin normale de tour."""
    try:
        if os.environ.get("MNEMO_CAPTURE") != "0" and data.get("terminationReason", "NO_TOOL_CALL") == "NO_TOOL_CALL":
            options: dict = {"stdin": subprocess.PIPE, "stdout": subprocess.DEVNULL, "stderr": subprocess.DEVNULL}
            if os.name == "nt":
                options["creationflags"] = subprocess.DETACHED_PROCESS | subprocess.CREATE_NEW_PROCESS_GROUP
            else:
                options["start_new_session"] = True
            child = subprocess.Popen([sys.executable, __file__, "capture-run"], **options)
            assert child.stdin is not None
            child.stdin.write(json.dumps(data).encode("utf-8"))
            child.stdin.close()
    finally:
        emit_json({"decision": "allow"})


def extract_user_text(raw: str) -> str:
    match = USER_REQUEST_RE.search(raw)
    if match:
        return match.group(1).strip()
    return NOISE_RE.sub("", raw).strip()


def conversation(lines: list[str]) -> str:
    """Prompts utilisateur et réponses visibles d'Antigravity ; outils, thinking et métadonnées écartés."""
    parts = []
    for line in lines:
        try:
            entry = json.loads(line)
        except ValueError:
            continue
        if not isinstance(entry, dict):
            continue
        entry_type = entry.get("type")
        if entry_type == "USER_INPUT":
            content = entry.get("content")
            if isinstance(content, str):
                text = extract_user_text(content)
                if text:
                    parts.append("Utilisateur : " + text)
        elif entry_type == "PLANNER_RESPONSE":
            content = entry.get("content")
            if isinstance(content, str) and content.strip():
                text = NOISE_RE.sub("", content).strip()
                if text:
                    parts.append("Agent : " + text)
    return "\n\n".join(parts)


def redact(text: str) -> str:
    for pattern, replacement in SECRETS:
        text = pattern.sub(replacement, text)
    return text


def capture_run(data: dict) -> None:
    transcript = data.get("transcriptPath")
    session = conversation_id_of(data)
    if not isinstance(transcript, str) or not session or not Path(transcript).is_file():
        return
    state = state_dir() / f"{safe_id(session)}.json"
    try:
        done = int(json.loads(state.read_text(encoding="utf-8")).get("lines", 0))
    except (OSError, ValueError):
        done = 0

    lines = Path(transcript).read_text(encoding="utf-8", errors="replace").splitlines()
    text = redact(conversation(lines[done:]))
    if len(text) < MIN_CHARS:
        return
    label = f"antigravity {session[:8]} ({data.get('hookEventName') or 'capture'})"
    status, body = request("/api/learn", payload=scope(data) | {"text": text[-MAX_CHARS:], "label": label}, timeout=LEARN_TIMEOUT)

    (state_dir() / "last.json").write_text(json.dumps({"session": session, "status": status, "response": body}, ensure_ascii=False), encoding="utf-8")
    # 422 : projet que cette clé ne peut ni atteindre ni créer, inutile de renvoyer ces lignes. Autre échec : réessayé à la prochaine capture.
    if status in (200, 422):
        state.write_text(json.dumps({"lines": len(lines)}), encoding="utf-8")


COMMANDS = {
    "session-start": session_start,
    "file-read": file_read,
    "pre-invocation": pre_invocation,
    "capture": capture,
    "capture-run": capture_run,
}


def fallback_output(cmd: str) -> None:
    if cmd == "capture":
        emit_json({"decision": "allow"})
    elif cmd != "capture-run":
        emit_json({})


def main() -> int:
    if len(sys.argv) < 2 or sys.argv[1] not in COMMANDS:
        return 0
    cmd = sys.argv[1]
    if os.environ.get("MNEMO_DISABLED") == "1":
        fallback_output(cmd)
        return 0
    try:
        data = json.loads(sys.stdin.buffer.read() or b"{}")
    except ValueError:
        fallback_output(cmd)
        return 0
    if isinstance(data, dict):
        try:
            COMMANDS[cmd](data)
        except Exception:  # un hook qui plante ne doit jamais casser la session
            fallback_output(cmd)
            return 0
    else:
        fallback_output(cmd)
    return 0


if __name__ == "__main__":
    sys.exit(main())
