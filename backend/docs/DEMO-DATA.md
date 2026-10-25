# Demo data (`php artisan demo:seed`)

`demo:seed` creates one realistic, obviously synthetic family so an operator can
show the product and a new installation is not empty. It is the companion to
`backend/scripts/smoke.sh`: the command fills the database, the script proves a
running deployment answers the core endpoints.

> **Synthetic data only.** Every person, address, phone number and clinical value
> is fictitious. The workspace is marked `[DEMO]` and every account is at
> `example.test`. Never load this workspace into a deployment that holds real
> records.

## What it creates

| Item | Volume / detail |
|---|---|
| Tenant + workspace | One tenant, one workspace marked `settings.demo_seed = true` and named `[DEMO] ครอบครัวทดสอบ (คุณยาย)` |
| Members | Owner (`profile_owner`), caregiver (`caregiver`), viewer (`viewer`) |
| People | Four `persons` rows: the three caregivers and the elder `คุณยาย ทดสอบ` (born 1948) |
| Health profile | One elder profile managed by the owner person |
| Vitals | 180 readings over ~180 days: 90 blood pressure + 90 blood glucose, with a visible improving trend |
| Conditions | Hypertension (`I10`) and type 2 diabetes (`E11`) |
| Allergy | Penicillin, severe reaction (`ผื่นลมพิษ`) |
| Medication | 3 plans (Amlodipine, Metformin, Atorvastatin) and 200 reported dose records; adherence improves over time |
| Appointments | 12: 2 upcoming, 10 in the past (attended / cancelled / missed) |
| Care tasks | 25 in mixed states (scheduled, open, assigned, accepted, in progress, blocked, completed, cancelled, verified) with 52 real `task_events` |
| Documents | 3 metadata-only rows (lab result, prescription, discharge summary); **no object is written to storage** |
| Access grants | 3 (owner full, caregiver narrow, viewer profile basics) |
| Consent | `health_processing` and `data_export` granted; `ai_processing` withdrawn (AI is off) |

The rows are written through the domain services where the service supports the
state, so the business audit trail and the timeline projection are real. The
timeline is projected after commit and normally contains ~450 entries.

### Authorization model on display

The elder is a dependent with no user account of her own. The owner is the
manager, not the profile owner, so **every** member reads through an access
grant. This makes the demo useful for authorization walkthroughs:

| Account | Role | Scopes |
|---|---|---|
| `demo.owner@example.test` | `profile_owner` | `profile.basic`, `vitals`, `medications`, `tasks`, `appointments`, `documents`, `timeline`, `task_evidence` |
| `demo.caregiver@example.test` | `caregiver` | `profile.basic`, `vitals`, `medications`, `tasks` |
| `demo.viewer@example.test` | `viewer` | `profile.basic` (read only) |

`mental_health` and `lab_results` are reserved and disabled in the MVP
(ADR-014); the caregiver grant deliberately does not contain either token. The
viewer can see the profile basics but is denied `workspace.member.read`, which
the smoke script uses as its negative check.

## Login steps

The command prints the exact values. The demo password is a **demo credential,
not a secret**:

```
owner      demo.owner@example.test      / DemoPass!2026
caregiver  demo.caregiver@example.test  / DemoPass!2026
viewer     demo.viewer@example.test     / DemoPass!2026
```

Password login is the supported path for this demo:

```bash
curl -sS -X POST http://localhost:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"demo.owner@example.test","password":"DemoPass!2026"}'
```

The response contains a Sanctum bearer token; `GET /api/v1/me` then returns the
user and the workspace list. In a **non-production** environment with
`OTP_DEMO_MODE=true`, the OTP request
endpoint also returns the six-digit code in `data.demo_code`. The OTP endpoints
require an existing bearer token, so the smoke script logs in with the password
first and then exercises the OTP round-trip with that token. With
`OTP_DEMO_MODE=false` (the safe default) the API does not expose the code and
the smoke script reports the verify step as skipped instead of guessing it.

## Commands

```bash
# Create (or reuse) the demo workspace and print the summary + login steps.
php artisan demo:seed

# Remove the mutable demo rows and seed them again. Asks for confirmation.
php artisan demo:seed --reset

# The same, for automation (CI, compose exec, a script).
php artisan demo:seed --reset --force

# Inside the reference stack:
docker compose --profile app exec -T app php artisan demo:seed --reset --force
```

