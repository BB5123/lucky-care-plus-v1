# PROJECT STATE - Lucky Care Plus (LuckyCare+)

> **Read this file before doing any work.** It is the single source of truth for *where the work is*.
> It is written in English (like the rest of the repo) so any agent session can read it.
> Update it in the same PR as the work it describes - never let it drift.
>
> Punctuation rule for this file: **ASCII only** (plain hyphens, no em dashes, no curly quotes). An em dash
> written here once arrived as a three-character mojibake sequence, and `npm run check:mojibake` now fails CI
> on that. Thai words are fine and expected; the corruption came from a PowerShell round-trip, not from Thai.

**Last verified:** 2026-10-26 (Asia/Bangkok) - web suite re-run after the retention banner (140 passed); backend counts
are from the previous run; mobile counts are from the previous verification

---

## 1. What this product is

A family health CRM for Thai families: several caregivers coordinate the care of one person, with
fine-grained, revocable access to health data. Backend Laravel 13 / PHP 8.4 / PostgreSQL 16 (RLS),
web Angular 22 + Tailwind 4, mobile Flutter (`lucky_care`).

Design authority, in order: `.ai/00-CONSTITUTION.md` -> `docs/baseline-v1.0.0/*` (content = **v1.4.0**)
-> other `.ai/*` -> historical docs. A ticket never outranks the baseline.

---

## 2. Where the work stands

| Phase | State | Evidence |
|---|---|---|
| **0 - Foundation** | G0 passed (2026-10-23) | `backend/docs/G0-CHECKLIST.md` |
| **1 - Core MVP** | backend + web complete | health vault, medication, care tasks, appointments, dashboard, notifications, timeline, family |
| **2 - Coordination** | complete | recurring templates, escalation ladder, doctor brief, documents + photo evidence, quality sweep |
| **3 - AI** | **G4 not signed off** | gateway, evaluation, OKMD adapter, vision drafts, 16-document knowledge base with a measured benchmark, web + mobile AI surfaces |
| **4 - Scale/compliance** | in progress - P1 (physical deletion) + P2 (five states + state gate + P2b job filter) built | `backend/docs/RETENTION-MODEL.md` sections 7-8 |

**Verified test counts (2026-10-25 22:40):**

| Suite | Result |
|---|---|
| backend `php artisan test` | **466 passed / 2 skipped / 4,689 assertions** (468 tests) |
| web `npm test` | **140 passed / 43 files** |
| mobile `flutter test` | **125 passed** |
| `php artisan ai:evaluate` | all suites pass (offline) |
| `php artisan knowledge:bench` | **28 questions - hit@1 0.929, hit@3 1.000, MRR 0.964** (16 docs / 183 chunks) |

**Repo shape:** 15 backend modules, 52 migrations, 88 API routes, 22 web feature folders,
8 mobile feature folders, **96 merged PRs**, no open PRs.

---

## 3. Commands that must pass before you call work done

```bash
cd backend
php artisan test                 # 468 (466 passed, 2 skipped)
php artisan perf:smoke --iterations=10   # load smoke; see docs/PERFORMANCE.md
php artisan ai:evaluate          # offline gate; --live uses the provider
php artisan knowledge:bench      # hit@1/hit@3/MRR floors; --json for CI
php artisan retention:compact --dry-run  # P1: physical deletion; dry run by default
./vendor/bin/pint --test
php artisan demo:seed --reset --force    # synthetic pilot workspace; see docs/DEMO-DATA.md
bash scripts/smoke.sh --base-url=http://localhost:8080   # post-deploy check

cd ../frontend
npm test                         # 135
npm run build
npm run check:encoding           # Thai double-encoding guard
npm run check:mojibake           # repo-wide mojibake guard (added 2026-10-25)
npx prettier --check "src/**/*.{ts,html,css}"

cd ../mobile
flutter analyze
flutter test                     # 125
```

Workflow used throughout: branch -> verify -> commit -> `gh pr create` -> `gh pr checks --watch` ->
`gh pr merge --merge --delete-branch`. Required checks: Backend / Frontend / Mobile.

---

## 4. AI status (the part most likely to be misread)

- `AI_ENABLED` defaults to **false**; with it false the endpoint returns `503 ai_disabled` and writes a
  `disabled` row. **AI is not live.**
