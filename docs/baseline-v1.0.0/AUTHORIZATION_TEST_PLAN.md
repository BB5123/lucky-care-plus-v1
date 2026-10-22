# Authorization Test Plan

**Product:** Family Health CRM\
**Document ID:** AUTH-DOC-003\
**Version:** 1.1.0\
**Status:** Frozen for implementation (v1.1.0 — amended by `CR-001`)\
**Depends on:** `PERMISSION_CATALOG.md`, `DATA_ACCESS_MATRIX.md`

## Baseline status and binding decisions

- **Baseline ID:** `AUTH-BASELINE-001`
- **Baseline version:** `1.1.0`
- **Effective date:** `2026-10-18`
- **Amended:** `2026-10-22` by `CR-001` — added `AT-NOTIF-001…004` and `AT-AUDIT-004…005`. No existing test was removed or weakened.
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

This plan defines the minimum automated test suite required to verify
that the permission catalog and data access matrix are enforced by the
backend. Tests must cover both permitted and denied actions. UI behavior
alone is not evidence of authorization.

## 2. Test principles

1.  Every protected permission must have at least one positive test and
    one negative test.
2.  Every resource type must have cross-workspace and cross-profile
    isolation tests.
3.  Tests must use persisted users, memberships, relationships, grants,
    and consent records.
4.  Tests must exercise HTTP endpoints through middleware/policies and
    not only call helper methods.
5.  Authorization failure must not mutate data, enqueue an unauthorized
    job, or disclose protected fields.
6.  Re-check access at asynchronous execution and file delivery
    boundaries.
7.  Use realistic fixtures and distinct workspaces/profiles to detect
    accidental cross-tenant joins.
8.  Use the production database engine in CI for security-critical
    integration tests where feasible.
9.  Avoid asserting only status codes; assert no protected data leakage
    and no state change.
10. Each test must reference a Rule ID and Permission ID.

## 3. Baseline test gates

The test cases in this document are the minimum security scenarios, not proof that every catalog permission is already covered. A permission must remain disabled or unshipped until its traceability entry is complete.

For each permission enabled in a release, maintain a traceability row containing:
- Permission ID and matrix row;
- endpoint, job, integration, or file-release entry point;
- one positive test where the action is intended to be allowed;
- at least one negative test for missing permission or out-of-scope resource;
- relevant expiry/revocation, cross-workspace, and purpose tests where applicable;
- expected state changes, audit events, queue behavior, and response behavior.

The CI gate must fail if an enabled permission or protected endpoint has no traceability entry. Do not mark security tests as skipped to get a release green.

## 4. Test environment and fixtures

Create reusable test fixtures:

-   `Workspace A` and `Workspace B`
-   `Owner A`, `Owner B`
-   `Workspace Admin A`
-   `Caregiver A`, `Caregiver B`
-   `Viewer A`
-   `Platform Support`, `Platform Admin`
-   `Profile A1` and `Profile A2` in Workspace A; `Profile B1` in
    Workspace B
-   grant to read measurements for Profile A1 only
-   grant to create measurements for Profile A1 only
-   grant to read appointments for Profile A1 only
-   expired grant and revoked grant
-   consent records for different purposes, including AI health-context
    use
-   one care task assigned to Caregiver A, and another unassigned task
-   private medical document and export job
-   audit records for authorized and denied operations

All IDs should be random UUID/UUIDv7 values. Tests must not depend on
sequential IDs or predictable identifiers.

## 5. Rule IDs

  -----------------------------------------------------------------------
  Rule ID                             Requirement
  ----------------------------------- -----------------------------------
  `AUTH-001`                          Deny by default

  `AUTH-002`                          Authentication required

  `AUTH-003`                          Tenant/workspace isolation

  `AUTH-004`                          Profile-level authorization

  `AUTH-005`                          Role alone is insufficient

  `AUTH-006`                          Task assignment does not grant
                                      health-data access

  `AUTH-007`                          Revoked/expired grants deny access

  `AUTH-008`                          AI use requires separate
                                      authorization and purpose checks

  `AUTH-009`                          Export is separate from read

  `AUTH-010`                          Sensitive operations are audited

  `AUTH-011`                          Platform roles do not implicitly
                                      grant health-data access

  `AUTH-012`                          Client-supplied role/scope/consent
                                      cannot be trusted
  -----------------------------------------------------------------------

