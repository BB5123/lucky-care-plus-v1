# Deployment runbook - Lucky Care Plus API

This is the operator-facing install and upgrade guide for the container image and
the `app` compose profile. It describes the reference single-host deployment; it
does not claim to be a hardened production platform (see section 10).

Relevant files:

| File | Purpose |
|---|---|
| `backend/Dockerfile` | One image for `app` (php-fpm), `queue`, `scheduler`. |
| `backend/Dockerfile.dockerignore` | Keeps `.env`, `vendor/`, `storage/` out of the image. |
| `backend/.env.production.example` | Every variable, with production-safe defaults. |
| `docker/nginx.conf` | Nginx front door for the `web` service (edge rate limits). |
| `docker/caddy/Caddyfile` | Optional TLS terminator for the `proxy` profile. |
| `docker-compose.yml` | `app` profile (app, web, queue, scheduler), `proxy` profile, infra unchanged. |
| `.github/workflows/ci.yml` | `Image` job: a broken Dockerfile fails a pull request. |

The default `app` profile serves plain HTTP on `:8080`. The additive `proxy`
profile puts Caddy in front of `web`, terminates TLS, and serves HTTPS on `:443`
or the local-mode host port `:8443` (section 12). Run it in a real deployment;
keep `web:8080` for local debugging only.

---

## 1. Prerequisites

- Docker Engine 24+ with Compose v2.24+ (`docker compose version`). The
  `env_file: required: false` syntax used by `docker-compose.yml` needs 2.24+.
- **4 GB RAM** minimum for the host (Postgres, Redis, php-fpm, nginx and one
  queue worker). Add Redis/DB headroom for a real workload.
- 10 GB free disk for images, the Postgres volume, and document storage.
- A DNS name and a **TLS terminator** in front of the API. The optional `proxy`
  profile (section 12) provides one; alternatively use your own load balancer in
  front of `http://<host>:8080`. The `app` profile alone listens on plain HTTP.
- Host ports `8080` (web, debug only) and, when the `proxy` profile is enabled,
  `8443` (local TLS) or `80`/`443` (domain TLS).
- Outbound HTTPS during the first install (Docker Hub, Packagist, Alpine
  packages). The running stack does not need internet unless AI is enabled.
- Git and a checkout of this repository on the host.

```bash
git clone <repository-url> lucky-care-plus
cd lucky-care-plus
docker compose version
```

The default `docker compose up -d` stays infrastructure-only (Postgres, Redis,
optional MinIO/ClamAV). Nothing in this runbook requires changing that default.

---

## 2. First install

### 2.1 Create the environment file

```bash
cp backend/.env.production.example backend/.env
```

Edit `backend/.env` with a real editor (never through a Windows shell for Thai
content, but this file is ASCII). Fill in at least:

- `APP_URL` - the public `https://...` URL clients will call.
- `DB_PASSWORD` - a strong password for `luckycare_app`.
- `MAIL_*` - a real relay, or `MAIL_MAILER=log` for a staging install only.
- `REDIS_PASSWORD` if you enable Redis auth (the reference compose does not).

The reference compose creates `luckycare_app` with the development password
`luckycare_app_dev` (see `docker/postgres/init/01-app-role.sql`). If
`DB_PASSWORD` in `backend/.env` is different, apply the same value to the role
before relying on the stack - the init script only runs on an empty `pgdata`
volume:

```bash
docker compose --profile app up -d postgres
docker compose --profile app exec -T postgres psql -U luckycare -d lucky_care_plus \
  -c "ALTER ROLE luckycare_app WITH PASSWORD 'CHANGE_ME';"
```

The `luckycare` superuser password (`luckycare_dev` in `docker-compose.yml`) is
only for administration. Change it on a fresh volume before exposing the host;
on an existing volume use `ALTER ROLE luckycare WITH PASSWORD ...`.

`APP_ENV=production` and `APP_DEBUG=false` are already set. `AI_ENABLED=false`,
`OTP_DEMO_MODE=false`, `RETENTION_DRY_RUN=true` and
`DOCUMENTS_SCAN_REQUIRED=false` are deliberate safe defaults (sections 6-8).

### 2.2 Build the image and generate the application key

```bash
docker compose --profile app build app
docker compose --profile app run --rm --no-deps -T app \
  php artisan key:generate --show --no-ansi
```

Paste the printed `base64:...` value into `APP_KEY=` in `backend/.env`. Do not
reuse a key from another environment. Keep the old value in
`APP_PREVIOUS_KEYS` while rotating, then remove it once every encrypted payload
has been re-written.

### 2.3 Bring the stack up

```bash
docker compose --profile app up -d --build
docker compose --profile app ps
```

Expected: `luckycare-app`, `luckycare-web`, `luckycare-queue` and
`luckycare-scheduler` are `Up (healthy)`. `postgres` and `redis` are healthy.
The `app`, `queue` and `scheduler` healthchecks boot Laravel and call `GET /up`;
`web` checks the same route through nginx. Adding `--profile proxy` starts
`luckycare-proxy` as well (section 2.6).

On a completely fresh database the queue worker and the scheduled commands may
log "relation jobs does not exist" and restart until step 2.4 runs. That is
expected; `restart: unless-stopped` brings them back once the schema exists.

### 2.4 Run migrations

**Staging / acceptance / demo** (this is the literal `--seed` path; the seeder
creates `owner@example.test`, `caregiver@example.test` and
`viewer@example.test` with the password `password`):

```bash
docker compose --profile app exec -T app php artisan migrate --seed --force
```

**Production** - do **not** run `FoundationSeeder`: its own docblock says "Never
use this seeder in production", and it creates known-password accounts. Migrate
the schema, then create the first real account and workspace through the public
API:

```bash
docker compose --profile app exec -T app php artisan migrate --force

# 1. Register the first owner. The response contains a bearer token.
curl -fsS -X POST http://localhost:8080/api/v1/auth/register \
  -H 'Content-Type: application/json' \
  -d '{
        "name": "First Owner",
        "email": "owner@example.com",
        "password": "CHANGE_ME_AT_LEAST_10_CHARS",
        "password_confirmation": "CHANGE_ME_AT_LEAST_10_CHARS",
        "device_name": "install"
      }'

# 2. Use the token from the response to create the first workspace.
curl -fsS -X POST http://localhost:8080/api/v1/workspaces \
  -H "Authorization: Bearer <token-from-step-1>" \
  -H 'Content-Type: application/json' \
  -d '{"name":"My Family"}'
```

> **Deviation from the task text:** the ticket said `migrate --seed` in the
> first-install path. The seeded accounts have a known password and the seeder is
> documented as non-production, so the production path above uses
> `migrate --force` plus the real registration API. `--seed` remains documented
> for staging and for the acceptance smoke.

### 2.5 Verify the API is up

```bash
curl -fsS -o /dev/null -w 'GET /up    -> %{http_code}\n' http://localhost:8080/up
curl -fsS -o /dev/null -w 'GET /ready -> %{http_code}\n' http://localhost:8080/ready
```

Expected: both `200`. `/up` is the Laravel liveness route registered in
`backend/bootstrap/app.php`; it does not touch the database, so a 200 proves the
HTTP path and the application boot, not schema state. `/ready` additionally
proves the database answers `select 1` and the cache/Redis dependency answers
`PING` (section 16). A `503` names the failed dependency in the JSON body.

If you terminate TLS, the same requests through the terminator must return 200.
Set `APP_URL` to the external HTTPS URL so signed document/export links use the
public host, and set `FORCE_HTTPS=true` once the proxy is the only public path
(section 13).

### 2.6 Enable TLS (optional `proxy` profile)

**Local mode** uses Caddy's internal CA, so it needs no domain and no DNS. It
listens on host port `8443`:

```bash
docker compose --profile app --profile proxy up -d --build
curl -k -o /dev/null -w 'GET /ready -> %{http_code}\n' https://localhost:8443/ready
curl -k -I https://localhost:8443/up
```

`-k` is required because the certificate is signed by Caddy's local CA. To trust
it in a browser, import the root certificate from the `caddydata` volume
(`/data/caddy/pki/authorities/local/root.crt`) or keep using `curl -k`.

