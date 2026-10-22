# Data Access Matrix

**Product:** Family Health CRM\
**Document ID:** AUTH-DOC-002\
**Version:** 1.1.0\
**Status:** Frozen for implementation (v1.1.0 — amended by `CR-001`)\
**Depends on:** `PERMISSION_CATALOG.md`

## Baseline status and binding decisions

- **Baseline ID:** `AUTH-BASELINE-001`
- **Baseline version:** `1.1.0`
- **Effective date:** `2026-10-18`
- **Amended:** `2026-10-22` by `CR-001` — added Matrix E (self-service preferences and self-audit) and two scope types. No existing matrix row was removed or weakened.
- **Status:** **FROZEN FOR IMPLEMENTATION**
- **Change policy:** Do not silently alter a permission, role mapping, scope rule, denial behavior, or required test. Propose a versioned change, document its security impact, update all three documents, and add/adjust tests in the same change.
- **Approval boundary:** This is an engineering baseline for implementation, not legal certification or production-readiness approval. Product, security, and qualified legal review remain required for the open decisions explicitly listed in these documents.

### Binding decisions for v1.0.0

1. **A role is contextual, not global.** A user's role applies only within the relevant workspace or explicitly defined relationship. Never infer access to all profiles from membership in a workspace.
2. **Profile owner scope is self-only.** `profile_owner` permissions apply only to profiles for which the authenticated person is the verified owner, except where an explicitly approved representative workflow applies.
3. **Workspace administration is not health-data access.** Workspace membership, invitation, billing, and configuration permissions do not grant access to health records.
4. **Guardian authority must be verified.** A `legal_guardian` role is conditional on verified, current authority and a defined scope. A relationship label or user assertion alone is insufficient.
5. **Task assignment grants task scope only.** It does not grant permission to read the entire profile, medication plan, or medical documents.
6. **Read is not export, share, correction, deletion, or AI use.** Each is a separate action requiring its own permission and applicable checks.
7. **Grants cannot exceed the grantor's authority.** A grantor cannot delegate a permission or scope they do not currently possess and have authority to delegate.
8. **Consent, legal authority, and permission are separate checks.** Apply consent only where it is required by the applicable legal basis and approved product policy. Never assume consent is always the only lawful basis, or that a consent record alone authorizes processing.
9. **Revocation must be enforced at every release boundary.** Re-check current membership/grant/purpose status for new requests, queued jobs before execution, and file delivery. Clear or version authorization-sensitive caches. If immediate revocation of a file link is required, use an authorization-checking download endpoint/proxy rather than relying only on a previously issued direct signed URL.
10. **Sensitive access fails closed.** If a required authorization dependency is unavailable or its decision cannot be established, deny access to protected health data.
11. **Platform staff have no standing health-data access.** Operational access, if later approved, must use a separately authorized, time-limited, purpose-bound workflow with audit and review.
12. **Deletion is denied by default for health records.** Use correction/versioning and an approved retention/deletion policy; do not silently erase provenance or audit evidence.
13. **Break-glass is not an MVP bypass.** It remains disabled until eligibility, approval, scope, expiry, alerting, audit, and retrospective review are explicitly approved and tested.
14. **Denial responses must not leak resource existence.** Choose a consistent `403` or policy-approved `404` strategy and test both status and response body for information leakage.
15. **Every protected permission must be traceable.** Before a permission is enabled in a release, it must map to a matrix rule, endpoint/job entry point, positive test where applicable, negative test, and required side effects/audit behavior.

> **v1.1.0 addition (`CR-001`):** decision 16 (`ABD-016`) is recorded in `AUTHORIZATION_BASELINE.md`. It states that notification preferences and self-audit visibility are **not** authorization or consent state. Decisions 1–15 above are unchanged.

### Authorization decision precedence

Apply the following precedence consistently:

1. Authentication and account/session validity.
2. Hard security/legal/policy deny or prohibited action.
3. Tenant/workspace and target-resource consistency.
4. Exact permission and resource/field scope.
5. Current relationship, grant, expiry, revocation, and delegation authority.
6. Consent/legal-basis and purpose checks where applicable.
7. Domain state, separation-of-duties, and workflow rules.
8. Required audit/outbox handling and execution.

