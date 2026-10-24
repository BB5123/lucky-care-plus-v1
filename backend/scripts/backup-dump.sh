#!/usr/bin/env bash
#
# Nightly backup producer: dump the database, prove the archive is readable,
# checksum it, and prune old copies inside the backup directory.
#
# Why this exists
# ---------------
# `restore-drill.sh` proves that a dump *can* be restored, but for a long time
# nothing produced a dump at all (PROJECT-STATE.md §6 gap 4). A drill without a
# producer is a false comfort. This script is the producer side and is used by
# `.github/workflows/backup.yml`.
#
# What it does, in order:
#
#   1. create BACKUP_DIR if it does not exist,
#   2. `pg_dump -Fc` into `luckycare-<UTC timestamp>.dump`,
#   3. read the archive back with `pg_restore --list` and fail if a core table
#      is missing (an archive that cannot even be listed is not a backup),
#   4. write a `.sha256` next to the dump and print its size,
#   5. prune dumps older than KEEP_DAYS inside BACKUP_DIR, refusing to leave
#      zero dumps behind,
#   6. exit non-zero on any failure and remove the partial dump.
#
# Usage (CI or a workstation with the pg client tools):
#
#   SOURCE_DB=lucky_care_plus PGHOST=127.0.0.1 PGPORT=5433 \
#   ADMIN_USER=postgres ADMIN_PASSWORD=postgres \
#   ./backend/scripts/backup-dump.sh
#
# Connection details other than the ones below (PGHOST, PGPORT, PGSSLMODE, ...)
# are read from the standard libpq environment, exactly like restore-drill.sh.
#
# This is NOT a compliance backup: the file is plaintext (`-Fc` is a compressed
# archive, not encryption), local to the machine that runs it, and retention is
# best-effort. Off-site storage, encryption at rest and RPO/RTO belong to the
# deployment target.
set -euo pipefail

SOURCE_DB="${SOURCE_DB:-lucky_care_plus}"
ADMIN_DB="${ADMIN_DB:-postgres}"
ADMIN_USER="${ADMIN_USER:-postgres}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
KEEP_DAYS="${KEEP_DAYS:-7}"

# The tables every archive of this product must contain. If one of them is
# missing, whatever was dumped is not this database (wrong SOURCE_DB, a partial
# archive, an empty schema) and the run must fail loudly.
CORE_TABLES=(workspaces health_profiles care_tasks)

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

case "$KEEP_DAYS" in
  "" | *[!0-9]*) fail "KEEP_DAYS must be a non-negative integer, got '$KEEP_DAYS'" ;;
esac

# Creating and dropping databases needs an admin role; the application role is
# deliberately NOCREATEDB/NOSUPERUSER. The admin connection is only used for
# the preflight check here, but keeping the same parameters as restore-drill.sh
# means one set of variables drives both scripts.
admin_psql() {
  if [ -n "$ADMIN_PASSWORD" ]; then
    PGPASSWORD="$ADMIN_PASSWORD" psql -w -U "$ADMIN_USER" "$@"
  else
    PGPASSWORD="" psql -w -U "$ADMIN_USER" "$@"
  fi
}

# Normalise a trailing slash so the "inside BACKUP_DIR" guard below compares
# like with like (`./backups/` and `./backups` must behave the same).
if [ "$BACKUP_DIR" != "/" ]; then
  BACKUP_DIR="${BACKUP_DIR%/}"
fi

mkdir -p "$BACKUP_DIR" || fail "cannot create backup directory '$BACKUP_DIR'"
[ -d "$BACKUP_DIR" ] || fail "'$BACKUP_DIR' is not a directory"
[ -w "$BACKUP_DIR" ] || fail "backup directory '$BACKUP_DIR' is not writable"

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
DUMP_FILE="$BACKUP_DIR/luckycare-$timestamp.dump"
CHECKSUM_FILE="$DUMP_FILE.sha256"
TOC_FILE="$(mktemp "${TMPDIR:-/tmp}/lc-backup-toc-XXXXXX")"
COMPLETE=0

# `set -e` does not run the cleanup on every failure path, so the trap does it.
# Anything short of a completed run leaves no half-written dump behind: a
# truncated archive that looks like a backup is worse than no archive at all.
cleanup() {
  rm -f "$TOC_FILE"
  if [ "$COMPLETE" -ne 1 ]; then
    if [ -f "$DUMP_FILE" ]; then
      echo "cleanup: removing incomplete dump '$DUMP_FILE'" >&2
      rm -f "$DUMP_FILE"
    fi
    rm -f "$CHECKSUM_FILE"
  fi
}
trap cleanup EXIT