**Domain mode** obtains a real certificate automatically. It requires a DNS name
that resolves to the host and inbound `80`/`443` (ACME needs port 80 for the
HTTP challenge):

```bash
APP_DOMAIN=api.example.com \
CADDY_TLS_SNIPPET=tls_domain \
CADDY_HSTS_SNIPPET=hsts \
CADDY_REDIRECT_TARGET=https://api.example.com \
CADDY_HTTP_PORT=80 \
CADDY_HTTPS_PORT=443 \
docker compose --profile app --profile proxy up -d
```

Then set `APP_URL=https://api.example.com`, `FORCE_HTTPS=true`,
`TRUSTED_PROXIES` to the compose network CIDR, and restart the application
containers so `config:cache` reads the new values. The proxy profile is additive:
`web` keeps `:8080` for local debugging, but do not expose it to the internet.


For a demo or pilot workspace with realistic synthetic data (and the exact
login steps), run `docker compose --profile app exec -T app php artisan demo:seed`
- see [`DEMO-DATA.md`](DEMO-DATA.md). The full post-deploy check is
`bash backend/scripts/smoke.sh --base-url=http://localhost:8080` (section 4).

---

## 3. Verify the queue and the scheduler are actually running

`docker compose ps` showing `Up` is not enough; a worker can be up and stuck.
Run one real command per service.

```bash
# Both should print a healthy state and the 4 application containers.
docker compose --profile app ps

# Queue: show the worker process and its exact command line.
docker compose --profile app exec -T queue pgrep -af 'artisan queue:work'
# Expected: a PID followed by "php artisan queue:work --tries=3 --backoff=5 --timeout=120"

# Queue: prove the worker can reach the configured queue backend.
# Use "<connection>:<queue>" - the connection is `database` when
# QUEUE_CONNECTION=database, or `redis` when QUEUE_CONNECTION=redis.
docker compose --profile app exec -T queue php artisan queue:monitor database:default --max=1000
# Expected: a table with connection/queue and a pending-jobs number (0 is fine).

# Scheduler: show the schedule:work process.
docker compose --profile app exec -T scheduler pgrep -af 'artisan schedule:work'
# Expected: a PID followed by "php artisan schedule:work"

# Scheduler: print the schedule it is driving (same code the worker evaluates).
docker compose --profile app exec -T scheduler php artisan schedule:list
# Expected: retention:purge, retention:compact, retention:inactivity, notifications:send-due,
# notifications:send-appointment-reminders, tasks:materialise, tasks:escalate.

# Non-root check: both workers must run as www-data, not root.
docker compose --profile app exec -T queue id
docker compose --profile app exec -T scheduler id
# Expected: uid=82(www-data) gid=82(www-data)
```

If `queue:monitor` cannot connect, the worker is alive but not working: check
`QUEUE_CONNECTION`/`REDIS_*` in `backend/.env` and section 9.

The worker's per-job limit is `--timeout=120`. Keep
`DB_QUEUE_RETRY_AFTER`/`REDIS_QUEUE_RETRY_AFTER` above it (the production
template uses 150) so a second worker cannot reserve a job that is still
running.

---

## 4. Smoke check after every deploy

The packaged post-deploy check is
`bash backend/scripts/smoke.sh --base-url=http://localhost:8080` (it needs the
demo accounts from `demo:seed`; see [`DEMO-DATA.md`](DEMO-DATA.md)).
The manual block below checks the HTTP path, the auth surface, the database
schema and the queue backend.

```bash
set -euo pipefail
BASE=http://localhost:8080

# 1. Liveness route (HTTP 200, empty body).
curl -fsS -o /dev/null -w 'GET /up -> %{http_code}\n' "$BASE/up"

# 2. Readiness route (HTTP 200; 503 names the failing dependency).
curl -fsS -o /dev/null -w 'GET /ready -> %{http_code}\n' "$BASE/ready"

# 3. Auth surface is reachable and validates input (422, not 500/502).
curl -sS -o /dev/null -w 'POST /auth/login (bad credentials) -> %{http_code}\n' \
  -X POST "$BASE/api/v1/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"smoke@example.invalid","password":"not-a-real-password"}'

# 4. Database is migrated (prints the last few migrations and their batch).
docker compose --profile app exec -T app php artisan migrate:status | tail -n 5

# 5. The app sees the production environment and no debug output.
docker compose --profile app exec -T app php artisan about --only=environment

# 6. Queue backend is reachable from the worker (0 pending is fine).
#    Use "<connection>:<queue>": database:default or redis:default.
docker compose --profile app exec -T queue php artisan queue:monitor database:default --max=1000
```

Expected: `200`, `200`, `422`, migration rows with `Ran`, `environment: production`,
and a queue table. A `502` from step 1/2 means nginx cannot reach php-fpm; a
`500` means the application booted but a dependency failed; a `503` from `/ready`
means the JSON body names the dependency; see sections 9 and 16.

For a PDF/photo upload smoke, use the API with a real token and a small PDF.
The upload limit is 10 MiB (`DOCUMENTS_MAX_BYTES`, enforced by
`DocumentService`), nginx and PHP both allow 12 MiB so the application can
return its Thai 422 instead of nginx's 413.

---

## 5. Backups

The CI producer is [`.github/workflows/backup.yml`](../../.github/workflows/backup.yml):
it dumps the database with `pg_dump -Fc`, uploads the dump as a workflow
artifact, downloads it back, restores it into a scratch database, and runs RLS
probes. [`.github/workflows/restore-drill.yml`](../../.github/workflows/restore-drill.yml)
repeats the restore weekly. Those are a **safety net, not a production backup**:
artifacts expire after 7 days and live in GitHub.

A production deployment still needs:

- an off-site, encrypted copy (S3 with SSE-KMS, an encrypted restic/borg repo,
  or equivalent) written on a schedule you can state;
- a **tested restore procedure** with a measured restore time, not just a
  successful dump exit code;
- a stated **RPO/RTO** and key management (who holds the encryption key);
- document/object storage coverage if `FILESYSTEM_DISK=s3` is not used (the
  `appstorage` volume holds uploads and exports).

Manual dump for the reference stack:

```bash
docker compose --profile app exec -T postgres \
  pg_dump -U luckycare -Fc lucky_care_plus > "luckycare-$(date +%F).dump"
```

Restore **with ownership preserved**. Do **not** use `pg_restore --no-owner`:
it makes the restoring superuser the owner and the application role loses table
privileges (section 9.1).

```bash
docker compose --profile app exec -T postgres \
  pg_restore -U luckycare -d lucky_care_plus --role=luckycare_app < luckycare-YYYY-MM-DD.dump
```

The compose PostgreSQL credentials (`luckycare` / `luckycare_dev`) and the
runtime role password (`luckycare_app_dev`) are development defaults. Rotate
them before a real deployment (section 10, items 4-5).

---

## 5.1 Knowledge corpus (mutable state)

The knowledge corpus is **not** immutable application code. It has two parts:

| Path (inside the container) | What it is |
|---|---|
| `database/knowledge/sources/*.json` | One public source per file. `review_status`, `reviewed_by` and `reviewed_at` are written by the platform-admin review API and by `php artisan knowledge:review`. |
| `database/knowledge/health_kb.sqlite` | The SQLite FTS5 index rebuilt from those sources after a review. |

Both live on the named volume `knowledgedata`, which `docker-compose.yml`
mounts at `/var/www/html/database/knowledge` on `app`, `queue` and `scheduler`.
Only `app` writes; the workers read the same files. The image ships an immutable
copy at `database/knowledge-seed` and the container entrypoint copies it into
the volume **once, only when the volume is empty**. The first boot logs
`knowledge corpus: seeded from image`; every later start logs
`knowledge corpus: existing corpus kept` and leaves review decisions untouched.
Rebuilding the image therefore never resets a review. A new image's seed does
**not** update an existing volume: if a release ships changed sources, copy the
specific files in deliberately (or rebuild the whole volume and re-review).

### Back up the corpus by hand

`php artisan knowledge:backup` archives `sources/*.json`, `health_kb.sqlite` and
the `benchmark/` directory into a gzipped tar, writes a `.sha256` next to it,
verifies the archive is readable and prunes old copies:

