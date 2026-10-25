# Family Health CRM — Authorization Foundation

**Baseline:** `AUTH-BASELINE-001`  
**Version:** `1.4.0`  
**Status:** Frozen for implementation  
**Effective date:** 2026-10-18  
**Amended:** 2026-10-22 (`CR-001`), 2026-10-24 (`CR-003`, `CR-004`), 2026-10-25 (`CR-005`)

> **Directory note:** this set lives in `docs/baseline-v1.0.0/` for path stability. Its content is **v1.4.0**; the directory name is historical and is not renamed to avoid breaking references.

## Documents

1. `PERMISSION_CATALOG.md` — canonical action/permission identifiers.
2. `DATA_ACCESS_MATRIX.md` — role, action, resource, and scope policy templates.
3. `AUTHORIZATION_TEST_PLAN.md` — minimum automated test scenarios and release gates.
4. `AUTHORIZATION_BASELINE.md` — frozen decision log (`ABD-001`…`ABD-019`) and change history.

Change records:
- v1.1.0: [`../../.ai/changes/CR-001-permission-delta-v1.1.md`](../../.ai/changes/CR-001-permission-delta-v1.1.md)
- v1.2.0: [`../../.ai/changes/CR-003-permission-delta-v1.2.md`](../../.ai/changes/CR-003-permission-delta-v1.2.md)
- v1.3.0: [`../../.ai/changes/CR-004-task-evidence-scope-v1.3.md`](../../.ai/changes/CR-004-task-evidence-scope-v1.3.md)
- v1.4.0: [`../../.ai/changes/CR-005-platform-knowledge-review-v1.4.md`](../../.ai/changes/CR-005-platform-knowledge-review-v1.4.md)

## Frozen v1.0.0 decisions

- Workspace membership does not imply health-profile access.
- Workspace administrators do not automatically access health records.
- Care-task assignment grants task scope only, not general health-data access.
- Read, correct, delete, export, share, and AI use are separate permissions.
- Guardian authority must be verified.
- Grants cannot exceed the grantor's current authority and scope.
- Consent is checked where required by applicable legal basis and approved purpose policy; it is not interchangeable with permission or legal authority.
- Sensitive access fails closed.
- Authorization is re-checked for asynchronous processing and file delivery.
- Health-record deletion and break-glass access are denied/disabled by default.
- Every enabled permission must map to a matrix rule, entry point, and positive/negative tests.

## v1.1.0 amendment (2026-10-22)

Additive only — **no existing permission, role, matrix row, or test was removed, renamed, or weakened.**

- **+4 permissions:** `notification.preference.read`, `notification.preference.update`, `notification.delivery.read`, `audit_event.read_own` (89 → 93)
- **+ Matrix E** — self-service preferences and self-audit
- **+2 scope types:** `self_membership`, `owned_profile_audit`
- **+ `ABD-016`** — notification preferences and self-audit visibility are not authorization or consent state
- **+6 tests:** `AT-NOTIF-001…004`, `AT-AUDIT-004…005`

## v1.2.0 amendment (2026-10-24)

Additive only — **no existing permission, role, matrix row, or test was removed, renamed, or weakened.**

- **+6 permissions:** `care_task.template.read`, `care_task.template.create`, `care_task.template.update`,
  `care_task.template.deactivate`, `escalation_policy.read`, `escalation_policy.update` (93 → 99)
- **+6 Matrix C rows** — task templates and escalation policy, using the existing `tasks` scope
- **+ `ABD-017`** — routine definitions and escalation policy are not implied by task-read authority
- **Routes moved off borrowed IDs:** templates no longer use `care_task.read` / `care_task.create`;
  escalation no longer uses `workspace.read` / `workspace.update`

## v1.3.0 amendment (2026-10-24)

Narrowing only — **no existing permission, role, matrix row, or test was removed, renamed, or weakened.**

- **+1 resource scope:** `task_evidence` — evidence reads (photos, measured values, notes) now need
  `task_evidence:read` instead of being covered by `tasks:read`
- **Re-mapped:** `care_task.evidence.read` → `task_evidence:read`. `care_task.evidence.create` stays on
  `tasks:create` because attaching proof is part of doing the task
- **+ `ABD-018`** — evidence reads are not implied by task-read authority or task assignment
- **No permission count change:** 99 IDs, 8 roles and all Matrix cells unchanged; existing grants must add
  `task_evidence:read` before they can read evidence (fail closed)

## v1.4.0 amendment (2026-10-25)

Additive only — **no existing permission, role, matrix row, or test was removed, renamed, or weakened.**

- **+2 platform permissions:** `knowledge.source.read`, `knowledge.source.review` (99 → 101)
- **+ `users.is_platform_admin`** plus `platform_admin_granted_at` and `platform_admin_granted_by`
- **+ `Authorizer::authorizePlatform()`** — platform capabilities are evaluated without a workspace and are
  **not** available through the workspace path or from any workspace role
- **+ `platform:grant {email} {--revoke}`** — refuses to revoke the last remaining platform administrator and
  writes one audit row per change
- **+ two routes:** `GET /api/v1/admin/knowledge/sources`, `POST /api/v1/admin/knowledge/sources/{id}/review`
- **+ `ABD-019`** — platform capabilities are granted by an explicit user flag, never a workspace role, and are
  audited on every change

## Version history

| Version | Date | Summary | Change record |
|---|---|---|---|
| `1.0.0` | 2026-10-18 | Initial frozen baseline (`ABD-001`...`ABD-015`, 89 permissions) | — |
| `1.1.0` | 2026-10-22 | +4 notification/self-audit permissions, Matrix E, `ABD-016` (89 → 93) | `CR-001` |
| `1.2.0` | 2026-10-24 | +6 task-template/escalation permissions, six Matrix C rows, `ABD-017` (93 → 99) | `CR-003` |
| `1.3.0` | 2026-10-24 | +`task_evidence` scope, evidence-read re-map, `ABD-018` (99 permissions unchanged) | `CR-004` |
| `1.4.0` | 2026-10-25 | +2 platform knowledge permissions, platform-admin flag, `ABD-019` (99 → 101) | `CR-005` |

## Change control

Do not silently edit frozen rules. A change must:
1. identify the affected permission/rule/test IDs;
2. explain the security and data-privacy impact;
3. update all affected documents in one change;
4. add or update automated tests;
5. bump the appropriate version and record the decision.

## Important qualification

This is an engineering implementation baseline, not legal certification or a claim of production readiness. Guardian/representative authority, lawful bases, consent details, retention/deletion, professional access, and any emergency-access process require product, security, and qualified legal review before production.
