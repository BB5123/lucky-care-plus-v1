#!/usr/bin/env bash
#
# Restore drill: prove that a dump of the live schema can be restored and that
# row-level security still holds after the restore.
#
# Why this exists
# ---------------
# A manual drill on 2026-10-24 found that `pg_restore --no-owner` "succeeds",
# restores every row and policy, and still produces a database the application
# role cannot use (`permission denied for table workspaces`). A restore that
# only checks the exit code is therefore worthless. This script asserts the
# three things that actually matter:
#
#   1. every table's row count matches the source,
#   2. the application role can read its own tenant's rows,
#   3. the same role sees nothing without tenant context, and nothing for a
#      different tenant.
#
# Usage (CI or a workstation with the pg client tools):
#
#   SOURCE_DB=lucky_care_plus APP_ROLE=luckycare_app \
#   PGHOST=127.0.0.1 PGPORT=5432 PGUSER=luckycare \
#   ./backend/scripts/restore-drill.sh
#
# Set DUMP_FILE to drill an archive that already exists instead of dumping the
# source database again. `.github/workflows/backup.yml` uses this to restore the
# *uploaded artifact*, which proves the artifact itself is restorable rather
# than proving it about a fresh dump taken seconds later:
#
#   DUMP_FILE=./backups/luckycare-20261025T190000Z.dump \
#   SOURCE_DB=lucky_care_plus APP_ROLE=luckycare_app ./backend/scripts/restore-drill.sh
#
# The database must contain at least one workspace (run `migrate:fresh --seed`
# first). Exit code 0 means the drill passed; anything else means it failed and
# the scratch database was removed.
set -euo pipefail

SOURCE_DB="${SOURCE_DB:-lucky_care_plus}"
ADMIN_DB="${ADMIN_DB:-postgres}"
APP_ROLE="${APP_ROLE:-luckycare_app}"
SCRATCH_DB="${SCRATCH_DB:-restore_drill_$$}"
# Optional: restore this archive instead of dumping SOURCE_DB. When set, the
# file is read-only input and is never deleted by this script.
DUMP_FILE="${DUMP_FILE:-}"
TMP_DUMP=""

# Creating and dropping a database needs an admin role; the application role is
# deliberately NOCREATEDB, so the two must not be confused. `ADMIN_PASSWORD` is
# optional (local trust auth needs none).
ADMIN_USER="${ADMIN_USER:-postgres}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"

admin_psql() {
  if [ -n "$ADMIN_PASSWORD" ]; then
    PGPASSWORD="$ADMIN_PASSWORD" psql -U "$ADMIN_USER" "$@"
  else
    PGPASSWORD="" psql -U "$ADMIN_USER" "$@"
  fi
}

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

cleanup() {
  admin_psql -d "$ADMIN_DB" -v ON_ERROR_STOP=0 -c "drop database if exists \"$SCRATCH_DB\";" >/dev/null 2>&1 || true
  if [ -n "$TMP_DUMP" ]; then
    rm -f "$TMP_DUMP"
  fi
}
trap cleanup EXIT

# 1. Dump (or reuse an existing archive) ------------------------------------
if [ -n "$DUMP_FILE" ]; then
  [ -f "$DUMP_FILE" ] || fail "DUMP_FILE does not exist: $DUMP_FILE"
  [ -s "$DUMP_FILE" ] || fail "DUMP_FILE is empty: $DUMP_FILE"
  RESTORE_FROM="$DUMP_FILE"
  DUMP_ORIGIN="existing dump $DUMP_FILE"
else
  TMP_DUMP="$(mktemp "${TMPDIR:-/tmp}/lc-restore-drill-XXXXXX")"
  RESTORE_FROM="$TMP_DUMP"
  DUMP_ORIGIN="fresh dump of $SOURCE_DB"
  PGPASSWORD="$ADMIN_PASSWORD" pg_dump -U "$ADMIN_USER" -d "$SOURCE_DB" -Fc -f "$TMP_DUMP" || fail "pg_dump failed"
  [ -s "$TMP_DUMP" ] || fail "dump file is empty"
