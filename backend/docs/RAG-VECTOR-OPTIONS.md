# RAG retrieval — measured quality and the vector-search decision (TASK KB-3)

**Status:** decision record. **AI stays disabled.** No Qdrant, pgvector or embedding sidecar is part of
this slice; this document records what the current lexical retriever scores, where it fails, and the
concrete condition under which vector search becomes worth its operational cost.

**Measured:** 2026-10-25, against the committed corpus
`backend/database/knowledge/health_kb.sqlite` (7 documents, 76 chunks, 1,011,712 bytes, built
2026-10-24T18:00:29+00:00) and `backend/database/knowledge/benchmark/queries.json` (28 Thai questions).

Reproduce with:

```bash
cd backend
php artisan knowledge:bench              # table, exits non-zero below the floors
php artisan knowledge:bench --json       # machine-readable object
php artisan knowledge:bench --limit=10   # deeper ranking for inspection
php artisan test tests/Feature/AiGateway/KnowledgeBenchTest.php
```

## 1. Current state

`knowledge:bench` runs every question through the same `KnowledgeRetriever` the API uses, in-process,
against the **built** SQLite file. It reads only; it neither rebuilds the corpus nor calls a provider.
Each question names one or two acceptable source ids. The rank is the position of the **first acceptable**
source; a question with no acceptable source in the retrieved list is a miss.

| Metric | Measured | Floor | Questions |
|---|---:|---:|---:|
| hit@1 | **0.929** | 0.75 | 26 / 28 |
| hit@3 | **0.964** | 0.95 | 27 / 28 |
| MRR | **0.946** | 0.80 | 28 |
| top-3 misses | 1 | — | Q-08 |
| not found at all | 1 | — | Q-08 |

The set has 28 questions: 5 hand-foot-and-mouth (HFMD), 5 leptospirosis, 5 dengue, 5 influenza,
6 PM2.5, 2 road-traffic injury. It deliberately over-covers caregiver-style names: 22 questions use
`มือเท้าปาก`, `ฉี่หนู`, `ไข้เลือดออก`, `หวัดใหญ่` or `ฝุ่น`, and several of those (`ฉี่หนู`,
`ไข้เลือดออก`, `ฝุ่น`) do not occur in the source title at all. Five questions are symptom
descriptions rather than name questions. 24 hits came from the normal query path and 3 from the
explicit alias table (Q-06, Q-07, Q-24).

### Per-topic result

| Topic | Questions | hit@1 | hit@3 | MRR | Notes |
|---|---:|---:|---:|---:|---|
| HFMD (`ddc-disease-11`) | 5 | 1.000 | 1.000 | 1.000 | Name and symptom questions both work. |
| Leptospirosis (`ddc-disease-16`) | 5 | 0.800 | 0.800 | 0.800 | The symptom-only question Q-08 returns nothing. |
| Dengue (`ddc-disease-44`) | 5 | 1.000 | 1.000 | 1.000 | `ไข้เลือดออก` reaches the document through the query path. |
| Influenza (`ddc-disease-13`) | 5 | 1.000 | 1.000 | 1.000 | Name and symptom questions both work. |
| PM2.5 (two ThaiHealth articles) | 6 | 0.833 | 1.000 | 0.917 | Q-21 puts the symptom article first; the heart article is second. |
| Road traffic injury (`ddc-disease-73`) | 2 | 1.000 | 1.000 | 1.000 | `ทางถนน` is enough. |

### Failure cases, named

1. **Q-08 — outright miss.**
   `"หลังลุยน้ำท่วมมีไข้สูง, ปวดน่องมาก, ตาแดง ควรไปหาหมอไหม"` (leptospirosis).
   The retriever returns **no document**. This is the paraphrase/symptom gap in its purest form:
   the question contains no corpus keyword, and after normalisation the fallback tokens are
   `หลังลุยน้ำท่วมมีไข้สูง`, `ปวดน่องมาก`, `ตาแดงควรไปหาหมอไหม`. The corpus writes `ปวดที่น่อง`
   and `ตาแดง` in different sentences, so no trigram phrase matches. No alias covers symptom
   vocabulary. Semantic similarity would place this question next to the leptospirosis article
   without difficulty.