- A live evaluation **was** run against the OKMD/KKU gateway (text `llama-4-maverick`, vision
  `gemini-3.8-flash`) - evidence in `backend/docs/G4-LIVE-EVALUATION.md`.
- G4 is **not signed off**: the DPA / provider-approval item (AIG-010) is an owner decision, and the
  production text model (`deepseek-v4-flash`) must repeat the run after its daily quota resets.
- A local model can be scored with the same suites:
  `php artisan ai:evaluate --live --base-url=<tunnel>/v1 --model=<name>` (see `backend/docs/LOCAL-AI-PATHUMMA.md`).
- Credentials live only in `backend/.env` (gitignored). No key is tracked in git.
- **D2 (key rotation) is done (2026-10-25):** the owner rotated the key, and `ai:ping` now really contacts the
  provider (it forces `AI_ENABLED` for that one run and fails with `provider_not_configured` instead of reporting ok
  against the stub). The rotated key answers: `ok openai_compatible / deepseek-v4-flash 2620ms in=27 out=27`.

---

## 5. Open decisions (do not guess these)

| # | Decision | Blocking what |
|---|---|---|
| D1 | **Provider + DPA (AIG-010)**: which vendor, and written confirmation the data is not used for training. The letter is ready to send: `backend/docs/DPA-REQUEST-OKMD.md` (5 questions, EN + TH) | G4 sign-off, `AI_ENABLED=true` |
| D2 | ~~API keys rotated~~ - **done 2026-10-25**: rotated by the owner and verified with `php artisan ai:ping` against the real gateway (exit 0, 2.6 s, tokens counted). The command now forces `AI_ENABLED` for that one run and fails with `provider_not_configured` instead of reporting ok against the stub | done |
| D3 | ~~Retention numbers + privacy notice (Q-12)~~ - **signed 2026-10-25**: R1-R7 approved, R8 = 28-day grace (`retention.compact_grace_days`) so the notice's "within 30 days" holds. The repository keeps `retention.dry_run=true` by design; a deployment turns it on with `RETENTION_DRY_RUN=false` | done (the switch is per deployment) |
| D4 | ~~ClamAV before production~~ - **decided 2026-10-25: ON.** `DOCUMENTS_SCAN_REQUIRED` defaults to true, the app reaches clamd over `tcp://clamav:3310`, and the gate is proven with EICAR (422 + audit row), fail-closed (503), and a clean upload (201). Start with `--profile app --profile scan` | done |
| D5 | Pricing / plan entitlements (Q-2) | billing, quota tiers |

---

## 6. Known gaps (honest list)

1. **Knowledge base is lexical (SQLite FTS5 + Thai normaliser + aliases)**, and that was **measured against a
   local embedding model** on 2026-10-25: lexical hit@3 0.964 (now **1.000** after the Q-08 fix) vs bge-m3 vectors 0.929 on the same 28
   questions (Ollama 0.40.7 + bge-m3, 181 chunks). The vector store is still not justified: the symptom question was
   fixed with one source and two alias entries instead. Details, reproduction and the recommended alternative (re-rank the lexical
   top-20 with embeddings instead of replacing retrieval) are in `backend/docs/RAG-VECTOR-OPTIONS.md`.
2. **All 16 sources are `unreviewed` in the repository** (2 are reviewed in the local volume only), so every
   answer still carries the "not yet reviewed" warning until a clinician marks them with `knowledge:review` or
   the `/admin/knowledge` screen. Three tools now exist for that session: the generated
   `backend/docs/KNOWLEDGE-REVIEW-PACK.md` (provenance + excerpts + checklist),
   `backend/docs/KNOWLEDGE-REVIEW-SHEET.md` (the four questions each Group-1 source must answer, drawn from the
   benchmark, plus the 45-minute runbook and the nine remaining sources), and the console itself. The remaining
   work is the clinical decision.
3. **Load behaviour is measured by an in-process harness, not by concurrent load.** `php artisan perf:smoke`
   seeds a dedicated `perf-smoke` tenant (500 measurements over 24 months, 120 care tasks, 60 medication
   records, 12 appointments), drives the real HTTP kernel for the dashboard aggregate, the timeline and the
   care-task list, and reports p50/p95/p99 plus queries per request. Xdebug-off baseline on this machine
   (10 iterations): dashboard p95 156 ms / 36 queries, timeline p95 137 ms / 48 queries, care-tasks p95
   150 ms / 40 queries, all inside the 800/800/300 ms budgets. See `backend/docs/PERFORMANCE.md`. It is
   single-process and sequential; a real concurrent load test against the deployed stack is still a
   pre-launch task and the numbers are not capacity evidence.
