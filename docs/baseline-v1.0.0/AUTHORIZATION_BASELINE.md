# Authorization Baseline Decision Log

**Baseline ID:** `AUTH-BASELINE-001`  
**Version:** `1.2.0`  
**Date:** 2026-10-18 (amended 2026-10-22 by `CR-001`, 2026-10-24 by `CR-003`)  
**Status:** Frozen for implementation

| Decision ID | Frozen decision | Rationale |
|---|---|---|
| `ABD-001` | Workspace membership never grants blanket access to health profiles. | Prevents accidental family-wide disclosure. |
| `ABD-002` | Workspace administration is separate from health-data authorization. | Separates operational and health-data privileges. |
| `ABD-003` | Care-task assignment grants task scope only. | Avoids privilege escalation through workflow assignment. |
| `ABD-004` | Read, correction, deletion, export, share, and AI use are distinct actions. | Enforces least privilege and purpose limitation. |
| `ABD-005` | Profile owner scope is limited to verified own profile unless an approved representative workflow applies. | Prevents owner-role misuse across profiles. |
| `ABD-006` | Guardian/representative authority must be verified and scoped. | Relationship labels alone do not establish legal authority. |
| `ABD-007` | Grantors cannot delegate beyond their own current authority. | Prevents privilege escalation. |
| `ABD-008` | Consent, permission, legal authority, and purpose are separate checks. | Avoids conflating technical authorization and legal basis. |
| `ABD-009` | Grant and membership state are re-checked at asynchronous processing and release boundaries. | Reduces stale-authority and time-of-check/time-of-use risks. |
| `ABD-010` | Protected health-data access fails closed when required authorization checks fail. | Prioritizes confidentiality. |
| `ABD-011` | Health-record deletion is denied by default; correction/provenance rules apply. | Preserves traceability and retention compliance. |
| `ABD-012` | Break-glass is disabled in MVP until separately approved and tested. | Prevents an unbounded emergency bypass. |
| `ABD-013` | Every enabled permission must have matrix and test traceability. | Prevents unreviewed endpoints and undocumented privileges. |
| `ABD-014` | Sensitive file delivery re-checks current authorization; file URLs alone are not authorization. | Controls post-generation disclosure. |
| `ABD-015` | Authorization changes require synchronized document and test updates. | Maintains a single coherent baseline. |
| `ABD-016` | Notification preferences and self-audit visibility are **not** authorization or consent state; they never grant, widen, or withdraw data access. | Prevents channel settings from being mistaken for consent withdrawal, and stops `audit_event.read_own` from becoming a data-access path. Added in v1.1.0 by `CR-001`. |
| `ABD-017` | Routine definitions (`care_task.template.*`) and escalation policy (`escalation_policy.*`) are **not** implied by task-read authority, task-creation authority, or workspace-settings authority. | A caregiver who may read or create one task is not automatically allowed to define a family routine or change accountability behaviour; `workspace.update` administers settings, not escalation conduct. Added in v1.2.0 by `CR-003`. |

## Change control

This log records the frozen implementation baseline. It does not imply legal approval. Any amendment must identify affected IDs, assess privacy/security impact, update the catalog/matrix/test plan together, add tests, and version the baseline.

## Change history

| Version | Date | Change | Record |
|---|---|---|---|
| `1.0.0` | 2026-10-18 | Initial frozen baseline (`ABD-001`…`ABD-015`, 89 permissions, Matrices A–D) | — |
| `1.1.0` | 2026-10-22 | Added 4 permissions (`notification.preference.read`, `notification.preference.update`, `notification.delivery.read`, `audit_event.read_own`); added Matrix E; added 2 scope types (`self_membership`, `owned_profile_audit`); added `ABD-016`; added `AT-NOTIF-001…004` and `AT-AUDIT-004…005`. **No existing permission, role, matrix row, or test was removed, renamed, or weakened.** | `CR-001` |
| `1.1.0` (editorial) | 2026-10-22 | Editorial correction, no rule change: `AUTHORIZATION_TEST_PLAN.md` subsections were numbered `5.1…5.9` under section `## 6`; renumbered to `6.1…6.9` and the new set to `6.10`. `DATA_ACCESS_MATRIX.md` had no section `8`; Matrix E now occupies it. | `CR-001` |
| `1.2.0` | 2026-10-24 | Added 6 permissions (`care_task.template.read`, `care_task.template.create`, `care_task.template.update`, `care_task.template.deactivate`, `escalation_policy.read`, `escalation_policy.update`); added six Matrix C rows; added `ABD-017`; switched the template and escalation routes off the borrowed `care_task.*` / `workspace.*` IDs. **No existing permission, role, matrix row, or test was removed, renamed, or weakened.** | `CR-003` |

Change record location: `.ai/changes/CR-001-permission-delta-v1.1.md`
Change record location: `.ai/changes/CR-003-permission-delta-v1.2.md`