Passing an earlier check never overrides a later required check. An explicit deny overrides a broader role-based allow. This sequence is conceptual; implementations may order checks differently only if they preserve the same security outcome and do not perform side effects before authorization.

## 1. Purpose

This document maps roles to actions and resource scopes. A matrix entry
is a policy template, not an unconditional grant. Every `ALLOW` remains
subject to workspace/profile scope, relationship, grant validity,
consent/legal authority where applicable, domain rules, and
deny-by-default behavior.

## 2. Decision notation

-   **A** --- allow for the actor's own profile or explicitly authorized
    scope.
-   **C** --- conditional; requires a specific valid grant,
    relationship, legal authority, purpose, or workflow.
-   **D** --- deny by default.
-   **N/A** --- not applicable to this role in the standard product.
-   Explicit deny takes precedence over a broader allow.
-   If any required authorization service or policy dependency is
    unavailable, sensitive access fails closed.

## 3. Role definitions

  -----------------------------------------------------------------------
  Role                                Meaning
  ----------------------------------- -----------------------------------
  `profile_owner`                     Person authorized to control their
                                      own profile, subject to legal and
                                      product constraints

  `legal_guardian`                    Verified representative with
                                      authority limited to the
                                      represented person and applicable
                                      law

  `workspace_admin`                   Manages membership and workspace
                                      configuration; no implied
                                      health-data access

  `caregiver`                         Performs explicitly delegated care
                                      actions

  `viewer`                            Reads a narrowly scoped subset of
                                      data

  `healthcare_professional`           Optional role for an authorized
                                      care relationship and defined
                                      purpose

  `platform_support`                  Account/system support; no default
                                      health-data access

  `platform_admin`                    Platform operations; no default
                                      health-data access
  -----------------------------------------------------------------------

A person may hold different roles in different workspaces or
relationships. Do not store a single global role that grants access
across all workspaces.

## 4. Matrix A --- workspace administration

  ---------------------------------------------------------------------------------------------
  Action               Owner   Guardian   Workspace   Caregiver    Viewer   Platform   Platform
                                              Admin                          Support      Admin
  ---------------- --------- ---------- ----------- ----------- --------- ---------- ----------
  Read workspace           A          C           A           C         C          C          A
  metadata                                                                           

  Update workspace         C          C           A           D         D          D          C
  settings                                                                           

  Read member list         C          C           A           D         D          C          C

  Invite member            C          C           A           D         D          D          C

  Change member            D          C         C\*           D         D          D        C\*
  role                                                                               

  Suspend/remove           C          C         A\*           D         D          D        C\*
  member                                                                             

  Accept own               A          A           A           A         A        N/A        N/A
  invitation                                                                         
  ---------------------------------------------------------------------------------------------

`*` An administrator may only assign roles within their delegated
authority, cannot grant themselves additional privileges, and cannot
thereby grant health-profile access. Membership changes must trigger
review of grants and assignments.

## 5. Matrix B --- profile and health data

  ----------------------------------------------------------------------------------------------
  Action                      Owner   Guardian   Workspace   Caregiver     Viewer     Healthcare
                                                     Admin                          Professional
  ---------------------- ---------- ---------- ----------- ----------- ---------- --------------
  Read person/profile             A          C           D           C          C              C
  overview                                                                        

  Create profile                A/C          C           D           D          D              C

  Update demographics             A          C           D         C\*          D            C\*

  Read measurements               A          C           D           C          C              C

  Create measurement              A          C           D           C          D              C

  Correct measurement           A/C          C           D         C\*          D            C\*

  Delete measurement           D by       D by           D           D          D              D
                            default    default                                    

  Read                            A          C           D           C          C              C
  conditions/allergies                                                            

  Update                        A/C          C           D         C\*          D            C\*
  conditions/allergies                                                            

  Read medication plan            A          C           D           C          C              C

  Create/update                 A/C          C           D         C\*          D            C\*
  medication plan                                                                 

  Record reported                 A          C           D           C          D              C
  medication event                                                                

  Read medical documents          A          C           D           C          C              C

  Upload medical                  A          C           D           C          D              C
  document                                                                        

  Share/export medical            C          C           D         C\*          D              C
  document                                                                        
  ----------------------------------------------------------------------------------------------

