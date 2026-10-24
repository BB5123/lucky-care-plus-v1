# G0 Evidence — Phase 0 (Foundation) exit gate

**Status: ✅ G0 PASSED (2026-10-23).** 9 of the 10 Authorization Readiness items are proven; the
remaining item (AI/export) is **deferred to G4 by [ADR-018](../../.ai/DECISIONS.md#adr-018--g0-scoping--capability-dependent-checklist-ย้ายไป-gate-ของ-feature-นั้น)**,
which is a deferral, not a pass. Branch protection on `main` is now active with the three CI checks
required, so the ADR-019 override ended.

> **Update 2026-10-24 (P-4):** item 8 now has **gateway-level evidence** — the `AI-*` and
> `EXPORT-*` tests exist and `php artisan ai:evaluate` passes all six §6 suites. This does **not**
> pass G4: **live-provider evaluation is still pending (P-2)** and **AI remains disabled**
> (`AI_ENABLED=false`). See §2.1.
>
> **Update 2026-10-24 (G4 live run):** the live-provider evaluation now exists —
> `php artisan ai:evaluate --live` sends the injection, grounding and safety suites to the configured
> provider and passed 20/20, 10/10 and 7/7 against OKMD (`llama-4-maverick`; the intended
> `deepseek-v4-flash` hit its daily cap mid-run). The run also found and fixed a production bug:
> `TenantContext::run()` outside a transaction made **every workspace-scoped route answer 404** on a
> real server. Evidence and limitations: [`G4-LIVE-EVALUATION.md`](G4-LIVE-EVALUATION.md).
> **G4 is still not signed off** — the DPA/provider-approval item is an owner decision and the run has
> to be repeated with the production model after its quota resets. `AI_ENABLED` remains `false`.

- Repository: `lucky-care-plus-v1`
- Baseline commit: `466d6e4` (Phase 0.1–0.5) plus the guard tests and CI workflow of this task.
- Evidence collected: 2026-10-23, locally, on Windows, and on GitHub Actions.
- Toolchain: PHP 8.4.15 · Laravel 13.38.0 · PostgreSQL 16 (Docker, host port 5433) · Pint 1.32.1.
- Test database role: `luckycare_app` — verified `usesuper = false`, `usebypassrls = false`
  (`php artisan tinker --execute="dump(DB::selectOne('select current_user as role, usesuper, usebypassrls from pg_user where usename = current_user'));"`).
  This matters: as a superuser, every RLS policy would be silently bypassed.

## Source note — where the 10-item checklist actually lives

The task cited `docs/baseline-v1.0.0/AUTHORIZATION_TEST_PLAN.md` §7. That section is
**“Test implementation strategy”**, not a checklist. The 10-item *Authorization Readiness
Checklist* is in `docs/SUMMARY.md` §7 (“เกณฑ์ผ่านก่อนเริ่มพัฒนา Feature”),
and it is the one referenced by `.ai/12-ROADMAP.md` line 37 (“checklist §7 ของ baseline ครบ (10 ข้อ)”).
This document therefore maps the **actual 10-item checklist** from `docs/SUMMARY.md` §7.
Nothing in `docs/` was modified.

## How to reproduce the evidence

```bash
cd backend

# Full suite (includes both new guards)
php artisan test                    # 69 passed, 823 assertions
./vendor/bin/pint --test            # passed

# Targeted evidence
php artisan ai:evaluate            # six evaluation suites, exit 0 (AI stays disabled)
php artisan test --filter=AiEvaluationTest
php artisan test --filter=ExportGateTest
php artisan test --filter=PermissionCatalogTest
php artisan test --filter=TenantIsolationTest
php artisan test --filter=WorkspaceContextMiddlewareTest
php artisan test --filter=AuthorizerTest
php artisan test --filter=GrantAndConsentTest
php artisan test --filter=RoutePermissionCoverageTest
php artisan test --filter=PhiLeakTest
```

CI workflow: `.github/workflows/ci.yml` (backend job runs the same two commands against
`postgres:16`; YAML parsed successfully with PyYAML 6.0.1 and ruamel.yaml (YAML 1.2) —
`actionlint` was not installed).

## 1. G0 exit criteria — `.ai/10-TESTING.md` §5 (G0 · Foundation)

§5 defines G0 as: authorization Unit + Feature tests pass 100%, `TEN-*` passes, and the
baseline §7 checklist is complete. `.ai/12-ROADMAP.md` adds two Phase 0 exit criteria.

| # | Criterion | Evidence (command/file) | Status |
|---|---|---|---|
| G0-a | Authorization Unit + Feature tests pass 100% | `php artisan test` → **69 passed / 823 assertions**. Authorization-focused suites: `AuthorizerTest` 11/488, `GrantAndConsentTest` 9/21, `PermissionCatalogTest` 2/24, `WorkspaceContextMiddlewareTest` 4/16 → 26 tests, 0 failures. | ✅ |
| G0-b | `TEN-*` passes | `php artisan test --filter=TenantIsolationTest` → **5 passed / 35 assertions** (`TEN-T01..T05`). RLS is `ENABLE` + `FORCE` in `database/migrations/2026_10_22_150700_enable_row_level_security.php`; tests run as non-superuser `luckycare_app`. | ✅ |
| G0-c | Baseline §7 readiness checklist complete (10 items) | Section 2 below: **9 ✅ / 1 ✅ gateway level (item 8; the full G4 gate is still open, see §2.1)**. No item is open at the gateway level; the live-provider evaluation is recorded in §2.2 and AI stays disabled. | ✅ |
| G0-d | (Roadmap) No endpoint without a permission annotation | `php artisan test --filter=RoutePermissionCoverageTest` → **4 passed / 34 assertions**. All 14 `api/v1` route entries are either permission-guarded (4) or explicitly allowlisted with a written reason (10); every declared permission ID exists in the 99-ID catalog. | ✅ |
| G0-e | (Roadmap) PHI leak scan passes | `php artisan test --filter=PhiLeakTest` → **3 passed / 6 assertions**. Scans every `app/**/*.php` `Log::*()` call for PHI/credential tokens; empty allowlist; scanner has a planted-leak self-test. Scope: log calls only. | ✅ |

## 2. Authorization Readiness Checklist (10 items) — `docs/SUMMARY.md` §7

Legend: ✅ = proven by a test/command listed in the row · ⏭ = deferred to a later gate by an ADR
· ⬜ = not done or not proven · **✅ gateway level** = the gateway/export surfaces are proven, but the
full G4 gate is still open (DPA/provider approval and the production-model repeat).

| # | Item | Evidence (command/file) | Status |
|---|---|---|---|
| 1 | Permission Catalog with explicit Action and Resource | `backend/config/authorization.php` (99 IDs, normative v1.2.0). `PermissionCatalogTest` parses `docs/baseline-v1.0.0/PERMISSION_CATALOG.md` and fails on drift: **CATALOG-T01/T02, 2 passed / 24 assertions**. | ✅ |
| 2 | Permission Matrix covers every primary role and every health-data type | **`MatrixConformanceTest`** parses `docs/baseline-v1.0.0/DATA_ACCESS_MATRIX.md` Matrices A–E (47 rows), maps every row to permission IDs, and asserts each of the 8 roles against `RoleGate`: **1 test / 590 assertions**, with a floor that fails the build if the parser finds too few cells. It found and fixed six real disagreements, including **caregiver could create person/health profiles** (Matrix B says `D`). Break-glass stays denied per ADR-002 via a named `MVP_DISABLED_PERMISSIONS` list; guardian `C` cells pass the gate while authority verification remains Phase 2. | ✅ |
| 3 | Workspace Isolation and Profile Isolation rules | RLS `ENABLE`/`FORCE` migration + `TEN-T01..T05` (5/35); `WorkspaceContextMiddlewareTest ISO-T01..T04` (4/16: cross-workspace rows hidden, context cleared after request); `AUTH-003` non-member → 404 without existence leak; `AUTH-004` same-workspace member without a grant on another person's profile → 403. Connection is non-superuser. | ✅ |
| 4 | Grant, Consent, and revocation checks | `GrantAndConsentTest` (9/21): GRANT-T01 active / T02 expired / T03 revoked, CONSENT-T01 withdraw once / T02 no other mutation; `AuthorizerTest` GRANT-T04/T05 (scope+action containment) and CONSENT-T03/T04 (AI purpose). | ✅ |
| 5 | Every endpoint has an authorization policy and declares its scope | `RoutePermissionCoverageTest AP-001..004` (4/34): 14 `api/v1` entries = 4 guarded + 10 allowlisted; guarded routes must carry `EnsureAuthorized` and a catalog ID. Scope/action is declared centrally per permission in `Authorizer::PERMISSION_MAP` and checked by `AUTH-005`. | ✅ |
| 6 | Negative tests for every risky action | For every endpoint that exists in Phase 0: AUTH-003/004, ISO-T02, WS-T02, MEM-T01 (viewer + caregiver denied), MEM-T02, INV-T01, plus auth negatives (wrong password, OTP exhausted/expired, PIN lockout/weak PIN). Future health endpoints must add their own negative test in the same change (`.ai/10-TESTING.md` §1). | ✅ |
| 7 | IDOR tests for APIs that accept a resource ID | The only enumerable resource ID exposed today is the workspace UUID. `WS-T02`, `MEM-T02`, `ISO-T02`, and `AUTH-003` prove non-members get 404 with no existence leak. Invitation links use an unguessable bearer token, not an ID. Profile-scoped routes do not exist yet, so a profile-ID cross-workspace IDOR test is deferred to the Phase 1 endpoints. | ✅ |
| 8 | AI Context and Export authorization tests before enabling those features | **Satisfied at the gateway level (2026-10-24); G4 itself is not passed.** `AI-*`: `ContextGatewayTest` (15 tests / 144 assertions) proves disabled → 503 with no fetch, consent refusal, scope containment, scrubbing, audit shape and quota, and adds a diagnosis-question refusal that is recorded without touching a health table. `EXPORT-*`: `ExportGateTest` (4 tests / 59 assertions) proves the document link and the Doctor Brief share require `.export` (not `.read`), the 30-minute share TTL, the absence of any `data_export.*` route, and exactly one PHI-free audit row per export path. `php artisan ai:evaluate` passes all six §6 suites (§2.1). **Live-provider evaluation now exists too:** `php artisan ai:evaluate --live` passed injection 20/20, grounding 10/10 and safety 7/7 against OKMD on 2026-10-24, and the run exposed a production bug (`TenantContext::run()` outside a transaction made every workspace route 404) that is now fixed and regression-tested (§2.2). **G4 is still not signed off** (DPA/provider approval is an owner decision and the production model must repeat the run after its quota resets); `AI_ENABLED` remains `false`. | ✅ gateway level |
| 9 | Audit policy for health-data access and permission changes | `SecurityAuditLogger` (hash chain, append-only, PHI-key rejection) with AUDIT-T01/T02/T03; every allow/deny decision writes exactly one row (AUDIT-T03); login denial is audited (`LoginTest`); retention purge batches are audited (RET-T06). Policy: `.ai/09-SECURITY-PRIVACY.md` (N-02) and `.ai/06-DATA-MODEL.md` §5. Note: grant/consent mutation endpoints do not exist yet; their mutation audit must land with those endpoints. | ✅ |
| 10 | CI enforces authorization tests and they must pass before merge | `.github/workflows/ci.yml` runs on push-to-main and on `pull_request`: Pint + full `php artisan test` against PostgreSQL 16 with the **non-superuser** role, plus the frontend build and `flutter analyze`. Green on every push since run #1 (`4cee485` → `6bbfa9c`). **Enforcement is now active:** the repository ruleset `Allow AI Push` on `main` requires a pull request and the three status checks — `Backend (PHP 8.4, PostgreSQL 16)`, `Frontend (Angular 22)`, `Mobile (Flutter stable)` — and still forbids force-push. Verified against the GitHub ruleset API on 2026-10-23. | ✅ |

### 2.1 Item 8 evidence — `AI-*` / `EXPORT-*` and the evaluation suites (2026-10-24)

Run locally on Windows against the same PostgreSQL 16 test database as the rest of the suite.
**No outbound HTTP call is made:** the provider used by the evaluation is the deterministic
in-process `EvaluationProvider`, and `AI_ENABLED` stays `false`.

`php artisan ai:evaluate` — exit code 0:

| Suite | Cases | Rate | Threshold | Result |
|---|---|---|---|---|
| leakage | 20 | 100.0% | 100% | PASS |
| scope | 20 | 100.0% | 100% | PASS |
| refusal | 20 | 100.0% | ≥95% | PASS |
| injection | 20 | 100.0% | ≥95% | PASS |
| grounding | 10 | 100.0% | ≥90% | PASS |
| safety | 10 | 100.0% | 100% | PASS |

The runner calls the gateway's production components, not re-implemented checks: leakage,
injection, grounding and safety go through `GuardrailPipeline` (steps 3–5), refusal through
`IntakeClassifier` (step 1b), and scope through `ScopedDataFetcher::admitSections()`. The fixtures
are synthetic PHP arrays (`สมชาย ทดสอบ`, `example.test`, impossible phone/ID numbers) — no PHI, no
files, no database seed.

Commands and results:

- `php artisan test --filter=AiEvaluationTest` → **7 passed / 33 assertions** (`EVAL-LEAK-01`,
  `EVAL-SCOPE-01`, `EVAL-REFUSAL-01`, `EVAL-INJECT-01`, `EVAL-GROUND-01`, `EVAL-SAFETY-01`, plus the
  "AI stays disabled" assertion).
- `php artisan test --filter=ExportGateTest` → **4 passed / 59 assertions** (`EXPORT-01` … `EXPORT-04`).
- `php artisan test --filter=ContextGatewayTest` → **15 passed / 144 assertions** (`AI-T01` … `AI-T10`
  plus the diagnosis-refusal integration test).
- Full suite `php artisan test` → **278 passed / 3125 assertions**; `./vendor/bin/pint --test` → clean.
- `grep -rn "AI_ENABLED" backend/config/ai.php` → `'enabled' => env('AI_ENABLED', false)`.

**What this does not prove:** a live provider's behaviour, cost/latency, or its DPA/AIG-010 review.
The live half is now recorded in **§2.2** and [`G4-LIVE-EVALUATION.md`](G4-LIVE-EVALUATION.md);
the DPA review remains the owner decision, so **AI remains disabled**.

### 2.2 Live-provider evaluation (2026-10-24)

`php artisan ai:evaluate --live --max-calls=50` against OKMD through the OpenAI-compatible adapter
(model `llama-4-maverick`; the intended `deepseek-v4-flash` exhausted its daily quota mid-run):

| Suite | Mode | Cases | Rate | Threshold | Result |
|---|---|---|---|---|---|
| leakage | offline | 20 | 100.0% | 100% | PASS |
| scope | offline | 20 | 100.0% | 100% | PASS |
| refusal | offline | 20 | 100.0% | ≥95% | PASS |
| injection | live | 20 | 100.0% | ≥95% | PASS |
| grounding | live | 10 | 100.0% | ≥90% | PASS |
| safety | live | 7 | 100.0% | 100% | PASS |

37 provider calls, 17,688 input / 3,569 output tokens, avg 8,029 ms, 0 retries. An end-to-end HTTP
run (summary + vision extraction) also returned 200, recorded `ai_requests` rows with document/record
refs only, and created **no** medication row (AIG-007).

The run found and fixed: the `TenantContext::run()` transaction bug (every workspace route 404 on a
real server; regression test `TenantContextTest`), Markdown-formatted disclaimers being rejected,
legitimate period/hotline numbers failing grounding, and 700-token truncation. Full detail, provider
quotas and the remaining G4 blockers: [`G4-LIVE-EVALUATION.md`](G4-LIVE-EVALUATION.md).

## 3. What was left — now closed (2026-10-23)

The last open item was a repository setting, not code, and it is now done:

1. ~~**Configure branch protection on `main`**~~ ✅ **Done.** The ruleset `Allow AI Push` requires a pull
   request plus the three status checks (`Backend (PHP 8.4, PostgreSQL 16)`, `Frontend (Angular 22)`,
   `Mobile (Flutter stable)`) and forbids force-push. A direct push to `main` is now rejected
   (`push declined due to repository rule violations`), which is the proof that enforcement is live.
   With enforcement live, the **ADR-019 time-boxed override ends** — its expiry (2026-11-06) is no
   longer needed.

Closed items:
- ~~Item 2 — Matrix conformance~~ ✅ `MatrixConformanceTest` (47 rows / 590 assertions).
- ~~Item 8 — AI/export~~ ⏭ deferred to **G4** by [ADR-018](../../.ai/DECISIONS.md#adr-018--g0-scoping--capability-dependent-checklist-ย้ายไป-gate-ของ-feature-นั้น); `.ai/10-TESTING.md` G4 requires `AI-*` and `EXPORT-*`. **Update 2026-10-24 (P-4):** `AI-*`/`EXPORT-*` and all six evaluation suites now exist and pass at the gateway level (§2.1). **Update 2026-10-24 (G4 live run):** the live suites also pass against OKMD (§2.2). The G4 gate is **not** passed: the DPA/provider approval is an owner decision and the production model must repeat the run after its quota resets; `AI_ENABLED=false`.
- ~~Item 10 — CI enforcement~~ ✅ required checks active on `main`.

**G0 verdict: ✅ PASSED** — 9 items proven, 1 explicitly deferred to its own gate. Phase 1 may proceed.

## 4. CI status notes (verified on GitHub)

GitHub Actions run **#1** for commit `4cee485` (`chore(phase-0): CI pipeline, guard tests and G0 evidence`):

| Job | Result | Duration |
|---|---|---|
| `Backend (PHP 8.4, PostgreSQL 16)` | ✅ success | 38 s (incl. `Create non-superuser runtime role`, Pint, full suite) |
| `Frontend (Angular 22)` | ✅ success | 21 s (`npm ci` + `npm run build`) |
| `Mobile (Flutter stable)` | ✅ success | 83 s (`flutter pub get` + `flutter analyze`) |

Run URL: <https://github.com/BB5123/lucky-care-plus-v1/actions/runs/39822288584>

Notes:
- The backend job creates `luckycare_app` (`NOSUPERUSER NOBYPASSRLS`) and hands it `public`, so the RLS
  assertions in CI are real — not bypassed by the service container's superuser.
- The mobile job was red on the first local rehearsal (`package:mobile/main.dart` vs package `lucky_care`);
  the import was corrected before this run, and the analyzer is now clean.
- ~~**Branch protection is not configured.**~~ **Superseded on 2026-10-23:** the ruleset `Allow AI Push`
  now requires a pull request and the three status checks on `main`, so the pipeline is not only green
  but *enforcing*. The runs above predate that change; run #7 (the `phase-1-complete` PR) is the first
  enforced run and it was green on all three jobs before the merge.

## 5. Files that provide this evidence

| File | Purpose |
|---|---|
| `backend/tests/Feature/Authorization/RoutePermissionCoverageTest.php` | AP-001..004 route/permission coverage guard |
| `backend/tests/Unit/PhiLeakTest.php` | SEC-PHI-001..003 log-call PHI guard + scanner self-test |
| `.github/workflows/ci.yml` | backend / frontend / mobile CI jobs |
| `backend/config/authorization.php` | 99-ID permission catalog |
| `backend/app/Modules/Authz/Authorizer.php` | single decision point + permission→scope/action map |
| `backend/database/migrations/2026_10_22_150700_enable_row_level_security.php` | `ENABLE`/`FORCE` RLS policies |
| `backend/tests/Feature/Tenancy/TenantIsolationTest.php` | `TEN-T01..T05` |
| `backend/tests/Feature/Authorization/WorkspaceContextMiddlewareTest.php` | `ISO-T01..T04` |
| `backend/tests/Feature/Authorization/AuthorizerTest.php` | AUTH-*, GRANT-T04/T05, CONSENT-T03/T04, FAIL-T01, AUDIT-T03 |
| `backend/tests/Feature/Authorization/GrantAndConsentTest.php` | GRANT-T01..T03, CONSENT-T01/T02, AUDIT-T01/T02 |
| `backend/tests/Feature/Authorization/PermissionCatalogTest.php` | CATALOG-T01/T02 |
| `backend/tests/Feature/AiGateway/AiEvaluationTest.php` | EVAL-LEAK-01 … EVAL-SAFETY-01 (one per §6 suite) |
| `backend/tests/Feature/AiGateway/ExportGateTest.php` | EXPORT-01 … EXPORT-04 (export authorization, TTL, absent routes, audit) |
| `backend/app/Console/Commands/AiEvaluateCommand.php` | `ai:evaluate` — offline evaluation, non-zero on a missed threshold |
| `backend/app/Modules/AiGateway/Application/Evaluation/` | synthetic datasets, runner and in-process evaluation provider |