4. **Backups cover the database, the corpus and the local document objects.** The nightly workflow dumps
   PostgreSQL (row counts + RLS probes on the restored copy) and archives the knowledge corpus
   (`knowledge:backup` -> `knowledge:restore-drill`: source JSON, unique ids, SQLite integrity, index counts,
   review fields, membership), uploads both as one 7-day artifact and drills the downloaded copy. Uploaded
   document objects on the local disk now have a producer too: `documents:backup` archives every object key the
   database expects and **fails when a metadata row has no object**, and `documents:restore-drill` re-reads the
   archive against its manifest. Failure alerting is wired as an **optional** step: setting the
   `BACKUP_ALERT_WEBHOOK` repository secret posts one line with the run URL (the step is a no-op without it), and
   the `::error::` annotations plus GitHub's failed-workflow notifications remain. Still **not** covered:
   S3/MinIO buckets (the command refuses a non-local driver; mirror the bucket instead), `.env`/`APP_KEY` escrow,
   off-site encryption and RPO/RTO - see `backend/docs/BACKUP-COVERAGE.md`.
5. **Documents are scanned before they are stored** (since 2026-10-25). `DOCUMENTS_SCAN_REQUIRED`
   defaults to true; clamd is reached over `tcp://clamav:3310` (sharing the unix socket does not work with the
   clamav image). The gate is proven with EICAR, and a deployment that forgets `--profile scan` fails closed
   with 503 rather than storing unscanned bytes - see `backend/docs/MALWARE-SCANNING.md`.
6. **Retention P1/P2/P2b/P3a/P3b are built; P3c and P4 are not.** `retention:compact` physically deletes rows
   soft-deleted longer than the 28-day grace (R8), audited, with holds respected and objects removed before their
   metadata row (`RETENTION-TURN-ON.md`). The five account states exist on `workspaces.retention_state`, with audited
   transitions (`retention:lapse` / `restore` / nightly `advance`) and an HTTP gate: read_only refuses writes,
   archived/scheduled_deletion refuse reads except export + the workspace metadata read, deleted answers 410.
   **P2b:** scheduled work starts from `Workspace::acceptingBackgroundWork()`, and a queued notification re-checks the
   state at delivery. **P3a:** `retention:notices` sends the 30/7/1-day notices in-app **and by email**, overrides
   channel preferences (legal notice), reaches the lapsed account through the documented job exception, and is
   idempotent through the delivery ledger. **P3b:** a lapse sends an in-app export offer on day 1. **Web:** a
   `read_only`/`archived`/`scheduled_deletion` account gets one banner in the shell (with a link to the export card);
   the API's 409 stays the authority. `RETENTION_DRY_RUN`
   is still `true` here on purpose (`RetentionDecisionPackTest` fails if it is flipped). **Still open:** plan-specific
   windows (D5), the cancellation choice screen (P3c - blocked on a permission change record), the final
   `scheduled_deletion -> deleted` arrow (P4), the separate archive store (P4) and the self-service restore/export
   screens.
7. **Mobile write gaps are closed** (appointments, invites, OCR, data export) and **history pagination now
   works**: the measurements list is cursor-paginated with an idempotent loadMore, dedupe by id and a
   footer that keeps the rows when a page fails.
8. **AI and export surfaces are at parity** (summary, knowledge answers, OCR, data export) on web and mobile.
   Mobile's download hands the short-lived URL to the clipboard because `url_launcher` is not a dependency.
9. **No knowledge source is reviewed yet.** The review path exists in the product (`/admin/knowledge`,
   CR-005) and in the console (`knowledge:review`); the remaining work is the clinical decision, not tooling.
   Start from `backend/docs/KNOWLEDGE-REVIEW-SHEET.md` (questions first), then the pack for the excerpts.
   Beware one API detail when scripting it: send `Accept: application/json`, otherwise Laravel redirects and
   the caller sees a misleading 500 instead of the 401.
10. **The demo smoke check uses password login, not OTP as the first step.** `/auth/otp/request` and
    `/auth/otp/verify` require an existing bearer token, so `scripts/smoke.sh` logs in with the seeded demo
    password, then exercises the OTP round-trip with that token. With `OTP_DEMO_MODE=true` it verifies the
    code; with the safe default (`false`) it reports that step as skipped. There is also no
    `GET /api/v1/workspaces` collection route: the script reads the workspace list from `/me` and then
    proves `GET /api/v1/workspaces/{id}`. See `backend/docs/DEMO-DATA.md`.

