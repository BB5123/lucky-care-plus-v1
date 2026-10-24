# PROJECT STATE — Lucky Care Plus (LuckyCare+)

> **อ่านไฟล์นี้ก่อนทำงานทุกครั้ง.** It is the single source of truth for *where the work is*.
> It is written in English (like the rest of the repo) so any agent session can read it.
> Update it in the same PR as the work it describes — never let it drift.

**Last verified:** 2026-10-25 00:20 (Asia/Bangkok) on `main` @ `ed964fe`

---

## 1. What this product is

A family health CRM for Thai families: several caregivers coordinate the care of one person, with
fine-grained, revocable access to health data. Backend Laravel 13 / PHP 8.4 / PostgreSQL 16 (RLS),
web Angular 22 + Tailwind 4, mobile Flutter (`lucky_care`).

Design authority, in order: `.ai/00-CONSTITUTION.md` → `docs/baseline-v1.0.0/*` (content = **v1.3.0**)
→ other `.ai/*` → historical docs. A ticket never outranks the baseline.

---

## 2. Where the work stands

| Phase | State | Evidence |
|---|---|---|
| **0 · Foundation** | ✅ G0 passed (2026-10-23) | `backend/docs/G0-CHECKLIST.md` |
| **1 · Core MVP** | ✅ backend + web | health vault, medication, care tasks, appointments, dashboard, notifications, timeline, family |
| **2 · Coordination** | ✅ | recurring templates, escalation ladder, doctor brief, documents + photo evidence, quality sweep |
| **3 · AI** | 🚧 **G4 not signed off** | gateway, evaluation, OKMD adapter, vision drafts, knowledge base |
| **4 · Scale/compliance** | ⬜ | not started |

**Verified test counts (2026-10-25):**

| Suite | Result |
|---|---|
| backend `php artisan test` | **328 passed / 3,409 assertions** |
| web `npm test` | **111 passed / 40 files** |
| mobile `flutter test` | **59 passed** |
| `php artisan ai:evaluate` | all suites pass (offline) |

**Repo shape:** 15 backend modules · 51 migrations · 88 API routes · 22 web feature folders ·
8 mobile feature folders · **36 merged PRs** · no open PRs.

---

## 3. Commands that must pass before you call work done

```bash
cd backend
php artisan test                 # 328
php artisan ai:evaluate          # offline gate; --live uses the provider
./vendor/bin/pint --test

cd ../frontend
npm test                         # 111
npm run build
npm run check:encoding           # Thai double-encoding guard
npx prettier --check "src/**/*.{ts,html,css}"

cd ../mobile
flutter analyze
flutter test                     # 59
```

Workflow used throughout: branch → verify → commit → `gh pr create` → `gh pr checks --watch` →
`gh pr merge --merge --delete-branch`. Required checks: Backend / Frontend / Mobile.

---

## 4. AI status (the part most likely to be misread)

- `AI_ENABLED` defaults to **false**; with it false the endpoint returns `503 ai_disabled` and writes a
  `disabled` row. **AI is not live.**
- A live evaluation **was** run against the OKMD/KKU gateway (text `llama-4-maverick`, vision
  `gemini-3.8-flash`) — evidence in `backend/docs/G4-LIVE-EVALUATION.md`.
- G4 is **not signed off**: the DPA / provider-approval item (AIG-010) is an owner decision, and the
  production text model (`deepseek-v4-flash`) must repeat the run after its daily quota resets.
- Credentials live only in `backend/.env` (gitignored). No key is tracked in git.

---

## 5. Open decisions (do not guess these)

| # | Decision | Blocking what |
|---|---|---|
| D1 | **Provider + DPA (AIG-010)**: which vendor, and written confirmation the data is not used for training | G4 sign-off, `AI_ENABLED=true` |
| D2 | **API keys rotated** after they appeared in a chat transcript | security hygiene, not a blocker |
| D3 | Retention numbers + privacy notice (Q-12) — `retention.dry_run` is still true | turning retention on |
| D4 | ClamAV before production — `DOCUMENTS_SCAN_REQUIRED=false`, uploads are unscanned | production readiness |
| D5 | Pricing / plan entitlements (Q-2) | billing, quota tiers |

---

## 6. Known gaps (honest list)

1. **AI UI is web-only**; mobile has no AI summary / knowledge-ask screens yet.
2. **Knowledge base is lexical (SQLite FTS5)**, not vector search — the provider has no embeddings
   endpoint. `KnowledgeRetriever` is the seam for a future Qdrant/pgvector retriever.
3. **No load test** on the dashboard aggregate or the timeline endpoint.
4. **Backups are produced nightly in CI, not in production.** `.github/workflows/backup.yml` +
   `backend/scripts/backup-dump.sh` dump the seeded database with `pg_dump -Fc`, verify the archive's
   table of contents (`workspaces`, `health_profiles`, `care_tasks`) and its sha256, upload it as a
   7-day build artifact, then restore *that artifact* into a scratch database and re-run the three RLS
   probes. A GitHub artifact is a safety net, not a compliance backup: no encrypted off-site storage,
   no key management, no RPO/RTO (D3/Q-12) — those belong to the deployment target.
5. **Documents are unscanned** (fail closed only if `scan_required` is turned on).
6. **Retention has never deleted anything** (`dry_run = true`).
7. **Mobile** has no appointments create/edit, no family invite, no history pagination — read-mostly.

---

## 7. Where the detail lives

| Topic | File |
|---|---|
| Blueprint (design authority) | `.ai/` — start at `.ai/README.md` |
| Authorization baseline | `docs/baseline-v1.0.0/` (v1.3.0) |
| Change records | `.ai/changes/CR-001|CR-003|CR-004` |
| G0 gate evidence | `backend/docs/G0-CHECKLIST.md` |
| G4 live evaluation | `backend/docs/G4-LIVE-EVALUATION.md` |
| Knowledge base | `backend/docs/KNOWLEDGE-BASE.md` |
| Backup and restore | `backend/docs/QUALITY-SWEEP-2026-10-24.md` · `.github/workflows/backup.yml` (nightly producer) · `.github/workflows/restore-drill.yml` (weekly drill) |
| First overnight run | `MORNING-REPORT.md` |

---

## 8. How to resume in one line

> “Read `PROJECT-STATE.md`, then continue with the next open item in §5/§6 — verify with the commands
> in §3 before committing.”