Only `--profile=elder` is implemented. An unknown profile fails with a clear
message instead of silently seeding a different persona.

### Idempotency

The first run creates the workspace and stores the demo marker. A second run
finds the marker (inside the tenant context required by the `workspaces` RLS
policy) and only reports the existing counts; it inserts nothing. `--reset`
re-runs the same seed after deleting the mutable rows, so counts stay flat
instead of doubling.

The normal run uses a random `demo-<8hex>` tenant/workspace slug so it cannot
collide with a family workspace. `--reset` on an empty database creates the
fixed `demo-elder` slug/ids instead, which is convenient for automation.

### What `--reset` removes, and what it preserves

`--reset` removes only the **mutable** rows of the marked demo workspace:

- measurements, conditions, allergies, medication plans and records;
- appointments, care tasks, task assignments, task events and task evidence;
- timeline entries, medical document metadata, exports, AI metadata and
  notification deliveries;
- access grants (they are recreated with the current scopes).

It deliberately preserves the workspace, users, memberships, persons, and the
health profile, plus these append-only ledgers:

- `consent_records` - a new consent is a new row; withdrawal is the only update
  (DM-006);
- `audit_events` - business history is append-only (DM-009);
- `security_audit_logs` - the hash-chained security ledger is append-only
  (DM-A01..DM-A05, DM-R05).

Deleting those ledgers would require disabling database triggers. The demo
command does not do that. The consequence is intentional: `--reset` gives you a
clean demo story while keeping the audit/consent history that a real deployment
must never lose.

`task_events` and `notification_deliveries` are operational histories, not the
audit chain, so the reset uses the same narrow `app.retention_purge` opt-in that
the retention job uses for them.

## Safety guards

- `APP_ENV=production` is refused before any write. A production operator must
  use the real registration API, not a known-password demo account.
- `--reset` confirms unless `--force` is supplied.
- No external network calls are made.
- No real document files are written; the three document rows are metadata only,
  so a download attempt will not find an object.
- The data is deliberately fake: `example.test` addresses, `080000000x` phones,
  Thai names containing `ทดสอบ`, and the `[DEMO]` workspace name.
- The smoke script also refuses to continue if the signed-in workspace is not
  marked `[DEMO]`, so a wrong base URL or credential cannot silently smoke a
  real workspace.

## Smoke check

With the stack running (API on `http://localhost:8080`):

```bash
bash backend/scripts/smoke.sh --base-url=http://localhost:8080
bash backend/scripts/smoke.sh --base-url=http://localhost:8080 --verbose
```

The script checks `/up`, owner login + the dev OTP request, `/me`, workspace
detail, the profile picker, the dashboard, timeline, care tasks, appointments,
and a viewer denial on the owner-only member list. It prints one `PASS`/`FAIL`
line per step, stops at the first failure, and needs only `curl` and `php`
(no `jq`). It is safe to run repeatedly.

## Tests

`backend/tests/Feature/Demo/DemoSeedTest.php` covers:

| ID | Assertion |
|---|---|
| `DEMO-T01` | workspace, three members, one profile and the data volumes |
| `DEMO-T02` | a second run adds nothing |
| `DEMO-T03` | `--reset` removes only the demo workspace's rows |
| `DEMO-T04` | the command refuses `APP_ENV=production` |
| `DEMO-T05` | the caregiver grant has no `mental_health` / `lab_results` |

## Known deviations from the ticket text

1. **No `GET /api/v1/workspaces` collection route exists.** The workspace list
   is part of `GET /api/v1/me`; the smoke script checks that list and then
   proves `GET /api/v1/workspaces/{id}`. Adding a route was outside the allowed
   file set.
2. **OTP requires an existing token.** `POST /api/v1/auth/otp/request` and
   `/verify` are behind `auth:sanctum`, so they cannot be the first login step.
   The script uses the seeded password for the token, then performs the OTP
   round-trip. With `OTP_DEMO_MODE=false` it reports the verify as skipped.
3. **`--reset` preserves consent/audit ledgers.** They are append-only by
   design; deleting them would require disabling triggers.
