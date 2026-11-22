"""Branche Antigravity (agy) sur Mnemo, la mémoire du projet.

  session-start   SessionStart : ligne de statut, consigne d'enregistrer les mémoires via `add_memory` sans attendre
                  qu'on le demande, puis mémoire du projet (conventions, décisions, règles validées)
  file-read       PostToolUse (read_file|view_file) : récupère règles, documents et mémoires liés au fichier lu
                  et les met en file pour le prochain appel modèle
  pre-invocation  PreInvocation : injecte les contextes de fichiers mis en file par `file-read`
  capture         Stop : rend la main aussitôt et lance `capture-run` en arrière-plan pour envoyer la partie
                  nouvelle du transcript à `learn`
  capture-run     le travail de capture lui-même, lancé détaché par `capture`

Le projet est celui que nomme MNEMO_PROJECT (son slug dans le graphe). Sans cette variable, c'est celui du dépôt
distant `origin`, sinon celui dont le chemin contient le dossier courant (même machine que le serveur seulement).
Un projet inconnu est créé par le serveur au début de la session, nommé d'après le slug, le dépôt ou le dossier.
Serveur absent ou erreur : le hook sort proprement en 0 avec un JSON valide, il ne gêne jamais la session.

Variables d'environnement :
  MNEMO_URL       adresse du serveur, défaut http://localhost:8080
  MNEMO_PROJECT   slug du projet dans le graphe, pour ne pas le déduire du dépôt
  MNEMO_API_KEY   clé d'API, quand le serveur en demande une
  MNEMO_DISABLED  1 coupe tout
  MNEMO_CAPTURE   0 coupe l'envoi des transcripts
Bibliothèque standard seulement.
"""

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

N'attends pas que l'utilisateur le demande : après chaque tâche, décision ou échange qui apprend quelque chose de durable sur ce projet, enregistre-le toi-même avec l'outil MCP `add_memory` du serveur mnemo, au fil de l'eau et pas seulement en fin de session.
- Toujours `projects: ["{project}"]` et `agent: "antigravity"` ; `files` : chemins relatifs au dépôt concernés.
- `type` : `decision`, `convention`, `task_learning` (bug corrigé, piège, solution), `preference` (de l'utilisateur) ou `fact`.
- Une mémoire = un fait autonome en une ou deux phrases, compréhensible sans la conversation. Jamais de secret.
- Vise 1 à 3 mémoires par échange substantiel ; rien pour une simple question ou un fait déjà présent ci-dessous.
- Avant une tâche non triviale ou quand l'utilisateur évoque un travail passé, cherche d'abord avec l'outil `search` (`project: "{project}"`).

{body.get("context") or ""}""")


def file_read(data: dict) -> None:
    """PostToolUse sur read_file|view_file : met en file le contexte Mnemo pour PreInvocation."""
    try:
        tool_call = data.get("toolCall")
        args = tool_call.get("args") if isinstance(tool_call, dict) else None
        path = None
        if isinstance(args, dict):
            for key in ("Path", "AbsolutePath", "path", "file_path"):
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

        status, body = request("/api/file-context", scope(data) | {"path": path})
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


def pre_invocation(data: dict) -> None:
    """PreInvocation : injecte les contextes de fichiers mis en attente par PostToolUse."""
    cid = conversation_id_of(data)
    if not cid:
        emit_json({})
        return
    pending_file = state_dir() / f"pending_{safe_id(cid)}.json"
    if not pending_file.is_file():
        emit_json({})
        return
    try:
        pending = json.loads(pending_file.read_text(encoding="utf-8"))
        pending_file.unlink(missing_ok=True)
    except (OSError, ValueError):
        emit_json({})
        return
    if not isinstance(pending, list) or not pending:
        emit_json({})
        return
    emit_system_message("\n\n".join(str(item) for item in pending if item))


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