---

## 7. Where the detail lives

| Topic | File |
|---|---|
| Blueprint (design authority) | `.ai/` - start at `.ai/README.md` |
| Authorization baseline | `docs/baseline-v1.0.0/` (v1.4.0) |
| Change records | `.ai/changes/CR-001`, `CR-003`, `CR-004`, `CR-005` |
| G0 gate evidence | `backend/docs/G0-CHECKLIST.md` |
| Demo data and smoke check | `backend/docs/DEMO-DATA.md`, `backend/scripts/smoke.sh` |
| G4 live evaluation | `backend/docs/G4-LIVE-EVALUATION.md` |
| Knowledge base | `backend/docs/KNOWLEDGE-BASE.md` |
| Knowledge review pack (generated) | `backend/docs/KNOWLEDGE-REVIEW-PACK.md` - regenerate with `php artisan knowledge:review-pack` |
| RAG retrieval decision (lexical vs vector) | `backend/docs/RAG-VECTOR-OPTIONS.md` |
| Local model evaluation (Pathumma/Kaggle) | `backend/docs/LOCAL-AI-PATHUMMA.md` |
| Retention model (states, plan windows, P1) | `backend/docs/RETENTION-MODEL.md` |
| Retention runbook + rehearsal results | `backend/docs/RETENTION-TURN-ON.md` |
| Backup and restore | `backend/docs/BACKUP-COVERAGE.md`, `backend/docs/QUALITY-SWEEP-2026-10-24.md`, `backend/docs/BACKUP-COVERAGE.md` (coverage table), `.github/workflows/backup.yml` (nightly producer), `.github/workflows/restore-drill.yml` (weekly drill) |
| First overnight run | `MORNING-REPORT.md` |
| Second overnight run (this one) | `NIGHT-REPORT-2026-10-25.md` |

---

## 8. How to resume in one line

> "Read `PROJECT-STATE.md`, then continue with the next open item in section 5 or 6 - verify with the
> commands in section 3 before committing."

For the knowledge corpus, open `backend/docs/KNOWLEDGE-REVIEW-PACK.md`, review the 16 sources in one
sitting through `/admin/knowledge`, then regenerate the pack with `php artisan knowledge:review-pack`
(or `--status=all` to include already-reviewed sources).

---

## Start here (next session, 2026-10-26 00:45)

**Where the work stopped:** **retention P1/P2/P2b/P3a/P3b are built**, the **document-object backup producer** closes
the last unproduced part of gap 4 for the local disk, and the **web shell shows the account state** (one banner for
read_only/archived/scheduled_deletion with a link to the export card). Notices go out in-app + email at 30/7/1 days, a
lapse offers the export on day 1, and `documents:backup` fails when a metadata row has no object.
P3c (the cancellation choice screen) is blocked on a permission change record.

**Do this first, in order:**

1. **P4** - the separate archive store + restore action + access audit; only then does the final
   `scheduled_deletion -> deleted` arrow become honest.
2. **Load test (gap 3)** - a real concurrent run against the deployed stack, keeping inside the per-user rate limits
   (the current harness is single-process by design).
3. **P3c** once the owner approves a "cancel my account" permission change record.
4. **F** (16 sources) once the clinician is available; **D1** (send `DPA-REQUEST-OKMD.md`).

**Verified at hand-off:** backend 466 passed / 2 skipped / 4,689 assertions · web 140 · mobile 125 · 96 merged PRs ·
CI green · P1 rehearsed on the live stack · P2 states + gate tested (RET-S01..S08, RET-G01..G07) · P2b job filter
tested (WIRE-T12/T13, REC-T14, ESC-T13, RET-T04b) · P3a/P3b notices + export offer tested (RET-N01..N08) · document
backup tested (DOC-B01..B04) · web retention banner tested (FE5-T10/T11) · D2 `ai:ping` proven against the real
gateway · malware scanning ON and EICAR-proven · TLS + `/ready` live in the `proxy` profile.

**Session habit that worked:** one slice per session, verified by running the suite, then merged. Do not carry a
second slice in the same context - that is what forced the hand-off this time.