```bash
# Default destination: storage/app/backups/knowledge
docker compose --profile app exec -T app php artisan knowledge:backup

# Or an explicit host-visible directory, keeping 30 days of archives:
docker compose --profile app exec -T app \
  php artisan knowledge:backup --to=storage/app/backups/knowledge --keep-days=30
```

The command prints one summary line (path, size, sha256, member count) and
`--json` emits the same as JSON. It exits non-zero and removes a partial archive
on any failure, and it refuses to prune if that would leave zero archives behind.

### Prove a corpus archive before trusting it

`php artisan knowledge:restore-drill` extracts an archive into a temporary
directory (never over the live corpus) and checks, in order: every source parses
as JSON with `id`/`title`/`url`/`text`, source ids are unique, the SQLite index
passes `PRAGMA integrity_check`, the index has rows and its `meta` build record
agrees with the tables, every `reviewed` source still carries `reviewed_by` and
`reviewed_at`, and the source set matches the indexed documents.

```bash
docker compose --profile app exec -T app \
  php artisan knowledge:restore-drill --archive=storage/app/backups/knowledge/knowledge-YYYYMMDDTHHMMSSZ.tar.gz
```

It prints a table (or JSON with `--json`), exits non-zero on the first failure,
and always removes the temporary directory. Run it after every backup: the
nightly CI workflow does exactly this.

### Restore the corpus into a running deployment

Stop the writers first so no review is in flight, restore the archive into the
volume, then rebuild the SQLite index from the restored sources:

```bash
# 1. Stop the writers.
docker compose --profile app stop app queue scheduler

# 2. Repack the archive's contents (it holds sources/, health_kb.sqlite and
#    benchmark/ at its root) into the volume.
docker run --rm \
  -v lucky-care-plus_knowledgedata:/data \
  -v "$PWD":/backup \
  alpine:3.20 \
  sh -c 'rm -rf /data/* && tar xzf /backup/knowledge-YYYYMMDDTHHMMSSZ.tar.gz -C /data'

# 3. Start again and rebuild the index from the restored sources.
docker compose --profile app start app queue scheduler
docker compose --profile app exec -T app php artisan knowledge:build
docker compose --profile app exec -T app php artisan knowledge:validate
```

The corpus archive is a gzipped tar built from the two paths above; its member
layout (`sources/…`, `health_kb.sqlite`, `benchmark/…`) matches the volume
layout, so a plain `tar xzf … -C /data` is enough.

**The volume is the only copy of the review decisions.** Removing it destroys
them:

```bash
# DESTRUCTIVE: deletes PostgreSQL, Redis, uploads and every knowledge review.
docker compose --profile app down -v
```

A plain `docker compose --profile app down` (without `-v`) keeps the volume. A
rebuild (`docker compose --profile app up -d --build app web queue scheduler`)
also keeps it and re-runs the entrypoint, which detects the populated volume.

### Retention of the CI artifact

`.github/workflows/backup.yml` runs nightly (19:00 UTC = 02:00 Asia/Bangkok)
and on demand: it produces the corpus archive with `knowledge:backup`, drills it
with `knowledge:restore-drill`, uploads it alongside the database dump as the
`nightly-dump` artifact with **7-day retention**, downloads that artifact back
and drills the downloaded copy. A 7-day workflow artifact is a safety net, not a
compliance backup — it lives in the same CI system that produces it, and the
archive is compressed but **not encrypted**. Off-site encrypted storage and
RPO/RTO stay with the deployment target. `--keep-days` only governs the local
copy on the runner/volume, not the artifact.

> The throw-away-container commands below are the path to use when you need to
> copy the raw volume instead of producing a fresh archive from inside a
> running container.

Back up and restore the corpus with a throw-away container (do not copy from a
running `app` container if a review may be in flight):

```bash
# Back up the volume to a host tarball.
docker run --rm \
  -v lucky-care-plus_knowledgedata:/data:ro \
  -v "$PWD":/backup \
  alpine:3.20 \
  tar czf /backup/knowledgedata-$(date +%F).tar.gz -C /data .

# Restore into a fresh volume (stop the app first so no request writes).
docker compose --profile app stop app queue scheduler
docker run --rm \
  -v lucky-care-plus_knowledgedata:/data \
  -v "$PWD":/backup \
  alpine:3.20 \
  sh -c 'rm -rf /data/* && tar xzf /backup/knowledgedata-YYYY-MM-DD.tar.gz -C /data'
docker compose --profile app start app queue scheduler
```

The compose project name is `lucky-care-plus`, so the volume is
`lucky-care-plus_knowledgedata` (check with `docker volume ls`). If the volume
is recreated while a backup holds old sources, run
`docker compose --profile app exec -T app php artisan knowledge:build` to
rebuild the SQLite index from the restored sources.

A review that cannot write the corpus returns `503 knowledge_corpus_readonly`
(Thai message, one failed `security_audit_logs` row with
`reason_code=corpus_readonly`). That means the volume is read-only, full, or
owned by another user - it is a deployment fault, not a validation error. Check
ownership and free space:

```bash
docker compose --profile app exec -T app sh -c \
  'id; ls -ld /var/www/html/database/knowledge; df -h /var/www/html/database/knowledge'
```

---

## 6. Malware scanning (ClamAV)

Scanning is **fail-closed but disabled by default** (owner decision D4). The
adapter is a unix-socket `INSTREAM` client; it never shells out to
`clamdscan`. See `backend/docs/MALWARE-SCANNING.md` for the pipeline.

### 6.1 Start clamd

```bash
docker compose --profile app --profile scan up -d clamav
docker compose --profile scan ps clamav
```

The first boot downloads the signature database (several minutes; the
healthcheck has a 10-minute `start_period`). Watch it with:

```bash
docker compose --profile scan logs -f clamav
```

### 6.2 Share the socket with the app container

The `clamav/clamav:stable` image creates its unix socket at `/tmp/clamd.sock`
inside its own container. The app container cannot see that path by default.
Two supported options:

1. **Shared volume (containerized reference stack).** Add a named volume to
   both `app` (and `queue`/`scheduler`) and `clamav` at `/tmp`, then set
   `DOCUMENTS_CLAMAV_SOCKET=/tmp/clamd.sock`. Mounting over `/tmp` is a
   trade-off; verify permissions before enabling the gate:
   ```bash
   docker compose --profile app --profile scan exec -T app ls -l /tmp/clamd.sock
   ```
2. **Linux host with a bind mount.** Run clamd on the host (or a container
   whose socket is bind-mounted into the host filesystem) and point
   `DOCUMENTS_CLAMAV_SOCKET` at the host path. On Docker Desktop for
   Windows/macOS the container socket is not exposed to the host, so option 1
   or a Linux deployment is required.

### 6.3 Flip the switches

In `backend/.env`:

```dotenv
DOCUMENTS_SCANNER=clamav
DOCUMENTS_CLAMAV_SOCKET=/tmp/clamd.sock
DOCUMENTS_SCAN_REQUIRED=true
DOCUMENTS_CLAMAV_TIMEOUT=10
```

Recreate the containers so the environment is re-read:

```bash
docker compose --profile app up -d --force-recreate app queue scheduler
```

Then run the EICAR check in `backend/docs/MALWARE-SCANNING.md` (a harmless
industry-standard marker inside a minimal PDF). Expected: HTTP `422`
`document_infected`, no `medical_documents` row, one
`document.scan_infected` security-audit row. If clamd is unreachable the upload
returns `503 scan_unavailable` and stores nothing - that is the intended
fail-closed behaviour, not a bug.

---

## 7. AI

**AI stays off.** `AI_ENABLED=false` makes every AI endpoint return `503
ai_disabled` and write a `disabled` attempt row without fetching context or
contacting a provider. G4 is not signed off and D1 (provider + DPA, AIG-010) is
an owner decision. See `.ai/08-AI-GATEWAY.md`,
`backend/docs/G4-LIVE-EVALUATION.md`, `backend/docs/LOCAL-AI-PATHUMMA.md`.

The exact variables (all present in `backend/.env.production.example`):

