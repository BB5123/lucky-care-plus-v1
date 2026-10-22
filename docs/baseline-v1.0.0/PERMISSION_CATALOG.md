# Permission Catalog

**Product:** Family Health CRM\
**Document ID:** AUTH-DOC-001\
**Version:** 1.1.0\
**Status:** Frozen for implementation (v1.1.0 — amended by `CR-001`)\
**Applies to:** Laravel API, Web, Flutter Mobile, background jobs,
exports, AI Gateway

## Baseline status and binding decisions

- **Baseline ID:** `AUTH-BASELINE-001`
- **Baseline version:** `1.1.0`
- **Effective date:** `2026-10-18`
- **Amended:** `2026-10-22` by `CR-001` — added `notification.preference.read`, `notification.preference.update`, `notification.delivery.read`, `audit_event.read_own`. No existing permission was renamed, removed, or weakened.
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

This document defines the canonical permissions available in the system.
It is the source of truth for permission identifiers and their intended
meaning. It does not, by itself, grant any user access; assignments and
resource scope are defined in `DATA_ACCESS_MATRIX.md`.

## 2. Normative rules

1.  **Deny by default:** an unlisted action is denied.
2.  **Server-side enforcement:** UI hiding is not authorization.
3.  **Least privilege:** permissions must be narrow and action-specific.
4.  **Scope is mandatory:** a permission without an authorized resource
    scope does not authorize access.
5.  **No role inference:** role names do not substitute for an explicit
    permission decision.
6.  **No ID trust:** UUID/UUIDv7 values are identifiers, not access
    credentials.
7.  **Background jobs re-authorize:** queued work must verify
    authorization and relevant grant/consent state at execution and
    delivery time.
8.  **Audit sensitive operations:** policy-defined reads, all writes,
    permission changes, exports, and AI data disclosures must generate
    appropriate audit events.
9.  **No silent privilege escalation:** assigning a care task does not
    implicitly grant access to health records.
10. **Consent is not a universal permission:** consent, legal authority,
    purpose limitation, permission, and subscription entitlement are
    distinct checks.

## 3. Permission naming convention

**Baseline rule:** a permission catalog entry defines a possible action, not a grant to any role. A permission is not active for an endpoint until its matrix mapping, scope policy, and tests exist.



Format: `<domain>.<resource>.<action>`

-   lowercase snake-free dot notation
-   stable identifiers; do not rename without migration and
    compatibility review
-   use action-specific permissions, not broad `manage` permissions
-   `read` means viewing data; it does not imply export, share,
    correction, or use by AI
-   `create` does not imply `update` or `delete`
-   `correct` is preferred over generic update for clinical/health
    observations where preserving history is required

## 4. Permission catalog

### 4.1 Workspace and membership

  ----------------------------------------------------------------------------------------
  Permission ID                    Meaning                      Sensitive Notes
  -------------------------------- ---------------- --------------------- ----------------
  `workspace.read`                 Read workspace                      No Does not grant
                                   metadata                               health-profile
                                                                          access

  `workspace.update`               Update permitted                    No Cannot change
                                   workspace                              security policy
                                   settings                               beyond assigned
                                                                          authority

  `workspace.member.read`          View workspace                     Low Minimize
                                   membership                             personal details
                                                                          shown

  `workspace.member.invite`        Invite a new                    Medium Invitee receives
                                   member                                 no profile
                                                                          access until
                                                                          separately
                                                                          granted

  `workspace.member.update_role`   Change a                          High Cannot assign a
                                   member's                               role higher than
                                   workspace role                         actor's
                                                                          authority

  `workspace.member.suspend`       Suspend a                         High Must audit
                                   workspace                              
                                   membership                             

  `workspace.member.remove`        Remove a                          High Must evaluate
                                   workspace                              owned tasks and
                                   membership                             active grants

  `workspace.invitation.accept`    Accept an                       Medium Must verify
                                   invitation                             invitation token
                                   addressed to the                       and expiry
                                   authenticated                          
                                   identity                               
  ----------------------------------------------------------------------------------------

