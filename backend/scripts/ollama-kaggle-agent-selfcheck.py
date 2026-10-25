"""Self-checks for the Ollama/Kaggle agent script, runnable anywhere.

No GPU, no Ollama, no network: it imports the module, exercises the pure helpers and
asserts the behaviour that used to be broken. This is what makes the script
reviewable in a pull request instead of "it worked on my notebook".

Two ways to run it:

    # as a file (CI, a workstation)
    python3 backend/scripts/ollama-kaggle-agent-selfcheck.py

    # as a notebook cell (Kaggle) - paste this single line into a cell:
    exec(open("/kaggle/working/backend/scripts/ollama-kaggle-agent-selfcheck.py").read())

    # or, when the agent script lives somewhere else:
    OLLAMA_AGENT_SCRIPT=/kaggle/working/ollama-kaggle-agent.py \
        python3 backend/scripts/ollama-kaggle-agent-selfcheck.py

A notebook cell has no ``__file__``, which is why the target is resolved instead of
assumed: the original version raised ``NameError: name '__file__' is not defined``
when pasted into Kaggle.
"""

from __future__ import annotations

import importlib.util
import json
import os
import sys
import tempfile
from pathlib import Path

AGENT_SCRIPT_NAME = "ollama-kaggle-agent.py"


def resolve_agent_script() -> Path:
    """Find the agent script without assuming ``__file__`` exists.

    Order: an explicit environment variable, the directory of this file (when it has
    one), the working directory, then the tree around it. The error message names
    every place that was tried, so a notebook user is never left guessing.
    """

    candidates: list[Path] = []

    override = os.environ.get("OLLAMA_AGENT_SCRIPT")
    if override:
        candidates.append(Path(override))

    module_file = globals().get("__file__")
    if module_file:
        candidates.append(Path(module_file).resolve().parent / AGENT_SCRIPT_NAME)

    here = Path.cwd()
    candidates += [
        here / AGENT_SCRIPT_NAME,
        here / "backend" / "scripts" / AGENT_SCRIPT_NAME,
        here / "scripts" / AGENT_SCRIPT_NAME,
        Path("/kaggle/working") / AGENT_SCRIPT_NAME,
        Path("/kaggle/working") / "backend" / "scripts" / AGENT_SCRIPT_NAME,
    ]

    # One level up covers `python scripts/x.py` from a repository root.
    for parent in [here, *here.parents][:4]:
        candidates.append(parent / "backend" / "scripts" / AGENT_SCRIPT_NAME)
        candidates.append(parent / "scripts" / AGENT_SCRIPT_NAME)

    for candidate in candidates:
        try:
            if candidate.is_file():
                return candidate.resolve()
        except OSError:
            continue

    tried = "\n".join(f"  - {candidate}" for candidate in dict.fromkeys(candidates))
    raise SystemExit(
        f"Cannot find {AGENT_SCRIPT_NAME}.\n"
        f"Tried:\n{tried}\n\n"
        "Fix: set the path explicitly, for example\n"
        f"  OLLAMA_AGENT_SCRIPT=/kaggle/working/{AGENT_SCRIPT_NAME} python3 <this file>"
    )


SCRIPT = resolve_agent_script()
print(f"agent script: {SCRIPT}")

