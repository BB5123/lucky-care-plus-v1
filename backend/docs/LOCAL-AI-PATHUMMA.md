# Running a local Thai model (Pathumma GGUF) and scoring it with our own suite

This document exists because the owner asked whether we could use a **local Thai LLM**
(`nectec/pathumma-thaillm-8b-think-3.0.0-GGUF`) with **Kaggle** for testing before committing to a paid
provider. Our adapter is OpenAI-compatible, so a `llama.cpp` server can stand in for the provider with **no
code change** — only an endpoint and a model name.

**Nothing here enables AI.** `AI_ENABLED` is still `false`; `ai:evaluate --live` opens the gate for its own
process only (see `AiEvaluateCommand`).

## Why bother with a local model at all

| | Hosted provider (OKMD/KKU today) | Local 8B GGUF |
|---|---|---|
| Data residency | leaves the server to a third party | stays on the machine |
| AIG-010 (no training on our data) | needs a written DPA | trivially satisfied — we never fine-tune |
| Cost | per token / daily caps | GPU time only |
| Quality | larger models (DeepSeek/Gemini class) | 8B quantised, weaker reasoning |
| Ops | none | model file, GPU, server process, uptime |

The honest use of a local model is **a fallback and a privacy benchmark**, not the default: 8B will likely
miss our grounding and safety thresholds, and that is exactly what the run will tell us.

## The Kaggle recipe

1. **New notebook**, *Accelerator* = **GPU T4 ×2** (or P100), **Internet = On**.
2. Fetch the weights (about 4–5 GB for Q4_K_M) and a server binary:

```bash
!pip install -q huggingface_hub
!huggingface-cli download nectec/pathumma-thaillm-8b-think-3.0.0-GGUF --include "*Q4_K_M.gguf" --local-dir /kaggle/working/model

# llama.cpp builds an OpenAI-compatible server
!git clone --depth 1 https://github.com/ggml-org/llama.cpp /kaggle/working/llama.cpp
!cmake -S /kaggle/working/llama.cpp -B /kaggle/working/llama.cpp/build -DLLAMA_CURL=OFF
!cmake --build /kaggle/working/llama.cpp/build --config Release -j --target llama-server
```

3. **Serve** it (this is the OpenAI-compatible surface our adapter expects):

```bash
!cd /kaggle/working && nohup ./llama.cpp/build/bin/llama-server \
  -m /kaggle/working/model/*Q4_K_M.gguf \
  --host 0.0.0.0 --port 8000 -c 4096 -ngl 99 -t 4 > /kaggle/working/server.log 2>&1 &
```

4. **Expose it** — Kaggle gives no inbound port, so use a quick tunnel:

```bash
!wget -q https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64 -O /kaggle/working/cloudflared
!chmod +x /kaggle/working/cloudflared
!nohup /kaggle/working/cloudflared tunnel --url http://127.0.0.1:8000 > /kaggle/working/tunnel.log 2>&1 &
!sleep 8 && grep -o 'https://[-a-z0-9]*\.trycloudflare\.com' /kaggle/working/tunnel.log | head -1
```

5. **Score it from the workstation** with the same suites the production provider faced:

```bash
cd backend
php artisan ai:evaluate --live --max-calls=30 \
  --base-url=https://<your-tunnel>.trycloudflare.com/v1 \
  --model=pathumma-thaillm-8b
```

`--base-url` / `--model` are new: they override the config **in memory for that command only**, so you never
have to edit `.env` and never risk accidentally pointing production at a laptop.

## What to compare

Use `backend/docs/G4-LIVE-EVALUATION.md` as the baseline. The interesting columns are:

| Suite | Threshold | What a failure means for an 8B model |
|---|---|---|
| leakage / scope / refusal | 100% / 100% / 95% | decided in PHP before the model is called — should be identical; a difference means the override did not take effect |
| injection | 95% | whether the model obeys text inside `<untrusted_record>` blocks |
| grounding | 90% | whether it invents numbers not present in the context — the most likely failure at 8B |
| safety | 100% | whether a critical vital produces the emergency advice |

Record the numbers in `G4-LIVE-EVALUATION.md` next to the hosted-provider run so the two are comparable.

## Constraints you must respect

- **Never send real patient data.** The evaluation fixtures are synthetic by construction; keep it that way.
  A tunnel URL is public to anyone who learns it.
- **Kaggle limits**: 9–12 h per session, ~30 GPU hours per week, and the notebook is destroyed on timeout.
  Do not put anything durable there.
- **Speed**: Q4_K_M on a T4 is roughly 10–20 tokens/second, so 30 calls with long contexts can take tens of
  minutes. Use `--max-calls` deliberately.
- **No vision.** The OCR path (`ai/extractions`) needs a vision model; keep `vision_models` empty for this
  endpoint so it fails closed with `503 ai_vision_unavailable` instead of sending an image to a text model.
- **Licence**: confirm the NECTEC model's licence terms before any non-evaluation use. AIG-010 is satisfied
  *only if* we never fine-tune on patient data, which is our plan either way.
- **Quantisation is a quality variable.** A Q4 run is not the same model as its full-precision counterpart;
  state which file was used in the report.

## Decision this feeds

Switch the default provider to a local model only when **all** of the following hold:

1. grounding ≥ 90% and safety = 100% on our suite (not a vendor benchmark);
2. p95 latency for a summary stays inside the product's budget;
3. the GPU host has an owner, a restart policy and a backup of the model file;
4. the licence and the AIG-010 position are written down.

Until then the local model is an experiment, and AI stays off.