Conditions for `C`/`C*` must be expressed as explicit policy rules and
grant scopes. In particular, healthcare-professional access requires an
established and current relationship, permitted purpose, and applicable
legal basis; the role alone is insufficient. A caregiver's access should
be narrowed to the minimum data necessary for their assigned care
duties.

## 6. Matrix C --- care coordination

  Action                         Owner   Guardian   Workspace Admin   Caregiver   Viewer
  ---------------------------- ------- ---------- ----------------- ----------- --------
  Read care plan                     A          C                 D           C        C
  Create/update care plan          A/C          C                 D         C\*        D
  Create task                        A          C               C\*           C        D
  Read task                          A          C               C\*       C\*\*        C
  Assign/reassign task               A          C               C\*         C\*        D
  Accept/start assigned task         A          C               C\*       C\*\*        D
  Complete task                      A          C               C\*       C\*\*        D
  Verify task                      A/C          C               C\*         C\*        D
  Reopen/cancel task               A/C          C               C\*         C\*        D
  Read task evidence                 A          C               C\*       C\*\*        C

-   `C*`: only if workspace policy explicitly delegates the action and
    the actor is authorized for the target profile/task.
-   `C**`: assigned task or explicit task scope is required. Task
    details must not reveal unrelated health data.
-   Task assignment does not grant permission to read a profile,
    medication plan, or document.
-   Verification may require a different actor from the completer for
    high-risk tasks.
-   Medication reminders and completion records represent reported
    activity; they do not prove actual ingestion.

## 7. Matrix D --- consent, delegation, AI, and export

  ------------------------------------------------------------------------------------------
  Action                 Owner   Guardian   Workspace   Caregiver     Viewer        Platform
                                                Admin                          Support/Admin
  ----------------- ---------- ---------- ----------- ----------- ---------- ---------------
  Read                       A          C           D           D          D             C\*
  own/authorized                                                             
  consent status                                                             

  Record consent             A          C           D           D          D             C\*

  Withdraw consent           A          C           D           D          D             C\*

  Create access            A/C          C           D           D          D               D
  grant                                                                      

  Revoke grant             A/C          C           D           D          D             C\*

  Use health data            C          C           D           C          D               D
  in AI                                                                      

  Read AI                  A/C          C           D         C\*          D               D
  conversation                                                               

  Generate health            C          C           D           C          D               D
  report                                                                     

  Export/download            C          C           D         C\*          D               D
  health data                                                                

  Read audit events          C          C           D           D          D             C\*

  Use break-glass            D          D           D           D          D             C\*
  access                                                                     
  ------------------------------------------------------------------------------------------

`C*` for platform personnel means a narrowly approved operational
workflow, not standing access. Platform support/admin must not read
health content simply because they can administer the infrastructure.

Consent/AI/export decisions require separate checks: 1. actor
permission; 2. resource scope; 3. purpose limitation; 4. applicable
consent or other valid legal basis; 5. data minimization and safety
policy; 6. export/delivery authorization at the time the data is
released.

## 8. Matrix E --- self-service preferences and self-audit (v1.1.0)

| Action | Owner | Guardian | Workspace Admin | Caregiver | Viewer | Platform Support/Admin |
|---|---|---|---|---|---|---|
| Read own notification preferences | A | A | A | A | A | A\* |
| Update own notification preferences | A | A | A | A | A | A\* |
| Read own notification delivery status | A | A | A | A | A | A\* |
| Read own audit events | A | C | D | D | D | D |

- **Scope:** `self_membership:<membership_id>` for the three notification actions; `owned_profile_audit:<person_id>` for audit reads.
- **Action → permission mapping** (required for traceability, `ABD-013`):
  - Read own notification preferences → `notification.preference.read`
  - Update own notification preferences → `notification.preference.update`
  - Read own notification delivery status → `notification.delivery.read`
  - Read own audit events → `audit_event.read_own`
