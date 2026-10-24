# CR-004 · Baseline v1.3 — Evidence Reads Get Their Own Scope

| | |
|---|---|
| **Change Record ID** | `CR-004` |
| **Target baseline** | `AUTH-BASELINE-001` v1.2.0 → **v1.3.0** |
| **Date raised** | 2026-10-24 |
| **Status** | ✅ **APPLIED** — baseline bumped to **v1.3.0** on 2026-10-24 |
| **Approver** | Product owner (TASK M / CR-004) |
| **Related** | `ABD-004`, `ABD-010`, `ABD-015`, `ABD-018`, Matrix C |
| **Scope** | +1 resource scope (`task_evidence`), 1 permission re-mapped, +1 frozen decision |
| **Excluded** | No new permission, no new role, no Matrix cell change, no data migration |

> **Current status:** **in force** — `AUTHORIZATION_BASELINE.md`, `README.md`, `.ai/03-AUTHORIZATION.md`
> and `backend/config/authorization.php` were updated together and the baseline was bumped to
> **v1.3.0** under `ABD-015`.
> **Consequence:** `care_task.evidence.read` now requires a usable `task_evidence:read` grant (or profile
> ownership). A grant that only carries `tasks:read` can still read the task but receives `403` on
> `GET .../care-tasks/{task}/evidence`.
> **The `docs/baseline-v1.0.0/` directory keeps its historical name** so existing links do not break;
> its content is v1.3.0.

---

## 1. Summary

| | Before (v1.2.0) | After (v1.3.0) |
|---|---|---|
| Permission count | 99 | 99 (**unchanged**) |
| Resource scopes | existing set | **+ `task_evidence`** |
| Frozen decision | `ABD-001`...`ABD-017` | + **`ABD-018`** |
| Roles | 8 | 8 (**unchanged**) |
| Matrix cells | A–E | **unchanged** |

**No permission ID was added, renamed, removed, or weakened.** The only permission-level change is the
scope that `care_task.evidence.read` requires.

---

## 2. Context

`care_task.read` and `care_task.evidence.read` both mapped to
`['scope' => 'tasks', 'action' => 'read']` in `Authorizer::PERMISSION_MAP`. A single access grant
therefore could not separate them:

- `tasks:read` proved authority to see the task, **and** authority to read every photo, measured value
  and free-text note attached to it.
- Revoking or withholding `task_evidence` was impossible without also hiding the task itself.

That is broader than the blueprint intends. Task metadata is coordination information: title, owner,
due time, status and assignment. Evidence is the PHI-bearing artifact — it can contain a glucose value,
a wound photo, or a note describing a symptom. `ABD-004` already says read, export, share, correction
and AI use are distinct; this record makes the **resource dimension** of a read equally distinct.

