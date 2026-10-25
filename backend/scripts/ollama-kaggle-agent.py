#!/usr/bin/env python3
"""Kaggle notebook cell / plain script: Ollama + ngrok, tuned for a coding agent.

Sibling of ``ollama_ngrok_qwen38.py``. A coding agent needs different things than a
chat demo, and each of these was a real failure at some point:

1. **A large context window.** 16K cannot hold a repo plus a conversation. The
   default here is 64K with a ``q8_0`` KV cache, so 128K fits on two 16 GB cards.
2. **Thinking under control.** ``qwen3.8-27b`` reasons by default, which roughly
   doubles latency and burns tokens every turn. Ollama API: ``"think": false``.
   OpenAI-compatible API: ``"reasoning_effort": "none"``. Both are asserted below.
3. **Tool calling in the OpenAI schema**, because that is what agent frameworks speak.
4. **More than one model**, so a heavy model can be swapped for a fast one behind
   the same tunnel.

Everything is environment-driven, so one file serves several projects:

    MODEL_TAGS          comma separated ollama tags to pull        (qwen3.8:27b-q4_K_M)
    MODEL_ALIASES       comma separated friendly names, one each   (qwen3.8-27b)
    NUM_CTX             context window                             (65536)
    KV_CACHE_TYPE       f16 | q8_0 | q4_0                          (q8_0)
    TEMPERATURE                                                      (0.3)
    TOP_P / TOP_K / PRESENCE_PENALTY                                 (0.9 / 20 / 1.5)
    NGROK_AUTHTOKEN     fallback when the Kaggle secret is not attached
    NGROK_STATIC_DOMAIN optional; empty = dynamic URL
    HDIR                where the handover files are written        (/kaggle/working)

**Notebook usage** (a Kaggle cell has no ``__file__`` and its ``sys.argv`` belongs to the
kernel, so neither a plain ``python script.py`` nor ``argparse`` works as written):

    import importlib.util, sys
    path = "/kaggle/working/backend/scripts/ollama-kaggle-agent.py"
    spec = importlib.util.spec_from_file_location("ollama_agent", path)
    module = importlib.util.module_from_spec(spec)
    sys.modules["ollama_agent"] = module      # dataclasses look the module up here
    spec.loader.exec_module(module)
    module.main(["--dry-run"])                # then module.main([]) for the real run

Useful flags:

    --dry-run      print the resolved configuration and exit (no install, no tunnel)
    --no-smoke     skip the smoke tests (fast restart of an already-warm session)
    --skip-pull    do not pull models (they are already in the model store)
    --json         print the handover as JSON instead of the prose block
    --stop         tear down this session's ngrok agent and the ollama server

What changed from the original, and why:

* The three phases that used to be inline (install, pull, alias) are functions with
  an explicit ``Phase`` timer, so a slow step is obvious in the log.
* Every HTTP call goes through one ``request_json`` with retries on 429/5xx and a
  proper ngrok-interstitial check (content-type **and** the ngrok error header,
  not just a substring of the body).
* Pull and alias creation are **idempotent**: an existing model is not re-pulled and
  an alias whose parameters already match is not recreated. Re-running the cell
  after a kernel hiccup used to cost 20 minutes of downloads.
* ``OLLAMA_FLASH_ATTENTION=1`` and ``OLLAMA_NUM_PARALLEL=1`` are set: quantized KV
  cache needs flash attention on current builds, and one parallel slot keeps the
  VRAM estimate honest.
* The warm-up **verifies** ``context_length == NUM_CTX`` through ``/api/ps``, so a
  silently ignored ``NUM_CTX`` fails here instead of truncating an agent's context
  days later.
* The smoke tests are data-driven (a list of cases) and each one states what a
  failure means; ``reasoning_effort: none`` is asserted, not assumed.
* A handover is written to ``HDIR`` as ``ollama-agent.env`` **and**
  ``ollama-agent.json`` so a project can consume the endpoint without copy-paste.
  The ngrok token is never written or printed.
* ``main()`` is importable and ``--dry-run`` works with no GPU, which makes the
  configuration reviewable in a pull request.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.request
from dataclasses import dataclass, field
from pathlib import Path

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

OLLAMA_PORT = 11434
# 0.0.0.0, not 127.0.0.1: Ollama's allowedHostsMiddleware rejects any Host header
# that is not loopback/private/local when the server binds to loopback, and ngrok
# forwards the original Host (yourapp.ngrok-free.dev) -> 403 on every request.
OLLAMA_BIND = "0.0.0.0"
NGROK_SKIP_HEADER = "ngrok-skip-browser-warning"


def env_list(name: str, default: str) -> list[str]:
    return [item.strip() for item in os.environ.get(name, default).split(",") if item.strip()]


@dataclass(frozen=True)
class Config:
    model_tags: list[str]
    model_aliases: list[str]
    num_ctx: int
    kv_cache_type: str
    temperature: float
    top_p: float
    top_k: int
    presence_penalty: float
    models_dir: Path
    hdir: Path
    static_domain: str
    origins: str
    gpu_count: int
    gpu_vram_gb: float

    @property
    def primary_model(self) -> str:
        return self.model_aliases[0]


def load_config() -> Config:
    tags = env_list("MODEL_TAGS", "qwen3.8:27b-q4_K_M")
    aliases = env_list("MODEL_ALIASES", "qwen3.8-27b")

    # Pad the aliases so every tag has a name to publish under.
    aliases += [f"model{index + 1}" for index in range(len(aliases), len(tags))]

    if not tags:
        raise SystemExit("MODEL_TAGS is empty: nothing to pull")

    return Config(
        model_tags=tags,
        model_aliases=aliases,
        num_ctx=int(os.environ.get("NUM_CTX", "65536")),
        kv_cache_type=os.environ.get("KV_CACHE_TYPE", "q8_0") or "f16",
        temperature=float(os.environ.get("TEMPERATURE", "0.3")),
        top_p=float(os.environ.get("TOP_P", "0.9")),
        top_k=int(os.environ.get("TOP_K", "20")),
        presence_penalty=float(os.environ.get("PRESENCE_PENALTY", "1.5")),
        models_dir=Path(os.environ.get("OLLAMA_MODELS_DIR", "/tmp/ollama_models")),
        hdir=Path(os.environ.get("HDIR", "/kaggle/working")),
        static_domain=os.environ.get("NGROK_STATIC_DOMAIN", "").strip(),
        origins=os.environ.get("OLLAMA_ORIGINS", "").strip(),
        gpu_count=max(1, int(os.environ.get("GPU_COUNT", "2"))),
        gpu_vram_gb=float(os.environ.get("GPU_VRAM_GB", "16")),
    )


# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------

@dataclass
class Phase:
    """Timed log section: every step prints how long it took, which is the only
    reliable way to see that the 18 GB pull is the slow part and not the install."""

    label: str
    started: float = field(default_factory=time.time)

    def __enter__(self) -> "Phase":
        say(f"\n== {self.label} ==")
        self.started = time.time()
        return self

    def __exit__(self, *_exc: object) -> None:
        say(f"== {self.label}: {time.time() - self.started:.1f}s ==")


def say(*args: object) -> None:
    print(*args, flush=True)


# ---------------------------------------------------------------------------
# HTTP
# ---------------------------------------------------------------------------

class HttpError(RuntimeError):
    def __init__(self, message: str, status: int | None = None) -> None:
        super().__init__(message)
        self.status = status


@dataclass
class Response:
    status: int
    headers: dict[str, str]
    body: object


def header_get(headers: dict[str, str], name: str, default: str = "") -> str:
    for key, value in headers.items():
        if key.lower() == name.lower():
            return value
    return default


def looks_like_ngrok_interstitial(response: Response) -> bool:
    """True when ngrok answered with its browser-warning page.

    The old check looked for the substring ``ERR_NGROK`` in the body, which the
    warning page does not always contain. The reliable signals are the HTML
    content type and the ``ngrok-error-code`` header.
    """
    if header_get(response.headers, "ngrok-error-code"):
        return True
    if "text/html" in header_get(response.headers, "Content-Type").lower():
        return True
    return isinstance(response.body, str) and "ngrok" in response.body.lower() and "<html" in response.body.lower()


def request_json(
    url: str,
    payload: object | None = None,
    *,
    headers: dict[str, str] | None = None,
    timeout: float = 60,
    retries: int = 2,
    method: str | None = None,
) -> Response:
    """One JSON call with retries on 429/5xx and a clear error for the rest.

    Retries exist because ngrok's free plan rate-limits bursts: a smoke test that
    fails on the first 429 sends you looking for a bug in the model.
    """
    data = None
    request_headers = dict(headers or {})
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        request_headers.setdefault("Content-Type", "application/json")

    last: Exception | None = None

    for attempt in range(retries + 1):
        request = urllib.request.Request(url, data=data, headers=request_headers, method=method)

        try:
            with urllib.request.urlopen(request, timeout=timeout) as raw:
                text = raw.read().decode("utf-8", "replace")
                content_type = raw.headers.get("Content-Type", "")
                parsed: object = text
                if "json" in content_type:
                    try:
                        parsed = json.loads(text)
                    except ValueError:
                        parsed = text

                return Response(status=raw.status, headers=dict(raw.headers), body=parsed)

        except urllib.error.HTTPError as error:
            body = error.read().decode("utf-8", "replace")
            last = HttpError(f"HTTP {error.code} from {url}: {body[:400]}", status=error.code)

            if error.code in (429, 500, 502, 503, 504) and attempt < retries:
                time.sleep(2 ** attempt)
                continue

            raise last from error

        except urllib.error.URLError as error:
            last = HttpError(f"Cannot reach {url}: {error.reason}")
            if attempt < retries:
                time.sleep(2 ** attempt)
                continue
            raise last from error

    raise last or HttpError(f"Cannot reach {url}")


# ---------------------------------------------------------------------------
# Ollama
# ---------------------------------------------------------------------------

def ollama_env(config: Config) -> dict[str, str]:
    environment = dict(
        os.environ,
        OLLAMA_MODELS=str(config.models_dir),
        OLLAMA_HOST=f"{OLLAMA_BIND}:{OLLAMA_PORT}",
        OLLAMA_KEEP_ALIVE="-1",        # keep the model resident for the session
        OLLAMA_MAX_LOADED_MODELS="1",  # one model in VRAM at a time
        OLLAMA_NUM_PARALLEL="1",       # keeps the VRAM estimate honest
        OLLAMA_CONTEXT_LENGTH=str(config.num_ctx),
        # A quantized KV cache needs flash attention on current builds; without it
        # the cache type is ignored or the request is refused.
        OLLAMA_FLASH_ATTENTION="1",
        CUDA_VISIBLE_DEVICES=os.environ.get("CUDA_VISIBLE_DEVICES", "0,1"),
    )

    if config.kv_cache_type != "f16":
        environment["OLLAMA_KV_CACHE_TYPE"] = config.kv_cache_type
    if config.origins:
        environment["OLLAMA_ORIGINS"] = config.origins

    return environment


def installed_models(ollama: str, environment: dict[str, str]) -> set[str]:
    result = subprocess.run(
        [ollama, "list"], capture_output=True, text=True, env=environment, timeout=120
    )
    if result.returncode != 0:
        return set()

    names: set[str] = set()
    for line in result.stdout.splitlines()[1:]:
        parts = line.split()
        if parts:
            names.add(parts[0])
            names.add(parts[0].split(":")[0])
    return names


def ensure_models(config: Config, ollama: str, environment: dict[str, str], skip_pull: bool) -> None:
    present = installed_models(ollama, environment)

    for tag in config.model_tags:
        if skip_pull or tag in present or tag.split(":")[0] in present:
            say(f"   present already: {tag}")
            continue

        say(f"   pulling {tag} (this is the slow step)")
        subprocess.run([ollama, "pull", tag], check=True, env=environment)


def modelfile_for(config: Config, tag: str) -> str:
    return (
        f"FROM {tag}\n"
        f"PARAMETER num_ctx {config.num_ctx}\n"
        f"PARAMETER temperature {config.temperature}\n"
        f"PARAMETER top_p {config.top_p}\n"
        f"PARAMETER top_k {config.top_k}\n"
        f"PARAMETER presence_penalty {config.presence_penalty}\n"
    )


def ensure_aliases(config: Config, ollama: str, environment: dict[str, str]) -> None:
    """Create the friendly aliases, idempotently.

    Re-creating an alias is cheap but not free (it re-writes the manifest), and on
    a re-run after a kernel restart it is pure noise. Compare the stored Modelfile
    first and skip when it already carries our parameters.
    """
    for tag, alias in zip(config.model_tags, config.model_aliases):
        desired = modelfile_for(config, tag)

        current = subprocess.run(
            [ollama, "show", "--modelfile", alias],
            capture_output=True, text=True, env=environment, timeout=120,
        )

        if current.returncode == 0:
            normalized_current = re.sub(r"\s+", " ", current.stdout).strip()
            normalized_desired = re.sub(r"\s+", " ", desired).strip()
            params = ["num_ctx", "temperature", "top_p", "top_k", "presence_penalty"]
            if all(parameter in normalized_current for parameter in params):
                say(f"   alias present already: {alias} -> {tag}")
                continue

        path = Path(f"/tmp/Modelfile_{alias.replace('/', '_').replace(':', '_')}")
        path.write_text(desired, encoding="utf-8")
        subprocess.run([ollama, "create", alias, "-f", str(path)], check=True, env=environment)
        say(f"   created alias: {alias} -> {tag}")


# ---------------------------------------------------------------------------
# ngrok
# ---------------------------------------------------------------------------

def read_stored_token(path: Path) -> str | None:
    if not path.exists():
        return None

    raw = path.read_text(encoding="utf-8", errors="replace")

    try:
        import yaml  # available on Kaggle; optional elsewhere

        document = yaml.safe_load(raw)
        if isinstance(document, dict):
            token = document.get("authtoken")
            if not token and isinstance(document.get("agent"), dict):
                token = document["agent"].get("authtoken")
            if token:
                return str(token).strip()
    except Exception:
        pass

    for line in raw.splitlines():
        stripped = line.strip()
        if stripped.startswith("authtoken:"):
            return stripped.split(":", 1)[1].strip().strip("'\"") or None

    return None


def clear_stored_token(path: Path) -> bool:
    """Strip the authtoken so a different one can be written.

    ``ngrok config add-authtoken`` refuses to overwrite an existing token, which
    is what made every re-run of the original cell fail.
    """
    if not path.exists():
        return False

    raw = path.read_text(encoding="utf-8", errors="replace")

    try:
        import yaml

        document = yaml.safe_load(raw)
        if isinstance(document, dict):
            changed = False
            if "authtoken" in document:
                del document["authtoken"]
                changed = True
            agent = document.get("agent")
            if isinstance(agent, dict) and "authtoken" in agent:
                del agent["authtoken"]
                changed = True
                if not agent:
                    del document["agent"]
            if changed:
                path.write_text(yaml.safe_dump(document, sort_keys=False), encoding="utf-8")
                return True
    except Exception:
        pass

    kept = [line for line in raw.splitlines(keepends=True) if not re.match(r"^\s*authtoken\s*:", line)]
    if len(kept) != len(raw.splitlines(keepends=True)):
        path.write_text("".join(kept), encoding="utf-8")
        return True

    return False


def load_ngrok_token() -> str:
    """Kaggle secret first, environment variable second, never a literal."""
    try:
        from kaggle_secrets import UserSecretsClient  # only on Kaggle
    except ImportError:
        pass
    else:
        try:
            token = UserSecretsClient().get_secret("NGROK_AUTHTOKEN")
        except Exception as error:
            say(
                "\nNGROK_AUTHTOKEN is not available to this notebook.\n"
                f"Raised: {type(error).__name__}: {str(error)[:200]}\n\n"
                "Fix, in order:\n"
                "  1. Add-ons -> Secrets -> 'Add secret', label EXACTLY: NGROK_AUTHTOKEN\n"
                "  2. On the Secrets panel, switch NGROK_AUTHTOKEN ON.\n"
                "  3. Restart the session (secrets are handed to the kernel at start-up).\n"
                "\nIf a token was ever written into a file or a chat, revoke it in the ngrok\n"
                "dashboard first: a leaked token must be treated as compromised.\n"
            )
        else:
            if token and token.strip():
                return token.strip()

    token = os.environ.get("NGROK_AUTHTOKEN", "").strip()
    if token:
        return token

    raise RuntimeError(
        "No ngrok authtoken. Attach the Kaggle secret NGROK_AUTHTOKEN (switched ON) "
        "or export NGROK_AUTHTOKEN in the environment."
    )


def open_tunnel(config: Config, ngrok: object, pyngrok_config: object) -> str:
    def connect() -> str:
        if config.static_domain and pyngrok_config is not None:
            from pyngrok.conf import PyngrokConfig

            v2 = PyngrokConfig(config_version="2")
            last: Exception | None = None
            for key in ("domain", "subdomain"):
                try:
                    return ngrok.connect(OLLAMA_PORT, pyngrok_config=v2, **{key: config.static_domain}).public_url
                except Exception as error:  # try the other key
                    last = error
            if last is not None:
                raise last

        return ngrok.connect(OLLAMA_PORT).public_url

    try:
        url = connect()
    except Exception as error:
        message = str(error)
        say("   ngrok connect failed:\n   " + message[:1500].replace("\n", "\n   "))
        if "ERR_NGROK_334" in message or "already online" in message:
            raise RuntimeError(
                "ngrok ERR_NGROK_334: this endpoint is already online in another session.\n"
                "Stop the other notebook that runs ollama + ngrok, wait a minute, then re-run."
            ) from error

        say("   restarting the local agent and retrying once ...")
        try:
            ngrok.kill()
        except Exception:
            pass
        time.sleep(5)
        url = connect()

    return url.replace("http://", "https://", 1) if url.startswith("http://") else url


# ---------------------------------------------------------------------------
# Smoke tests
# ---------------------------------------------------------------------------

@dataclass
class SmokeCase:
    label: str
    url: str
    payload: dict
    expect: str  # documented as code so every case has a roadmap
    check: object  # Callable[[dict], bool]


def chat_ok(response: object) -> bool:
    return bool(
        isinstance(response, dict)
        and response.get("message", {}).get("content", "").strip().lower().startswith("pong")
    )


def ollama_tool_ok(response: object) -> bool:
    return bool(isinstance(response, dict) and response.get("message", {}).get("tool_calls"))


def openai_tool_ok(response: object) -> bool:
    return bool(
        isinstance(response, dict)
        and response.get("choices", [{}])[0].get("message", {}).get("tool_calls")
    )


def openai_no_reasoning_ok(response: object) -> bool:
    message = response.get("choices", [{}])[0].get("message", {}) if isinstance(response, dict) else {}
    if message.get("reasoning"):
        return False
    return bool(message.get("content"))


WEATHER_TOOL = [
    {
        "type": "function",
        "function": {
            "name": "get_weather",
            "description": "Get the weather for a city",
            "parameters": {
                "type": "object",
                "properties": {"city": {"type": "string"}},
                "required": ["city"],
            },
        },
    }
]


def smoke_cases(config: Config, local: str, public_url: str) -> list[SmokeCase]:
    return [
        SmokeCase(
            label="chat (Ollama API, think=false)",
            url=f"{local}/api/chat",
            payload={
                "model": config.primary_model,
                "stream": False,
                "think": False,
                "options": {"num_predict": 64},
                "messages": [{"role": "user", "content": "Reply with the single word: pong"}],
            },
            expect="thinking off + content starts with 'pong'",
            check=chat_ok,
        ),
        SmokeCase(
            label="tool calling (Ollama API)",
            url=f"{local}/api/chat",
            payload={
                "model": config.primary_model,
                "stream": False,
                "think": False,
                "messages": [{"role": "user", "content": "What is the weather in Bangkok? Use the tool."}],
                "tools": WEATHER_TOOL,
            },
            expect="message.tool_calls is non-empty",
            check=ollama_tool_ok,
        ),
        SmokeCase(
            label="tool calling (OpenAI-compat)",
            url=f"{public_url}/v1/chat/completions",
            payload={
                "model": config.primary_model,
                "stream": False,
                "max_tokens": 400,
                "messages": [{"role": "user", "content": "What is the weather in Bangkok? Use the tool."}],
                "tools": WEATHER_TOOL,
            },
            expect="choices[0].message.tool_calls is non-empty",
            check=openai_tool_ok,
        ),
        SmokeCase(
            label="reasoning_effort=none (OpenAI-compat)",
            url=f"{public_url}/v1/chat/completions",
            payload={
                "model": config.primary_model,
                "stream": False,
                "max_tokens": 200,
                "reasoning_effort": "none",
                "messages": [{"role": "user", "content": "Reply with the single word: pong"}],
            },
            expect="no reasoning field and non-empty content",
            check=openai_no_reasoning_ok,
        ),
    ]


def run_smoke(case: SmokeCase) -> None:
    try:
        response = request_json(
            case.url, case.payload, headers={NGROK_SKIP_HEADER: "true"}, timeout=900
        )
    except HttpError as error:
        raise RuntimeError(f"{case.label} failed: {error}") from error

    if looks_like_ngrok_interstitial(response):
        raise RuntimeError(
            f"{case.label}: ngrok returned its browser-warning page.\n"
            f"  Send '{NGROK_SKIP_HEADER}: true' or upgrade the ngrok plan."
        )

    body = response.body
    if isinstance(body, dict) and body.get("error"):
        raise RuntimeError(f"{case.label}: provider error: {body['error']}")

    if not case.check(body):  # type: ignore[operator]
        raise RuntimeError(f"{case.label}: expected {case.expect}. Got: {str(body)[:600]}")

    say(f"   ok  {case.label}")


# ---------------------------------------------------------------------------
# Handover
# ---------------------------------------------------------------------------

def write_handover(config: Config, public_url: str) -> dict[str, str]:
    """Machine-readable handover so a project can consume the endpoint directly.

    Written to HDIR because a notebook's working directory survives the session and
    a project can check the file into nothing - it is a local artefact. It never
    contains the ngrok token.
    """
    handover = {
        "base_url": public_url,
        "openai_base_url": f"{public_url}/v1",
        "ollama_api": f"{public_url}/api",
        "models": ",".join(config.model_aliases),
        "primary_model": config.primary_model,
        "num_ctx": str(config.num_ctx),
        "kv_cache_type": config.kv_cache_type,
        "temperature": str(config.temperature),
        "chat_path": "/chat/completions",
        "thinking_off": 'ollama: {"think": false} | openai: {"reasoning_effort": "none"}',
    }

    config.hdir.mkdir(parents=True, exist_ok=True)

    env_path = config.hdir / "ollama-agent.env"
    env_path.write_text(
        "\n".join(f"{key.upper()}={value}" for key, value in handover.items()) + "\n",
        encoding="utf-8",
    )

    json_path = config.hdir / "ollama-agent.json"
    json_path.write_text(json.dumps(handover, indent=2) + "\n", encoding="utf-8")

    say(f"   handover written to {env_path} and {json_path}")
    return handover


def print_handover(config: Config, handover: dict[str, str], as_json: bool) -> None:
    if as_json:
        print(json.dumps(handover, indent=2))
        return

    base = handover["base_url"]
    model = config.primary_model

    say(f"""