```dotenv
AI_ENABLED=false
AI_PROVIDER=null
AI_BASE_URL=
AI_API_KEY=
AI_MODEL=
# AI_MODEL_1=            # alias for AI_MODEL (default text model)
# AI_MODEL_2=            # alias for AI_MODEL_VISION (vision model)
# AI_MODEL_VISION=
# AI_MODEL_SUMMARY=
# AI_MODEL_KNOWLEDGE=
# AI_VISION_MODELS=      # comma-separated allow-list; empty = no image ever sent
# AI_TIMEOUT=20
# AI_MAX_RETRIES=1
# AI_PER_USER_DAY=20
# AI_PER_USER_MINUTE=5
# AI_MAX_TOKENS=1500
# AI_TEMPERATURE=0.2
# AI_LOCALE=th
```

After G4/D1 are signed off, set `AI_ENABLED=true`, `AI_PROVIDER=okmd` (or
`openai_compatible`), `AI_BASE_URL`, `AI_API_KEY`, `AI_MODEL`, and a vision
model plus `AI_VISION_MODELS` only when the provider is approved for images.
The adapter stays on the in-process provider until base URL, key and model are
all present; half-configuration does not silently go live. Do not enable AI
before the DPA confirms the data is not used for training.

---

## 8. Upgrade procedure

The image is immutable; code, config and PHP settings arrive by rebuilding. The
`appstorage`/`applogs` volumes and the Postgres/Redis volumes survive. Set
`APP_IMAGE_TAG` in the shell (or the root `.env` used by Compose) to tag a
release; the default is `local`.

```bash
# 1. Fetch and inspect the new revision.
git pull --ff-only
git log --oneline -5

# 2. Build the new image (pulls the base image too).
docker compose --profile app build --pull app

# 3. Apply migrations from the NEW image before serving it.
docker compose --profile app run --rm --no-deps -T app php artisan migrate --force

# 4. Recreate application containers in dependency order.
docker compose --profile app up -d --no-deps app
docker compose --profile app up -d --no-deps queue scheduler
docker compose --profile app up -d --no-deps web

# 5. Rebuild caches the production image should run with. Do this AFTER the
#    recreate: a new container starts from the image and loses the old layer.
docker compose --profile app exec -T app php artisan config:cache
docker compose --profile app exec -T queue php artisan config:cache
docker compose --profile app exec -T scheduler php artisan config:cache
docker compose --profile app exec -T app php artisan view:cache

# 6. Run the smoke check from section 4.
```

Notes:

- Stop accepting writes with `php artisan down --retry=60` only if a migration
  is not backward compatible; `php artisan up` afterwards. Most migrations here
  are additive.
- The queue worker is stopped with `SIGTERM` and gets a 130-second grace period
  (`stop_grace_period`) so the in-flight job can finish (`--timeout=120`).
- `opcache.validate_timestamps=0` in the image. A code change is picked up only
  after the container is recreated. Always restart, never edit files in place.
- `config:cache` freezes the environment. If you change `backend/.env`, re-run
  `config:cache` (or `config:clear`) and recreate the containers.

---

## 9. Troubleshooting

### 9.1 `permission denied for table workspaces`

**Cause:** the database was restored with `pg_restore --no-owner`. That makes
the restoring superuser the owner of the tables, so the runtime role
`luckycare_app` loses the privileges it normally gets through ownership. Row
Level Security was never the problem; the role cannot even reach the table.
This is recorded in `backend/docs/QUALITY-SWEEP-2026-10-24.md`.

**Fix:** restore with ownership preserved (`pg_restore ... --role=luckycare_app`
or without `--no-owner`). If a bad restore already happened, re-own the objects
as the bootstrap superuser and verify as the app role:

```bash
# Within the lucky_care_plus database, as the postgres superuser:
docker compose --profile app exec -T postgres \
  psql -U luckycare -d lucky_care_plus -c \
  "REASSIGN OWNED BY luckycare TO luckycare_app;"

# Verify as the application role (must not error):
docker compose --profile app exec -T postgres \
  psql -U luckycare_app -d lucky_care_plus -tAc "select count(*) from workspaces;"
```

`REASSIGN OWNED` is a blunt repair; the correct long-term fix is to re-run the
restore without `--no-owner`.

### 9.2 The RLS runtime role does not exist

**Symptom:** `FATAL: password authentication failed for user "luckycare_app"` or
`role "luckycare_app" does not exist`.

**Cause:** `docker/postgres/init/01-app-role.sql` runs only when the `pgdata`
volume is empty. A volume created before that file existed (or a restored
cluster) does not have the role.

**Fix:** create it with the same SQL the init script uses, then restart the app:

```bash
docker compose --profile app exec -T postgres psql -U luckycare -d lucky_care_plus <<'SQL'
CREATE ROLE luckycare_app
  LOGIN PASSWORD 'CHANGE_ME'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
GRANT ALL PRIVILEGES ON DATABASE lucky_care_plus TO luckycare_app;
ALTER SCHEMA public OWNER TO luckycare_app;
GRANT ALL ON SCHEMA public TO luckycare_app;
ALTER DATABASE lucky_care_plus OWNER TO luckycare_app;
SQL
docker compose --profile app up -d --force-recreate app queue scheduler
```

If the role already exists but the password is wrong, use
`ALTER ROLE luckycare_app WITH PASSWORD '...';` instead. Confirm with
`\du` and `\l` that `luckycare_app` is not a superuser and does not have
`BYPASSRLS`. A superuser connection silently disables every RLS policy.

### 9.3 `sqlite FTS5` missing in a slim image

**Symptom:** a knowledge query fails with `no such module: fts5` or
`SQLSTATE[HY000]: General error: 1 no such module: fts5`.

**Cause:** the PHP SQLite extension was compiled against a library without
`SQLITE_ENABLE_FTS5`, or a different base image replaced the official one.

**Fix:** run the check that the image ships:

```bash
docker compose --profile app exec -T app luckycare-fts5-check
# Expected: ENABLE_FTS5
```

The build runs the same check (`backend/Dockerfile`), so a base image that drops
FTS5 fails the build instead of failing at runtime. Rebuild from this Dockerfile;
do not "fix" it by installing a distro `php-sqlite3` that may be compiled
differently. The knowledge corpus is read from
`database/knowledge/health_kb.sqlite` (override with
`KNOWLEDGE_SQLITE_PATH`).

### 9.4 The queue container is running as root

**Symptom:** `docker compose --profile app exec -T queue id` prints
`uid=0(root)`.

**Cause:** a local override (`user: root` in a compose override), or an image
built before the `USER www-data` line existed.

**Fix:** remove the override, rebuild, recreate, and verify:

```bash
docker compose --profile app build --no-cache app
docker compose --profile app up -d --force-recreate app queue scheduler
docker compose --profile app exec -T queue id
# Expected: uid=82(www-data) gid=82(www-data)

# Shared volumes must be writable by the same user.
docker compose --profile app exec -T queue ls -l storage/app storage/logs
```

A root queue worker with the database password and PHI-bearing volumes is a
privilege-escalation and file-ownership hazard; treat it as a defect, not a
preference.

### 9.5 A stale `config:cache`

**Symptom:** a changed `backend/.env` value is ignored (for example the app is
still `APP_ENV=local`, or `AI_ENABLED` is still false after setting it true).

**Cause:** `bootstrap/cache/config.php` was built earlier and freezes the old
values; with `opcache.validate_timestamps=0`, the PHP files are also cached.

**Fix:** clear the stale file, recreate the containers so they read the new
environment, then (optionally) cache again **inside the new containers**:

```bash
docker compose --profile app exec -T app php artisan config:clear
docker compose --profile app up -d --force-recreate app queue scheduler
docker compose --profile app exec -T app php artisan config:cache
docker compose --profile app exec -T queue php artisan config:cache
docker compose --profile app exec -T scheduler php artisan config:cache
docker compose --profile app exec -T app php artisan about --only=environment
```

Remember that compose `environment:` overrides `backend/.env` for the three
network values (`DB_HOST`, `DB_PORT`, `REDIS_HOST`, `REDIS_PORT`); changing
those in `.env` alone has no effect on the container network.

### 9.6 `/up` returns 502, 500, or the web container is unhealthy

- `502`: nginx cannot reach `app:9000`. Check
  `docker compose --profile app ps app` and
  `docker compose --profile app logs app`. The app healthcheck also checks that
  php-fpm is listening.