`has_photo_evidence` (PR #16) makes the separation safe: the task payload and the need-to-know card
expose only a boolean derived from a photo evidence row, without the document id, filename, value,
metric or note. A client can therefore enable the completion flow without `care_task.evidence.read`.

---

## 3. Delta · Resource scope

Exactly one scope token was added; nothing else was invented.

| Scope | Covers | Enabled in MVP |
|---|---|---|
| `task_evidence` | `task_evidence` rows for a profile: photo document references, measured values, notes | Yes |

`backend/config/authorization.php` adds `task_evidence` to `scopes` (it is **not** in
`disabled_scopes`), and `.ai/03-AUTHORIZATION.md` §5 documents it next to `tasks`.

`docs/baseline-v1.0.0/PERMISSION_CATALOG.md` has no per-permission scope column (its §5 lists scope
*types* such as `profile:<id>`, not scope tokens), so no catalog row changes. The permission count and
all permission IDs stay exactly as frozen by v1.2.0.

---

## 4. Delta · Permission mapping

| Permission ID | Before | After |
|---|---|---|
| `care_task.evidence.read` | `tasks` / `read` | **`task_evidence` / `read`** |
| `care_task.evidence.create` | `tasks` / `create` | `tasks` / `create` (**unchanged**) |

This is a **narrowing** change: the permission ID, role gate and Matrix row are unchanged, but the
grant that confers it is now explicit and separate.

### Why the asymmetry (read yes, create no)

- **Reading** evidence discloses someone else's health data. It is the sensitive disclosure, and it
  must be granted deliberately. A person who may see that a task exists, or who is assigned to do it,
  is not thereby entitled to see the proof another person attached.
- **Attaching** evidence is part of doing the task. The assignee is producing their own proof in the
  course of a task they are already authorized to work on. Requiring a second scope would make the
  normal completion flow fail for every ordinary caregiver and push clients toward workarounds.
- The write path still has its own protections: `care_task.evidence.create` is role-gated, the actor
  must be assigned or hold full task access, the document must belong to the same
  tenant/workspace/profile and be clean, and every attach is audited.
- A person with `tasks:create` but no `task_evidence:read` can therefore attach evidence and **cannot**
  read it back — which is exactly the least-privilege shape we want for a helper who captures a
  reading on someone else's behalf.

---

## 5. Security and privacy impact

| Question | Assessment | Control |
|---|---|---|
| Does `tasks:read` still disclose evidence? | **No.** The evidence route and the service-level evidence query both require `task_evidence:read`. | `Authorizer::PERMISSION_MAP` + `CareTaskService::assertEvidenceAccess`. |
| Does task assignment imply evidence read? | **No.** Assignment still proves task visibility only (`F-04`, `ABD-003`). | `CareTaskAccess::canReadEvidence` checks the grant scope, not the assignment. |
| Does the change hide evidence from the profile owner? | **No.** Ownership is self-scope and remains sufficient. | `CareTaskAccess::hasScope` returns true for the owner. |
| Does it break completion? | **No.** `has_photo_evidence` remains plain task metadata and is available without the new scope. | `FLG-T05`, `EVD-T04`. |
| Existing grants? | They fail closed: no `task_evidence` token means no evidence read. | Explicit grant model; no implicit upgrade. |
| Audit? | Every allow/deny on the route still writes one `security_audit_logs` row with `care_task.evidence.read`. | `SecurityAuditLogger` (`F-11`). |
| Privilege escalation? | **Reduced.** The old mapping let a task-read grant reach PHI without a separate decision. | Scope check after the role gate. |

**Summary:** Low risk, strictly narrowing. No role, Matrix cell, permission ID or test contract was
weakened.

---

## 6. Implementation impact

| Area | Change |
|---|---|
| `backend/config/authorization.php` | Added `task_evidence` to `scopes`. |
| `backend/app/Modules/Authz/Authorizer.php` | `care_task.evidence.read` maps to `task_evidence` / `read`. |
| `backend/app/Modules/CareCrm/Application/CareTaskAccess.php` | Added `canReadEvidence()` using `task_evidence:read`. |
| `backend/app/Modules/CareCrm/Application/CareTaskService.php` | `listEvidence()` uses `assertEvidenceAccess()`. |
| `backend/app/Modules/CareCrm/Http/Controllers/TaskEvidenceController.php` | Comment records the read/create asymmetry. |
| `docs/baseline-v1.0.0/AUTHORIZATION_BASELINE.md` | `ABD-018`; v1.3.0; change history. |
| `docs/baseline-v1.0.0/README.md` | v1.3.0 amendment and version history. |
| `.ai/03-AUTHORIZATION.md` | `task_evidence` in the §5 scope table; baseline version. |
| `.ai/README.md` | Baseline version and change-record index. |
| Tests | `EVD-T01...T05`; existing denial test preserved. |

No role-gate (Matrix) cells changed: the role still decides **whether** the permission can be held, and
the scope decides **which grant** confers it.

### Existing test adjustment

No existing positive evidence-read test needed a new grant — the suite previously asserted only the
denial path (`FLG-T05`, viewer). That assertion is preserved unchanged: the viewer still gets `403`. The
new positive and negative cases live in `EvidenceScopeTest`.

---

## 7. Migration notes

- **No database migration.** Permission mappings and scope vocabulary live in config/code;
  `task_evidence` is stored only as a token inside the existing `access_grants.resource_scope` JSONB
  array.
- **No data rewrite.** Grants are explicit by design (`CD-010...016`). We deliberately do **not** add
  `task_evidence` to every grant that has `tasks` — that would recreate the coupling in a migration and
  widen access without an owner decision.
- **Operational consequence:** after deployment, a grant with `tasks:read` can read tasks but gets
  `403` on evidence until its grantor adds `task_evidence:read`. This is the intended fail-closed
  behaviour (`ABD-010`), not a bug.
- **Rollback** means restoring the old mapping, removing the scope token and `ABD-018`, and reverting
  the evidence service check. No data to undo.
- **Rate/audit:** no new table, job, queue message or notification payload.

---

## 8. Rejected alternatives

| Alternative | Why rejected |
|---|---|
| Reuse the existing `documents` scope | `documents` describes the medical-document library (metadata + file). Evidence also includes measured values and notes that are not documents, and reusing it would give every document reader access to task proof. It also conflates two different retention and UI domains. |
| Introduce a new **action** (e.g. `tasks` / `evidence_read`) | Scope and action are two dimensions; keeping the scope as `tasks` keeps the authority tied to task scope. A new action token alone cannot separate grants unless every evidence grant also carries `tasks`, which restores the coupling. The action vocabulary is also frozen by the baseline. |
| Leave the mapping as `tasks` / `read` | It lets anyone with task-read authority read the PHI-bearing artifact, violating least privilege and `ABD-004`'s principle that reads of different resources are distinct. |
| Require `task_evidence:read` for **create** too | Attaching proof is part of doing an assigned task; requiring a second scope would break the normal completion flow and is not supported by the Matrix. The write path is already gated by assignment/document rules and audited. |
| Make assignment imply `task_evidence:read` | `ABD-003` and `F-04` say assignment grants task scope only. Evidence disclosure must be an explicit grant, not a side effect of workflow assignment. |
| Add a Matrix cell or role change | The role still decides whether the permission can be held; only the grant/scope dimension changes. Matrix cells are unchanged. |

---

## 9. Frozen decision

| Decision ID | Frozen decision | Rationale |
|---|---|---|
| `ABD-018` | Task evidence reads (`care_task.evidence.read`) require a scope of their own (`task_evidence:read`) and are **not** implied by task-read authority or task assignment. | Evidence is the PHI-bearing artifact; the task is coordination metadata. A separate scope lets a grant show progress without disclosing photos, values or notes, while `has_photo_evidence` keeps completion possible. |

---

## 10. Compatibility note for clients

- **No payload change.** Task responses, the need-to-know card and `has_photo_evidence` are unchanged.
- **UI behaviour:** a client that shows an evidence list must handle `403 forbidden` on
  `GET .../care-tasks/{task}/evidence` even when it can read the task. It must not infer evidence access
  from `care_task.read`, assignment, or the `has_photo_evidence` flag.
- **Grant management UI:** `task_evidence` is a selectable scope token. Existing grants do not gain it
  automatically; the grantor must edit the grant.
- **Mobile/web:** both clients already tolerate a denied evidence call (the flag is the documented
  completion affordance) and require no protocol change.

---

## 11. Approval record

| Date | Decision maker | Decision | Reference |
|---|---|---|---|
| 2026-10-24 | Product owner | Approve the single `task_evidence` scope and the `care_task.evidence.read` re-map | TASK M / CR-004 |
| 2026-10-24 | Engineering | **APPLIED** — baseline documents, config, mapping, service check and tests updated together; v1.3.0 | §6 |