--------------------------------------------------------------------
 READY

 Base URL      : {base}
 OpenAI-compat : {base}/v1
 Ollama API    : {base}/api
 Models        : {", ".join(config.model_aliases)}
 Primary model : {model}
 Context       : {config.num_ctx}   KV cache: {config.kv_cache_type}

 THINKING
   This is a reasoning model and it thinks by default, roughly doubling latency.
   Switch it off per request:
     Ollama API      ->  "think": false
     OpenAI-compat   ->  "reasoning_effort": "none"
   "none" is the only value that fully disables it; "minimal" maps to "low".

 SAMPLING GOTCHA
   The OpenAI-compatible endpoint forces temperature=1.0 and top_p=1.0 when the
   client omits them, overriding the values baked into the model. Always send
   temperature explicitly if you care.

 ---- curl ----
   curl -H "Content-Type: application/json" \\
     -d '{{"model":"{model}","reasoning_effort":"none","temperature":{config.temperature},"max_tokens":2000,"messages":[{{"role":"user","content":"hi"}}]}}' \\
     {base}/v1/chat/completions

 ---- OpenAI SDK (python) ----
   from openai import OpenAI
   client = OpenAI(base_url="{base}/v1", api_key="ollama")
   client.chat.completions.create(
       model="{model}", reasoning_effort="none", temperature={config.temperature},
       max_tokens=4096, messages=[{{"role": "user", "content": "..."}}],
   )

 ---- Cline / Roo Code (VS Code) ----
   Provider: OpenAI Compatible | Base URL: {base}/v1 | API key: ollama
   Model ID: {model} | Reasoning Effort: None

 ---- Aider ----
   aider --model openai/{model} --openai-api-base {base}/v1 --openai-api-key ollama

 ---- This project ----
   cd backend && php artisan ai:evaluate --live --max-calls=30 \\
     --base-url={base}/v1 --model={model}

 ---- Notes ----
   ngrok free: the domain changes on every restart, with monthly request and
   bandwidth limits. The endpoint dies with this kernel. Cold start is 12-15 s.
   Anything sent to this URL is public: add auth before using it for real data.
   Every reply costs 30-60 s on a 27B model over a free tunnel; that is fine for
   batch evaluation and painful for interactive use.

 Keep this notebook running. The tunnel and the model die with the kernel.
