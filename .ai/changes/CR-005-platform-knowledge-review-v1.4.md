# CR-005 · Baseline v1.4 — Platform Knowledge Review

| | |
|---|---|
| **Change Record ID** | `CR-005` |
| **Target baseline** | `AUTH-BASELINE-001` v1.3.0 → **v1.4.0** |
| **Date raised** | 2026-10-25 |
| **Status** | ✅ **APPLIED** — baseline bumped to **v1.4.0** on 2026-10-25 |
| **Approver** | Product owner (TASK CR-005 / D1) |
| **Related** | `ABD-001`, `ABD-002`, `ABD-010`, `ABD-015`, `ABD-019`, Matrix A/D |
| **Scope** | +2 platform permissions, +1 user flag (3 columns), +1 authorization entry point, +1 console command, +2 API routes, +1 frozen decision |
| **Excluded** | No new workspace role, no Matrix cell change, no tenant/health-data table change, no new Composer package |

> **Current status:** **in force** — `AUTHORIZATION_BASELINE.md`, `README.md`, `.ai/03-AUTHORIZATION.md`
> and `backend/config/authorization.php` were updated together and the baseline was bumped to
> **v1.4.0** under `ABD-015`.
> **Consequence:** a clinician can now review a knowledge source through the product, but only if the
> user row carries `is_platform_admin = true`. A workspace owner, workspace admin, or a workspace role
> literally named `platform_admin` gets `403 forbidden` (ABD-019).
> **The `docs/baseline-v1.0.0/` directory keeps its historical name** so existing links do not break;
> its content is v1.4.0.

---

## 1. Summary

| | Before (v1.3.0) | After (v1.4.0) |
|---|---|---|
| Permission count | 99 | **101** |
| Resource scopes | existing set | **unchanged** |
| Frozen decision | `ABD-001`...`ABD-018` | + **`ABD-019`** |
| Roles | 8 | 8 (**unchanged**) |
| Matrix cells | A–E | **unchanged** |
| Platform admin source | none | `users.is_platform_admin` + grant metadata |

**No existing permission ID, role, Matrix cell, or test contract was renamed, removed, or weakened.**
The only test count change is the catalog literal (99 → 101).

---

## 2. Context

The health knowledge corpus is **platform data**: public health documents stored as JSON under
`backend/database/knowledge/sources`, with no `tenant_id`, no `workspace_id`, and no RLS. It is built
into a read-only SQLite FTS5 file for retrieval.

Every permission in the system was workspace-scoped: `Authorizer` needs a workspace and a profile
membership, and `RoleGate` derives authority from a workspace role. That made the corpus unreviewable
through the product. The only path was:

```bash
php artisan knowledge:review ddc-disease-44 --reviewer="..." --build
```

which requires shell access. All 16 sources were `unreviewed`, so every answer carried the "not yet
reviewed" warning. The corpus is public reference material, but leaving it unreviewed indefinitely
undermines the product's own provenance claim.

This record adds the smallest honest mechanism: an explicit **platform-administrator flag** on the
user row, two platform permission IDs, and an API that lets that person review a source. It does not
give platform staff any standing health-data access (ABD-001, ABD-002, ABD-011).

---

## 3. Delta · Permissions

Exactly two permission IDs were added; nothing else was invented.

| Permission ID | Meaning | Scope | Action | Sensitive |
|---|---|---|---|---|
| `knowledge.source.read` | List the corpus and see review status | platform (no workspace) | `read` | Low — public reference data |
| `knowledge.source.review` | Mark a source reviewed and rebuild the corpus | platform (no workspace) | `update` | Medium — changes provenance |

Both are listed in `docs/baseline-v1.0.0/PERMISSION_CATALOG.md` §4.10 and in
`backend/config/authorization.php`.

### Why a platform capability is not a workspace role

- `workspace_memberships.role` is stored per workspace and describes authority **inside that
  workspace**. The corpus exists outside every workspace, so no membership role can describe it
  without inventing a fake workspace.
- Reusing `workspace.member.*` (or any tenant role) would either widen a tenant-scoped role into
  platform data or force the platform admin to become a member of every workspace — both violate
  ABD-001 and N-01.
- The role named `platform_admin` is itself a workspace membership role in the frozen Matrix. It is
  not a global switch and must not become one. `RoleGate` now returns `false` for the two platform
  permissions for every role, including `platform_admin`.
- The explicit flag is revocable, auditable and independent of health-data grants. It is the same
  shape as the existing `workspace_admin` "ownership flag" (a property, not a new role).

`Authorizer::authorizePlatform()` is a **separate entry point**. The workspace path (`authorize()`)
refuses the two IDs with `403 forbidden` even when the actor is a workspace owner. This is deliberate:
adding a flag must not weaken the workspace pipeline or create a second way to reach health data.

---

## 4. Delta · Platform administrator flag

`users` gains:

| Column | Type | Purpose |
|---|---|---|
| `is_platform_admin` | boolean, default `false` | The only grant for platform capabilities |
| `platform_admin_granted_at` | timestamptz, nullable | When the current grant was made |
| `platform_admin_granted_by` | bigint FK → `users.id`, nullable | Who granted it; `null` for the `system` CLI actor |

