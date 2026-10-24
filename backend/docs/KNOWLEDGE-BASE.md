# Health knowledge base (RAG) — TASK G4-KB

A public, citation-backed health knowledge corpus that the AI gateway can answer from. It is
**not** patient data: no `tenant_id`/`workspace_id`, no RLS, no PHI, and it never writes to a health
table. `AI_ENABLED` stays `false`; this slice adds the corpus and the endpoint behind the same gate
as the other AI features.

## Why SQLite + lexical retrieval (not Qdrant yet)

- The provider account (OKMD) has **no `/embeddings` endpoint** — a vector store cannot be populated
  without an embedding model, and adding a local embedding service is a separate infrastructure
  decision.
- PHP already ships PDO SQLite **3.49 with FTS5 and the trigram tokenizer**, which searches Thai
  substrings without a tokenizer or a network call.
- The corpus is a separate, read-only file. It can be committed or copied into a deployment, so a
  fresh installation restores it without crawling the internet again.
- `KnowledgeRetriever` is the only class that knows about SQLite. A Qdrant/pgvector retriever can
  implement the same `search()` contract once an embedding provider is chosen.

## Sources and robots

- Only allow-listed government hosts (`config/knowledge.php` → `fetch.allowed_hosts`).
- **PDFs are not ingested.** DDC's `robots.txt` disallows `/uploads/` (where its PDFs live), and this
  environment has no `pdftotext`/PyMuPDF; LibreOffice's PDF import fails on the sample. The fetcher
  refuses disallowed paths. HTML article pages are used instead.
- Every source stores `url`, `publisher`, `fetched_at`, `review_status` and a licence note. Citations
  show the title, publisher, URL and date.
- `review_status` is `unreviewed` for the current corpus; the answer appends a warning until a
  clinician/ops reviewer marks the source `reviewed`.

## Corpus format

`backend/database/knowledge/sources/*.json`:

```json
{
  "id": "ddc-disease-44",
  "title": "ไข้เด็งกี่ (Dengue)",
  "publisher": "กรมควบคุมโรค กระทรวงสาธารณสุข",
  "url": "https://ddc.moph.go.th/disease_detail.php?d=44",
  "category": "disease",
  "language": "th",
  "fetched_at": "2026-10-24",
  "published_at": null,
  "review_status": "unreviewed",
  "license_note": "…",
  "headings": ["ระบาดวิทยา", "อาการ", "การป้องกัน"],
  "text": "…"
}
```

`php artisan knowledge:build` writes `database/knowledge/health_kb.sqlite` (documents, chunks,
keywords, `chunks_fts` FTS5 trigram). The build is deterministic and replaces the file atomically;
the previous file is untouched on failure.

## Commands

```bash
php artisan knowledge:fetch "https://ddc.moph.go.th/disease_detail.php?d=44" --id=ddc-disease-44
php artisan knowledge:validate
php artisan knowledge:build
php artisan knowledge:search "ไข้หวัดใหญ่ป้องกันอย่างไร" --limit=3
```

`knowledge:fetch` refuses non-https URLs, non-allow-listed hosts and robots-disallowed paths; it
does not retry automatically. `knowledge:validate` checks ids, URLs, hosts, categories, review status
and text length without touching the network. `knowledge:search` is the operator view of retrieval and
never calls a provider.

## API

`POST /api/v1/workspaces/{workspace}/profiles/{profile}/ai/knowledge-answers`

```json
{"question": "ไข้หวัดใหญ่ป้องกันอย่างไร", "locale": "th"}
```

```json
{"data": {
  "answer": "… [A]\n\nแหล่งอ้างอิง:\n[A] ไข้หวัดใหญ่ (Influenza) — กรมควบคุมโรค — https://… (ข้อมูล ณ 2026-10-24)",
  "sources": [{"label": "A", "id": "ddc-disease-13", "title": "…", "publisher": "…", "url": "…",
               "published_at": null, "fetched_at": "2026-10-24", "review_status": "unreviewed"}],
  "disclaimer": "…",
  "model": "deepseek-v4-flash",
  "usage": {"requests_today": 1, "remaining": 19}
}}
```

Gate order (each step has its own status):

| Step | Behaviour |
|---|---|
| AI disabled | 503 `ai_disabled`, no provider |
| consent / `ai.health_context.use` | 403 `no_consent` / `forbidden` |
| diagnosis, prescribing or emergency question (`IntakeClassifier`) | 422 `refuse_*`, no provider |
| no corpus match | 422 `knowledge_not_found`, no provider |
| quota spent | 429 `ai_quota_exceeded`, no provider |
| provider failure | 503 `provider_*` / `ai_provider_unavailable` |
| answer without the knowledge disclaimer | 503 `ai_output_invalid`, the attempt is recorded |

The route declares `ai.report.create`; the gateway re-checks `ai.health_context.use` plus the
`ai_processing` consent, as the other AI paths do. The question is scrubbed, wrapped in
`<untrusted_record type="user_question">` and passed through the injection guard; the retrieved chunks
are wrapped as `knowledge_source` blocks. The `OutputValidator` checks the knowledge-specific
disclaimer and grounds every number against the retrieved chunks (the requested source text), so an
invented value is still rejected.

`ai_requests` records `purpose=knowledge`, `record_refs=[{type: knowledge_document, id}]`, the model
and token counts only — no question, no answer, no corpus text.

## Updating the corpus

1. Add or edit a JSON source (or `knowledge:fetch` one).
2. Set `review_status` to `reviewed` only after a qualified review.
3. Run `knowledge:validate` and `knowledge:build`; commit both the source and the rebuilt `.sqlite`.
4. `knowledge:search` to confirm retrieval before enabling the endpoint.

A web admin UI for editing is **not** built: the platform-role model (`platform_admin` in
`RoleGate`/Matrix) has no data-model representation, so `platform.configuration.manage` cannot be
held by anyone today. That is a separate change-control item; until then the CLI is the admin path.

## Verified (2026-10-24)

- `php artisan knowledge:validate` → 3 sources, all `unreviewed`; `knowledge:build` → 3 documents,
  37 chunks, 508 KB SQLite.
- `knowledge:search` returned the expected document for Thai questions without spaces
  (`ไข้เลือดออกมีอาการอย่างไร`, `หวัดใหญ่ป้องกันอย่างไร`, `อุบัติเหตุทางถนน`).
- End-to-end HTTP against OKMD (`llama-4-maverick`; `AI_ENABLED=true` for that process only):
  `POST .../ai/knowledge-answers` → **200**, three sources (A/B/C), the answer contains `[A]`, the
  `แหล่งอ้างอิง` block and the knowledge disclaimer. The unreviewed warning was appended because no
  source is reviewed yet.
- `ai_requests`: `purpose=knowledge`, `status=succeeded`, input 2,009 / output 164 tokens,
  `record_refs` = three `knowledge_document` ids. No question, answer or corpus text is stored.
- Full suite: **325 passed / 3,401 assertions**; `pint --test` clean (474 files).

## Limitations

- Lexical retrieval cannot answer a question with no keyword overlap; adding embeddings (Qdrant or
  pgvector) is the next step once an embedding provider exists.
- Thai segmentation is heuristic: titles/headings plus a prefix/suffix fallback. Questions that use
  a synonym not present in the corpus will not retrieve the document.
- The corpus is three DDC disease pages (dengue, influenza, road-traffic injuries), all
  `unreviewed`; it is a technical seed, not clinical guidance.
- No live-evaluation suite for the knowledge purpose yet; the deterministic tests cover the gate
  order, citations, disclaimer, grounding and injection.