## 6. Minimum test cases

### 6.1 Authentication and default deny

  ----------------------------------------------------------------------------
  Test ID           Scenario               Expected result   Rules
  ----------------- ---------------------- ----------------- -----------------
  `AT-AUTH-001`     Anonymous request      `401`; no profile AUTH-002
                    reads a health profile data returned     

  `AT-AUTH-002`     Authenticated user     Denied; no state  AUTH-001
                    calls an action with   change            
                    no permission mapping                    

  `AT-AUTH-003`     Viewer attempts to     Denied; no row    AUTH-001,
                    create a measurement   created           AUTH-005

  `AT-AUTH-004`     Client submits         Server ignores    AUTH-012
                    `role=profile_owner`   untrusted role;   
                    in payload             denied            

  `AT-AUTH-005`     Client submits another Scope is resolved AUTH-003,
                    user's `workspace_id`  server-side;      AUTH-012
                                           denied            
  ----------------------------------------------------------------------------

### 6.2 Workspace and profile isolation

  -----------------------------------------------------------------------
  Test ID           Scenario          Expected result   Rules
  ----------------- ----------------- ----------------- -----------------
  `AT-ISO-001`      Owner A reads     Denied with       AUTH-003
                    Profile B1 in     non-leaking       
                    Workspace B       response          

  `AT-ISO-002`      Caregiver A reads Denied            AUTH-004
                    Profile A2                          
                    without a grant                     

  `AT-ISO-003`      User changes a    Denied; no fields AUTH-003,
                    route UUID to     leaked            AUTH-004
                    another profile's                   
                    UUID                                

  `AT-ISO-004`      List/search query Only authorized   AUTH-003
                    spans two         resources         
                    workspaces        returned          

  `AT-ISO-005`      Pagination or     No unauthorized   AUTH-003
                    aggregate count   rows or counts    
                    includes          leak              
                    unauthorized                        
                    profiles                            

  `AT-ISO-006`      Nested route uses Denied;           AUTH-003,
                    authorized        relationship      AUTH-004
                    workspace but     consistency       
                    foreign profile   checked           
                    ID                                  
  -----------------------------------------------------------------------

### 6.3 Grants, roles, and revocation

  -------------------------------------------------------------------------
  Test ID           Scenario            Expected result   Rules
  ----------------- ------------------- ----------------- -----------------
  `AT-GRANT-001`    Caregiver has       Read allowed      AUTH-004
                    read-measurements   within scope      
                    grant for Profile                     
                    A1                                    

  `AT-GRANT-002`    Same caregiver      Denied            AUTH-005
                    tries to read                         
                    medication plan                       
                    without that grant                    

  `AT-GRANT-003`    Grant has expired   Denied            AUTH-007

  `AT-GRANT-004`    Grant was revoked   Denied            AUTH-007

  `AT-GRANT-005`    Grantor attempts to Denied            AUTH-005
                    delegate a                            
                    permission they do                    
                    not possess                           

  `AT-GRANT-006`    Workspace Admin     Membership        AUTH-005
                    invites a member    created; no       
                                        profile access    
                                        granted           

  `AT-GRANT-007`    Workspace           Subsequent        AUTH-003,
                    membership is       requests and      AUTH-007
                    removed             relevant queued   
                                        work are denied   

  `AT-GRANT-008`    Client widens a     Server            AUTH-012
                    grant scope in      rejects/ignores   
                    request payload     unapproved scope  
  -------------------------------------------------------------------------

### 6.4 Consent and purpose limitation

  ------------------------------------------------------------------------
  Test ID            Scenario          Expected result   Rules
  ------------------ ----------------- ----------------- -----------------
  `AT-CONSENT-001`   Actor has read    AI context        AUTH-008
                     permission but no assembly denied   
                     valid AI-use                        
                     basis                               

  `AT-CONSENT-002`   Consent is        New processing    AUTH-008
                     withdrawn and no  relying on that   
                     other valid basis consent is denied 
                     applies                             

  `AT-CONSENT-003`   Consent covers    Denied            AUTH-008
                     purpose A,                          
                     request uses                        
                     purpose B                           

  `AT-CONSENT-004`   Unauthorized      Denied; no        AUTH-012
                     caregiver         consent record    
                     attempts to       created           
                     record consent                      
                     for owner                           

  `AT-CONSENT-005`   Consent           Job re-checks and AUTH-008
                     withdrawal occurs does not proceed  
                     while an AI job   where no valid    
                     is queued         basis remains     

  `AT-CONSENT-006`   Historical        Historical        AUTH-010
                     consent is        evidence remains  
                     withdrawn         according to      
                                       retention policy  
  ------------------------------------------------------------------------

