# Turning retention on — the runbook (D3)

`retention.dry_run` is still `true`; nothing has ever been deleted. The numbers are the owner's decision
(`RETENTION-DECISION-PACK.md`, R1–R5). This file is the *operational* half: what to do once those numbers are
signed, in an order that can be undone.

## What is already verified (2026-10-25)

```
docker compose --profile app exec -T app php artisan retention:purge --dry-run
```

Every type reports `0 eligible` on the demo data, and `ai_conversations` reports `table_not_present` instead of
failing the run — the command tells the truth about what it can and cannot purge. `--type=` and `--limit=` exist,
so a first real run can be one type, one batch.

## Live re-verification after P4b-1 (2026-10-26)

The `app` image was rebuilt from `main` and the stack restarted (`app`, `queue`, `scheduler`, plus `web` for the
nginx upstream). Then, against the demo workspace:

| Command | Result |
|---|---|
| `retention:status --verify-archives` | `active 1`, every other state 0; dry run `true`; nothing due; `0` held; `0` upcoming; "No archived workspaces to verify"; exit 0 |
| `retention:purge --dry-run` | every type `0 eligible`, `ai_conversations` `table_not_present`, "no rows were deleted" |
| `app:key-fingerprint` | `AES-256-CBC`, key length 32, the same sha256 as the host `.env` (the container and the host agree on the key) |
| `retention:archive-manifest <demo>` | exits 1 with "No archive manifest exists ... (it has never entered `archived`)" - the expected answer for an active account |
| `schedule:list` | `retention:purge` 03:00, `retention:compact` 03:20, `retention:advance` 03:40, `retention:notices` 04:20, `retention:inactivity` 04:00 (UTC) |

The archive drill itself is covered by `RET-B01..B04` with a fake disk; the demo workspace is deliberately left
`active`, so the live stack has no bundle to drill.

## Rehearsed end to end on 2026-10-25 (live stack, synthetic row)

One row dated `2021-01-15` was inserted as the database owner (RLS hides it from the app otherwise), then:

```
retention:purge --type=health_measurements --limit=100   (RETENTION_DRY_RUN=false)
| health_measurements | 1 eligible | 1 purged | 0 held | dry run: no | done |
```

| Check | Result |
|---|---|
| the old row no longer counts as live | `deleted_at` set: 1 soft-deleted, 180 live |
| recent rows untouched | 180 live, unchanged |
| audit | `retention.purge` / `allow` written (the earlier dry run wrote one with `reason_code = dry_run`) |
| running it again | `0 eligible` - idempotent |

### The finding (2026-10-25): purge deletes *logically*, not physically - closed by P1

`PurgeService` calls `$row->delete()` for models that use `SoftDeletes`, so `health_measurements` keeps its row and
only sets `deleted_at`. Tables without soft deletes are removed with a real `DELETE`. Until 2026-10-25 there was no
hard-delete or compaction step anywhere in the codebase (`grep forceDelete` found nothing).

That is now the `retention:compact` pass (`RETENTION-MODEL.md` section 8, P1, built 2026-10-25). It physically removes
rows that have been soft-deleted for at least the grace period (28 days, R8, `retention.compact_grace_days`), writes
one `retention.compact` audit row per batch, respects legal holds, deletes a document object before its metadata row,
and is gated by the same `RETENTION_DRY_RUN` switch. **The privacy notice may describe "deletion" only once that pass
runs with `RETENTION_DRY_RUN=false`** - which is still not the case in this repository: the flag stays `true` in
`.env`/`config` on purpose, and turning it on is a deployment action (step 6 below).

## Order of operations

0. **Read the state machine before touching it.**
   ```bash
   php artisan retention:status --verify-archives
   ```
   It prints the workspace count per state, what is due now, what is due within 30 days, how many accounts are
   pinned by a legal hold, and (with the flag) whether every archived bundle passes the same checks as
   `retention:archive-drill`. `--json` is available for a monitoring probe; `--verify-archives` exits non-zero when
   any archive fails, so it can be scheduled separately.
1. **Back up first, both halves.**
   ```bash
   bash backend/scripts/backup-dump.sh              # PostgreSQL
   php backend/artisan knowledge:backup             # the corpus
   php backend/artisan knowledge:restore-drill      # prove the corpus archive
   ```
   The retention run cannot be undone from the database alone; the dump is the only rollback.
   The **archive disk** (`RETENTION_ARCHIVE_DISK`) is not in that dump: copy it too, and keep `APP_KEY`
   (the bundles are encrypted with it).
2. **Rehearse on synthetic rows**, never on real data (below).
3. **One type, one small batch, dry run first:**
   ```bash
   php artisan retention:purge --type=health_measurements --limit=100 --dry-run
   ```
   Read the eligible count per workspace before deleting anything.
4. **Delete that batch**, then read the audit:
   ```bash
   RETENTION_DRY_RUN=false php artisan retention:purge --type=health_measurements --limit=100
   ```