### 4.2 Person and health profile

  ---------------------------------------------------------------------------------------
  Permission ID              Meaning                           Sensitive Notes
  -------------------------- --------------------- --------------------- ----------------
  `person.read`              Read permitted person                  High Field-level
                             identity fields                             minimization may
                                                                         apply

  `person.create`            Create a person                        High Does not
                             record in a permitted                       establish legal
                             workspace                                   guardianship

  `person.update`            Update permitted                       High Field-level
                             demographic/profile                         restrictions
                             fields                                      apply

  `person.merge`             Merge duplicate                    Critical Restricted
                             person records                              administrative
                                                                         workflow and
                                                                         audit

  `health_profile.read`      Read health-profile                Critical Requires profile
                             overview                                    scope

  `health_profile.create`    Create a health                    Critical Validate
                             profile                                     workspace and
                                                                         person
                                                                         relationship

  `health_profile.update`    Update permitted                   Critical Sensitive fields
                             profile fields                              may require
                                                                         stronger
                                                                         authorization

  `health_profile.archive`   Archive a profile                  Critical Not equivalent
                             where policy permits                        to erasing
                                                                         legally retained
                                                                         records
  ---------------------------------------------------------------------------------------

### 4.3 Relationships, access grants, and consent

  ---------------------------------------------------------------------------------------------------
  Permission ID                Meaning                               Sensitive Notes
  ---------------------------- ------------------------- --------------------- ----------------------
  `care_relationship.read`     Read permitted care                        High Relationship is not
                               relationships                                   itself full data
                                                                               access

  `care_relationship.create`   Establish a care                           High Must validate
                               relationship                                    invitation/authority
                                                                               and target scope

  `care_relationship.update`   Update permitted                           High Cannot self-assert
                               relationship attributes                         legal authority

  `care_relationship.revoke`   End a care relationship                Critical Must
                                                                               recalculate/revoke
                                                                               dependent grants where
                                                                               policy requires

  `access_grant.read`          Read grants within                     Critical Avoid exposing grants
                               actor's authority                               to unrelated members

  `access_grant.create`        Create a scoped access                 Critical Actor must have
                               grant                                           authority to delegate
                                                                               each granted action

  `access_grant.update`        Narrow or update an                    Critical Widening scope
                               existing grant                                  requires fresh
                                                                               authorization and,
                                                                               where required,
                                                                               consent

  `access_grant.revoke`        Revoke a grant the actor               Critical Enforce on subsequent
                               is authorized to revoke                         requests and queued
                                                                               work

  `consent.read`               Read consent status and                Critical Evidence access may be
                               permitted evidence                              more restricted than
                               metadata                                        status

  `consent.record`             Record a consent decision              Critical Record actor, subject,
                               from an authorized                              purpose, scope,
                               subject/representative                          version, timestamp,
                                                                               evidence

  `consent.withdraw`           Record withdrawal by an                Critical Does not erase
                               authorized                                      historical consent
                               subject/representative                          evidence

  `legal_authority.verify`     Record verification of                 Critical Restricted workflow;
                               guardian/representative                         requires evidence and
                               authority                                       reviewer policy
  ---------------------------------------------------------------------------------------------------

### 4.4 Health measurements and conditions

  ----------------------------------------------------------------------------------------------
  Permission ID                  Meaning                          Sensitive Notes
  ------------------------------ -------------------- --------------------- --------------------
  `health_measurement.read`      Read permitted                    Critical Includes only
                                 measurements                               authorized profile
                                                                            and fields

  `health_measurement.create`    Record a new                      Critical Record source,
                                 measurement                                subject, author,
                                                                            measured time,
                                                                            recorded time

  `health_measurement.correct`   Correct an erroneous              Critical Preserve original
                                 measurement                                value and correction
                                                                            history according to
                                                                            policy

  `health_measurement.delete`    Delete a measurement              Critical Default denied;
                                 where legally and                          retention and
                                 operationally                              correction policy
                                 permitted                                  takes precedence

  `health_condition.read`        Read permitted                    Critical Sensitive medical
                                 conditions/history                         information

  `health_condition.create`      Add a condition                   Critical Distinguish
                                 record                                     user-reported from
                                                                            clinically verified

  `health_condition.correct`     Correct a condition               Critical Preserve
                                 record                                     provenance/history

  `allergy.read`                 Read allergy                      Critical Minimize display and
                                 information                                notification
                                                                            exposure

  `allergy.update`               Update allergy                    Critical Audit changes;
                                 information                                retain provenance
  ----------------------------------------------------------------------------------------------