Consent tests must model the product's legally approved rules. Do not
assume consent is the only possible legal basis, or that withdrawal
always requires erasing prior lawful processing.

### 6.5 Health data actions

  -----------------------------------------------------------------------------
  Test ID           Scenario             Expected result      Rules
  ----------------- -------------------- -------------------- -----------------
  `AT-HEALTH-001`   Caregiver has        Measurement created  AUTH-004
                    create-measurement   with correct profile 
                    grant                and author           

  `AT-HEALTH-002`   Caregiver has        Denied; original     AUTH-005
                    read-only grant and  data unchanged       
                    attempts correction                       

  `AT-HEALTH-003`   Measurement request  Denied               AUTH-004
                    uses a profile ID                         
                    outside grant scope                       

  `AT-HEALTH-004`   User attempts to     Protected fields are AUTH-012
                    mass-assign          server-controlled;   
                    `created_by` or      request denied or    
                    `profile_id`         safely ignored       

  `AT-HEALTH-005`   Correction is        Correction           AUTH-010
                    authorized           history/provenance   
                                         preserved            

  `AT-HEALTH-006`   Unauthorized delete  Denied; record and   AUTH-001
                    attempt              history remain       
  -----------------------------------------------------------------------------

### 6.6 Care coordination

  -------------------------------------------------------------------------
  Test ID           Scenario          Expected result     Rules
  ----------------- ----------------- ------------------- -----------------
  `AT-CARE-001`     Caregiver reads a Allowed with        AUTH-006
                    task assigned to  minimum necessary   
                    them              fields              

  `AT-CARE-002`     Task assignee     Denied              AUTH-006
                    reads the full                        
                    health profile                        
                    without a grant                       

  `AT-CARE-003`     Non-assignee      Denied unless a     AUTH-005
                    completes a task  separate permission 
                                      applies             

  `AT-CARE-004`     Task transition   Domain transition   AUTH-005
                    skips a required  rejected            
                    state                                 

  `AT-CARE-005`     Task is           New assignee gets   AUTH-006,
                    reassigned to     task scope only;    AUTH-007
                    another caregiver old assignee loses  
                                      it as policy        
                                      specifies           

  `AT-CARE-006`     Task evidence     Denied; no          AUTH-004
                    belongs to a      attachment leak     
                    different                             
                    profile/task                          

  `AT-CARE-007`     Task completion   Validation/domain   AUTH-005
                    requires          rule rejects        
                    result/evidence   completion          
                    but fields are                        
                    absent                                
  -------------------------------------------------------------------------

### 6.7 Documents, reports, and exports

  -----------------------------------------------------------------------
  Test ID           Scenario          Expected result   Rules
  ----------------- ----------------- ----------------- -----------------
  `AT-EXPORT-001`   Actor can read a  Download denied   AUTH-009
                    document but                        
                    lacks export                        
                    permission                          

  `AT-EXPORT-002`   Actor requests    Request denied;   AUTH-003,
                    export of a       no job enqueued   AUTH-009
                    profile outside                     
                    scope                               

  `AT-EXPORT-003`   Access revoked    Job re-authorizes AUTH-007,
                    after export job  and stops if      AUTH-009
                    is queued but     access is no      
                    before generation longer valid      

  `AT-EXPORT-004`   Access revoked    Download denied   AUTH-007,
                    after generation  or file           AUTH-009
                    but before        invalidated       
                    download          according to      
                                      policy            

  `AT-EXPORT-005`   Signed download   Download denied   AUTH-009
                    URL has expired                     

  `AT-EXPORT-006`   Report combines   Every source is   AUTH-004,
                    multiple sources  authorized;       AUTH-009
                                      unauthorized      
                                      source is         
                                      excluded or       
                                      entire report     
                                      denied according  
                                      to defined policy 

  `AT-EXPORT-007`   Export file is    Private storage,  AUTH-009,
                    stored            ownership         AUTH-010
                                      binding, expiry   
                                      and audit         
                                      verified          
  -----------------------------------------------------------------------

