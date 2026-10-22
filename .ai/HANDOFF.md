# HANDOFF — where we stopped

**Last updated:** 2026-10-23 (morning) · **Branch:** `main` · **Tests:** 153 passing / 2,100 assertions · **Pint:** clean (285 files)

---

## 1. Read this first when resuming

1. `AGENTS.md` (repo root) — rules and precedence
2. `.ai/README.md` — blueprint index
3. This file — the exact state and the next task

Everything below is committed locally. **Nothing is unstaged.**

---

## 2. ⚠️ Blocking item — `git push` needs one manual sign-in

Five commits are **local only**; the remote is still at `5fc9b73` because the cached GitHub credential
expired and Git Credential Manager cannot prompt inside the agent session.

```bash
cd E:\Projects\Workspace\dsh\lucky-care-plus-v1
git push origin main        # a GitHub sign-in window will appear — sign in once
```

Unpushed commits (oldest → newest):

| Commit | Slice |
|---|---|
| `68f0cd4` | Medication: plans, records, adherence |
| `8f968ea` | Care Task: three-tier state machine |
| `9260098` | Appointments + automatic follow-up task |
| `0e336fb` | Dashboard aggregate (permission-aware) |
| `cddd806` | Notifications: preferences, delivery ledger, adapters |

After pushing, check CI: <https://github.com/BB5123/lucky-care-plus-v1/actions>

---

## 3. Where the project stands

| Phase | State |
|---|---|
| 0 · Foundation | ✅ done (8/10 G0 items proven, 1 deferred to G4 by ADR-018, **1 open**: branch protection) |
| 1 · Core MVP | 🚧 5 of ~7 slices done: health vault, medication, care tasks, appointments, dashboard, notifications |
| 2–4 | ⬜ not started |

**Open gate condition:** G0 item 10 = branch protection on `main`. Running under **ADR-019**
(time-boxed override, **expires 2026-11-06**). Enabling required status checks closes it immediately.

See `backend/docs/G0-CHECKLIST.md` for the evidence table.

---

## 4. Next task — P1.6b: wire the notification dispatcher to domain events

`NotificationDispatcher` exists and is tested, **but nothing calls it in production**, so notifications
never fire. This is the last gap in the notification slice.

Scope:
- Listen for `CareTaskAssigned` → `task.assigned`, and a due-date reminder path → `task.due_soon`
- Listen for `AppointmentConfirmed`/upcoming → `appt.reminder`
- Fire in a queued job (the dispatcher already resolves context server-side), never inline in the request
- Never include PHI in `params` (the dispatcher enforces this — keep the templates clean)
- Tests: an event produces a delivery row per enabled channel; a disabled channel produces none

Then **P1.7**: `timeline_entries` projection + `timeline.read`, re-checking per-entry visibility,
plus a `timeline:rebuild` command (CRM-011: the projection must be rebuildable from events).

---

## 5. Local environment cheat-sheet

```bash
docker compose up -d            # postgres:5433, redis:6380 (minio is an optional profile)
cd backend
php artisan migrate:fresh --seed
php artisan test
php artisan retention:purge --dry-run
```

Runtime DB role is **`luckycare_app`** (NOSUPERUSER, NOBYPASSRLS). If RLS tests suddenly "pass" too easily,
check that the connection is not a superuser — that was a real incident (see `.ai/DECISIONS.md` ADR-013 context).

---

## 6. How the last slices were run (for continuity)

Each slice is delegated to `opencode` with a self-contained prompt, then verified independently:

```bash
opencode run --auto -m token_harbor/deepseek-v4.1-flash-1017 \
  --title "Phase 1.x <slice>" -f ".dsh-scratch/prompt-<slice>.md" "<instruction>"
```

The prompts are kept in `.dsh-scratch/` (gitignored). Review discipline that caught real bugs:

- run the **full** suite, not only the new tests (a retention/append-only conflict surfaced this way)
- diff the schema against `.ai/06-DATA-MODEL.md` §3 (missing `recorded_by`/`source` was caught this way)
- check RLS policies for command separation (the notification leak was caught this way)
- when a task instruction contradicts `docs/baseline-v1.0.0/*`, the **baseline wins** and the conflict is reported

---

## 7. Open questions still waiting on you / externals

| ID | Question | Waiting on |
|---|---|---|
| Q-2 | Pricing (3 tiers) | market test (G2) |
| Q-7 | LINE OA budget | vendor |
| Q-8 | eKYC / NDID phase | partner |
| Q-11 | Evidence list for representative L1–L3 | legal |
| Q-12 | Retention numbers + privacy notice | legal |
| Q-13 | Break-glass legal basis | legal |

Full list: `.ai/TRACEABILITY.md` §4.
