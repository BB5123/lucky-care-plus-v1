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

### Current corpus — 16 documents / 183 chunks (2026-10-25)

Thirteen DDC disease pages plus three ThaiHealth articles (two on PM2.5, one on leptospirosis). The diseases were chosen for what Thai
families actually ask about, not for what the site happens to expose:

| Id | Topic |
|---|---|
| `ddc-disease-10` | อาหารเป็นพิษ (food poisoning) |
| `ddc-disease-11` | มือเท้าปาก (HFMD) |
| `ddc-disease-13` | ไข้หวัดใหญ่ (influenza) |
| `ddc-disease-16` | เลปโตสไปโรซิส / ฉี่หนู (leptospirosis) |
| `ddc-disease-17` | มาลาเรีย (malaria) |
| `ddc-disease-21` | ปอดบวม, ปอดอักเสบ (pneumonia) |
| `ddc-disease-23` | วัณโรค (tuberculosis) — thin page, 2k characters |
| `ddc-disease-25` | พิษสุนัขบ้า (rabies) |
| `ddc-disease-44` | ไข้เด็งกี่ (dengue) |
| `ddc-disease-67` | ทางเดินอาหารอักเสบเฉียบพลันจากไวรัส (viral gastroenteritis) |
| `ddc-disease-73` | การบาดเจ็บจากการจราจรทางถนน (road traffic injury) |
| `ddc-disease-74` | อหิวาตกโรค (cholera) |
| `ddc-disease-75` | โรคหัด (measles) |
| `thaihealth-lepto-symptoms` | โรคไข้ฉี่หนู (leptospirosis) — the article that names the colloquial term and the symptoms |
| `thaihealth-pm25-symptoms` | PM2.5 — symptom relief |
| `thaihealth-pm25-heart` | PM2.5 — heart and blood-vessel risk |

Found by scanning DDC pages 1–90 for body keywords and reading each candidate's first heading, because the
site's `<title>` is generic on every page ("Home | กรมควบคุมโรค") — a title-based crawl finds nothing.

