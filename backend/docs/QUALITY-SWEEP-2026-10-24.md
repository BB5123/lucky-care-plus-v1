# Quality sweep — 2026-10-24

Operational evidence collected on this workstation. Every number below came from a command run during the
sweep, not from memory.

| | |
|---|---|
| Branch | `main` @ `478a12e` |
| Backend suite | 234 passed / 2,795 assertions |
| Permission IDs | 99 (baseline v1.2.0) |
| Database | PostgreSQL 16, container `luckycare-postgres`, database `lucky_care_plus` |
| Runtime role | `luckycare_app` (NOSUPERUSER, NOBYPASSRLS) |
| Admin role | `luckycare` (superuser — migrations only) |

---

## 1. Backup and restore drill

### What was run

```bash
docker exec luckycare-postgres pg_dump -U luckycare -d lucky_care_plus -Fc -f /tmp/lc-backup.dump
docker cp luckycare-postgres:/tmp/lc-backup.dump backup.dump
docker exec luckycare-postgres psql -U luckycare -d postgres -c "create database lucky_care_restore_test owner luckycare;"
docker exec luckycare-postgres pg_restore -U luckycare -d lucky_care_restore_test /tmp/lc-backup.dump
```

### Finding 1 — `--no-owner` silently breaks the application role

The first restore used `pg_restore --no-owner`. It reported success, row counts matched, and 37 policies on
32 RLS-enabled tables came back. But then, as the application role:

```
ERROR: permission denied for table workspaces
```

`--no-owner` makes the restoring user the owner, so `luckycare_app` loses the table privileges gained through
schema ownership. A restore that "succeeds" this way produces a database the app cannot use — and, worse, one
where the RLS story is untested because the role cannot even reach the table.

**Rule: restore with ownership preserved** (`pg_restore` without `--no-owner`, or `--role=luckycare_app`),
then prove it with the probes below.

### Finding 2 — after a correct restore, RLS is genuinely enforced

Restored again without `--no-owner`; `pg_tables.tableowner` for `workspaces` is `luckycare_app`. Probes as the
application role:

| Probe | Result |
|---|---|
| no `app.tenant_id` / `app.workspace_id` | **0 rows** |
| correct tenant + workspace | **1 row** |
| wrong tenant id (all zeroes) | **0 rows** |

Row counts matched the source exactly: `tenants=1 users=3 workspaces=1 memberships=3 audit_events=0
security_audit_logs=0`.

The scratch database and the dump were removed afterwards; only `postgres` and `lucky_care_plus` remain.

### Recommendation

- Keep the `pg_dump -Fc` + restore-into-scratch + **RLS probe** sequence as the documented recovery test.
- Do not use `--no-owner` for this schema; the app role owns the tables on purpose.
- A production backup job should dump with `-Fc` and store the dump encrypted, off the database host.

---

## 2. Migration rollback drill

```bash
php artisan migrate:reset --force     # exit 0, every down() ran
# public schema tables left: 1 (the migrations bookkeeping table)
php artisan migrate --seed --force    # exit 0, 45 tables, seeder ran
php artisan test                      # 234 passed / 2,795 assertions
```

RLS still enforced after the cycle (0 rows without context), so no migration re-created a table without its
policy or `FORCE ROW LEVEL SECURITY`.

**Conclusion:** every migration has a working `down()`, and a full teardown + rebuild reproduces the schema
the tests expect.

---

## 3. Secrets sweep

| Check | Result |
|---|---|
| Tracked files matching `.env`, `*.pem`, `*.key`, `id_rsa`, `credentials.json` | only `backend/.env.example` |
| `ghp_…`, `sk-…`, `AKIA…`, `BEGIN … PRIVATE KEY` in tracked files | **none** |
| `git log --all -- .env backend/.env` | **never committed** |
| Local `backend/.env` | untracked (gitignored) |

---

## 4. PHI and authorization guards

| Guard | Result |
|---|---|
| `PhiLeakTest` (log calls must not contain PHI or credentials) | 3 passed / 6 assertions |
| `RoutePermissionCoverageTest` (every API route declares a catalogued permission or is allowlisted) | 5 passed / 66 assertions |
| Declared permission IDs | 99 (matches baseline v1.2.0) |

---

## 5. What is still open after this sweep

1. **Documents are unscanned by default, but the scanner is now wired and fail-closed.**
   `DOCUMENTS_SCAN_REQUIRED` remains false, so accepted uploads still record `scan_status = skipped` and
   log a warning. The new ClamAV unix-socket adapter, the `MalwareScanner` contract and the fail-closed
   verdict handling are in place (422 `document_infected` for infected bytes, 503 `scan_unavailable` for
   scanner errors, clean only after a clean verdict); see `backend/docs/MALWARE-SCANNING.md`. It stays
   disabled until the owner decides to turn it on and a deployment runs clamd with a socket the backend
   can see (D4 in `PROJECT-STATE.md`).