### 4.5 Medication

  ---------------------------------------------------------------------------------------
  Permission ID                   Meaning                      Sensitive Notes
  ------------------------------- ---------------- --------------------- ----------------
  `medication_plan.read`          Read medication               Critical Profile scope
                                  plan                                   required

  `medication_plan.create`        Create a                      Critical Do not imply
                                  medication plan                        prescribing
                                                                         authority

  `medication_plan.update`        Update permitted              Critical Preserve
                                  plan details                           history;
                                                                         clinically
                                                                         significant
                                                                         changes may need
                                                                         stronger
                                                                         workflow

  `medication_plan.discontinue`   Mark plan                     Critical Record actor,
                                  discontinued                           reason, and
                                                                         effective time

  `medication_record.read`        Read medication               Critical Distinguish
                                  activity records                       reported
                                                                         activity from
                                                                         verified
                                                                         administration

  `medication_record.create`      Record a                      Critical Does not prove
                                  reported                               ingestion
                                  medication event                       

  `medication_record.correct`     Correct a                     Critical Preserve
                                  medication event                       original event
                                                                         and correction
                                                                         history
  ---------------------------------------------------------------------------------------

### 4.6 Care coordination

  --------------------------------------------------------------------------------------
  Permission ID                 Meaning                       Sensitive Notes
  ----------------------------- ----------------- --------------------- ----------------
  `care_plan.read`              Read permitted                     High Plan scope
                                care plan                               applies

  `care_plan.create`            Create a care                      High Does not grant
                                plan                                    health-record
                                                                        access

  `care_plan.update`            Update plan                        High Audit material
                                details                                 changes

  `care_task.read`              Read task details           Medium/High Hide unnecessary
                                within assigned                         health details
                                scope                                   

  `care_task.create`            Create a task               Medium/High Validate profile
                                                                        and workspace
                                                                        scope

  `care_task.assign`            Assign/reassign                    High Assignment does
                                task                                    not imply
                                                                        health-data
                                                                        access

  `care_task.accept`            Accept an                        Medium Must be assigned
                                assigned task                           or explicitly
                                                                        authorized

  `care_task.start`             Start a permitted                Medium Enforce state
                                task                                    machine

  `care_task.complete`          Record completion                  High Validate
                                and result                              required fields
                                                                        and transition

  `care_task.verify`            Verify completion                  High Separation of
                                where required                          duties may apply

  `care_task.block`             Mark blocked with                Medium Validate
                                reason                                  transition and
                                                                        audit

  `care_task.reopen`            Reopen a                           High Require reason
                                completed task                          and audit

  `care_task.cancel`            Cancel a task                      High Validate actor
                                                                        authority and
                                                                        state

  `care_task.evidence.read`     Read task                          High Evidence may
                                evidence                                contain health
                                                                        data

  `care_task.evidence.create`   Attach task                        High Validate type,
                                evidence                                size, ownership,
                                                                        malware policy
  --------------------------------------------------------------------------------------

### 4.7 Appointments and medical documents

  ------------------------------------------------------------------------------------
  Permission ID               Meaning                       Sensitive Notes
  --------------------------- ----------------- --------------------- ----------------
  `appointment.read`          Read permitted                     High Limit fields
                              appointment                             according to
                              details                                 scope

  `appointment.create`        Create an                          High Does not grant
                              appointment                             medical-record
                                                                      access

  `appointment.update`        Update an                          High Audit important
                              appointment                             changes

  `appointment.cancel`        Cancel an                          High Check actor
                              appointment                             authority and
                                                                      state

  `medical_document.read`     Read an                        Critical File storage
                              authorized                              must be private
                              document                                

  `medical_document.upload`   Upload a document              Critical Validate type,
                                                                      scan, and bind
                                                                      to authorized
                                                                      profile

  `medical_document.share`    Share a document               Critical Explicit
                              with an                                 recipient,
                              authorized                              scope, expiry,
                              recipient                               audit

  `medical_document.export`   Export/download a              Critical Separate from
                              document                                read permission;
                                                                      signed URLs must
                                                                      expire

  `medical_document.delete`   Delete where                   Critical Default denied;
                              permitted                               retention rules
                                                                      apply
  ------------------------------------------------------------------------------------

