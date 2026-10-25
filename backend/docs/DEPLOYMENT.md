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
| `docker/nginx.conf` | Nginx front door for the `web` service. |
| `docker-compose.yml` | `app` profile (app, web, queue, scheduler), infra unchanged. |
| `.github/workflows/ci.yml` | `Image` job: a broken Dockerfile fails a pull request. |

The stack does **not** terminate TLS. Run it behind a TLS terminator (Caddy,
Traefik, nginx on the host, or a cloud load balancer) that forwards to port 8080.

---

## 1. Prerequisites

- Docker Engine 24+ with Compose v2.24+ (`docker compose version`). The
  `env_file: required: false` syntax used by `docker-compose.yml` needs 2.24+.
- **4 GB RAM** minimum for the host (Postgres, Redis, php-fpm, nginx and one
  queue worker). Add Redis/DB headroom for a real workload.
- 10 GB free disk for images, the Postgres volume, and document storage.
- A DNS name and a **TLS terminator** in front of `http://<host>:8080`. The
  compose stack listens on plain HTTP only.
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
`web` checks the same route through nginx.

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
curl -fsS -o /dev/null -w 'GET /up -> %{http_code}\n' http://localhost:8080/up
```

Expected: `GET /up -> 200`. `/up` is the Laravel health route registered in
`backend/bootstrap/app.php`; it does not touch the database, so a 200 proves the
HTTP path and the application boot, not schema state (section 4 checks that).

If you terminate TLS, the same request through the terminator must return 200.
Set `APP_URL` to the external HTTPS URL so signed document/export links use the
public host.

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
# Expected: retention:purge, retention:inactivity, notifications:send-due,
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

Run this short block after the first install and after every upgrade. It checks
the HTTP path, the auth surface, the database schema and the queue backend.

```bash
set -euo pipefail
BASE=http://localhost:8080

# 1. Health route (HTTP 200, empty body).
curl -fsS -o /dev/null -w 'GET /up -> %{http_code}\n' "$BASE/up"

# 2. Auth surface is reachable and validates input (422, not 500/502).
curl -sS -o /dev/null -w 'POST /auth/login (bad credentials) -> %{http_code}\n' \
  -X POST "$BASE/api/v1/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"smoke@example.invalid","password":"not-a-real-password"}'

# 3. Database is migrated (prints the last few migrations and their batch).
docker compose --profile app exec -T app php artisan migrate:status | tail -n 5

# 4. The app sees the production environment and no debug output.
docker compose --profile app exec -T app php artisan about --only=environment

# 5. Queue backend is reachable from the worker (0 pending is fine).
#    Use "<connection>:<queue>": database:default or redis:default.
docker compose --profile app exec -T queue php artisan queue:monitor database:default --max=1000
```

Expected: `200`, `422`, migration rows with `Ran`, `environment: production`,
and a queue table. A `502` from step 1/2 means nginx cannot reach php-fpm; a
`500` means the application booted but a dependency failed; see section 9.

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
them before a real deployment (section 10).

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

---

## 10. Honest "not production-ready yet" list

This slice makes the stack installable. It does not make it a compliant
production platform. Known gaps:

1. **No TLS.** The stack terminates nothing. A separate terminator and a
   certificate lifecycle are required. The application also does not yet read a
   trusted-proxy allow-list, so generated absolute URLs behind a terminator can
   use `http`; add `$middleware->trustProxies(...)` in `bootstrap/app.php` as a
   follow-up (outside this task's allowed file set).
2. **No log shipping, metrics, tracing or alerting.** Logs go to stderr (or the
   `applogs` volume) and are readable with `docker compose logs`. There is no
   Prometheus endpoint, no APM, and no alert on failed jobs, scan failures or
   audit denials.
3. **No autoscaling or high availability.** One host, one Postgres, one Redis,
   one queue worker. A host failure is downtime. Postgres/Redis are not
   replicated.
4. **Backups are not production-grade.** The CI producer is a 7-day artifact
   safety net; there is no off-site encrypted storage, tested restore, or
   stated RPO/RTO in this repository (section 5).
5. **Default credentials are development values.** The reference compose uses
   `luckycare` / `luckycare_dev` for Postgres and `luckycare_app_dev` for the
   runtime role. Rotate both, and update `backend/.env`, before exposing the
   host.
6. **AI is disabled** until G4/D1 (section 7). **Scanning is disabled** until D4
   and a reachable clamd socket (section 6). **Retention has never deleted
   anything** (`RETENTION_DRY_RUN=true`) until D3.
7. **No per-tenant quotas/backpressure for scans**, no quarantine review UI and
   no rescan after signature updates (see `backend/docs/MALWARE-SCANNING.md`).
8. **Single-host document storage** unless you switch `DOCUMENTS_DISK`/
   `EXPORTS_DISK` to S3/MinIO. The `appstorage` named volume is not replicated.
9. **No edge WAF or global rate limiting** beyond Laravel's per-endpoint
   throttles.
10. **No zero-downtime deploy.** The upgrade section recreates containers; a
    short 502 window is possible. Use a load balancer with two hosts if that
    matters.

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
| `TRUSTED_PROXIES` | Reserved; not read by the application yet (section 10). |
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