Still missing and worth adding: ไข้เลือดออก has only the dengue page, โรคเรื้อน (id 47) is identified but not
ingested, and there is no PM2.5 **air-quality index** source (only health advice).

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
  "reviewed_by": null,
  "reviewed_at": null,
  "license_note": "…",
  "headings": ["ระบาดวิทยา", "อาการ", "การป้องกัน"],
  "text": "…"
}
```

`php artisan knowledge:build` writes `database/knowledge/health_kb.sqlite` (documents, chunks,
keywords, and `chunks_fts`, an FTS5 virtual table with the trigram tokenizer). Each FTS row is keyed
by the chunk id and has **two** columns: `title` (the document title) and `text` (the chunk body).
Both columns are passed through `Knowledge\Domain\ThaiText::normalise()` before indexing, so a
disease name that appears only in the title is findable and a spaced spelling (`มือ เท้า ปาก`) and an
unspaced query (`มือเท้าปาก`) produce the same token stream. The build is deterministic and replaces
the file atomically; the previous file is untouched on failure.

## Commands

```bash
php artisan knowledge:fetch "https://ddc.moph.go.th/disease_detail.php?d=44" --id=ddc-disease-44
php artisan knowledge:validate
php artisan knowledge:build
php artisan knowledge:search "ไข้หวัดใหญ่ป้องกันอย่างไร" --limit=3
php artisan knowledge:review ddc-disease-44 --reviewer="Dr. …" --build
```

`knowledge:fetch` refuses non-https URLs, non-allow-listed hosts and robots-disallowed paths; it
does not retry automatically. `knowledge:validate` checks ids, URLs, hosts, categories, review status
and text length without touching the network. `knowledge:search` is the operator view of retrieval and
never calls a provider. It prints a `Matched by` column (`query` or `alias: <term>`) so an operator can
see why a hit appeared. `knowledge:review` records `reviewed_by`/`reviewed_at`, revalidates the source
and (with `--build`) publishes the change; a source marked `reviewed` without a reviewer is rejected.
`knowledge:backup` and `knowledge:restore-drill` cover the mutable corpus (review decisions live in a
volume, not the image); see [`BACKUP-COVERAGE.md`](BACKUP-COVERAGE.md).

## Retrieval and Thai matching (TASK KB-2)

`KnowledgeRetriever` is lexical and deterministic:

1. **Normalise the query** with `ThaiText::normalise()`: zero-width characters are removed, whitespace
   between Thai characters is collapsed, and the space inside a Latin+digit measurement is removed
   (`PM 2.5` → `PM2.5`). Spaces between Latin words are preserved (`Hand, Foot and Mouth`).
2. **Search FTS5** over the `title` and `text` columns. `bm25(chunks_fts, 8.0, 1.0)` weights the title
   eight times the body, so a name that the body never repeats still ranks at least as high as a body
   hit. The keyword vocabulary is used to turn a Thai question without spaces into a term.
3. **Alias fallback** only when step 2 returned no row: for each key in `config/knowledge.php` →
   `aliases` that occurs in the normalised question, search every listed spelling and merge the
   results. Those rows carry `matched_by = alias` and `matched_term`. A query that already has hits is
   never widened, so the common case keeps its precision.

Current aliases: `ฉี่หนู` → `เลปโตสไปโรซิส`/`leptospirosis`, `มือเท้าปาก` → `HFM`/`Hand Foot and Mouth`,
`ฝุ่น` → `PM2.5`/`PM 2.5`, `ไข้เลือดออก` → `เด็งกี่`/`dengue`.

## Benchmark (TASK KB-3)

`php artisan knowledge:bench` runs `database/knowledge/benchmark/queries.json` (28 Thai questions)
through the same retriever, in-process, against the built SQLite file. It reports hit@1, hit@3 and
MRR, exits non-zero below 0.75 / 0.95 / 0.80, and supports `--json` and `--limit=N`. Measured
2026-10-25: **hit@1 0.929, hit@3 0.964, MRR 0.946** (7 documents / 76 chunks); the one top-3 miss is
a symptom-only leptospirosis question. The measured case for keeping lexical retrieval, and the
trigger for adding a vector retriever, are in [`RAG-VECTOR-OPTIONS.md`](RAG-VECTOR-OPTIONS.md).
`KnowledgeBenchTest` makes the floors a CI gate and fails on a stale expected source id.

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
2. Review it, then run `knowledge:review <id> --reviewer="…" --build` (or set `review_status`,
   `reviewed_by` and `reviewed_at` by hand).
3. Run `knowledge:validate` and `knowledge:build`; commit both the source and the rebuilt `.sqlite`.
4. `knowledge:search` to confirm retrieval before enabling the endpoint.

A web admin UI for editing is **not** built: the platform-role model (`platform_admin` in
`RoleGate`/Matrix) has no data-model representation, so `platform.configuration.manage` cannot be
held by anyone today. That is a separate change-control item; until then the CLI is the admin path.

## Verified (2026-10-25, TASK KB-2)

- `php artisan knowledge:validate` → 7 sources, 0 reviewed / 7 `unreviewed`; `knowledge:build` →
  7 documents, 76 chunks.
- Before KB-2: `มือเท้าปาก` and `มือเท้า` → no match; `HFM` → `ddc-disease-11`; `ฉี่หนู` → no match;
  `ฝุ่น` → only `thaihealth-pm25-heart`.
- After KB-2: `มือเท้าปาก` and `มือ เท้า ปาก` → `ddc-disease-11` (title-only match);
  `ฉี่หนู` → `ddc-disease-16` with `matched_by: alias`; `PM2.5` and `ฝุ่น` → both ThaiHealth sources;
  `ไข้เลือดออก` still returns `ddc-disease-44` through the query path (no alias expansion).
- `KnowledgeRetrievalTest` covers KB-T01 … KB-T07 against the real seven-source corpus.

## Verified (2026-10-24, initial corpus)

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

- Lexical retrieval cannot answer a question with no keyword overlap (Q-08 in the benchmark is the
  measured example). The decision and the trigger for a vector/hybrid retriever are in
  [`RAG-VECTOR-OPTIONS.md`](RAG-VECTOR-OPTIONS.md); it is not built yet.
- Thai segmentation is heuristic: titles/headings plus a prefix/suffix fallback. Questions that use
  a synonym not present in the corpus rely on the explicit alias table; anything outside it will not
  retrieve the document.
- The corpus is seven public sources (five DDC disease pages, two ThaiHealth PM2.5 articles), all
  `unreviewed`; it is a technical seed, not clinical guidance.
- No live-evaluation suite for the knowledge purpose yet; the deterministic tests cover the gate
  order, citations, disclaimer, grounding and injection.