### 6.8 AI context and integrations

  -----------------------------------------------------------------------
  Test ID           Scenario          Expected result   Rules
  ----------------- ----------------- ----------------- -----------------
  `AT-AI-001`       User asks AI to   No unauthorized   AUTH-008
                    summarize an      data reaches      
                    unauthorized      provider          
                    profile                             

  `AT-AI-002`       User can read     Context gateway   AUTH-008
                    measurements but  denies AI data    
                    AI purpose is not use               
                    authorized                          

  `AT-AI-003`       Context builder   Only explicitly   AUTH-003,
                    receives records  authorized        AUTH-008
                    from multiple     profile data      
                    profiles          included          

  `AT-AI-004`       Consent/grant     Re-check before   AUTH-007,
                    changes between   dispatch; deny if AUTH-008
                    request           no valid basis    
                    validation and    remains           
                    context dispatch                    

  `AT-AI-005`       External          Reject unless     AUTH-003,
                    integration       integration has   AUTH-012
                    submits data for  explicit scope    
                    another profile                     

  `AT-AI-006`       AI conversation   Denied unless     AUTH-004
                    is read by        conversation      
                    another workspace sharing is        
                    member            explicitly        
                                      granted           
  -----------------------------------------------------------------------

### 6.9 Platform roles, audit, and emergency access

  ---------------------------------------------------------------------------
  Test ID           Scenario              Expected result   Rules
  ----------------- --------------------- ----------------- -----------------
  `AT-PLAT-001`     Platform Admin tries  Denied            AUTH-011
                    to read health data                     
                    via ordinary admin                      
                    API                                     

  `AT-PLAT-002`     Support performs an   Only permitted    AUTH-010,
                    approved              account fields    AUTH-011
                    account-support       changed; audited  
                    action                                  

  `AT-AUDIT-001`    Authorized sensitive  Required audit    AUTH-010
                    operation occurs      event emitted     
                                          with actor,       
                                          action, target,   
                                          time, outcome,    
                                          correlation ID    

  `AT-AUDIT-002`    Unauthorized          Denial event      AUTH-010
                    operation is denied   logged where      
                                          policy requires;  
                                          no health content 
                                          leaked into logs  

  `AT-AUDIT-003`    Ordinary user         Denied            AUTH-010
                    attempts to                             
                    modify/delete audit                     
                    event                                   

  `AT-BG-001`       Break-glass request   Access denied     AUTH-001
                    has no approved                         
                    reason/scope/expiry                     

  `AT-BG-002`       Break-glass grant     Subsequent access AUTH-007,
                    expires               denied            AUTH-010

  `AT-BG-003`       Emergency access is   Alert, audit, and AUTH-010
                    used                  retrospective     
                                          review task       
                                          generated         
  ---------------------------------------------------------------------------

### 6.10 Notification preferences and self-audit (v1.1.0)

| Test ID | Scenario | Expected result | Rules |
|---|---|---|---|
| `AT-NOTIF-001` | A member reads their own notification preferences | Allowed; returns only the actor's own preferences | AUTH-002 |
| `AT-NOTIF-002` | Member A reads member B's notification preferences | Denied with the policy-approved non-disclosing response | AUTH-003, AUTH-004 |
| `AT-NOTIF-003` | A member disables a notification channel | Preference changes and **no** consent record is created, amended, or withdrawn | ABD-008, ABD-016 |
| `AT-NOTIF-004` | A member reads their own delivery status while a delivery has failed | Returns channel, status, attempts, timestamps and error code only — no message body or health content | AUTH-010 |
| `AT-AUDIT-004` | A profile owner reads `audit_event.read_own` | Returns only events where the actor is the subject, or which concern a profile the actor owns or represents | ABD-016, AUTH-010 |
| `AT-AUDIT-005` | A caregiver without ownership attempts `audit_event.read_own` | Denied; no audit content returned | AUTH-001, AUTH-004 |

These cases map to Matrix E (`DATA_ACCESS_MATRIX.md` §8) and to the permissions added in `PERMISSION_CATALOG.md` §4.11.

## 7. Test implementation strategy

### Unit tests

Test pure policy/domain rules: - permission evaluation; - scope
containment; - grant expiry/revocation; - delegation cannot exceed
grantor authority; - care-task state transitions; - purpose/consent
decision logic.