2. **Retention still runs dry.** `retention.dry_run` is true (ADR-016); the numbers need legal sign-off
   (Q-12) before it deletes anything.
3. ~~**No automated restore test.**~~ **Closed the same day.** `backend/scripts/restore-drill.sh` plus
   `.github/workflows/restore-drill.yml` (weekly Sunday 03:00 UTC, plus manual dispatch) dump, restore with
   ownership preserved, compare every table's row count and run the three RLS probes. The first CI dispatch
   passed on 2026-10-24 (run `40074656709`, conclusion `success`). Two real defects were found while
   automating it: the workflow had not set `DB_USERNAME`/`DB_PASSWORD` as real environment variables (so
   Laravel read `.env.example` and authentication failed), and the script was committed without the
   executable bit.
4. ~~**Load behaviour is unmeasured.**~~ **Satisfied by a repeatable harness on 2026-10-25.**
   `php artisan perf:smoke` seeds a marked synthetic workspace (500 measurements over 24 months,
   120 care tasks, 60 medication records, 12 appointments), drives the real HTTP kernel for the
   dashboard aggregate, the timeline and the care-task list, and reports p50/p95/p99 plus queries per
   request. The measured Xdebug-off baseline on this machine is dashboard p95 156 ms / 36 queries,
   timeline p95 137 ms / 48 queries, care-tasks p95 150 ms / 40 queries (10 iterations, 800/800/300 ms
   budgets). See [`PERFORMANCE.md`](PERFORMANCE.md). **Honest note:** this is a single-process,
   no-concurrency harness against a local database. A real concurrent load test with multiple clients,
   network and the deployed stack is still a pre-launch task; these numbers must not be quoted as
   capacity evidence.
5. ~~**Backups are not scheduled.**~~ **Satisfied for CI on 2026-10-25.** `backend/scripts/backup-dump.sh`
   plus `.github/workflows/backup.yml` (nightly at 19:00 UTC = 02:00 Asia/Bangkok, plus manual dispatch)
   dump the seeded database with `pg_dump -Fc`, read the archive back with `pg_restore --list` and fail
   unless `workspaces`, `health_profiles` and `care_tasks` are present, write a `.sha256` next to the dump,
   and prune dumps older than `KEEP_DAYS` **inside the backup directory only**, refusing to prune if that
   would leave zero dumps. The workflow uploads dump + checksum as a build artifact with 7-day retention,
   downloads that artifact back, and feeds it to `restore-drill.sh` through `DUMP_FILE`, so the table row
   counts and the three RLS probes run against the artifact itself rather than a fresh dump. The first
   scheduled or manually dispatched run is what proves the whole path end to end; outside CI only the
   script logic can be checked, not the service container, artifact store or restore.
   **This is not a production backup.** A GitHub artifact with 7-day retention lives in the same CI system
   that produces it, `-Fc` is compression and not encryption, and neither off-site storage nor RPO/RTO is
   defined by it. A production backup — encrypted storage away from the database host, key management,
   restore drills against production data, agreed RPO/RTO numbers — still belongs to the deployment
   target (D3/Q-12 in `PROJECT-STATE.md`).
6. ~~**The knowledge corpus has no backup.**~~ **Closed 2026-10-25.** Since the corpus is a volume (#67)
   and holds the clinician review decisions, the nightly workflow now also runs
   `php artisan knowledge:backup` and proves the archive with `php artisan knowledge:restore-drill`
   (source JSON, unique ids, SQLite `integrity_check`, index counts, lost `reviewed_by`/`reviewed_at`,
   membership drift), then uploads and downloads it back like the dump. The full covered/not-covered
   table — object storage for uploaded documents, `.env`/secrets, Redis, off-site storage, monitoring —
   is in [`BACKUP-COVERAGE.md`](BACKUP-COVERAGE.md).

---

## 6. Reproduce this sweep

```bash
# 1. backup / restore
docker exec luckycare-postgres pg_dump -U luckycare -d lucky_care_plus -Fc -f /tmp/lc.dump
docker exec luckycare-postgres psql -U luckycare -d postgres -c "drop database if exists lc_restore;"
docker exec luckycare-postgres psql -U luckycare -d postgres -c "create database lc_restore owner luckycare;"
docker exec luckycare-postgres pg_restore -U luckycare -d lc_restore /tmp/lc.dump
docker exec luckycare-postgres psql -U luckycare -d postgres -c "grant connect on database lc_restore to luckycare_app;"
docker exec luckycare-postgres psql -U luckycare_app -d lc_restore -tAc "select count(*) from workspaces;"   # expect 0

# 2. migrations
cd backend && php artisan migrate:reset --force && php artisan migrate --seed --force && php artisan test

# 3. guards
php artisan test --filter="PhiLeakTest|RoutePermissionCoverage|TenantIsolation"

# 4. secrets
git ls-files | grep -E '(\.env$|\.pem$|\.key$)' | grep -v example
git grep -nE 'ghp_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}' -- . ':(exclude)references/*'
```