- `500`: Laravel booted but a dependency failed. Check
  `docker compose --profile app logs app` for the exception class (N-02 means
  the log payload must not contain health data).
- `web` unhealthy while `app` is healthy: the nginx config was not mounted, or
  the `app` service name changed. The config is mounted from
  `docker/nginx.conf`; the `fastcgi_pass` target is `app:9000`.

### 9.7 The proxy returns 503, a certificate warning, or will not start

- **`curl -k https://localhost:8443/up` returns Caddy's 503 with no body.** Caddy's
  active health check uses `/ready`, so the upstream was marked down. Query the
  app through nginx to see the real reason:
  `curl http://localhost:8080/ready` (section 16). Then check
  `docker compose --profile app --profile proxy logs proxy`.
- **Certificate warning in local mode.** Caddy uses its internal CA; `curl -k`
  is expected. To trust it, import
  `/data/caddy/pki/authorities/local/root.crt` from the `caddydata` volume.
- **Domain mode cannot get a certificate.** Confirm the DNS name resolves to the
  host, ports `80` and `443` are reachable from the internet, and
  `docker compose --profile app --profile proxy logs proxy` shows the ACME
  challenge. Do not set `CADDY_TLS_SNIPPET=tls_local` in domain mode.
- **Port already allocated.** Local mode publishes `8088` (HTTP redirect) and
  `8443` (HTTPS). Change `CADDY_HTTP_PORT`/`CADDY_HTTPS_PORT`; do not reuse
  `web`'s `8080`.
- **`service "proxy" depends on undefined service "web"`.** The `proxy` service
  needs the `web` service, which is in the `app` profile. Start both:
  `docker compose --profile app --profile proxy up -d`.

### 9.8 HTTPS is terminated but Laravel still generates `http://` URLs

**Cause:** `TRUSTED_PROXIES` is empty, or it does not include every hop between
the client and php-fpm. The application then sees the connection as plain HTTP
and ignores `X-Forwarded-Proto`.

**Fix:** set `TRUSTED_PROXIES` to the compose network CIDR (for example the
subnet from `docker network inspect lucky-care-plus_default`), recreate `app`,
`queue` and `scheduler`, and confirm:

```bash
docker compose --profile app exec -T app php artisan tinker --execute="
  echo config('app.trusted_proxies'), PHP_EOL;
  echo config('app.force_https') ? 'force_https=on' : 'force_https=off', PHP_EOL;
"
```

`FORCE_HTTPS=true` additionally redirects HTTP and forces `https://` in
generated URLs; `/up` and `/ready` stay exempt so the container healthcheck over
plain HTTP is not broken. See section 13.

### 9.9 A client gets `429 {"error":"rate_limited"}`

This is the nginx edge limit, not necessarily Laravel's throttle. Auth endpoints
allow about 10 requests/minute per source IP, everything else about 60/minute
(section 14). `Retry-After: 60` is set. Check `docker compose logs web`; the app's
own per-endpoint limits still apply and are stricter in places (for example
`throttle:6,1` on login). Behind a proxy the key is the first
`X-Forwarded-For` address, so a missing or forged header changes the bucket; see
the warning in `docker/nginx.conf`.

### 9.10 `/ready` returns 503

The body names the failed dependency, for example:

```json
{"status":"unavailable","checks":{"database":"failed","redis":"ok"},"failed":["database"]}
```

**The Redis half is driver-aware.** `checks.redis` is `skipped` when the deployment does not use
Redis - when `CACHE_STORE`, `QUEUE_CONNECTION` and `SESSION_DRIVER` are all non-Redis drivers - because a probe
that fails for a dependency the app never touches marks a healthy instance unavailable. The reference stack
defaults to the `database` drivers, so it reports:

```json
{"status":"ok","checks":{"database":"ok","redis":"skipped"}}
```

Switch any of those three drivers to `redis` and the probe follows automatically; set
`READINESS_CHECK_REDIS=true` to force the check even when the drivers are database-based (useful when Redis
carries the rate limiter directly), or `false` to skip it in a deployment with no Redis at all.

- `database`: `docker compose ps postgres`, then check `DB_*` in `backend/.env`
  and run `docker compose --profile app exec -T app php artisan migrate:status`.
- `redis`: `docker compose ps redis`, `REDIS_*`, and whether `CACHE_STORE` points
  at a reachable store. A bare `503` from Caddy means Caddy already marked the upstream down after its own
  `/ready` probe; query `http://localhost:8080/ready` to read the JSON body.

---

## 10. Honest "not production-ready yet" list

TLS termination, trusted-proxy handling, edge rate limiting, bounded log
retention and a real readiness probe are implemented in sections 12-16. The
items below are genuinely outside this slice:

1. **No WAF or DDoS protection.** nginx's per-IP limits blunt a single-source
   credential-stuffing run; they do nothing against a botnet or a volumetric
   attack. Put the API behind a provider WAF / scrubbing service, or a
   load-balancer product with those features, before exposing it publicly.
2. **No external monitoring or alerting.** `/ready` is a probe an orchestrator
   can poll; it is not a monitor. There is no uptime check, no alert on a 503,
   no failed-job or scan-failure alert, and no dashboard. The containers keep a
   bounded local log window (section 15), not a searchable store.
3. **No multi-host or high availability.** One host, one Postgres, one Redis,
   one queue worker. A host failure is downtime; Postgres/Redis are not
   replicated; the `appstorage`, `applogs` and `knowledgedata` volumes are
   single-host. There is no zero-downtime deploy (section 8 recreates
   containers, so a short 502 window is possible).
4. **No secret manager.** Credentials are read from `backend/.env` on the host
   (section 2.1). Docker secrets, Vault, SOPS or a cloud secret manager, plus a
   defined access path for operators, are still required.
5. **No key/certificate rotation procedure.** `APP_KEY` rotation has a
   documented `APP_PREVIOUS_KEYS` mechanism, but no runbook for rotating it, the
   database password, the mail credentials or the TLS material on a schedule.
   Domain-mode certificates renew automatically; local-mode Caddy CA material
   and the application secrets do not.

Everything else that used to be on this list - plain HTTP, inert
`TRUSTED_PROXIES`, no edge limit, unbounded Docker logs, liveness dressed up as
readiness - is now implemented and documented below.

---

## 11. Environment variable reference

Every key in `backend/.env.production.example` is listed here. "Optional" means
the application has a working default; uncomment it only when you need to
override that default. Driver-specific keys for drivers this project does not
use in production (MySQL, SQL Server, Beanstalk, SQS, DynamoDB, Memcached,
Postmark, Resend, PaperTrail) are still present so that switching a driver is a
configuration change, not a code change.

### Application

| Variable | Meaning |
|---|---|
| `APP_NAME` | Application name used in logs and mail defaults. |
| `APP_ENV` | `production` in this template. Changes error verbosity. |
| `APP_DEBUG` | Must be `false` in production; never expose stack traces. |
| `APP_URL` | Public base URL, including scheme. Used for absolute/signed links. |
| `APP_KEY` | 32-byte encryption key (`php artisan key:generate --show`). Required. |
| `APP_PREVIOUS_KEYS` | Comma-separated old keys kept during rotation. Optional. |
| `APP_LOCALE` | Default UI/validation locale. |
| `APP_FALLBACK_LOCALE` | Locale used when a translation is missing. |
| `APP_FAKER_LOCALE` | Faker locale (tests/factories only). |
| `APP_MAINTENANCE_DRIVER` | `file`, `cache` or `array` for `artisan down`. |
| `APP_MAINTENANCE_STORE` | Cache store when the maintenance driver is `cache`. Optional. |
| `TRUSTED_PROXIES` | Comma-separated proxy IPs/CIDRs whose `X-Forwarded-*` headers are trusted. `*` trusts the calling address and is risky (section 13). |
| `FORCE_HTTPS` | Redirect HTTP to HTTPS and force https URLs. `false` by default; `/up` and `/ready` are exempt (section 13). |
| `BCRYPT_ROUNDS` | Framework hashing cost; no `config/hashing.php` exists today. |
| `BROADCAST_CONNECTION` | Framework default; no `config/broadcasting.php` exists. |

### Database