### Feature tests

Exercise actual HTTP requests through Laravel authentication,
middleware, policies, validation, database queries, and response
serialization.

### Integration tests

Test database isolation, queue workers, export storage, notification
delivery, and AI context dispatch. Re-check authorization at
asynchronous boundaries.

### Security tests

Test IDOR, mass assignment, route/resource mismatch, cross-tenant joins,
search/count leakage, cached response leakage, and unauthorized file
access.

## 8. Suggested Laravel test layout

``` text
tests/
├── Unit/
│   └── Domain/
│       ├── Authorization/
│       │   ├── PermissionEvaluatorTest.php
│       │   ├── ScopeContainmentTest.php
│       │   ├── AccessGrantTest.php
│       │   └── PurposePolicyTest.php
│       └── CareCoordination/
│           └── CareTaskTransitionTest.php
├── Feature/
│   └── Authorization/
│       ├── AuthenticationTest.php
│       ├── WorkspaceIsolationTest.php
│       ├── HealthProfileAccessTest.php
│       ├── MeasurementAuthorizationTest.php
│       ├── MedicationAuthorizationTest.php
│       ├── CareTaskAuthorizationTest.php
│       ├── GrantRevocationTest.php
│       ├── ConsentPurposeTest.php
│       ├── AIContextAuthorizationTest.php
│       ├── ExportAuthorizationTest.php
│       └── AuditAuthorizationTest.php
└── Security/
    ├── IDORTest.php
    ├── MassAssignmentTest.php
    ├── CrossTenantAccessTest.php
    └── FileAccessTest.php
```

## 9. CI and release gates

In addition to the gates below, maintain a machine-readable permission-to-test mapping (for example `authorization-traceability.csv` or a test manifest). The mapping is an implementation artifact and must be reviewed whenever the catalog changes.



Minimum gates: - all authorization unit and feature tests pass; - every
permission in the catalog is referenced by at least one test or
explicitly marked not yet implemented; - every protected endpoint
declares its required permission and scope; - no known
cross-workspace/profile isolation test fails; - static analysis and code
style checks pass; - test failures cannot be bypassed by silently
skipping authorization tests; - any new permission or endpoint updates
the matrix and tests in the same change.

Coverage targets should focus on permission decisions and negative
paths, not only line coverage. A high line-coverage percentage does not
prove access control is correct.

## 10. Additional mandatory race/cache tests

- `AT-RACE-001`: grant is revoked between authorization check and a sensitive write; implementation must use a transaction/locking/version strategy so the operation cannot succeed using stale authority.
- `AT-RACE-002`: grant is revoked after a job is queued but before it runs; job re-checks and does not process unauthorized data.
- `AT-RACE-003`: grant is revoked after a file is generated but before download; delivery re-checks access or invalidates the file according to the approved revocation policy.
- `AT-CACHE-001`: an authorized user's cached response is not served to another user or workspace.
- `AT-CACHE-002`: access-sensitive cache is invalidated or version-mismatched after grant/member revocation.
- `AT-FAIL-001`: authorization dependency times out/errors; protected request fails closed and performs no side effect.
- `AT-FAIL-002`: audit/outbox handling fails for an operation that requires atomic audit; behavior follows the documented transaction/outbox policy and never silently performs an unaudited sensitive action.

## 11. Traceability

Every test should reference: - one or more Permission IDs; - one or more
Rule IDs; - the affected resource and scope; - the expected decision
(`ALLOW` or `DENY`); - the expected side effects (audit, state change,
job creation, or no side effect).

A release checklist should confirm that every catalog permission has a
defined matrix policy and appropriate tests.

**v1.1.0 mapping (`CR-001`):** `AT-NOTIF-001…003` cover `notification.preference.read` and
`notification.preference.update`; `AT-NOTIF-004` covers `notification.delivery.read`;
`AT-AUDIT-004…005` cover `audit_event.read_own`. Each of these must reference a Matrix E row,
the required scope (`self_membership` or `owned_profile_audit`), and the expected side effects.

## 12. Open decisions

Before production, product/legal/security owners must approve: -
guardian and representative rules; - consent/legal-basis behavior by
purpose; - data retention and deletion; - healthcare professional
access; - break-glass policy; - whether unauthorized resource requests
consistently return `403` or policy-approved `404`; - exact audit event
taxonomy and retention.