echo "== backup: $SOURCE_DB -> $DUMP_FILE =="

# 0. Preflight ---------------------------------------------------------------
# A missing database otherwise surfaces as an opaque pg_dump error.
source_exists="$(admin_psql -d "$ADMIN_DB" -v db="$SOURCE_DB" -tAc \
  "select 1 from pg_database where datname = :'db';")" \
  || fail "cannot connect to admin database '$ADMIN_DB' as '$ADMIN_USER'"
[ "$source_exists" = "1" ] || fail "database '$SOURCE_DB' does not exist"

# 1. Dump --------------------------------------------------------------------
# Cron has no terminal: --no-password turns "would prompt" into an immediate,
# comprehensible failure instead of a job that hangs until the timeout.
PGPASSWORD="$ADMIN_PASSWORD" pg_dump --no-password -U "$ADMIN_USER" -d "$SOURCE_DB" \
  -Fc -f "$DUMP_FILE" || fail "pg_dump failed"
[ -s "$DUMP_FILE" ] || fail "dump file is empty"

# 2. Verify the archive is readable ------------------------------------------
# `pg_restore --list` reads the archive itself; it needs no database connection.
# This catches truncation and "dumped the wrong database" before the artifact is
# published, which is the whole point of producing a dump in CI.
PGPASSWORD="$ADMIN_PASSWORD" pg_restore --no-password --list "$DUMP_FILE" >"$TOC_FILE" \
  || fail "pg_restore --list failed: the archive is not readable"

for table in "${CORE_TABLES[@]}"; do
  if ! grep -Eq "[[:space:]]TABLE[[:space:]]+public[[:space:]]+${table}([[:space:]]|$)" "$TOC_FILE"; then
    fail "core table '$table' is missing from the archive"
  fi
done

printf '   ok       archive readable, %s core tables present\n' "${#CORE_TABLES[@]}"

# 3. Checksum ----------------------------------------------------------------
# Written from inside BACKUP_DIR so the file carries only the basename and
# `sha256sum -c` works for anyone who downloads it next to the dump.
(
  cd "$BACKUP_DIR"
  sha256sum "$(basename "$DUMP_FILE")" >"$(basename "$CHECKSUM_FILE")"
) || fail "could not write checksum file '$CHECKSUM_FILE'"

size_bytes="$(wc -c <"$DUMP_FILE")"
printf '   size     %s bytes\n' "$size_bytes"
printf '   sha256   %s\n' "$(cut -d' ' -f1 "$CHECKSUM_FILE")"

# 4. Retention ---------------------------------------------------------------
# Only files directly inside BACKUP_DIR are candidates, and only the names this
# script produces. The guard against an empty result is deliberate: a pruning
# bug must never be able to leave the system with zero backups.
echo "-- retention: pruning dumps older than ${KEEP_DAYS} days inside $BACKUP_DIR"

existing=()
while IFS= read -r file; do
  existing+=("$file")
done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'luckycare-*.dump' | sort)

candidates=()
if [ "${#existing[@]}" -gt 0 ]; then
  while IFS= read -r file; do
    candidates+=("$file")
  done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'luckycare-*.dump' \
    -mtime +"$KEEP_DAYS" | sort)
fi

if [ "${#candidates[@]}" -eq 0 ]; then
  printf '   nothing older than %s days (%s dump(s) on disk)\n' "$KEEP_DAYS" "${#existing[@]}"
else
  remaining=$((${#existing[@]} - ${#candidates[@]}))
  if [ "$remaining" -le 0 ]; then
    echo "   refusing to prune: it would leave zero dumps" >&2
  else
    for file in "${candidates[@]}"; do
      # Belt and braces: find already guarantees this, but deleting the wrong
      # directory during a backup job is not a mistake worth risking.
      if [ "$(dirname "$file")" != "$BACKUP_DIR" ]; then
        echo "   skip     outside $BACKUP_DIR: $file" >&2
        continue
      fi
      rm -f "$file" "$file.sha256"
      printf '   pruned   %s\n' "$(basename "$file")"
    done
  fi
fi

COMPLETE=1
printf '== backup complete: %s ==\n' "$(basename "$DUMP_FILE")"
