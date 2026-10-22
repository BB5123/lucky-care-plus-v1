# Family Health CRM — Authorization Foundation

**Baseline:** `AUTH-BASELINE-001`  
**Version:** `1.1.0`  
**Status:** Frozen for implementation  
**Effective date:** 2026-10-18  
**Amended:** 2026-10-22 (`CR-001`)

> **Directory note:** this set lives in `docs/baseline-v1.0.0/` for path stability. Its content is **v1.1.0**; the directory name is historical and is not renamed to avoid breaking references.

## Documents

1. `PERMISSION_CATALOG.md` — canonical action/permission identifiers.
2. `DATA_ACCESS_MATRIX.md` — role, action, resource, and scope policy templates.
3. `AUTHORIZATION_TEST_PLAN.md` — minimum automated test scenarios and release gates.
4. `AUTHORIZATION_BASELINE.md` — frozen decision log (`ABD-001`…`ABD-016`) and change history.

Change record for v1.1.0: [`../../.ai/changes/CR-001-permission-delta-v1.1.md`](../../.ai/changes/CR-001-permission-delta-v1.1.md)

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

## Change control

Do not silently edit frozen rules. A change must:
1. identify the affected permission/rule/test IDs;
2. explain the security and data-privacy impact;
3. update all affected documents in one change;
4. add or update automated tests;
5. bump the appropriate version and record the decision.

## Important qualification

This is an engineering implementation baseline, not legal certification or a claim of production readiness. Guardian/representative authority, lawful bases, consent details, retention/deletion, professional access, and any emergency-access process require product, security, and qualified legal review before production.