The migration is additive and has a working `down()` (drops the index, the self-referencing FK, and
the three columns).

### Console command

```bash
php artisan platform:grant {email}
php artisan platform:grant {email} --revoke
```

- The CLI actor is `system`: `security_audit_logs.actor_type = 'system'`, `actor_id = null`.
- The command writes exactly **one** audit row per invocation for an existing target, containing the
  target user id and the new value.
- It refuses to revoke the last remaining platform administrator and audits the refusal as
  `result = deny`, `reason_code = last_platform_admin`. An installation cannot lock itself out.

---

## 5. Delta · API

| Method | Path | Permission |
|---|---|---|
| `GET` | `/api/v1/admin/knowledge/sources` | `knowledge.source.read` |
| `POST` | `/api/v1/admin/knowledge/sources/{id}/review` | `knowledge.source.review` |

- **No `{workspace}` in the path and no `workspace.context` middleware.** Authentication is
  `auth:sanctum`; authorization is a new `authorize.platform` middleware that calls
  `Authorizer::authorizePlatform()`.
- `GET` supports `?status=unreviewed|reviewed` and `?q=` over title/publisher. It returns `id`,
  `title`, `publisher`, `url`, `category`, `language`, `fetched_at`, `review_status`, `reviewed_by`,
  `reviewed_at`, and `stale` (the file's `fetched_at` is older than 180 days).
- `POST` takes `{reviewer: string, build: bool = true}`. `reviewer` is required with a minimum of 3
  characters. It reuses `KnowledgeSourceService` — the same code path as `knowledge:review` — so the
  console and the API cannot drift. It rewrites the source JSON, optionally rebuilds the SQLite corpus,
  and returns the updated summary.
- Each call writes exactly one `security_audit_logs` row through `authorizePlatform()`. The review row
  carries `source_id` and `reviewer` in metadata; neither row ever carries source text (N-02).
- Responses: `401 unauthenticated`, `403 forbidden` for a non-platform-admin (including a workspace
  owner), `404 out_of_scope` for an unknown source, `422 validation` for a short reviewer.

---

## 6. Security and privacy impact

| Question | Assessment | Control |
|---|---|---|
| Does the flag grant health-data access? | **No.** `authorizePlatform()` only accepts the two platform IDs. | Closed `PLATFORM_PERMISSIONS` list; `authorize()` refuses them. |
| Can a workspace owner review sources? | **No.** Workspace ownership is not platform authority. | `RoleGate` returns false; `authorize()` returns 403 before membership. |
| Can a role named `platform_admin` review sources? | **No.** It is a workspace role, not the user flag. | `evaluatePlatform()` reads only `users.is_platform_admin`. |
| Does a platform admin bypass workspace authorization? | **No.** `authorize()` still requires membership, role gate, grant and consent. | PLT-T08. |
| Does the corpus contain PHI? | **No.** It is public government reference content and has no tenant/profile identity. | Separate SQLite store; no health table read. |
| Could source text leak into logs? | **No.** Audit metadata is limited to `scope`, `source_id`, `reviewer`. | `SecurityAuditLogger` PHI-key guard + PLT-T06. |
| Could an installation lock itself out? | **No.** Revoking the last admin is refused. | `platform:grant --revoke` + PLT-T07. |
| Is the flag change auditable? | **Yes.** One row per invocation, actor `system`, target id and new value. | `SecurityAuditLogger`. |
| Revocation effect? | The next API call fails closed with `403`; there is no cached platform grant. | Flag read on every decision. |

**Summary:** Medium risk to provenance integrity, low privacy risk. The change is additive, does not
widen any existing permission, and does not create a path to a health record.

---

## 7. Implementation impact

| Area | Change |
|---|---|
| `backend/config/authorization.php` | Added the two platform IDs (101 total). |
| `backend/app/Modules/Authz/Authorizer.php` | `PLATFORM_PERMISSIONS`, `authorizePlatform()`, `isPlatformPermission()`, workspace-path refusal, one audit row per platform decision. |
| `backend/app/Modules/Authz/RoleGate.php` | Platform IDs return false for every workspace role. |
| `backend/app/Models/User.php` | New fillable/cast fields and `isPlatformAdmin()`. |
| `backend/database/migrations/2026_10_25_110000_add_platform_admin_to_users_table.php` | Three columns + FK + index, working `down()`. |
| `backend/app/Console/Commands/PlatformGrantCommand.php` | `platform:grant {email} {--revoke}`, last-admin guard, one audit row. |
| `backend/app/Console/Commands/KnowledgeReviewCommand.php` | Delegates to `KnowledgeSourceService` (same path as the API). |
| `backend/app/Modules/AiGateway/Knowledge/Application/KnowledgeSourceService.php` | Shared list/review/validate/rebuild logic. |
| `backend/app/Modules/AiGateway/Knowledge/Http/Controllers/KnowledgeSourceAdminController.php` | Two admin endpoints. |
| `backend/app/Http/Middleware/EnsurePlatformAuthorized.php` | `authorize.platform` declaration layer. |
| `backend/routes/api.php`, `backend/bootstrap/app.php` | Two routes + middleware alias. |
| `docs/baseline-v1.0.0/*` | Catalog, baseline log (`ABD-019`), README, version history. |
| `.ai/03-AUTHORIZATION.md`, `.ai/README.md` | Platform-capability note, baseline version/count, change-record index. |
| Tests | `PlatformAdminTest` `PLT-T01…T09`; catalog literal 99 → 101; route coverage recognises the platform middleware. |

### Existing test adjustments

- `PermissionCatalogTest`: the literal count moves 99 → 101 (required by the task).
- `AuthorizerTest::test_auth_005…`: the literal count also moves 99 → 101. This was not named in the
  task but is the same drift guard and would otherwise fail.
- `RoutePermissionCoverageTest`: `EnsurePlatformAuthorized` is added to the list of valid authorizing
  middleware classes. Without this, AP-001 would classify the two new routes as unguarded.

---

## 8. Migration notes

- **Database migration:** additive only. Existing users default to `is_platform_admin = false`, so
  nobody gains anything on deployment.
- **Bootstrap:** an installation with no platform admin runs `php artisan platform:grant <email>` once.
  There is no automatic promotion and no seed change.
- **No data rewrite:** existing knowledge sources keep their current `review_status`. They become
  reviewable, not reviewed. The "not yet reviewed" warning remains until a human reviews them.
- **Rollback:** drop the three columns (the migration `down()`), remove the two config IDs and routes,
  and restore the command to its previous inline implementation. No tenant/health data is touched.
- **Operational consequence:** the SQLite corpus must be writable by the API process for the `build`
  option. A read-only deployment can call the API with `build: false` and run
  `php artisan knowledge:build` separately.

---

## 9. Rejected alternatives

| Alternative | Why rejected |
|---|---|
| Reuse `workspace.member.*` | Those permissions describe workspace membership, are tenant-scoped, and would either widen a tenant role into platform data or require the reviewer to join every workspace. They also say nothing about a public corpus. |
| Add a workspace-level "ops" role | A new role needs Matrix cells, grant wiring and tests, and still cannot reach platform data without a fake workspace. It would also collide with the existing `platform_admin` membership role. |
| Keep the CLI only | The task's premise is that a clinician should not need shell access. It also means the review is unauditable through the product and impossible to delegate safely. |
| Reuse `platform.configuration.manage` | It is a workspace/platform configuration permission, not a knowledge-corpus action. Reusing it would silently widen an existing ID and break the "read ≠ review ≠ rebuild" separation. |
| Give the flag all `platform.*` permissions | The flag would then grant configuration and support powers that were never reviewed for this change. The closed two-ID list is the least-privilege shape. |
| Put the flag on a new table | A single boolean plus grant metadata does not justify a new aggregate; the user row is the actor identity the flag belongs to. |
| Let `authorize()` handle platform IDs with a null scope | The workspace pipeline requires a workspace and a membership; a null scope would still be a workspace decision and would let ownership/role logic leak in. A separate entry point is explicit and testable. |

---

## 10. Frozen decision

| Decision ID | Frozen decision | Rationale |
|---|---|---|
| `ABD-019` | Platform capabilities (`knowledge.source.read`, `knowledge.source.review`) are granted by an explicit user flag (`users.is_platform_admin`), are **never** derived from a workspace role or workspace ownership, and every grant, revocation and use is audited. | Workspace roles are tenant/workspace-scoped, while the knowledge corpus is platform data with no workspace. Reusing a workspace role would either widen a tenant role into platform data or require a fake workspace; an explicit flag keeps the capability revocable, auditable and outside the health-data model. |

---

## 11. Follow-ups (not in this change)

1. **UI.** The platform-admin review screen (list, filter, review dialog, build progress) is not part
   of this API slice.
2. **Reviewer identity model.** `reviewer` is currently free text chosen by the platform admin. A
   future change should bind it to a verifiable reviewer identity (user id + credential/profession
   metadata) so a review can be attributed without trust in free text.
3. **Scheduled staleness report.** `stale` is exposed per source; an alert when the stale count grows
   is a separate operations task.
4. **Review history.** This change stores only the latest review. An append-only review history would
   preserve who changed a status and when.

---

## 12. Compatibility note for clients

- **New surface only.** No existing payload, route, role or grant changed.
- Non-platform clients never see the two routes; a call returns `403 forbidden`.
- A deployment that does not want the API can leave the flag unset; the `knowledge:review` command
  continues to work exactly as before.
- The API returns the source summary (not the full `text`) so the response shape matches the list and
  cannot become an accidental bulk export channel.

---

## 13. Approval record

| Date | Decision maker | Decision | Reference |
|---|---|---|---|
| 2026-10-25 | Product owner | Approve the two platform permission IDs and the `is_platform_admin` flag | TASK CR-005 / D1 |
| 2026-10-25 | Engineering | **APPLIED** — baseline documents, config, authorizer, migration, command, routes and tests updated together; v1.4.0 | §7 |