2. **Q-21 — top-1 is the wrong PM2.5 article (acceptable answer at rank 2).**
   `"ฝุ่น PM2.5 ควรใส่หน้ากากอะไร"`. Both ThaiHealth articles share the `PM2.5`/`ฝุ่น` vocabulary,
   so bm25 returns the symptom-relief article first and the heart/cardiovascular article second.
   Only the heart article contains the N95/medical-mask guidance, so the benchmark accepts the heart
   article alone. The lexical score has no way to know which article answers the intent.

3. **Ordering weakness, not a top-3 miss: Q-23 and Q-24.**
   `"PM2.5 ทำให้หัวใจเสี่ยงอะไรบ้าง"` and `"อยู่กลางฝุ่นนาน ๆ เสี่ยงหัวใจขาดเลือดไหม"` both return
   the symptom article first and the dedicated heart article second. Both are accepted because both
   mention the cardiovascular effect, but the dedicated article is the better answer. The two PM2.5
   documents are not separable by shared vocabulary alone; this is a precision problem, not recall.

The three alias hits are also worth naming as a maintenance cost: `ฉี่หนู` (Q-06, Q-07) and `ฝุ่น`
(Q-24) only work because `config/knowledge.php` lists the corpus spellings. A new colloquialism or a
new source with different wording needs a hand-written alias; that list does not scale on its own.

## 2. Option A — keep lexical (FTS5 trigram + normalisation + aliases)

This is the current implementation: SQLite FTS5 with the trigram tokenizer, `ThaiText::normalise()`
on both the index and the query, a title-weighted `bm25`, a keyword vocabulary built from titles and
headings, and a config-driven alias fallback. It is one file, opened read-only.

**Cost:** zero marginal infrastructure. The file is committed, deterministic, rebuildable from seven
JSON sources, and works with no network and no API key. No question or corpus text leaves the server.
The query is answered in-process by PHP. A backup is a file copy; a rebuild is `knowledge:build`.

**Strengths measured here:** 0.929 hit@1 / 0.964 hit@3 / 0.946 MRR on 28 realistic Thai questions.
Thai substrings work without a Thai tokenizer (the trigram tokenizer does the work), spaced and
unspaced spellings meet after normalisation, a title-only disease name is findable, and aliases cover
the most common colloquialisms. It is fast, deterministic and easy to test.

**Weakness measured here:** Q-08 finds nothing. More generally, the lexical retriever cannot answer a
question that shares no vocabulary with the source: pure symptom descriptions, synonyms outside the
alias table, and intent that depends on meaning rather than a matching trigram. It also cannot
separate two articles that share the same topic vocabulary (Q-21, Q-23, Q-24). The normaliser's
space removal, which fixes `มือ เท้า ปาก`, makes multi-word Thai questions harder to tokenise unless a
known keyword is present. The alias table is hand-maintained and is the only path for some
colloquialisms.

**Verdict:** the current corpus and the current measured quality do not justify new infrastructure.
Keep A until a trigger in section 5 fires.

## 3. Option B — Qdrant + a local embedding sidecar

**What it adds.** A vector index stores one embedding per chunk. At query time the question is embedded
locally and the nearest chunks are returned by cosine/dot-product similarity, so a symptom description
can find the right article even when it shares no trigram with it. Because the corpus is public
reference data, the sidecar would see **only public source text** (and the question embedding request);
no patient record, tenant id or workspace data is involved. The sidecar can run on the same host, so
the embedding call does not leave the deployment.

**What it costs.**

- A second runtime: Qdrant itself (container or binary) plus a model-serving process (a small Python
  service with a multilingual sentence-transformer, or `llama.cpp` serving the Pathumma GGUF). Both
  need supervision, health checks, upgrades and a rollback path.
- RAM and CPU: an embedding model plus Qdrant's index; a small multilingual model is hundreds of MB,
  Qdrant's process and on-disk index add more. This competes with PostgreSQL/Redis/MinIO on the same
  host and is a new capacity item.
- An index to build, version, back up and rebuild. Model changes alter the vector dimension and the
  meaning of every stored vector, so a model upgrade is a full re-embedding, not a config toggle.
- A new failure mode: if Qdrant or the sidecar is down, retrieval fails (or silently falls back to
  lexical). The gateway must fail closed or fall back deliberately, and the benchmark must cover both
  paths.