| Variable | Meaning |
|---|---|
| `DB_CONNECTION` | `pgsql`. SQLite is not a supported production database. |
| `DB_HOST` | `postgres` inside the compose network. |
| `DB_PORT` | `5432` inside the compose network. |
| `DB_DATABASE` | Database name (`lucky_care_plus`). |
| `DB_USERNAME` | Runtime role (`luckycare_app`), never the superuser. |
| `DB_PASSWORD` | Runtime role password. Change it. |
| `DB_SSLMODE` | `prefer`, `require`, or `verify-full` with a CA. |
| `DB_FOREIGN_KEYS` | Enforce foreign keys (leave true). |
| `DB_CHARSET` | Client charset (`utf8`). |
| `DB_URL` | Optional full DSN; wins over the discrete values. |
| `DB_SOCKET` | Optional unix socket path (non-TCP connection). |
| `DB_COLLATION` | MySQL-only collation; unused by `pgsql`. |
| `DB_ENCRYPT` | SQL Server-only encryption flag. |
| `DB_TRUST_SERVER_CERTIFICATE` | SQL Server-only certificate flag. |
| `MYSQL_ATTR_SSL_CA` | MySQL-only CA path. |
| `DB_CACHE_CONNECTION` | Connection for the database cache store. Optional. |
| `DB_CACHE_TABLE` | Cache table (`cache`). |
| `DB_CACHE_LOCK_CONNECTION` | Connection for cache locks. Optional. |
| `DB_CACHE_LOCK_TABLE` | Lock table. Optional. |
| `DB_QUEUE_CONNECTION` | Connection for the database queue. Optional. |
| `DB_QUEUE_TABLE` | Jobs table (`jobs`). |
| `DB_QUEUE` | Default queue name (`default`). |
| `DB_QUEUE_RETRY_AFTER` | Seconds before a reserved job is retried (150; must exceed the worker timeout of 120). |

### Redis

| Variable | Meaning |
|---|---|
| `REDIS_CLIENT` | `phpredis` (the image installs the extension). |
| `REDIS_HOST` | `redis` inside the compose network. |
| `REDIS_PORT` | `6379` inside the compose network. |
| `REDIS_PASSWORD` | `null` or the Redis AUTH password. |
| `REDIS_DB` | Default Redis database number (0). |
| `REDIS_CACHE_DB` | Cache Redis database number (1). |
| `REDIS_USERNAME` | Redis 6+ ACL username. Optional. |
| `REDIS_URL` | Full Redis DSN; overrides host/port. Optional. |
| `REDIS_PREFIX` | Key prefix for the default connection. Optional. |
| `REDIS_PERSISTENT` | Persistent connections. Optional. |
| `REDIS_CLUSTER` | Cluster mode (`redis` or `predis`). Optional. |
| `REDIS_MAX_RETRIES` | Retry count for failed commands. Optional. |
| `REDIS_BACKOFF_ALGORITHM` | Backoff algorithm for retries. Optional. |
| `REDIS_BACKOFF_BASE` | Backoff base in milliseconds. Optional. |
| `REDIS_BACKOFF_CAP` | Backoff cap in milliseconds. Optional. |
| `REDIS_CACHE_CONNECTION` | Connection used by the cache store (`cache`). Optional. |
| `REDIS_CACHE_LOCK_CONNECTION` | Connection used by cache locks. Optional. |
| `REDIS_QUEUE_CONNECTION` | Connection used by the Redis queue. Optional. |
| `REDIS_QUEUE` | Queue name (`default`). Optional. |
| `REDIS_QUEUE_RETRY_AFTER` | Seconds before a reserved job is retried (150; must exceed the worker timeout of 120). Optional. |

### Session, cache and queue

| Variable | Meaning |
|---|---|
| `SESSION_DRIVER` | `redis`, `database`, `file`, etc. |
| `SESSION_LIFETIME` | Idle lifetime in minutes (120). |
| `SESSION_ENCRYPT` | Encrypt session payloads. |
| `SESSION_EXPIRE_ON_CLOSE` | Expire the session when the browser closes. |
| `SESSION_PATH` | Cookie path (`/`). |
| `SESSION_DOMAIN` | Cookie domain (`null` = request host). |
| `SESSION_SECURE_COOKIE` | Send the cookie only over HTTPS. |
| `SESSION_HTTP_ONLY` | Block JavaScript access to the cookie. |
| `SESSION_SAME_SITE` | `lax`, `strict` or `none`. |
| `SESSION_PARTITIONED_COOKIE` | Partitioned (CHIPS) cookie flag. |
| `SESSION_CONNECTION` | Connection for database/redis sessions. Optional. |
| `SESSION_TABLE` | Sessions table (`sessions`). |
| `SESSION_STORE` | Cache store for the cache session driver. Optional. |
| `CACHE_STORE` | `redis`, `database`, `file`, `array`, etc. |
| `CACHE_PREFIX` | Cache key prefix. Optional. |
| `CACHE_STORAGE_DISK` | Disk for the file cache driver. Optional. |
| `CACHE_STORAGE_PATH` | Path inside that disk. Optional. |
| `MEMCACHED_HOST` | Memcached host (unused unless the driver is memcached). |
| `MEMCACHED_PORT` | Memcached port. |
| `MEMCACHED_USERNAME` | Memcached SASL username. |
| `MEMCACHED_PASSWORD` | Memcached SASL password. |
| `MEMCACHED_PERSISTENT_ID` | Memcached persistent connection id. |
| `QUEUE_CONNECTION` | `redis`, `database`, `sync`, etc. |
| `QUEUE_FAILED_DRIVER` | Failed-job store (`database-uuids`). |
| `BEANSTALKD_QUEUE_HOST` | Beanstalk host (unused driver). |
| `BEANSTALKD_QUEUE` | Beanstalk tube. |
| `BEANSTALKD_QUEUE_RETRY_AFTER` | Beanstalk retry delay. |
| `SQS_PREFIX` | SQS endpoint prefix (unused driver). |
| `SQS_QUEUE` | SQS queue name. |
| `SQS_SUFFIX` | SQS queue suffix. |
| `DYNAMODB_CACHE_TABLE` | DynamoDB cache table (unused driver). |
| `DYNAMODB_ENDPOINT` | DynamoDB endpoint. |

### Filesystem and documents

