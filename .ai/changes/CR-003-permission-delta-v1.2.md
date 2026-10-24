# CR-003 · Baseline v1.2 — Permission Delta (Task Templates and Escalation)

| | |
|---|---|
| **Change Record ID** | `CR-003` |
| **Target baseline** | `AUTH-BASELINE-001` v1.1.0 → **v1.2.0** |
| **Date raised** | 2026-10-24 |
| **Status** | ✅ **APPLIED** — baseline bumped to **v1.2.0** on 2026-10-24 |
| **Approver** | Product owner (TASK B / CR-003) |
| **Related** | `ABD-013`, `ABD-015`, `ABD-017`, Matrix C |
| **Scope** | +6 permission IDs, 2 resources (`care_task_template`, `escalation_policy`), +1 frozen decision |
| **Excluded** | No new scope type, no new role, no data migration |

> **Current status:** **in force** — `PERMISSION_CATALOG.md`, `DATA_ACCESS_MATRIX.md` and
> `AUTHORIZATION_BASELINE.md` were updated together and the baseline was bumped to **v1.2.0**
> under `ABD-015`.
> **Consequence:** all six IDs are usable in code/API because each has a Matrix C row,
> `RoleGate` mapping, `Authorizer` scope/action mapping and tests (`TPL-T01...T05`).
> **The `docs/baseline-v1.0.0/` directory keeps its historical name** so existing links do not
> break; its content is v1.2.0.

---

## 1. Summary

| | Before (v1.1.0) | After (v1.2.0) |
|---|---|---|
| Permission count | 93 | **99** |
| Resources | 25 domains | + `care_task_template`, `escalation_policy` |
| Frozen decision | `ABD-001`...`ABD-016` | + **`ABD-017`** |
| Roles | 8 | 8 (**unchanged**) |
| Resource scopes | existing set | **no new scope** (`tasks` reused) |

**No existing permission was renamed or removed.** The change is additive, but it also
repoints six existing routes from borrowed IDs to the new IDs (see §6).

---

## 2. Context

Two Phase 2 capabilities shipped by borrowing permissions because the frozen catalog had no
IDs for them:

- **Task templates** (`care_task_templates`) used `care_task.read` / `care_task.create`.
- **Escalation policies** (`escalation_policies`) used `workspace.read` / `workspace.update`.

That overload is wrong for two reasons:

1. A caregiver who may read one task is not thereby allowed to define a family routine that
   changes what other people are expected to do. `care_task.create` covers one occurrence;
   a template governs every future occurrence.
2. Workspace settings authority is not the same as authority over escalation behaviour.
   `workspace.update` administers workspace configuration; escalation policy governs
   accountability and who gets notified when care is late.

This record closes the gap through the change-control process rather than by silently widening
the catalog.

---

## 3. Delta · Permission catalog

Exactly these six IDs were added. Nothing else was invented.

| Permission ID | Action | Resource | Scope |
|---|---|---|---|
| `care_task.template.read` | read | care_task_template | tasks |
| `care_task.template.create` | create | care_task_template | tasks |
| `care_task.template.update` | update | care_task_template | tasks |
| `care_task.template.deactivate` | deactivate | care_task_template | tasks |
| `escalation_policy.read` | read | escalation_policy | tasks |
| `escalation_policy.update` | update | escalation_policy | tasks |

`deactivate` maps to the existing action vocabulary as `update` inside
`Authorizer::PERMISSION_MAP`; the permission ID still expresses the separate product action.
All six reuse the existing `tasks` scope; no new scope token is introduced.

---

## 4. Delta · Matrix C cells

Role order: `profile_owner`, `legal_guardian`, `workspace_admin`, `caregiver`, `viewer`,
`healthcare_professional`, `platform_support`, `platform_admin`.

| Row | owner | guardian | admin | caregiver | viewer | professional | platform_support | platform_admin |
|---|---|---|---|---|---|---|---|---|
| Read task templates | A | C | A | A | D | D | D | D |
| Create task template | A | C | A | C | D | D | D | D |
| Update task template | A | C | A | C | D | D | D | D |
| Deactivate task template | A | C | A | D | D | D | D | D |
| Read escalation policy | A | C | A | A | D | D | D | D |
| Update escalation policy | A | D | A | D | D | D | D | D |

### Rationale (cells are fixed; do not adjust)

- **Reading is broad within the family.** A caregiver should see the routine they follow and the
  ladder that applies to their work. Read access still requires the `tasks` scope on the target
  profile; the role gate alone is never sufficient.
- **Defining a routine is conditional for a caregiver (`C`).** Creating or editing a template
  changes what other people are expected to do, so it requires an explicit `tasks` grant.
  `A` for owner/admin/guardian means the role may hold the action with the required scope.
- **Deactivating is admin/owner authority.** Stopping future materialisation is a
  destructive-looking change to the family's plan, so a caregiver is `D`.
- **Editing escalation is owner/admin authority.** The ladder governs accountability and
  notification behaviour, not daily care work. A guardian may read it (`C`) but not replace it
  (`D`) without a separate approved delegation.
- **Healthcare professional and platform roles are `D`.** They have no standing family-routine or
  escalation authority. `platform_support` / `platform_admin` do not gain access merely from an
  operational role (`ABD-002`, `ABD-011`).

### Matrix/document reconciliation note

The existing Matrix C table in `DATA_ACCESS_MATRIX.md` has five role columns
(owner, guardian, workspace admin, caregiver, viewer). The six rows were added in that exact
column order; the three remaining roles above are `D` and are enforced as deny-by-default by
`RoleGate`. The matrix document records this explicitly so the omission of their columns is not
mistaken for an allow.