### 4.8 Timeline, reports, and exports

  --------------------------------------------------------------------------------
  Permission ID            Meaning                      Sensitive Notes
  ------------------------ ---------------- --------------------- ----------------
  `timeline.read`          Read a timeline               Critical Every underlying
                           assembled from                         item must be
                           authorized                             authorized
                           sources                                

  `health_report.create`   Generate a                    Critical Check all source
                           health report                          records
                           from authorized                        
                           data                                   

  `health_report.read`     Read a generated              Critical Re-check access
                           report                                 at retrieval

  `health_report.export`   Export a report               Critical Explicit export
                                                                  permission and
                                                                  audit

  `data_export.request`    Request an                    Critical Validate allowed
                           export job                             datasets and
                                                                  fields

  `data_export.download`   Download export               Critical Re-authorize at
                           result                                 download;
                                                                  short-lived
                                                                  access

  `data_export.cancel`     Cancel an export                  High Actor must own
                           job                                    job or have
                                                                  admin authority
  --------------------------------------------------------------------------------

### 4.9 AI and integrations

  -------------------------------------------------------------------------------------------
  Permission ID                      Meaning                      Sensitive Notes
  ---------------------------------- ---------------- --------------------- -----------------
  `ai.health_context.use`            Permit                        Critical Requires purpose
                                     authorized                             and applicable
                                     health data to                         consent/legal
                                     be included in                         basis checks
                                     an AI request                          

  `ai.conversation.create`           Start an AI                       High Context may be
                                     conversation                           empty or scoped

  `ai.conversation.read`             Read                          Critical Conversation can
                                     conversation                           contain sensitive
                                     history                                data

  `ai.conversation.delete_request`   Request deletion              Critical Retention/legal
                                     under applicable                       exceptions must
                                     policy                                 be evaluated

  `ai.report.create`                 Generate an                   Critical Apply safety and
                                     AI-assisted                            provenance rules
                                     report                                 

  `integration.health_data.read`     Read data                     Critical Per-integration
                                     through an                             scope and
                                     integration                            credential policy

  `integration.health_data.write`    Write data                    Critical Validate
                                     through an                             provenance and
                                     integration                            idempotency
  -------------------------------------------------------------------------------------------

### 4.10 Audit, security, and platform operations

  -----------------------------------------------------------------------------------------
  Permission ID                     Meaning                      Sensitive Notes
  --------------------------------- ---------------- --------------------- ----------------
  `audit_event.read`                Read audit                    Critical Sensitive
                                    events within                          details must be
                                    authorized scope                       minimized

  `audit_event.read_own`            Read audit                    Medium Only events where
                                    events about the                       the actor is the
                                    actor's own data                       subject, or owns /
                                    access                                 legally represents
                                                                           the subject
                                                                           profile; never
                                                                           other members'
                                                                           activity
                                                                           (v1.1.0)

  `security_access_review.read`     Read                          Critical Restricted to
                                    access-review                          authorized
                                    results                                security roles

  `platform.configuration.manage`   Manage platform                   High Does not grant
                                    configuration                          health-data
                                                                           access

  `platform.user.support`           Perform approved                  High No implicit
                                    account support                        health-data read
                                    actions                                

  `break_glass.request`             Request                       Critical Does not itself
                                    emergency access                       grant access

  `break_glass.approve`             Approve                       Critical Separation of
                                    emergency access                       duties where
                                    under policy                           feasible

  `break_glass.use`                 Use a                         Critical Reason, scope,
                                    time-limited                           expiry,
                                    emergency grant                        alerting,
                                                                           retrospective
                                                                           review
  -----------------------------------------------------------------------------------------