| Variable | Meaning |
|---|---|
| `FILESYSTEM_DISK` | Default Laravel disk (`local`). |
| `DOCUMENTS_DISK` | Disk for medical documents (`local` or `s3`). |
| `EXPORTS_DISK` | Disk for PDPA exports (`local` or `s3`). |
| `EXPORTS_RETENTION_DAYS` | Export lifetime in days (7). |
| `EXPORTS_RATE_LIMIT_HOURS` | One export per profile per N hours (24). |
| `EXPORTS_LINK_TTL_MINUTES` | Signed export link TTL (15 max). |
| `AWS_ACCESS_KEY_ID` | S3/MinIO access key. |
| `AWS_SECRET_ACCESS_KEY` | S3/MinIO secret. |
| `AWS_DEFAULT_REGION` | S3 region. |
| `AWS_BUCKET` | Bucket name. |
| `AWS_URL` | Public/custom S3 URL. Optional. |
| `AWS_ENDPOINT` | MinIO or S3-compatible endpoint. |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` for MinIO. |
| `DOCUMENTS_MAX_BYTES` | Hard upload cap (10 MiB), enforced in PHP. |
| `DOCUMENTS_SCAN_REQUIRED` | Fail closed when scanning is on but clamd is down. |
| `DOCUMENTS_SCANNER` | Empty (unavailable) or `clamav`. |
| `DOCUMENTS_CLAMAV_SOCKET` | clamd unix socket path. |
| `DOCUMENTS_CLAMAV_TIMEOUT` | Seconds allowed for connect/write/read. |

### Mail, OTP and authentication

| Variable | Meaning |
|---|---|
| `MAIL_MAILER` | `smtp`, `log`, `array`, etc. |
| `MAIL_SCHEME` | `smtp`, `smtps`, or `null`. |
| `MAIL_HOST` | SMTP host. |
| `MAIL_PORT` | SMTP port. |
| `MAIL_USERNAME` | SMTP username. |
| `MAIL_PASSWORD` | SMTP password. |
| `MAIL_FROM_ADDRESS` | Envelope/from address. |
| `MAIL_FROM_NAME` | From display name. |
| `MAIL_URL` | Full mail DSN. Optional. |
| `MAIL_EHLO_DOMAIN` | EHLO domain. Optional. |
| `MAIL_SENDMAIL_PATH` | sendmail binary path (sendmail driver only). |
| `MAIL_LOG_CHANNEL` | Log channel for the `log` mailer. Optional. |
| `POSTMARK_API_KEY` | Postmark transport key (unused by default). |
| `POSTMARK_MESSAGE_STREAM_ID` | Postmark stream id. |
| `RESEND_API_KEY` | Resend transport key (unused by default). |
| `OTP_DEMO_MODE` | Must be `false` in production. |
| `AUTH_GUARD` | Default guard (`web`). |
| `AUTH_PASSWORD_BROKER` | Password broker (`users`). |
| `AUTH_MODEL` | User model class. |
| `AUTH_PASSWORD_RESET_TOKEN_TABLE` | Reset-token table. |
| `AUTH_PASSWORD_TIMEOUT` | Reset-link lifetime in seconds. |
| `SANCTUM_TOKEN_PREFIX` | Optional bearer-token prefix. |
| `SANCTUM_STATEFUL_DOMAINS` | Cookie-authenticated SPA domains. |

### Retention and AI

| Variable | Meaning |
|---|---|
| `RETENTION_DRY_RUN` | `true` logs what would be deleted; keep it until D3. |
| `AI_ENABLED` | Master switch; `false` returns `503 ai_disabled`. |
| `AI_PROVIDER` | `null`, `okmd`, or `openai_compatible`. |
| `AI_BASE_URL` | OpenAI-compatible base URL. |
| `AI_API_KEY` | Provider key (never commit it). |
| `AI_MODEL` | Default text model. |
| `AI_MODEL_1` | Alias for `AI_MODEL`. |
| `AI_MODEL_2` | Alias for `AI_MODEL_VISION`. |
| `AI_MODEL_VISION` | Vision model for OCR extraction. |
| `AI_MODEL_SUMMARY` | Per-purpose override for summaries. |
| `AI_MODEL_KNOWLEDGE` | Per-purpose override for knowledge answers. |
| `AI_VISION_MODELS` | Comma-separated allow-list for image requests. |
| `AI_TIMEOUT` | Provider request timeout in seconds. |
| `AI_MAX_RETRIES` | Provider retry count. |
| `AI_PER_USER_DAY` | Daily per-user request quota. |
| `AI_PER_USER_MINUTE` | Per-minute per-user request quota. |
| `AI_MAX_TOKENS` | Response token cap. |
| `AI_TEMPERATURE` | Sampling temperature. |
| `AI_LOCALE` | Answer locale (`th`). |

### Knowledge base

| Variable | Meaning |
|---|---|
| `KNOWLEDGE_SQLITE_PATH` | Read-only SQLite corpus (FTS5 required). |
| `KNOWLEDGE_SOURCES_PATH` | Source JSON directory. |
| `KNOWLEDGE_BENCHMARK_PATH` | Benchmark question set. |
| `KNOWLEDGE_TOP_K` | Retrieved chunks per answer (5). |
| `KNOWLEDGE_MAX_QUESTION_CHARS` | Question length cap (500). |
| `KNOWLEDGE_CHUNK_CHARS` | Chunk size (900). |
| `KNOWLEDGE_CHUNK_OVERLAP` | Chunk overlap (150). |
| `KNOWLEDGE_FETCH_TIMEOUT` | Source fetch timeout. |
| `KNOWLEDGE_FETCH_DELAY_MS` | Delay between source fetches. |

### Logging and observability

| Variable | Meaning |
|---|---|
| `LOG_CHANNEL` | Default channel (`stack`). |
| `LOG_STACK` | Comma-separated channels behind `stack`; `stderr` in containers. |
| `LOG_LEVEL` | Minimum level (`info` in production). |
| `LOG_DEPRECATIONS_CHANNEL` | Deprecation channel (`null`). |
| `LOG_DEPRECATIONS_TRACE` | Include traces for deprecations. |
| `LOG_DAILY_DAYS` | Daily-file retention. Optional. |
| `LOG_STDERR_FORMATTER` | Custom stderr formatter. Optional. |
| `LOG_SYSLOG_FACILITY` | Syslog facility. Optional. |
| `LOG_SLACK_WEBHOOK_URL` | Slack log channel webhook. Optional. |
| `LOG_SLACK_USERNAME` | Slack message username. Optional. |
| `LOG_SLACK_EMOJI` | Slack message emoji. Optional. |
| `PAPERTRAIL_URL` | Papertrail host. Optional. |
| `PAPERTRAIL_PORT` | Papertrail port. Optional. |
| `LOG_PAPERTRAIL_HANDLER` | Papertrail handler class. Optional. |
| `SLACK_BOT_USER_OAUTH_TOKEN` | Slack notifications bot token. Optional. |
| `SLACK_BOT_USER_DEFAULT_CHANNEL` | Slack notifications channel. Optional. |

There is no metrics/tracing endpoint in the application yet; the logging
variables above are the only observability knobs that exist.

---

## 12. TLS termination (optional `proxy` profile)

The stack ships a profile-gated Caddy terminator. It is additive: `docker
compose --profile app up -d` is unchanged and still serves plain HTTP on
`:8080` for local debugging, while `--profile app --profile proxy` adds a TLS
front door and leaves `web` unexposed by the proxy. Files:

| File | Purpose |
|---|---|
| `docker/caddy/Caddyfile` | Site address, security headers, TLS mode, `/ready` health check. |
| `docker-compose.yml` (`proxy`) | Caddy container, host ports, Caddy data volumes. |

### 12.1 Local mode (no domain, internal CA)

```bash
docker compose --profile app --profile proxy up -d --build
curl -k -o /dev/null -w 'GET /ready -> %{http_code}\n' https://localhost:8443/ready
curl -k -I https://localhost:8443/up
```

Defaults: `APP_DOMAIN=localhost`, `CADDY_TLS_SNIPPET=tls_local`,
`CADDY_HSTS_SNIPPET=no_hsts`, host ports `8088` (HTTP redirect) and `8443`
(HTTPS). `-k` accepts Caddy's internal CA; import
`/data/caddy/pki/authorities/local/root.crt` from the `caddydata` volume to trust
it. HSTS is deliberately **off** in local mode: pinning a locally trusted
certificate would break other local HTTPS sites in that browser profile.

The `proxy` profile depends on the `web` service, which lives in the `app`
profile, so always pass both profiles. Local TLS is useful even without a
domain, but Laravel only sees the forwarded scheme once it trusts the nginx hop:

```bash
docker network inspect lucky-care-plus_default \
  -f '{{range .IPAM.Config}}{{.Subnet}}{{end}}'   # e.g. 172.19.0.0/16
```

Put that CIDR in `backend/.env` as `TRUSTED_PROXIES`, then recreate `app`
(section 8). Without it the pages still load over HTTPS, but generated absolute
URLs and `secure` cookies use `http` (section 9.8).

### 12.2 Domain mode (real certificate)

```bash
APP_DOMAIN=api.example.com \
CADDY_TLS_SNIPPET=tls_domain \
CADDY_HSTS_SNIPPET=hsts \
CADDY_REDIRECT_TARGET=https://api.example.com \
CADDY_HTTP_PORT=80 \
CADDY_HTTPS_PORT=443 \
docker compose --profile app --profile proxy up -d
```

Requirements: the DNS name resolves to the host and inbound `80`/`443` reach
Caddy (ACME HTTP challenge). Caddy obtains and renews the certificate
automatically and stores it in the `caddydata` volume. Port 80 redirects to the
HTTPS URL, and HSTS is enabled.

Then set in `backend/.env` and recreate `app`, `queue`, `scheduler` (section 8):

```dotenv
APP_URL=https://api.example.com
FORCE_HTTPS=true
TRUSTED_PROXIES=<compose network CIDR>
```

### 12.3 What the proxy adds

- TLS on `:443` (host `:8443` locally) and an HTTP -> HTTPS redirect.
- `Strict-Transport-Security` in domain mode only.
- `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`,
  `X-Frame-Options: DENY` on every response (the API is JSON; nothing should
  frame it).
- `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host` on every proxied
  request (Caddy sets them by default; confirmed with `caddy adapt`).
- An active `health_uri /ready` check. A failing readiness probe marks the
  upstream down, so Caddy returns 503 instead of forwarding to an instance that
  cannot reach its database or cache.

Certificate material lives in `caddydata`; removing that volume in local mode
regenerates the CA and invalidates previously trusted copies.

---

## 13. Trusted proxies and forced HTTPS

`TRUSTED_PROXIES` is a **security control**, not boilerplate. `X-Forwarded-*`
headers are supplied by the client unless the application knows which peers are
allowed to set them. With an empty list Laravel ignores them: behind Caddy every
request looks like plain HTTP from the proxy's address, so generated URLs and
signed links are `http://` and audit rows record the proxy IP. With an
over-broad list any client that can reach `app` directly can forge its address
and scheme.