- More tests: index build determinism, dimension checks, collection schema, reindex after a source
  change, and a contract test that the Qdrant retriever returns the same result shape as the lexical
  one.

**What must be decided before writing code.**

- Model choice and licence: a multilingual sentence-transformer (e.g. a multilingual MiniLM/E5-class
  model) or the Pathumma GGUF the owner mentioned. Check the licence for commercial use and for
  redistribution of weights.
- Dimension and distance metric (cosine is the usual choice), and whether the collection is one per
  model version or one per environment.
- Chunking: reuse `KnowledgeBaseBuilder`'s chunks (900/150) so lexical and vector retrieval share the
  same citation boundaries.
- Hybrid or pure vector: lexical is strong on names and exact Thai substrings; vector is strong on
  paraphrases. The likely production shape is hybrid (merge both ranked lists, e.g. reciprocal rank
  fusion), not a wholesale replacement.
- Index lifecycle: where the index lives, who rebuilds it after a source review, and how it is backed
  up alongside the SQLite file.
- Privacy statement: the sidecar sees public corpus text only. The **question** is still user text;
  embedding it locally keeps it on the server, but it must not be logged (N-02).
- Benchmark acceptance: the same `knowledge:bench` set must improve, not just change. A vector
  retriever that loses Q-01 or Q-27 is a regression even if it fixes Q-08.

## 4. Option C — provider embeddings over the existing gateway

The measured provider facts at the time of writing:

- the OKMD/KKU IntelliSphere gateway used by this project exposes chat completions, **no
  `/embeddings` endpoint**;
- Groq exposes no embeddings endpoint;
- OpenRouter lists embedding models, for example `nvidia/nemotron-3-embed-1b:free`.

So C is technically possible: embed the 76 chunks once, store the vectors, and send each question to
the vendor for an embedding. It avoids a second local runtime and a model licence decision, but it
adds a **second vendor** next to the chat provider and a **network call on every retrieval**, before
the answer provider is called. That call has its own latency, rate limit, outage and circuit-breaker
surface, and it sends the user's question to another processor. The question is not yet scrubbed on
the retrieval path (scrubbing happens later in `KnowledgeGateway`), so C also requires a privacy
review: a question can contain a name, a location or a medication even when the corpus is public.

C is not recommended for the current corpus. It is a reasonable fallback if a local sidecar is
impossible to operate and the owner accepts the extra processor and per-query call.

## 5. Recommendation and switch trigger

**Recommendation: keep Option A now. Do not build Qdrant in this slice.** The measured numbers are
above every floor, the corpus is seven documents, and the single failure (Q-08) is a known limitation
that costs nothing today. The decision document exists so the next step is a measurement, not an
argument.

**Switch to vector/hybrid retrieval when any of these is observed on real traffic:**

1. **Recall trigger:** the top-3 rate on a de-identified, operator-reviewed sample of **30 days of real
   user questions** (at least 50 questions, scored with the same `expected` format) falls **below 0.90**.
   This is the main trigger; the current synthetic set is 0.964 and must not be used to claim real-traffic
   quality.
2. **Precision trigger:** hit@1 on the same real sample falls **below 0.80**, or more than 10% of the
   sample is a symptom/paraphrase miss like Q-08 (no acceptable source in the top 3).
