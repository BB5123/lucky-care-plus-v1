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

### The finding: this deletes *logically*, not physically

`PurgeService` calls `$row->delete()` for models that use `SoftDeletes`, so `health_measurements` keeps its row and
only sets `deleted_at`. Tables without soft deletes are removed with a real `DELETE`. **There is no hard-delete or
compaction step anywhere in the codebase** (`grep forceDelete` finds nothing).

For a PDPA retention obligation that matters: the product currently *hides* the record from the application but the
data is still in the database, in backups, and in every dump. That is a decision for the owner, not a bug to fix
silently:

1. accept logical deletion (data remains, unreachable through the API), or
2. add a hard-delete pass that runs after a further grace period (say 30 days past `deleted_at`), with its own
   audit row and the same rehearsal discipline.

Until one is chosen, do not describe retention as "data destruction" in the privacy notice - describe it as
"removal from the service".

## Order of operations

1. **Back up first, both halves.**
   ```bash
   bash backend/scripts/backup-dump.sh              # PostgreSQL
   php backend/artisan knowledge:backup             # the corpus
   php backend/artisan knowledge:restore-drill      # prove the corpus archive
   ```
   The retention run cannot be undone from the database alone; the dump is the only rollback.
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
5. Only after that is clean, set `RETENTION_DRY_RUN=false` for the deployment and let the scheduler own it.
   Check the scheduler is running: `docker compose ps scheduler` (the `app` profile starts it).

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

## Rollback

- Restore the dump from step 1 into a scratch database, then copy the affected rows back **for that workspace
  only**. Do not restore the whole cluster over a live one.
- The corpus is independent of retention: restoring `knowledge:backup` never resurrects health rows.
- Audit rows are out of scope forever (R5) — they are the record of what happened, including a purge.
