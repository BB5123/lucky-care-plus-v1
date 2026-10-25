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

Both are scheduled nightly (19:00 UTC = 02:00 Asia/Bangkok) and on demand
(`workflow_dispatch`). Both producers fail loudly: a partial archive is removed
and the run exits non-zero, a `.sha256` is written next to the archive, and —
for the corpus — the restore drill runs **on the uploaded artifact itself**
after downloading it back.

## What is NOT backed up

| Not covered | Why it is not covered | Risk if it is lost | Mitigation / owner action |
|---|---|---|---|
| **Object storage for uploaded documents** (`appstorage` volume, MinIO at `minio:9000` in the reference stack, or S3 when `FILESYSTEM_DISK=s3`) | The nightly job copies database rows and the corpus only; it never walks the filesystem disk. | Uploaded medical documents, photo evidence and generated exports are gone. Database rows then point at missing files. | Back up the object store separately (versioned bucket / `mc mirror` / restic on the volume). Deployment-target task; see `DEPLOYMENT.md` section 5. |
| **`.env` and secrets** (DB password, `APP_KEY`, provider API keys, `ADMIN_*`) | Deliberately out of scope: secrets must not be copied into a CI artifact. | `APP_KEY` loss makes encrypted columns unreadable; losing provider keys disables AI. | Store secrets in a managed secret store and back *that* up under your own policy. `APP_KEY` must be escrowed before production. |
| **Redis** (`redis:6380`) | The queue is **database-backed today** (`QUEUE_CONNECTION=database`), so no queue payload is lost with Redis; Redis holds cache and short-lived locks only. | If Redis is switched to a broker (e.g. `QUEUE_CONNECTION=redis`) and it is lost mid-flight, queued jobs are dropped: notifications not sent, exports not produced, no retry. | Redis is not backed up **by design** while the queue is database-backed. If the queue driver changes, revisit this row: an AOF/RDB snapshot becomes part of the recovery story. |
| **Off-site, encrypted copies / RPO-RTO numbers** | A GitHub artifact with 7-day retention lives in the same CI system that produces it, and the archives are compressed but **not encrypted**. | A CI account compromise or a lapse longer than 7 days is unrecoverable; no stated recovery-time objective. | Deployment-target decision (D3/Q-12). Encrypted storage away from the source host, key management, agreed RPO/RTO. |
| **Monitoring of a failed nightly run** | No alerting is wired: a failed run shows as a red badge in GitHub Actions. | A silent backup failure is only noticed when a restore is attempted. | Enable a GitHub Actions failure notification (email/Slack/webhook) or a status check. Owner action. |

## Notes on the corpus specifically

- The corpus archive is a gzipped tar of `database/knowledge` contents:
  `sources/*.json`, `health_kb.sqlite`, `benchmark/`. Its member layout matches
  the volume, so restoring is `tar xzf … -C <volume>` (see `DEPLOYMENT.md`
  section 5.1).
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