3. **Scale trigger:** the corpus exceeds **500 chunks** (roughly 6–7× today's 76) **and** hit@3 has not
   improved after the latest alias/keyword update. At that size, hand-maintained aliases stop being a
   credible recall strategy.
4. **Maintenance trigger:** the alias/keyword table needs a change for more than one in ten new
   sources, i.e. the vocabulary gap is structural rather than a few colloquialisms.

When a trigger fires, build the smallest hybrid slice: reuse the chunks, add a local sidecar, put
Qdrant behind the existing seam, and prove the improvement with `knowledge:bench` before enabling the
endpoint. Do not make vector search the only retriever until it beats lexical on both Q-08-style
paraphrases and the name/title questions.

How to collect the real sample without logging PHI: the API already records `knowledge_not_found` and
retrieval counts without question text. Use those aggregates to find the misses, then have an operator
re-type a **de-identified** question into the benchmark file (no names, no addresses, no identifiers).
Never log the raw question to make the sample (N-02).

## 6. What Qdrant would need later — the seam is already there

The gateway and prompt builder only know `KnowledgeRetriever::search(string $question, ?int $limit):
array`. The minimal future slice is:

**Classes to add**

| Class | Responsibility |
|---|---|
| `Knowledge\Contracts\KnowledgeSearch` | Contract: `search()`, `isAvailable()`, `stats()`, `documentIds()`. `KnowledgeRetriever` implements it. |
| `Knowledge\Application\QdrantKnowledgeRetriever` | Implements `KnowledgeSearch`; embeds the question via the sidecar, queries Qdrant, maps payloads to the existing result shape. |
| `Knowledge\Application\HybridKnowledgeRetriever` | Runs lexical + vector, merges with reciprocal rank fusion, keeps the existing `matched_by`/`source` fields. |
| `Knowledge\Contracts\Embedder` | `embed(array|string $text): array` (batch and single). |
| `Knowledge\Infrastructure\SidecarEmbedder` | HTTP client for the local Python/llama.cpp service. No Composer package: Laravel's HTTP client is enough. |
| `Knowledge\Infrastructure\QdrantClient` | Thin HTTP wrapper (`upsert`, `search`, `delete`, `collectionExists`). |
| `Knowledge\Application\KnowledgeIndexer` | Reads the built SQLite chunks, batches embeddings, creates/updates the collection. |
| `Console\Commands\KnowledgeIndexCommand` | `knowledge:index` — explicit, idempotent, never called by a request. |
| `Knowledge\Application\KnowledgeBenchmark` | Unchanged in shape; add a `--driver=lexical|qdrant|hybrid` switch so the same 28 questions compare every retriever. |

**Config keys to add under `config/knowledge.php`**

```php
'driver' => env('KNOWLEDGE_DRIVER', 'lexical'), // lexical | qdrant | hybrid
'qdrant' => [
    'url' => env('QDRANT_URL'),
    'api_key' => env('QDRANT_API_KEY'),
    'collection' => env('QDRANT_COLLECTION', 'luckycare_health_kb'),
    'timeout_seconds' => env('QDRANT_TIMEOUT', 5),
],
'embeddings' => [
    'driver' => env('KNOWLEDGE_EMBEDDINGS_DRIVER', 'sidecar'), // sidecar | openrouter
    'endpoint' => env('KNOWLEDGE_EMBEDDINGS_URL'),
    'model' => env('KNOWLEDGE_EMBEDDINGS_MODEL'),
    'dimension' => (int) env('KNOWLEDGE_EMBEDDINGS_DIMENSION', 384),
    'batch_size' => (int) env('KNOWLEDGE_EMBEDDINGS_BATCH', 32),
],
'hybrid' => [
    'lexical_weight' => (float) env('KNOWLEDGE_HYBRID_LEXICAL_WEIGHT', 1.0),
    'vector_weight' => (float) env('KNOWLEDGE_HYBRID_VECTOR_WEIGHT', 1.0),
    'rrf_k' => (int) env('KNOWLEDGE_HYBRID_RRF_K', 60),
],
```

**Operational shape**

- Keep SQLite as the source of truth and the fallback. Qdrant is a derived index; deleting it and
  running `knowledge:index` must fully restore it.
- Payload: `document_id`, `chunk_id`, `ordinal`, `title`, `publisher`, `url`, `category`,
  `published_at`, `fetched_at`, `review_status`, `text`. Public reference data only — no tenant id,
  no workspace id, no patient field.
- Reindex after `knowledge:review --build`; add a check that the index's source checksum matches the
  SQLite `meta.built_at`.
- Tests: contract tests for the lexical and Qdrant retrievers, a dimension/chunk-count test for the
  indexer, the existing `KnowledgeRetrievalTest` behaviour, and this benchmark with `--driver`.
- No new Composer package is required for the first version: Laravel's `Http` client covers the Qdrant
  REST API and the sidecar.

## 7. Acceptance evidence for this slice

- `php artisan knowledge:bench` → 28 questions, hit@1 0.929, hit@3 0.964, MRR 0.946, exit 0.
- `KnowledgeBenchTest` asserts the floors and that every `expected` id exists in the built corpus.
- `php artisan test` and `./vendor/bin/pint --test` pass; `php artisan ai:evaluate` still passes.
- No provider was called; `AI_ENABLED` remains false.
