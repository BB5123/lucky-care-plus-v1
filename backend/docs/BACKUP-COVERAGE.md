# Backup coverage - what is backed up, and what is not

> Written 2026-10-25 (TASK G). This file is the honest answer to "if we lose a
> machine right now, what survives?". Nothing here is aspirational: it describes
> what the repository actually does today, and every gap is listed.

The product keeps **two** kinds of persistent state that cannot be rebuilt from
the image:

1. the **PostgreSQL database** (tenants, workspaces, memberships, health data,
   care tasks, audit rows - protected by RLS), and
2. the **mutable knowledge corpus** on the `knowledgedata` volume
   (`database/knowledge/sources/*.json` with the clinician review decisions,
   `health_kb.sqlite`, `benchmark/`). Since
   `fix(deploy): the knowledge corpus is a volume` (#67) the corpus is not in
   the image, so `docker compose down -v` erases it.

## What the repository backs up today

| Item | Producer | Proof it restores | Where it goes | Retention |
|---|---|---|---|---|
| PostgreSQL | `backend/scripts/backup-dump.sh` via `.github/workflows/backup.yml` | `backend/scripts/restore-drill.sh` restores into a scratch DB, compares every table's row count and re-runs the RLS probes | `nightly-dump` GitHub artifact | 7 days |
| Knowledge corpus | `php artisan knowledge:backup` via `.github/workflows/backup.yml` | `php artisan knowledge:restore-drill` extracts into a temp dir and checks JSON, unique ids, SQLite `integrity_check`, index counts, review fields and membership | `nightly-dump` GitHub artifact (same artifact as the dump) | 7 days |
| **Document objects (local disk)** | `php artisan documents:backup` | `php artisan documents:restore-drill <archive>` re-reads every file and checks size + sha256 against the manifest; the producer itself fails when a `medical_documents` row has no object | `storage/app/backups/documents-*.zip` on the `appstorage` volume (+ `.sha256`); copy it off the host with the rest | `--keep-days=14` locally |

Both are scheduled nightly (19:00 UTC = 02:00 Asia/Bangkok) and on demand
(`workflow_dispatch`). Both producers fail loudly: a partial archive is removed
and the run exits non-zero, a `.sha256` is written next to the archive, and —
for the corpus — the restore drill runs **on the uploaded artifact itself**
after downloading it back.

## What is NOT backed up

| Not covered | Why it is not covered | Risk if it is lost | Mitigation / owner action |
|---|---|---|---|
| **Object storage for uploaded documents on S3/MinIO** (`DOCUMENTS_DISK=s3`, the reference stack's `minio:9000`) | The new `documents:backup` producer supports the **local** disk only and refuses a non-local driver instead of silently archiving nothing. | Uploaded medical documents, photo evidence and generated exports are gone; database rows point at missing files. | Mirror the bucket with `mc mirror` / `aws s3 sync` on the same schedule, then run `documents:restore-drill` on the archive that produces; or switch the deployment to the local disk and use the producer above. Deployment-target task. |
| **`RETENTION_ARCHIVE_DISK` (archived account bundles)** | The archive bundle is encrypted with `APP_KEY` and written outside the database, so the nightly `pg_dump` does not contain it; nothing copies that disk yet (P4b-2 owns archive backup/expiry). | Archived accounts cannot be restored even though the live database was backed up - the manifest says they were archived, the bundle is gone. | Back up the archive disk with the same schedule and keep its key (`app:key-fingerprint`); verify with `retention:archive-drill`. Until then, do not turn deletion on for `archived` accounts. Deployment-target task. |
| **`.env` and secrets** (DB password, `APP_KEY`, provider API keys, `ADMIN_*`) | Deliberately out of scope: secrets must not be copied into a CI artifact. | `APP_KEY` loss makes encrypted columns unreadable; losing provider keys disables AI. | Store secrets in a managed secret store and back *that* up under your own policy. `APP_KEY` must be escrowed before production; `php artisan app:key-fingerprint` prints a sha256 of the key (never the key) so the escrowed copy can be verified on the restored host. |
| **Redis** (`redis:6380`) | The queue is **database-backed today** (`QUEUE_CONNECTION=database`), so no queue payload is lost with Redis; Redis holds cache and short-lived locks only. | If Redis is switched to a broker (e.g. `QUEUE_CONNECTION=redis`) and it is lost mid-flight, queued jobs are dropped: notifications not sent, exports not produced, no retry. | Redis is not backed up **by design** while the queue is database-backed. If the queue driver changes, revisit this row: an AOF/RDB snapshot becomes part of the recovery story. |
| **Off-site, encrypted copies / RPO-RTO numbers** | A GitHub artifact with 7-day retention lives in the same CI system that produces it, and the archives are compressed but **not encrypted**. | A CI account compromise or a lapse longer than 7 days is unrecoverable; no stated recovery-time objective. | Deployment-target decision (D3/Q-12). Encrypted storage away from the source host, key management, agreed RPO/RTO. |
| **Monitoring of a failed nightly run** | The workflow writes `::error::` annotations and fails loudly; an optional `BACKUP_ALERT_WEBHOOK` repository secret posts one line with the run link to Slack/Teams/an incident endpoint (the step is a no-op without it). | A silent backup failure is only noticed when a restore is attempted. | Set the secret and confirm one test alert; also keep GitHub's own failed-workflow notifications on. Owner action. |

## Notes on the corpus specifically

- The corpus archive is a gzipped tar of `database/knowledge` contents:
  `sources/*.json`, `health_kb.sqlite`, `benchmark/`. Its member layout matches
  the volume, so restoring is `tar xzf … -C <volume>` (see `DEPLOYMENT.md`
  section 5.2).
- The archive is plaintext. It holds **public** reference text and reviewer
  names - no patient data - but it still must not be published as an artifact to
  an untrusted location.
- `--keep-days` (default 14) governs only the local copies on the runner or
  server running the command; it never touches the 7-day CI artifact.
- The drill never writes to the live corpus: it extracts into a temporary
  directory under the system temp path and removes it on every exit path
  (`KBC-T08` asserts the live corpus bytes/mtimes are unchanged).
- A backup produced while a review is in flight can catch the corpus
  half-updated. Stop the writers (`docker compose --profile app stop app queue
  scheduler`) before a manual backup, or accept that the next nightly run
  supersedes it.
