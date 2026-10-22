# Dashboard module

Read-only composition layer for `GET /api/v1/workspaces/{workspace}/dashboard`
(ADR-010). It owns no tables and has no write path.

- `Application/DashboardAssembler` builds the five zones of
  `.ai/MOBILE_DESIGN.md §4` from the owning modules' models.
- Every `(card, profile)` pair is authorized through
  `App\Modules\Authz\Authorizer::authorizeBatch` before its data is read.
- A hidden card is `{"visible":false,"reason":"<code>"}` with no other keys
  (AP-003/AP-005).
- The assembled payload is cached for 30 seconds under a key that includes the
  per-workspace `authz_version` (bumped by grant/consent mutations).
- `lab_results` and `mental_health` are never queried (ADR-014).