- **`A`** = allow for the actor's own membership, or for the actor's own profile only.
- **`C`** = allow only for profiles the actor is verified to represent (see `ABD-006`); requires a current, non-expired authority record.
- **`D`** = deny by default (`AUTH-001`).
- **`A*`** for platform personnel applies only when they act as an ordinary workspace member; a platform role adds no standing access (`ABD-011`).
- `notification.preference.update` **never** records, grants, or withdraws consent. Changing or disabling a channel is not a consent action (`ABD-008`, `ABD-016`).
- `audit_event.read_own` never exposes other members' activity. It returns events in which the actor is the subject, or events concerning a profile the actor owns or is verified to represent. Staff-level review continues to use `audit_event.read`.
- Reads through `audit_event.read_own` are themselves audited (`AUTH-010`).

## 9. Required authorization algorithm

For every protected request, implement a centralized policy decision
using this conceptual sequence:

1.  Authenticate actor and validate session/token.
2.  Resolve the target resource from trusted persisted data.
3.  Verify tenant/workspace isolation.
4.  Resolve relationship and role in the relevant workspace/profile.
5.  Verify the exact action permission.
6.  Verify resource and field scope.
7.  Verify grant status, expiry, and revocation where applicable.
8.  Verify consent/legal authority and purpose where applicable.
9.  Apply domain state and separation-of-duties rules.
10. Record the required audit event and execute atomically where
    possible.

Not every action requires every check. The policy for each action must
explicitly state which checks are mandatory. Never skip a required check
merely because another check passed.

## 10. Scope model

Recommended initial scope types:

-   `workspace`
-   `profile`
-   `resource`
-   `assigned_task`
-   `field_set`
-   `purpose`
-   `self_membership` (v1.1.0 — the actor's own workspace membership and its preferences)
-   `owned_profile_audit` (v1.1.0 — audit entries for profiles the actor owns or is verified to represent)

Use persisted identifiers and typed scope values. Do not accept
arbitrary scope strings from clients. A grant may only delegate
permissions the grantor is allowed to delegate, and cannot exceed the
grantor's own valid scope.

## 11. Field-level minimization

Where appropriate, return purpose-specific DTOs rather than full
database models. Example: a caregiver assigned to an appointment may
need date, time, location, and transport instructions, but not the
complete diagnosis history. A notification should avoid exposing
detailed health information on a lock screen.

## 12. Failure behavior

-   No authentication: return `401`.
-   Authenticated but unauthorized: return a consistent `403` or
    policy-approved `404` to reduce resource enumeration.
-   Invalid or expired grant: deny.
-   Authorization dependency unavailable: fail closed for protected
    health data.
-   Never mutate data before authorization succeeds.
-   Never disclose internal policy details that help an unauthorized
    caller enumerate resources.
-   Apply the same policy to API, exports, scheduled jobs,
    notifications, integrations, and AI context assembly.

## 13. Cache, files, and asynchronous boundary rules

- Sensitive responses must not be stored in shared/public caches. If private caching is used, bind it to the authenticated actor and authorization version, and invalidate it on membership/grant changes.
- A file identifier or storage URL is not authorization. File retrieval must check current access; direct signed URLs must be short-lived. Use a proxy/authorized download endpoint if policy requires revocation to take effect immediately.
- Queued jobs must re-check actor authority, target scope, grant status, and applicable purpose/consent before processing and before delivering results.
- Notifications should disclose the minimum necessary information and must not be used as a side channel to reveal health data to a person lacking access.

## 14. Required product decisions

The following must be resolved before production: - who may act for
minors or adults lacking decision-making capacity; - how legal
guardianship/representative authority is verified and renewed; - which
health data categories may be shared with each caregiver task type; -
whether healthcare-professional access is included in MVP; -
retention/deletion and correction policies; - lawful basis and consent
requirements by processing purpose; - emergency access conditions and
review process; - data export formats, expiry, and revocation behavior.
