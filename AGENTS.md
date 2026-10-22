# AGENTS.md — Lucky Care Plus (LuckyCare+)

> **Read this first.** This file is the entry point for any coding agent working in this repository.

## 1. What this project is

**Lucky Care Plus** (short: **LuckyCare+**) — a family health CRM platform:
multiple caregivers coordinate the care of one person, with fine-grained, revocable access to health data.

Repository layout:

| Path | Contents |
|---|---|
| `.ai/` | **Blueprint — the single source of truth for design.** Read before writing code. |
| `docs/` | Source summaries + frozen authorization baseline (`docs/baseline-v1.0.0/`, content = v1.1.0) |
| `backend/` | Laravel 13 API (PHP 8.4) |
| `frontend/` | Angular 22 + Tailwind 4 web app |
| `mobile/` | Flutter app (`lucky_care`) |
| `references/` | Cloned upstream projects — **read-only, never edit, never import wholesale** |

## 2. Rule precedence (do not violate)

1. `.ai/00-CONSTITUTION.md`
2. `docs/baseline-v1.0.0/*` (permission IDs, roles, matrix, test IDs — normative)
3. other `.ai/*.md`
4. `docs/SUMMARY*.md` (historical)
5. `docs/PDFs/*` (advisory only)
6. `references/*` (patterns only)

If two documents disagree, the higher one wins. If code would contradict them, **stop and ask** — do not invent authorization rules.

## 3. Non-negotiables (from the Constitution)

- `F-01` deny by default · `F-04` task assignment ≠ data access · `F-06` read ≠ export ≠ share ≠ AI
- `F-08` revocation must take effect on every path · `F-09` fail closed
- `N-01` never trust `tenant_id` / `workspace_id` / `profile_id` / `role` / `consent` from the client
- `N-02` never put health data in logs, notification payloads, file names, URLs, or error messages
- `N-06` never query across module boundaries — use contracts/events

## 4. Backend conventions (Phase 0)

- **Modular monolith**: `backend/app/Modules/<Context>/` with `Domain/`, `Application/`, `Infrastructure/`, `Http/`, `Policies/`, `Tests/`.
  Contexts: `Accounts`, `Workspace`, `People`, `Authz`, `Consent`, `HealthRecord`, `CareCrm`, `Timeline`, `Notifications`, `AiGateway`, `Audit`, `Billing` (see `.ai/02-DOMAIN-MODEL.md`).
- Every tenant-scoped table has **`tenant_id` + `workspace_id`** and an RLS policy (`.ai/06-DATA-MODEL.md` §4, ADR-013).
- Every route is **workspace-scoped** except `/api/v1/auth/*`, `/api/v1/me*`, `/api/v1/invitations/{token}` (ADR-005).
- Every endpoint declares its **permission ID** from `docs/baseline-v1.0.0/PERMISSION_CATALOG.md` — never invent permission names.
- Health data tables use **UUIDv7** primary keys; no auto-increment (ADR-008).
- All state transitions go through domain services and write `task_events` / audit (`.ai/05-CARE-COORDINATION.md`).
- Tests must run against **PostgreSQL** (never SQLite) — RLS and constraints are part of the spec.

## 5. Quality bar before declaring work done

1. `php artisan test` passes (or the specific suite you touched).
2. Every new endpoint has: permission annotation, positive test, negative test, cross-workspace test.
3. No PHI in logs/notifications (`.ai/09-SECURITY-PRIVACY.md` §2.4).
4. Migrations have working `down()`.
5. Update `.ai/` docs only through the change-control rules in `.ai/README.md` §5.

## 6. Environment

- PHP 8.4 · Composer 2 · PostgreSQL (Docker, host port **5433**) · Redis (host port **6380**) · MinIO (9002/9003)
- Start infrastructure: `docker compose up -d` (repo root)
- Backend: `cd backend && php artisan migrate --seed && php artisan test`
- **Do not touch** `references/`, `docs/baseline-v1.0.0/` content, or anything outside this repository.
- Do not run remote install scripts. If a tool is missing, report it instead of installing it.

## 7. Language

- Code, identifiers, commit messages, and technical docs: **English**.
- User-facing copy and product docs: **Thai** (the product targets Thai families).