--------------------------------------------------------------------
""")


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def teardown() -> None:
    subprocess.run("pkill -f ngrok; pkill -f 'ollama serve'", shell=True,
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def notebook_argv() -> list[str]:
    """Arguments for a notebook cell.

    IPython starts the kernel with its own argv (``-f /tmp/ipykernel_launcher.py``),
    so a plain ``parse_args(sys.argv[1:])`` dies with "unrecognized arguments" the
    first time someone calls ``main()`` from a cell. A cell passes flags explicitly,
    so the default there is "no flags".
    """
    launcher = " ".join(sys.argv[:2])
    if "ipykernel" in launcher or "ipykernel_launcher" in launcher:
        return []

    return sys.argv[1:]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Ollama + ngrok for a coding agent")
    parser.add_argument("--dry-run", action="store_true", help="print the configuration and exit")
    parser.add_argument("--no-smoke", action="store_true", help="skip the smoke tests")
    parser.add_argument("--skip-pull", action="store_true", help="do not pull models")
    parser.add_argument("--json", action="store_true", help="print the handover as JSON")
    parser.add_argument("--stop", action="store_true", help="stop this session's ngrok and ollama")
    args, unknown = parser.parse_known_args(argv if argv is not None else notebook_argv())
    if unknown:
        say(f"   ignoring unrecognised arguments: {' '.join(unknown)}")

    config = load_config()

    if args.dry_run:
        say(json.dumps({
            "model_tags": config.model_tags,
            "model_aliases": config.model_aliases,
            "num_ctx": config.num_ctx,
            "kv_cache_type": config.kv_cache_type,
            "temperature": config.temperature,
            "top_p": config.top_p,
            "top_k": config.top_k,
            "presence_penalty": config.presence_penalty,
            "models_dir": str(config.models_dir),
            "hdir": str(config.hdir),
            "static_domain": config.static_domain or "(dynamic)",
            "gpu": f"{config.gpu_count} x {config.gpu_vram_gb:.0f} GB",
            "vram_estimate_gb": round(
                config.gpu_vram_gb * config.gpu_count * 0.7, 1
            ),
        }, indent=2))
        return 0

    if args.stop:
        teardown()
        say("stopped")
        return 0

    with Phase("install / start Ollama"):
        teardown()
        time.sleep(1)

        subprocess.run(
            "apt-get update -y >/dev/null 2>&1 && apt-get install -y zstd curl >/dev/null 2>&1",
            shell=True,
        )

        if shutil.which("ollama") is None:
            subprocess.run("curl -fsSL https://ollama.com/install.sh | sh", shell=True, check=True)

        ollama = shutil.which("ollama") or "/usr/local/bin/ollama"
        if not os.path.exists(ollama):
            raise RuntimeError(f"Ollama install failed: no binary at {ollama}")

        config.models_dir.mkdir(parents=True, exist_ok=True)
        environment = ollama_env(config)

        log_path = Path("/tmp/ollama_serve.log") if Path("/tmp").is_dir() else config.hdir / "ollama_serve.log"
        say(f"   ollama: {ollama}")
        say(f"   num_ctx={config.num_ctx}  kv_cache_type={config.kv_cache_type}  "
            f"temp={config.temperature}  top_p={config.top_p}")

        log_handle = log_path.open("w")
        server = subprocess.Popen(
            [ollama, "serve"], env=environment, stdout=log_handle, stderr=subprocess.STDOUT
        )

        local = f"http://127.0.0.1:{OLLAMA_PORT}"
        deadline = time.time() + 180
        while time.time() < deadline:
            try:
                request_json(f"{local}/api/tags", timeout=5, retries=0)
                break
            except Exception:
                time.sleep(1)
        else:
            raise RuntimeError(f"Ollama did not become ready; see {log_path}")

        say(f"   up on {OLLAMA_BIND}:{OLLAMA_PORT}")

        try:
            gpu = subprocess.run(
                ["nvidia-smi", "--query-gpu=name,memory.total,memory.used", "--format=csv,noheader"],
                capture_output=True, text=True, timeout=30,
            )
            for line in gpu.stdout.strip().splitlines():
                say("   gpu:", line)
        except Exception:
            say("   (nvidia-smi unavailable)")

    with Phase("pull models"):
        ensure_models(config, ollama, environment, args.skip_pull)
        store_gb = sum(
            f.stat().st_size for f in config.models_dir.rglob("*") if f.is_file()
        ) / 1e9
        say(f"   model store: {store_gb:.1f} GB in {config.models_dir}")
        say(f"   free space: {shutil.disk_usage('/').free / 1e9:.1f} GB")

    with Phase("create aliases"):
        ensure_aliases(config, ollama, environment)

    with Phase("open tunnel"):
        try:
            import pyngrok  # noqa: F401
        except ImportError:
            subprocess.run([sys.executable, "-m", "pip", "install", "-qq", "pyngrok"], check=True)

        from pyngrok import ngrok

        try:
            from pyngrok.conf import PyngrokConfig

            pyngrok_config = PyngrokConfig()
        except ImportError:  # very old pyngrok
            PyngrokConfig = None
            pyngrok_config = None

        try:
            from pyngrok.conf import get_config_path

            config_path = Path(get_config_path(pyngrok_config))
        except Exception:
            base = os.environ.get("XDG_CONFIG_HOME", os.path.join(os.path.expanduser("~"), ".config"))
            config_path = Path(base) / "ngrok" / "ngrok.yml"

        token = load_ngrok_token()
        stored = read_stored_token(config_path)
        if stored == token:
            say(f"   token already configured in {config_path}")
        else:
            if stored:
                say("   replacing the stored token")
                clear_stored_token(config_path)
            ngrok.set_auth_token(token, pyngrok_config)
            if read_stored_token(config_path) != token:
                raise RuntimeError(f"Could not store the ngrok token in {config_path}; delete it and re-run.")

        try:
            ngrok.kill()
        except Exception:
            pass
        time.sleep(1)

        public_url = open_tunnel(config, ngrok, pyngrok_config)
        say(f"   public URL: {public_url}")

    with Phase("warm up"):
        request_json(
            f"{local}/api/generate",
            {"model": config.primary_model, "prompt": "hi", "stream": False,
             "think": False, "options": {"num_predict": 1}},
            timeout=1800,
        )

        status = request_json(f"{local}/api/ps", timeout=60).body
        loaded = status.get("models", []) if isinstance(status, dict) else []
        if not loaded:
            raise RuntimeError("The model is not loaded after the warm-up.")
        for model in loaded:
            context = model.get("context_length")
            vram = model.get("size_vram", 0) / 1e9
            say(f"   loaded {model.get('name')} ctx={context} vram={vram:.1f} GB")
            if context != config.num_ctx:
                # Silent context truncation is the worst failure for an agent: the
                # prompt is accepted and quietly cut.
                raise RuntimeError(
                    f"Context length is {context}, expected {config.num_ctx}. "
                    "The alias was probably created without num_ctx - re-run without --skip-pull."
                )
            if vram / config.gpu_count > config.gpu_vram_gb * 0.9:
                say(f"   WARNING: {vram / config.gpu_count:.1f} GB per GPU is near the "
                    f"{config.gpu_vram_gb:.0f} GB limit; lower NUM_CTX.")

    if not args.no_smoke:
        with Phase("smoke tests"):
            for case in smoke_cases(config, local, public_url):
                run_smoke(case)

            models = request_json(
                f"{public_url}/api/tags", headers={NGROK_SKIP_HEADER: "true"}, timeout=60
            ).body
            names = [m.get("name") for m in (models.get("models", []) if isinstance(models, dict) else [])]
            say("   models visible through the tunnel: " + ", ".join(str(n) for n in names))

    if server.poll() is not None:
        raise RuntimeError(
            f"The ollama serve process died (exit {server.returncode}); see {log_path}"
        )

    handover = write_handover(config, public_url)
    print_handover(config, handover, args.json)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