The application is configured in `backend/bootstrap/app.php` (a booting callback
runs after `config` is loaded) and reads `app.trusted_proxies`, which comes from
`TRUSTED_PROXIES`. Format: comma-separated IPs and/or CIDRs. `*` trusts the
calling address; it is only safe when nothing but the proxy can reach php-fpm
(for example a host firewall that drops `:8080`). In the reference stack the
nginx and Caddy containers are both hops, so the compose subnet is the practical
value:

```bash
docker network inspect lucky-care-plus_default \
  --format '{{(index .IPAM.Config 0).Subnet}}'
# Example: 172.19.0.0/16 -> TRUSTED_PROXIES=172.19.0.0/16
```

nginx deliberately does **not** rewrite `REMOTE_ADDR` with `real_ip`; it keeps
itself as Laravel's direct peer and appends its own hop to
`X-Forwarded-For`. That is why the allow-list must contain the nginx container
(and Caddy, because Symfony walks the chain right-to-left through every trusted
hop). Do not add a public client range.

`FORCE_HTTPS=true` does two things:

1. calls `URL::forceScheme('https')`, so `url()` and `secure_url()` produce
   `https://` even on a request that arrived over plain HTTP; and
2. redirects a plain-HTTP request with `301` to the same URI over HTTPS.

`/up` and `/ready` are exempt from the redirect: the container healthcheck
talks to php-fpm over plain HTTP from inside the container, and a 301 would make
a healthy instance look unhealthy. The URLs generated on those requests are
still forced to https. `FORCE_HTTPS=false` (the default) leaves both behaviours
off.

`trustHosts` is also enabled: the host patterns are the public host from
`APP_URL`, its subdomains, plus `localhost`, `127.0.0.1` and `web` (the internal
probe and Caddy health-check hosts). Laravel only enforces host validation
outside `local`/`testing`, so the framework does not reject the development
server. If you change `APP_URL` to a new public host, include it before pointing
DNS at the stack.

After changing either variable, recreate the application containers and rebuild
the config cache (section 8); `config:cache` freezes the old value.

---

## 14. Edge rate limiting

`docker/nginx.conf` adds a second, cheaper layer in front of Laravel's
per-endpoint throttle middleware. It is per source IP, enforced by nginx before
php-fpm is involved:

| Zone | Paths | Rate | Burst |
|---|---|---|---|
| `auth_limit` | `/api/v1/auth/*` (login, register, OTP, PIN) | **10 requests/minute** per IP | 10, `nodelay` |
| `api_limit` | everything else, including `/up` and `/ready` | **60 requests/minute** per IP | 60, `nodelay` |

Excess requests return `429` with
`{"error":"rate_limited","message":"Too many requests"}` and `Retry-After: 60`.
The app's own limits are untouched (for example `throttle:6,1` on login), so the
effective budget is the stricter of the two. When the request arrives through
Caddy the key is the first `X-Forwarded-For` address; a direct request to the
debug port has no header and falls back to the connection address. **Do not
expose `:8080` publicly** - a direct client can spoof that header and choose its
own bucket.

**A per-IP limit is weak against distributed traffic.** A botnet with a thousand
addresses gets a thousand buckets; only a provider WAF / DDoS service (section
10) addresses that. Tune the numbers in `docker/nginx.conf` and reload the `web`
container (`docker compose --profile app exec -T web nginx -s reload`) if the
reference values do not fit the traffic profile.

---

## 15. Logs

The reference stack has no log stack. Every service uses Docker's `json-file`
driver with bounded rotation:

```yaml
logging:
  driver: json-file
  options:
    max-size: "10m"
    max-file: "5"
```

That is at most 50 MB per container (10 MB x 5 files) and a bounded time window
that depends on traffic. It keeps a laptop or small host from filling its disk
and gives an operator a recent window for an incident. The application writes to
stderr (`LOG_STACK=stderr`), so the lines appear in the container log; the
optional `applogs` volume at `/var/www/html/storage/logs` only holds files if a
file channel is configured.

Useful idioms:

```bash
docker compose --profile app logs -f app
docker compose --profile app logs --since=30m app queue scheduler
docker compose --profile app logs --tail=200 web
docker compose --profile proxy logs -f proxy
docker compose --profile app logs --no-color app | Select-String -Pattern 'ERROR|CRITICAL'
```

`docker compose logs` follows the current container; `docker compose logs
--timestamps` adds the Docker timestamp, and `--since`/`--until` bound the
window. `docker compose ps` shows which services are up; a restarted container's
previous log is gone when the json-file was rotated.

### Shipping options (documented, not installed)

| Option | How it works | Trade-off |
|---|---|---|
| Vector or Fluent Bit -> Loki | A log agent reads the Docker socket / json-file and ships structured lines to Loki; Grafana queries them. | Best queryability and retention control; adds two services and a retention policy you must size. |
| Hosted collector | Point the Docker logging driver or an agent at a SaaS (Datadog, Grafana Cloud, etc.). | No local stack; cost, data-residency/DPA review, and PHI policy (N-02) before enabling. |
| `docker logs` scraping | A cron/agent calls `docker compose logs --since=...` and stores the text. | Last resort. No correlation, no back-pressure, easy to lose lines, weak retention. |

What is still missing even with shipping: **no correlation id across
services** (a request id is not propagated to queue jobs), **no retention policy
beyond file rotation** (no legal/PHI retention decision), and **no alerting**
(nothing watches error rates, failed jobs, scan failures or audit denials).
N-02 still applies: health data must never be put in logs, notification
payloads, file names, URLs or error messages.

---

## 16. Liveness vs readiness

| Probe | Path | What it proves | Used by |
|---|---|---|---|
| Liveness | `GET /up` | The application booted and can answer HTTP. It does **not** touch the database, Redis or the schema. | Docker healthcheck (`app`, `queue`, `scheduler`), `web` healthcheck. |
| Readiness | `GET /ready` | The database answers `select 1` **and** the cache/Redis dependency answers `PING` (or a put/get round-trip on a non-Redis store). | Caddy `health_uri`; an orchestrator or load balancer. |

`/ready` is registered in `backend/bootstrap/app.php` (outside the `web` and
`api` groups, so no session or auth), returns `200` with
`{"status":"ok","checks":{"database":"ok","redis":"ok"}}`, and `503` with the
failing dependency named:

```json
{"status":"unavailable","checks":{"database":"failed","redis":"ok"},"failed":["database"]}
```

It carries no secrets, version strings or stack traces. The route is
rate-limited by `ReadyRateLimit` (60/minute per IP, cache-backed) and by
nginx's `api_limit`. The limiter **fails open** if the cache is the failing
dependency, so a Redis outage is reported as a `503 naming redis` instead of a
`500 from the throttle middleware`; the nginx limit remains in front of it.

Caddy's `health_uri /ready` means traffic is only routed to an instance that can
actually serve. If `/ready` fails, Caddy marks the upstream down and returns its
own 503 (without the JSON body); query `http://localhost:8080/ready` directly to
read which dependency failed. Do not point a container healthcheck at `/ready`:
a database blip would then restart a perfectly good php-fpm process. Use `/up`
for liveness and `/ready` for routing.

A readiness probe is not monitoring. There is no alert on a persistent 503, no
uptime check from outside the host, and no dashboard (section 10).