spec = importlib.util.spec_from_file_location("ollama_agent", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
sys.modules["ollama_agent"] = module
spec.loader.exec_module(module)

# This file mutates os.environ to exercise the config loader; restore it afterwards so
# a notebook session is not polluted by running the checks.
_ENV_SNAPSHOT = dict(os.environ)

failures: list[str] = []


def check(name: str, condition: bool, detail: str = "") -> None:
    if condition:
        print(f"  ok    {name}")
    else:
        failures.append(f"{name}: {detail}")
        print(f"  FAIL  {name}  {detail}")


try:
    # --- configuration ---------------------------------------------------------
    os.environ["MODEL_TAGS"] = "a:1,b:2,c:3"
    os.environ["MODEL_ALIASES"] = "alpha"
    os.environ["NUM_CTX"] = "32768"
    os.environ["KV_CACHE_TYPE"] = "f16"
    config = module.load_config()

    check("aliases are padded to one per tag",
          config.model_aliases == ["alpha", "model2", "model3"], str(config.model_aliases))
    check("primary model is the first alias", config.primary_model == "alpha")
    check("num_ctx comes from the environment", config.num_ctx == 32768)
    check("kv cache type comes from the environment", config.kv_cache_type == "f16")

    # Empty MODEL_TAGS must fail loudly rather than silently doing nothing.
    os.environ["MODEL_TAGS"] = " , , "
    try:
        module.load_config()
    except SystemExit:
        check("empty MODEL_TAGS is a hard error", True)
    else:
        check("empty MODEL_TAGS is a hard error", False, "no SystemExit")

    # --- modelfile -------------------------------------------------------------
    os.environ.update({
        "MODEL_TAGS": "qwen3.8:27b-q4_K_M",
        "MODEL_ALIASES": "qwen3.8-27b",
        "NUM_CTX": "65536",
        "KV_CACHE_TYPE": "q8_0",
        "TEMPERATURE": "0.3",
        "TOP_P": "0.9",
        "TOP_K": "20",
        "PRESENCE_PENALTY": "1.5",
    })
    config = module.load_config()
    modelfile = module.modelfile_for(config, "qwen3.8:27b-q4_K_M")

    for expected in ("FROM qwen3.8:27b-q4_K_M", "num_ctx 65536", "temperature 0.3",
                     "top_p 0.9", "top_k 20", "presence_penalty 1.5"):
        check(f"modelfile carries {expected!r}", expected in modelfile, modelfile)

    # --- ngrok interstitial detection -----------------------------------------
    html_response = module.Response(
        status=200,
        headers={"Content-Type": "text/html; charset=utf-8"},
        body="<html>You are about to visit...</html>",
    )
    check("an HTML body is treated as an interstitial",
          module.looks_like_ngrok_interstitial(html_response))

    header_response = module.Response(
        status=403,
        headers={"ngrok-error-code": "ERR_NGROK_6024"},
        body={"object": "list"},
    )
    check("the ngrok error header is treated as an interstitial",
          module.looks_like_ngrok_interstitial(header_response))

    json_response = module.Response(
        status=200,
        headers={"Content-Type": "application/json"},
        body={"models": []},
    )
    check("a JSON response is not an interstitial",
          not module.looks_like_ngrok_interstitial(json_response))

    # --- ngrok token handling -------------------------------------------------
    config_path = Path(tempfile.mkdtemp()) / "ngrok.yml"
    config_path.write_text("authtoken: old-token\nversion: 3\n", encoding="utf-8")

    check("an existing token is read", module.read_stored_token(config_path) == "old-token")

    module.clear_stored_token(config_path)
    check("the token is removed", module.read_stored_token(config_path) is None)
    check("the rest of the config survives", "version: 3" in config_path.read_text(encoding="utf-8"))

    check("a missing config is handled",
          module.read_stored_token(config_path.parent / "nope.yml") is None)
    check("clearing a missing config returns False",
          module.clear_stored_token(config_path.parent / "nope.yml") is False)

    # --- handover --------------------------------------------------------------
    with tempfile.TemporaryDirectory() as directory:
        os.environ["HDIR"] = directory
        config = module.load_config()
        handover = module.write_handover(config, "https://example.ngrok-free.dev")

        env_file = Path(directory) / "ollama-agent.env"
        json_file = Path(directory) / "ollama-agent.json"

        check("the env handover exists", env_file.exists())
        check("the json handover exists", json_file.exists())

        written = json.loads(json_file.read_text(encoding="utf-8"))
        check("the handover points at /v1", written["openai_base_url"].endswith("/v1"))
        check("the handover names the primary model", written["primary_model"] == "qwen3.8-27b")
        check("the handover documents how to disable thinking",
              "reasoning_effort" in written["thinking_off"])
        check("no token in the handover", all("token" not in key.lower() for key in handover))

    # --- notebook compatibility ------------------------------------------------
    # A cell's sys.argv belongs to the kernel; argparse must not choke on it.
    original_argv = list(sys.argv)
    try:
        sys.argv = ["/usr/lib/python3/ipykernel_launcher.py", "-f", "/tmp/kernel.json"]
        check("a kernel argv yields no flags", module.notebook_argv() == [],
              str(module.notebook_argv()))

        sys.argv = ["ollama-kaggle-agent.py", "--dry-run"]
        check("a script argv is passed through",
              module.notebook_argv() == ["--dry-run"], str(module.notebook_argv()))
    finally:
        sys.argv = original_argv

    # --- smoke-case contract ---------------------------------------------------
    cases = module.smoke_cases(config, "http://127.0.0.1:11434", "https://example.ngrok-free.dev")
    check("four smoke cases are defined", len(cases) == 4, str(len(cases)))
    check("the OpenAI case disables reasoning",
          cases[3].payload.get("reasoning_effort") == "none", json.dumps(cases[3].payload))
    check("the Ollama chat case disables thinking", cases[0].payload.get("think") is False)

finally:
    # Restore the environment even when a check raised: a notebook user should be able
    # to run the real script afterwards without inheriting the test's variables.
    os.environ.clear()
    os.environ.update(_ENV_SNAPSHOT)

# --- verdict -------------------------------------------------------------------
if failures:
    print("\nFAILED:")
    for failure in failures:
        print("  -", failure)
    raise SystemExit(1)

print("\nAll self-checks passed.")
