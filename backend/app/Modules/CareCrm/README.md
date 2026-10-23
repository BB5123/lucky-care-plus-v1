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