### 4.11 Notification preferences and delivery (v1.1.0)

  -----------------------------------------------------------------------------------------
  Permission ID                     Meaning                      Sensitive Notes
  --------------------------------- ---------------- --------------------- ----------------
  `notification.preference.read`    Read the actor's own            Low Own membership only;
                                    notification and                    never reveals care
                                    communication                       relationships or
                                    preferences                         health data

  `notification.preference.update`  Update the actor's own          Low Not a consent action:
                                    notification and                    changing a channel
                                    communication                       never records,
                                    preferences                         grants, or withdraws
                                                                        consent (ABD-008)

  `notification.delivery.read`      Read delivery status of         Low Metadata only
                                    notifications addressed              (channel, status,
                                    to the actor                        attempts, timestamps,
                                                                        error_code); never
                                                                        message body or PHI
  -----------------------------------------------------------------------------------------

Scope: `self_membership:<membership_id>` for all three. A member may only read or change
their own preferences and their own delivery records. Platform personnel may use these
permissions only while acting as an ordinary workspace member, never by virtue of a
platform role (`ABD-011`).

See `DATA_ACCESS_MATRIX.md` Matrix E and `AUTHORIZATION_BASELINE.md` `ABD-016`.

## 5. Resource scopes

Every authorization decision must evaluate the applicable scope.
Supported scope types should be explicit, for example:

-   `workspace:<workspace_id>`
-   `profile:<profile_id>`
-   `resource:<resource_type>:<resource_id>`
-   `assigned_task:<task_id>`
-   `purpose:<approved_purpose>`
-   `field_set:<approved_field_set>`
-   `self_membership:<membership_id>` (v1.1.0 — the actor's own workspace membership and its preferences)
-   `owned_profile_audit:<person_id>` (v1.1.0 — audit entries whose subject profile the actor owns or is verified to represent)

Do not implement a scope as arbitrary client-provided text. Validate
scope types and resolve them against persisted relationships.

## 6. Permission decision contract

Authorization service input should include:

-   authenticated actor and authentication strength where relevant
-   action/permission identifier
-   resource type and resource identifier
-   tenant/workspace/profile context
-   current relationship and grant state
-   requested purpose
-   relevant consent/legal-authority status
-   request channel (web/mobile/job/integration)
-   time and risk context where applicable

Decision output should be one of `ALLOW` or `DENY`, with an internal
reason code. Do not expose sensitive internal denial details to an
unauthorized caller.

## 7. Non-goals and safety boundaries

-   A permission is not medical competence or prescribing authority.
-   A task assignment is not a data-access grant.
-   A workspace role is not universal access to all profiles.
-   A valid login is not authorization to every resource.
-   A consent record alone does not replace all legal, security,
    purpose, and domain checks.
-   Emergency access is not a general-purpose bypass.

## 8. Change control

Any new endpoint, resource, action, role, AI data flow, export, or
integration must: 1. reference an existing permission or propose a new
one; 2. update the Data Access Matrix; 3. add positive and negative
tests; 4. update API documentation and threat model if the risk changes;
5. pass CI authorization tests before merge.

## 9. Implementation and audit requirements

- Every endpoint, queued job, notification containing protected details, integration entry point, and file-download route must declare the permission(s) and scope it requires.
- Audit health-data reads according to a documented sensitivity policy; always audit health-data writes, grant/consent changes, exports, AI disclosures, and privileged support workflows. Record denials where security policy requires, without copying sensitive payloads into logs.
- Audit records should include actor, action, target resource reference, timestamp, outcome, reason code, request/correlation ID, and relevant grant/policy version where feasible. Do not log tokens, full medical content, or secrets.
- Authorization-sensitive caches must be keyed by actor, resource, action, and policy/grant version where applicable; revoke/invalidate them when membership or grants change.
- The API must not mutate data, enqueue a job, create a signed link, or disclose resource fields before authorization succeeds.
- For asynchronous processing, authorize at request time and re-authorize before processing and before releasing the result.
- Break-glass permissions are catalogued for future consideration but are **disabled in MVP** until a separately approved policy and tests exist.

## 10. Open decisions requiring product/legal approval

-   Age of majority and supported guardian/representative workflows.
-   Whether profile owners can self-manage all sensitive fields.
-   Data retention, deletion, and correction requirements.
-   Applicable lawful bases and consent requirements for each processing
    purpose.
-   Whether healthcare professionals are in MVP scope.
-   Emergency-access eligibility, approval, and post-event review.