---

## 5. Security and privacy impact

| Question | Assessment | Control |
|---|---|---|
| Does this widen access to health records? | **No** — none of the six reads or writes a health record. | They only describe schedules and escalation behaviour. |
| Does a template grant access to materialised tasks? | **No.** Template read is separate from `care_task.read`. | Distinct permission ID and route middleware. |
| Does escalation read expose PHI? | **No.** The ladder contains levels, delays, notify roles and actions. | Resource returns policy metadata only. |
| Privilege escalation from borrowed IDs? | **Reduced.** The overload is removed. | Explicit `RoleGate` branches; `workspace.update` and `care_task.create` no longer gate these routes. |
| Scope bypass? | **No.** Scope is still `tasks` and is checked after the role gate. | `Authorizer` profile scope + grant check. |
| Meta-audit? | Every allow/deny writes one `security_audit_logs` row with the new action name. | `SecurityAuditLogger` (F-11). |

**Summary:** Low–Medium risk. The change narrows authority compared with the stop-gap and does not
touch `ABD-001`...`ABD-016`.

---

## 6. Implementation impact

| Area | Change |
|---|---|
| `docs/baseline-v1.0.0/PERMISSION_CATALOG.md` | Six rows added in §4.6.1; total 99; v1.2.0. |
| `docs/baseline-v1.0.0/DATA_ACCESS_MATRIX.md` | Six Matrix C rows; reconciliation note; v1.2.0. |
| `docs/baseline-v1.0.0/AUTHORIZATION_BASELINE.md` | `ABD-017`; v1.2.0; change history. |
| `docs/baseline-v1.0.0/README.md` | v1.2.0 amendment and version history. |
| `backend/config/authorization.php` | Six IDs; count comment 99. |
| `backend/app/Modules/Authz/Authorizer.php` | Six `PERMISSION_MAP` entries (scope `tasks`; deactivate as `update`). |
| `backend/app/Modules/Authz/RoleGate.php` | Explicit role mapping for both prefixes. |
| `backend/routes/api.php` | Template routes → `care_task.template.*`; escalation routes → `escalation_policy.*`. |
| Tests | `TPL-T01...T05`; `MatrixConformanceTest` map; existing escalation test updated. |

### Route mapping applied

| Route | New permission |
|---|---|
| `GET .../task-templates` and `GET .../task-templates/{template}` | `care_task.template.read` |
| `POST .../task-templates` | `care_task.template.create` |
| `PATCH .../task-templates/{template}` | `care_task.template.update` |
| `POST .../task-templates/{template}/deactivate` | `care_task.template.deactivate` |
| `POST .../task-templates/{template}/exceptions` | `care_task.template.update` |
| `GET .../escalation-policies` | `escalation_policy.read` |
| `PUT .../escalation-policies` | `escalation_policy.update` |
| `POST .../escalation-policies/defaults` | `escalation_policy.update` |

Exceptions are a mutation of an existing schedule, so they use the update permission; the fixed
delta deliberately has no separate `...exception.manage` ID.

---

## 7. Migration notes

- **No database migration.** Permissions live in code/config; `care_task_templates` and
  `escalation_policies` store no permission decision.
- **No client payload change.** Clients do not send permission IDs; only server route
  declarations and audit action names change.
- **Existing rows remain valid.** No template or policy row needs rewriting.
- **Grants do not need rewriting.** The `tasks` scope and its actions are unchanged; a caregiver
  with `tasks:create`/`update` keeps the ability to define a routine (`C`), while deactivation is
  still denied by the role gate.
- **Rollback** means restoring the six old route declarations and removing the six IDs, Matrix C
  rows and `ABD-017`; there is no data to undo. The routes must be feature-flagged off before a
  rollback if they are live.

---

## 8. Rejected alternatives

| Alternative | Why rejected |
|---|---|
| Keep `workspace.update` for escalation | Workspace settings authority is unrelated to accountability behaviour; it would let any settings editor rewrite the ladder (`ABD-002`, `ABD-017`). |
| Keep `care_task.create` for templates | It hides a permanent, family-wide effect behind a permission that only covers one task occurrence. |
| Reuse `care_task.read` for template reads | A caregiver assigned one task must not automatically see the whole schedule (`F-04`), and a viewer holding `care_task.read` would inherit schedule visibility. |
| Add one broad `care_task.template.manage` ID | The baseline forbids broad `manage` permissions; create, update and deactivate have different audiences. |
| Add `care_task.template.exception.manage` now | Not in the approved six-ID delta. Exceptions mutate an existing schedule, so `care_task.template.update` covers them until a separate need is approved. |
| Introduce a new scope (e.g. `routines`) | The existing `tasks` scope already expresses the delegated domain and avoids expanding the scope vocabulary. |
| Give platform roles operational access | Platform staff have no standing family authority (`ABD-011`); any time-limited workflow must be separately approved. |

---

## 9. Frozen decision

| Decision ID | Frozen decision | Rationale |
|---|---|---|
| `ABD-017` | Routine definitions (`care_task.template.*`) and escalation policy (`escalation_policy.*`) are **not** implied by task-read authority, task-creation authority, or workspace-settings authority. | Prevents a caregiver who may read one task from silently defining the family routine, and prevents a workspace-settings edit from becoming accountability authority. |

---

## 10. Approval record

| Date | Decision maker | Decision | Reference |
|---|---|---|---|
| 2026-10-24 | Product owner | Approve exactly six IDs and the fixed Matrix C cells | TASK B / CR-003 |
| 2026-10-24 | Engineering | **APPLIED** — baseline documents, config, role gate and routes updated together; v1.2.0 | §6 |