# CareCrm

Care coordination: care plans, tasks, assignments, events, evidence, appointments, and cases.

## Recurring tasks (P2.1)

`care_task_templates` holds a schedule; `care_tasks` holds the materialised
occurrences. They are separate aggregates.

**Scheduling rules**

- Frequencies: `daily`, `weekly`, `monthly`, repeated every `interval`
  (1–30). `starts_on` anchors the sequence, not the current window.
- `weekly` uses ISO weekdays 1 (Monday) … 7 (Sunday) and a week-anchored
  interval; `monthly` keeps the start date's day of month and skips months
  without that day (the 31st is never clamped).
- `times_of_day` is one to six local `HH:MM` slots per occurrence day. The
  schedule is walked in the template `timezone`, so a DST change keeps the
  local wall clock (`RC-004`).
- Materialisation window:
  `max(starts_on, today) … min(ends_on, today + daysAhead)`, both inclusive.
  A slot already in the past is never created; today's future slots are.
- `occurrence_key` is `Y-m-d\TH:i` in the template timezone. The unique
  `(template_id, occurrence_key)` index makes a second or concurrent run
  idempotent (`RC-001`, `RC-002`).

**Exceptions**

- `skip` suppresses every slot of that local date.
- `reschedule` creates one occurrence at `rescheduled_to` instead of the
  scheduled slots. Exceptions are unique per `(template_id, occurrence_date)`;
  the API upserts them.

**Assignment**

- `fixed` → `assigned_user_id`.
- `rotating` → active `caregiver` members ordered by `workspace_memberships.id`;
  the cursor is the number of occurrences already materialised for the
  template, so the first goes to the lowest membership id and re-running never
  reshuffles existing assignments. If there is no caregiver, the occurrence is
  created `open`.
- `unassigned` → `open`.

A `routine` occurrence is always `scheduled` (the state machine has no
`open`/`assigned` state for routine tasks); the open/assigned split above
applies to the `delegated` and `clinical` tiers.

**Editing a template**

A `PATCH` (or the exception endpoint) only changes future runs. Occurrences
already materialised are independent rows and are never rewritten or deleted
(`RC-003`, `CRM-021`). Deactivating sets `is_active = false`: it stops future
materialisation and leaves existing tasks untouched.

**Commands**

`php artisan tasks:materialise {--days=14} {--dry-run}` runs daily at 00:15
Asia/Bangkok via `routes/console.php`.

## Escalation ladder (P2.2)

An overdue `delegated` or `clinical` task climbs a per-workspace ladder. The
ladder is a workspace setting (`escalation_policies`), never hardcoded per task
(ES-001). A workspace with no active policy escalates nothing (fail-safe).

**Rules implemented**

- Target level = the highest active policy whose `after_minutes` has elapsed
  since `due_at`; the task jumps straight there in one event, it does not fire
  every rung. `task.escalation_level >= target` is a no-op (idempotent).
- Only non-terminal `delegated` / `clinical` tasks with a `due_at` in the past
  are candidates. `routine` tasks and tasks without `due_at` never escalate.
- A `blocked` task whose `review_at` is still in the future is skipped: someone
  is already working on it.
- Each climb writes one `task_events` row (`event = escalated`, metadata
  `{from, to}`) and the matching business audit row (`care_task.escalate`).
- `reassign_to_admin` releases the previous primary and creates a new primary
  for the first active `workspace_admin`, through
  `CareTaskTransitionService::reassignBySystem()` so the responsibility history
  and audit trail are real (CT-007). The new assignment id is recorded in the
  same escalation event metadata.
- `task.escalated` is dispatched with `{escalation_level, due_at}` only —
  no title, no person name, no health value (ES-002).
- `php artisan tasks:escalate {--dry-run} {--limit=200}` runs every 15 minutes
  (`withoutOverlapping()`); `--dry-run` reports candidates and writes nothing.

**Permission decision**

The frozen catalog (`docs/baseline-v1.0.0/PERMISSION_CATALOG.md`) has no
escalation-specific permission id, and this change does not invent one. Policy
management is a workspace setting: `GET` uses `workspace.read`, `PUT` and
`POST /defaults` use `workspace.update`. Running the ladder is a scheduled
system job with no acting user and therefore no permission decision; it is
gated by the scheduler and its own workspace context, not by a role. If a
dedicated id is ever approved, it belongs in `config/authorization.php` and the
baseline first — not in this module.