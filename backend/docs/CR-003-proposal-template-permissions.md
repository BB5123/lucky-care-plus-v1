# CR-003 — Template permissions for recurring care tasks

**Status:** Proposal (not approved, not implemented in `config/authorization.php`)
**Owner:** Care CRM / Authorization
**Introduced by:** TASK P2.1 (recurring care-task templates)
**Baseline:** `AUTH-BASELINE-001` v1.1.0 (frozen)

## 1. Gap

P2.1 adds the following endpoints:

| Method | Path | Current permission |
|---|---|---|
| GET | `/api/v1/workspaces/{w}/profiles/{profile}/task-templates` | `care_task.read` |
| POST | `/api/v1/workspaces/{w}/profiles/{profile}/task-templates` | `care_task.create` |
| GET | `.../task-templates/{template}` | `care_task.read` |
| PATCH | `.../task-templates/{template}` | `care_task.create` |
| POST | `.../task-templates/{template}/deactivate` | `care_task.create` |
| POST | `.../task-templates/{template}/exceptions` | `care_task.create` |

The frozen catalog (`docs/baseline-v1.0.0/PERMISSION_CATALOG.md`) contains no
`care_task.template.*` ID. Matrix C has no row for a recurring schedule, only
for an individual task. The surface is therefore gated by the adjacent
`care_task.read` / `care_task.create` IDs so that P2.1 ships without inventing a
permission or editing the frozen baseline. That mapping is a stop-gap, not a
claim that reading a schedule is equivalent to reading every task under it.

### Why the stop-gap is not sufficient long term

- `care_task.create` lets a caregiver create one task; `PATCH`/`deactivate`
  let them change every future occurrence. Reusing one ID hides that widening
  from the role matrix.
- A viewer may hold `care_task.read` (Matrix C: "Read task" = `C`). The same ID
  also exposes the schedule (times, timezone, assigned user), which is more
  than one task's card. This needs an explicit product decision.
- Exception reasons are free text. If a future revision treats them as
  health-adjacent, a separate permission is the clean place to add a scope.

## 2. Proposed permission IDs

| Proposed ID | Action / scope | Justification |
|---|---|---|
| `care_task.template.read` | read, `tasks` scope | View a schedule and its exceptions without implying access to every materialised task. |
| `care_task.template.create` | create, `tasks` scope | Create a new recurring schedule for a profile. |
| `care_task.template.update` | update, `tasks` scope | Change a schedule, including future-only edits. Must not be implied by `care_task.create`. |
| `care_task.template.deactivate` | update, `tasks` scope | Stop future materialisation while keeping history (CRM-021). Separated because it is the destructive-looking operation. |
| `care_task.template.exception.manage` | update, `tasks` scope | Add/replace a skip/reschedule override. Separated because it can silently remove one occurrence from the plan. |

Scopes use the existing `tasks` scope from `.ai/03-AUTHORIZATION.md §5`; no new
scope token is required.

## 3. Proposed Matrix C changes

Add the following rows to Matrix C (Owner / Guardian / Workspace Admin /
Caregiver / Viewer), mirroring the existing care-task conditions:

| Action | Owner | Guardian | Workspace Admin | Caregiver | Viewer |
|---|---|---|---|---|---|
| Read task template | A | C | C\* | C\*\* | C |
| Create/update task template | A/C | C | C\* | C\* | D |
| Deactivate task template | A/C | C | C\* | C\* | D |
| Manage task template exception | A/C | C | C\* | C\* | D |

- `C*`: only when workspace policy delegates it and the actor is authorised for
  the target profile.
- `C**`: an explicit `tasks` read scope on the profile. Merely being assigned
  one occurrence must not reveal the whole schedule (F-04 / need-to-know).
- A template mutation never grants access to the underlying health record.

## 4. Migration plan if approved

1. Add the five IDs to `PERMISSION_CATALOG.md` and `config/authorization.php`
   in the same change; `PermissionCatalogTest` enforces equality.
2. Update Matrix C and `AUTHORIZATION_TEST_PLAN.md` (positive, negative,
   cross-workspace cases per ID).
3. Add `PERMISSION_MAP` entries in `App\Modules\Authz\Authorizer`:
   `read`/`create`/`update` with scope `tasks`.
4. Extend `RoleGate` for the new IDs using the same role sets as the table
   above, and add a `viewer` read entry only for `care_task.template.read`.
5. Replace the route middleware in `routes/api.php` with the new IDs. Keep
   `care_task.read` on the occurrence endpoints unchanged.
6. No data migration is needed; `care_task_templates` does not store a
   permission decision.

## 5. What was intentionally not changed

- `config/authorization.php`
- `docs/baseline-v1.0.0/PERMISSION_CATALOG.md`
- `docs/baseline-v1.0.0/DATA_ACCESS_MATRIX.md`

All three remain frozen. This document is the change request that must precede
any edit to them.