5. **Compact that batch**, first as a dry run, then for real - one type, small batch:
   ```bash
   php artisan retention:compact --type=health_measurements --dry-run
   RETENTION_DRY_RUN=false php artisan retention:compact --type=health_measurements --limit=100
   ```
   Without `--older-than` the command uses the signed policy (28 days, R8). Use `--older-than=1` only to prove the
   path on a synthetic row soft-deleted yesterday. Re-run it and expect `0 eligible`.
6. Only after both are clean, set `RETENTION_DRY_RUN=false` for the deployment and let the scheduler own it.
   Check the scheduler is running: `docker compose ps scheduler` (the `app` profile starts it) and that
   `schedule:list` shows `retention:purge` (03:00 UTC) and `retention:compact` (03:20 UTC).

   **How a bad night reports.** `retention:advance` now wraps each workspace in its own savepoint: one failed archive
   write (full disk, read-only volume) is reported as a `failed` row, the state change for that workspace rolls back,
   the other workspaces are still processed, and the command exits non-zero so the scheduler marks the run failed. The
   log entry is `retention.advance.failed` with the workspace id and the exception **class only** - a database error
   message can carry bound values, so the message is not logged (N-02). Read the table, fix the disk, re-run; the
   transition is idempotent.

## Rehearsal recipe (the one to use before step 3)

Seeding "old" rows needs a **tenant context**, because RLS hides everything without one. This is the trap: a
throwaway script that does `HealthProfile::first()` in a CLI command finds nothing and looks like a broken
database. Set the context first:

```php
App\Support\Database\TenantContext::set($tenantId, $workspaceId);   // then query
```

Then, for the type under test:

| Step | Expected |
|---|---|
| insert 3 rows dated 61 months ago + 1 today | 4 rows |
| `retention:purge --type=<type> --limit=100` with `RETENTION_DRY_RUN=false` | the 3 old rows are gone, the recent one stays |
| count `security_audit_logs` before and after | +1 (or more), never 0 |
| `retention:purge --dry-run` again | `0 eligible` for that type |
| any other workspace's rows | unchanged |

If the audit counter does not move, **stop** — a silent deletion is worse than none, and that is the one thing this
project has consistently refused to accept.

## Compaction: rehearsed end to end on 2026-10-25 (live stack, synthetic rows)

Rows were inserted as the database superuser (RLS hides them from the app otherwise) into the demo workspace, with
`deleted_at` set to controlled ages. The `app` image was rebuilt first, so the container ran the branch's code. The
run used the then-default grace of 30 days; the signed policy (R8) is **28 days**, so `--older-than` is no longer
needed for a real run - the numbers below are otherwise unchanged.

| Step | Expected | Observed |
|---|---|---|
| `retention:compact --type=health_measurements --older-than=30 --dry-run` | 1 eligible, 0 compacted | `Eligible 1 / Compacted 0 / dry run yes` |
| `RETENTION_DRY_RUN=false retention:compact --type=health_measurements --older-than=30` | the 40-day row is physically gone | 1 compacted; `select` for that id returns **0 rows** (no `deleted_at`, no row) |
| the row soft-deleted 5 days ago | untouched (inside the grace) | still present with its `deleted_at` |
| the live row dated 2021 (never deleted) | untouched | still present, `deleted_at` null |
| audit | +1 `retention.compact` row, counts only | `{"compacted":1,"evaluated":1,"grace_days":30,"dry_run":false}` |
| the dry run | audited too, `reason_code = dry_run` | `{"compacted":0,"evaluated":1,...,"dry_run":true}` |
| run it again | `0 eligible` | `0 / 0 / 0` |
| a profile-level legal hold + a 40-day row | held, not deleted | `Held 1`, row still present; after releasing the hold the same run compacts it |
| `retention:compact --dry-run` (every type) | truthful skip list | `task_events`/`task_evidence` = `no_soft_deletes`, `ai_conversations` = `table_not_present`, the rest `done` |
| the demo data | unchanged | 180 live measurements before and after; the synthetic rows were deleted by hand afterwards |

Two operational notes from the run:

- The order is **purge, then compact**. The purge sets `deleted_at` today; compaction only removes rows whose
  `deleted_at` is already older than the grace, so the same night's deletion can never be compacted by accident.
- Compaction is gated by `RETENTION_DRY_RUN` exactly like the purge. With the default (`true`) it reports
  `Eligible` and deletes nothing - that is the state of this repository today, and it is why the notice still must
  not promise physical deletion.

## Rollback

- Restore the dump from step 1 into a scratch database, then copy the affected rows back **for that workspace
  only**. Do not restore the whole cluster over a live one.
- The corpus is independent of retention: restoring `knowledge:backup` never resurrects health rows.
- Audit rows are out of scope forever (R5) — they are the record of what happened, including a purge.
