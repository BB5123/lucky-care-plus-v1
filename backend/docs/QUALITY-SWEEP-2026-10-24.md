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

1. **Documents are stored unscanned.** `DOCUMENTS_SCAN_REQUIRED` is false by default; the upload fails closed
   (503) if it is set without a scanner. Wire ClamAV before production.
2. **Retention still runs dry.** `retention.dry_run` is true (ADR-016); the numbers need legal sign-off
   (Q-12) before it deletes anything.
3. **No automated restore test.** The drill above is manual; a scheduled job that restores the latest dump
   into a scratch database and runs the three RLS probes would make it continuous.
4. **Load behaviour is unmeasured.** No load test has been run against the dashboard aggregate or the
   timeline endpoint.
5. **Backups are not scheduled.** Nothing currently produces a nightly dump.

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
