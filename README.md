# Lucky Care Plus · LuckyCare+

A family health CRM platform: several people coordinate the care of one person, with fine-grained,
revocable access to health data. Built for Thai families caring for elderly parents.

> **Design first.** The complete engineering blueprint lives in [`.ai/`](.ai/README.md) —
> domain model, authorization, data model, API contract, security, testing, roadmap.
> Read it before writing code.

## Repository layout

| Path | Contents | Stack |
|---|---|---|
| `.ai/` | Project blueprint (19 docs, 17 ADRs) | Markdown |
| `docs/` | Source summaries + **frozen authorization baseline v1.1.0** (`docs/baseline-v1.0.0/`, content = v1.1.0) | Markdown / PDF |
| `backend/` | API | Laravel 13 · PHP 8.4 · PostgreSQL · Sanctum |
| `frontend/` | Web app | Angular 22 · Tailwind 4 |
| `mobile/` | Mobile app (`lucky_care`) | Flutter |
| `references/` | Upstream projects kept for reference — **not versioned** | — |

## Quickstart

```bash
# 1. Infrastructure (PostgreSQL 5433, Redis 6380; MinIO optional: --profile storage)
docker compose up -d

# 2. Backend
cd backend
cp .env.example .env          # only the first time
php artisan key:generate      # only the first time
php artisan migrate --seed
php artisan test
php artisan serve             # http://localhost:8000
```

Seed accounts (local only): `owner@example.test`, `caregiver@example.test`, `viewer@example.test` — password `password`.

```bash
# 3. Frontend
cd frontend && npm install && npm start     # http://localhost:4200

# 4. Mobile
cd mobile && flutter pub get && flutter run
```

## Security model in one paragraph

Deny by default. Membership in a workspace never grants access to a health profile, task assignment
grants task scope only, and read/export/share/AI-use are separate permissions. Tenant isolation is
enforced twice — `tenant_id` + `workspace_id` columns with a composite foreign key, **and** PostgreSQL
Row Level Security — and the application connects as a non-superuser role (`luckycare_app`) so policies
are never bypassed. The full rule set is in [`.ai/00-CONSTITUTION.md`](.ai/00-CONSTITUTION.md).

## Status

| Phase | Scope | State |
|---|---|---|
| **0 · Foundation** | tenancy, RLS, workspace/membership, authorization service, auth | 🚧 in progress |
| 1 · Core MVP | health vault, care tasks, dashboard, notifications | ⬜ |
| 2 · Coordination | recurring, escalation, break-glass, doctor brief | ⬜ |
| 3 · AI | context gateway, summaries, OCR | ⬜ |

See [`.ai/12-ROADMAP.md`](.ai/12-ROADMAP.md) for gates and acceptance criteria.