fi

echo "== restore drill: $DUMP_ORIGIN -> $SCRATCH_DB =="

# 2. Scratch database + restore --------------------------------------------
admin_psql -d "$ADMIN_DB" -v ON_ERROR_STOP=1 -c "drop database if exists \"$SCRATCH_DB\";" >/dev/null
admin_psql -d "$ADMIN_DB" -v ON_ERROR_STOP=1 -c "create database \"$SCRATCH_DB\";" >/dev/null

# Ownership is preserved on purpose. --no-owner makes the restoring user the
# owner, which strips the application role's privileges and produces a database
# the app cannot use (this is the bug the drill was written to catch).
PGPASSWORD="$ADMIN_PASSWORD" pg_restore -U "$ADMIN_USER" -d "$SCRATCH_DB" "$RESTORE_FROM" || fail "pg_restore failed"

admin_psql -d "$ADMIN_DB" -v ON_ERROR_STOP=1 \
  -c "grant connect on database \"$SCRATCH_DB\" to \"$APP_ROLE\";" >/dev/null

# 3. Row counts, for every table in the schema ------------------------------
# The table list is discovered rather than hard-coded, so a new table is covered
# without editing this script.
echo "-- row counts"
mapfile -t TABLES < <(admin_psql -d "$SOURCE_DB" -tAc \
  "select tablename from pg_tables where schemaname = 'public' order by tablename;")

[ "${#TABLES[@]}" -gt 0 ] || fail "no tables found in $SOURCE_DB"

MISMATCH=0
for table in "${TABLES[@]}"; do
  source_count=$(admin_psql -d "$SOURCE_DB" -tAc "select count(*) from \"$table\";")
  restored_count=$(admin_psql -d "$SCRATCH_DB" -tAc "select count(*) from \"$table\";")

  if [ "$source_count" != "$restored_count" ]; then
    echo "   MISMATCH $table: source=$source_count restored=$restored_count"
    MISMATCH=1
  fi
done
[ "$MISMATCH" -eq 0 ] || fail "row counts do not match"
printf '   ok       %s tables match\n' "${#TABLES[@]}"

# 4. RLS probes -------------------------------------------------------------
echo "-- row-level security probes (as $APP_ROLE)"

read -r TENANT_ID WORKSPACE_ID <<<"$(admin_psql -d "$SCRATCH_DB" -tAc \
  "select tenant_id || ' ' || id from workspaces order by id limit 1;")"

[ -n "${TENANT_ID:-}" ] || fail "no workspace row to probe with (run migrate:fresh --seed first)"

no_context=$(psql -U "$APP_ROLE" -d "$SCRATCH_DB" -tAc "select count(*) from workspaces;")
[ "$no_context" = "0" ] || fail "without tenant context the app role saw $no_context rows (expected 0)"

own_tenant=$(psql -U "$APP_ROLE" -d "$SCRATCH_DB" -tAc "
  begin;
  select set_config('app.tenant_id', '$TENANT_ID', true),
         set_config('app.workspace_id', '$WORKSPACE_ID', true);
  select count(*) from workspaces;
  commit;" | tail -2 | head -1)

[ "${own_tenant:-0}" -ge 1 ] || fail "with its own tenant the app role saw $own_tenant rows (expected >= 1)"

other_tenant=$(psql -U "$APP_ROLE" -d "$SCRATCH_DB" -tAc "
  begin;
  select set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true);
  select count(*) from workspaces;
  commit;" | tail -2 | head -1)

[ "${other_tenant:-1}" = "0" ] || fail "with a foreign tenant the app role saw $other_tenant rows (expected 0)"

printf '   ok       no context=%s  own tenant=%s  foreign tenant=%s\n' \
  "$no_context" "$own_tenant" "$other_tenant"

echo "== restore drill passed =="
