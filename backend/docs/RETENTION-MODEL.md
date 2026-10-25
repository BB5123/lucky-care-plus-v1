# Retention model — states, plan windows and the archive (D3 decision document)

**Status:** draft for owner sign-off · **Supersedes the numbers question in**
[`RETENTION-DECISION-PACK.md`](RETENTION-DECISION-PACK.md) **· Blocks:** turning `RETENTION_DRY_RUN` off, and the
wording of the privacy notice.

This document exists because the first version of D3 asked only "how many months". That is the easy half. The hard
half is *what happens to a family's health record when the subscription stops* - and the answer must not be
"deleted because the card was declined".

## 1. Principles

1. **The record belongs to the member, not to the plan.** A plan decides features, seats and how long we keep a
   copy afterwards - never whether the health history continues to exist while the member still wants it.
2. **Retention follows purpose, not payment.** PDPA §37 requires deletion when the purpose ends; "the subscription
   lapsed" is not a purpose ending, and it is not a lawful basis for destroying medical history.
3. **A lapse is not a decision to leave.** Most lapses are money, forgetfulness, or a death in the family. The
   system must treat the first two as reversible, and the third with extra care (§4).
4. **Nothing is deleted without a notice that was actually delivered**, except when the member asks for deletion.
5. **Audit outlives the data.** `security_audit_logs` rows stay forever (`R5`); they carry no health content.

## 2. The states

```
                     ┌──────────── member action ────────────┐
                     │                                       ▼
  active ──lapse──▶ read_only ──window──▶ archived ──window──▶ scheduled_deletion ──▶ deleted
     ▲                 │                    │                       │
     │                 │                    │                       └─ 30 / 7 / 1 day notices
     └── restore ──────┴────────────────────┘                         + export offered
        (payment, support request, or heir request)
```

| State | What the member can do | What the system does | Who can move it |
|---|---|---|---|
| `active` | everything | normal operation | member, support |
| `read_only` | read, export, pay to restore; **no writes** | no AI calls, no reminders, no sync jobs | automatic on lapse |
| `archived` | nothing except "restore" and "export"; data not loaded into the app | encrypted at rest, excluded from every job, access requires a revival action | automatic, after the read-only window |
| `scheduled_deletion` | export or delete now (both offered) | notices at 30/7/1 days | automatic |
| `deleted` | nothing (audit row remains) | rows removed physically | automatic, or member-requested immediately |

**Legal hold overrides every arrow.** An `active` legal hold pins the record in `read_only` forever
(`RET-T02`, already implemented).

## 3. Plan windows (the only place a plan is allowed to matter)

| Plan | `read_only` | `archived` | Total time before deletion |
|---|---|---|---|
| Free **with health data** | 30 days | 11 months | **12 months** |
| Free **without health data** | 30 days | 30 days | **60 days** |
| Basic | 90 days | 21 months | **24 months** |
| Pro | 90 days | 33 months | **36 months** |
| Premium | 90 days | 45 months | **48 months** |

The Free row exists twice on purpose: a trial account that never entered a health record has nothing to protect
and may disappear quickly, but the moment a family records a measurement, an allergy or a medication, the minimum
becomes 12 months. Deleting a record because the account is "only free" would be the single worst failure this
product could have.

**Export is free in every plan, at every state.** Deleting is free in every plan, at every state.

## 4. The cases that are not "lapse"

| # | Case | What happens | Retention |
|---|---|---|---|
| 1 | Voluntary non-renewal (money, or simply stops) | read_only, export offered on day 1 | plan window |
| 2 | **Patient died** | **No automatic countdown.** The record is held until an heir or the account owner acts; the heir may request a copy | 12 months after the last action, then the plan window |
| 3 | Abandoned (no login, no payment) | archive, but only after three delivered notices | plan window |
| 4 | Dispute / investigation | legal hold, no countdown | indefinite |
| 5 | Transfer to a new caregiver | **transfer, not delete** | immediate on success |
| 6 | Minor patient reaches adulthood | rights transfer, not deletion | on the 18th birthday + 1 notice |
| 7 | Free account, no health data | delete after 60 days | 60 days |
| 8 | Free account **with** health data | the 12-month minimum applies | 12 months |

Case 2 is the one that needs a human. A death is not a billing event, and an automated countdown that erases a
child's history because nobody logged in would be indefensible - so **case 2 is manual by design**, even though it
costs support time.

## 5. What the member chooses at the moment of lapse

The cancellation flow asks once, with the safe option preselected:

| Option | Default? | Consequence |
|---|---|---|
| "ส่งข้อมูลของฉันให้ก่อน" (export my data) | **yes, preselected** | nothing is lost; the archive clock still runs |
| "เก็บไว้ ... เผื่อกลับมา" | no | archive window as in §3 |
| "ลบทั้งหมดทันที" | no, requires typing the workspace name | immediate deletion, audit row kept |

## 6. The archive, concretely

If we keep data for up to four years, the storage cost is trivial (a family's records are kilobytes) - the real
cost is **exposure**. So the archive is not "the same data with a flag":

| Requirement | Reason |
|---|---|
| encrypted at rest, separate from the active store | it is unattended data, the easiest thing to lose |
| excluded from AI, reminders, sync, search, and every scheduled job | no processing means nothing to leak through a feature |
| readable only through an explicit "restore" action | prevents quiet reading of archived records |
| every access writes an audit row | answers "who looked at it" |
| an expiry date stored with the data, plus notices at 30/7/1 days | no surprise deletion |
| deleting the archive also removes it from the next backup cycle | the privacy notice must describe the ≤7-day backup lag |

## 7. What is implemented today (honest)

| Piece | State |
|---|---|
| Soft delete with `deleted_at` | **yes** - rehearsed end to end on 2026-10-25 (`RETENTION-TURN-ON.md`) |
| Audit row per purge batch | **yes** (`RET-T06`) |
| Legal hold | **yes** (`RET-T02`, `RET-T02b`) |
| Per-tenant override within bounds | **yes** (`RET-T03`) |
| Idempotent re-run | **yes** (`RET-T07`) |
| Export for the member | **yes** (data export, web + mobile) |
| **Hard delete / compaction** | **yes since 2026-10-25** - `retention:compact` physically removes rows soft-deleted past the grace period (28 days, R8), audited and rehearsed on the live stack (`RET-C01..C08`) |
| **The five states above** | **yes since 2026-10-25** - `workspaces.retention_state` + `WorkspaceLifecycle`; the state is enforced on every workspace route (`RET-S01..S08`, `RET-G01..G07`) |
| Lapse trigger | **manual, audited (2026-10-25)** - `retention:lapse` is the support entry point and `retention:advance` moves due accounts nightly. Billing (D5) does not exist yet, so nothing lapses automatically |
| **Notices (30/7/1 day)** | **yes since 2026-10-25 (P3a)** - `retention:notices` sends in-app + email at day 30/7/1, idempotent through the delivery ledger |
| **Export offer at lapse** | **yes since 2026-10-25 (P3b)** - `retention.export_offer` in-app when the account enters `read_only` |
| **UI banner for non-active states** | **yes since 2026-10-26** - the web and mobile shells read the state and show it with a link to the export card (`FE5-T10/T11`, `RET-M01..M04`); the API's 409 remains the authority |
| **Cancellation choice screen** | **no** - needs a permission ID ("cancel my account") that the baseline does not have; waits for an owner-approved change record |
| **Archive inventory** | **yes since 2026-10-26 (P4a)** - entering `archived` writes a hashed per-table inventory (`workspace_archive_manifests`), shown and re-verified by `retention:archive-manifest` (`RET-A01..A04`) |
| **Encrypted archive bundle** | **yes since 2026-10-26 (P4b-1)** - the archived rows are written as NDJSON, gzipped and encrypted with `APP_KEY` on `RETENTION_ARCHIVE_DISK`; `retention:archive-drill` verifies the file, its hash, decryptability and the per-table counts (`RET-B01..B04`) |
| **Restore data path + per-archive keys** | **no** - P4b-2; until then the final `scheduled_deletion -> deleted` arrow stays closed |

So until 2026-10-25 the product *hid* expired rows forever - the gap P1 closed. Rows are now removed from the
database, but they still live in backups for up to the backup window (the privacy notice says so).

## 8. Implementation, phased

| Phase | Work | Effort | Unblocks |
|---|---|---|---|
| **P1** | `retention:compact --older-than=<grace>` : physical delete of rows already soft-deleted, audit row, legal hold respected, idempotent - **built and signed 2026-10-25 (R8: grace 28 days)** | ~1 day | "we actually delete" |
| **P2** | `retention_state` column + the five states + the lapse trigger - **built 2026-10-25 (P2a; plan windows wait for D5)** | ~2 days | plan windows |
| **P3** | Notices (email + in-app), export push, the cancellation choice screen - **notices + export push built 2026-10-25 (P3a/P3b); the choice screen waits for a permission change record** | ~2 days | the notices and the choice |
| **P4** | Archive store + restore action + access audit - **P4a (hashed inventory) and P4b-1 (encrypted bundle + drill) built 2026-10-26; the restore data path and per-archive keys remain** | ~3 days | the long windows |

P1 is the honest minimum: without it, no wording of the privacy notice is true. **Do P1 before publishing the
notice**, then P2-P4 behind their own review.

### P1 implementation notes (as built, 2026-10-25)

`PurgeService::compactBatch(string $type, int $graceDays, int $limit = 500): array` returns
`['compacted' => n, 'held' => n, 'skipped' => ?string, 'evaluated' => n, 'dry_run' => bool]`; `compactAll()` fans a
batch out over every tenant/workspace, and `retention:compact {--type=} {--older-than=} {--limit=500} {--dry-run}`
drives it. The grace is the policy value `retention.compact_grace_days` (28 days, R8); `--older-than` exists for a
rehearsal or a one-off, and when it is omitted the command uses the policy. The scheduler runs it at 03:20 UTC,
after the 03:00 purge, so the same night's soft deletes can never be compacted by accident.

Three details differ from the sketch above, each for a reason:

- **Compaction is schema-driven, not `TYPE_SPECS`-driven.** `TYPE_SPECS` marks only `health_measurements` as
  `soft_deletes: true`, but eight of the tables behind those types carry `deleted_at` (health measurements, health
  conditions, allergies, medication plans, medication records, care tasks, appointments, medical documents), because
  their models use `SoftDeletes`. A row a member deletes through the API is soft-deleted even when the purge never
  touched it, so the test is `Schema::hasColumn($table, 'deleted_at')` and the clock is always `deleted_at`, never
  the retention `date_column`. Types without the column report `skipped = no_soft_deletes`.
- **`retention.dry_run` gates compaction too.** The switch means "nothing is deleted"; a compaction pass that ignored
  it would be a bypass around the operator's global stop, which is worse than the gap it closes. `--dry-run` forces
  it on for one run.
- **A failed object delete is reported, not thrown.** Purge throws so the batch stops; compaction keeps that one
  metadata row, compacts the rest, returns `skipped = object_delete_failed`, and the command exits non-zero so the
  next run is paged for. A partial deletion is audited with the rows it did remove - never silent.

Every skip that never started (`not_yet_implemented`, `table_not_present`, `no_soft_deletes`) writes no audit row,
exactly like a skipped purge batch; a batch that was blocked by a hold is audited with its `held` count.

Tests are `RET-C01..C08` in `backend/tests/Feature/Retention/RetentionCompactionTest.php`: inside the grace window
nothing is removed; past it the row is physically gone and one `retention.compact` audit row is written; a profile
hold and a workspace-wide hold both block it; a second run removes nothing; the dry-run switch removes nothing; a
document object is deleted before its metadata row; a failed object delete keeps the row; a batch never touches
another workspace's rows (RLS, F-09); and the scheduled run uses the signed 28-day grace, not a hard-coded argument.

### P2 implementation notes (as built, 2026-10-25)

`workspaces` gains `retention_state` (five values, NOT NULL, default `active`), `retention_state_changed_at`,
`retention_expires_at` and `lapsed_at`. The column is deliberately **not mass-assignable**: a request body can never
move an account between states (N-01). A check constraint rejects any sixth value.

`WorkspaceLifecycle` is the only writer. It enforces the arrows, refuses a deleted account, pins a workspace with an
active legal hold in `read_only`, and requires the matching tenant/workspace context (a context-less call would see
zero holds - fail closed, F-09). Every transition writes one `retention.state` audit row with `from`, `to`, `reason`
and the new deadline.

| Command | When | What it does |
|---|---|---|
| `retention:lapse {workspace} {--reason=}` | support / payment / heir | `active -> read_only`, sets `lapsed_at` and the read-only deadline. Idempotent |
| `retention:restore {workspace} {--reason=}` | payment, support, heir | any state except `deleted` -> `active`, clears the deadline and `lapsed_at` |
| `retention:advance [--dry-run]` | scheduled 03:40 UTC | `read_only -> archived -> scheduled_deletion` when the window elapsed; skips held workspaces and reports `waiting` at `scheduled_deletion` |

`WorkspaceStateGate` runs inside `ResolveWorkspaceContext`, after membership and before any authorizer/controller:

| State | Allowed | Refused |
|---|---|---|
| `active` | everything | - |
| `read_only` | GET/HEAD + export | every write: `409 workspace_read_only` |
| `archived` | export + `GET /workspaces/{id}` | everything else: `409 workspace_archived` |
| `scheduled_deletion` | export + `GET /workspaces/{id}` | everything else: `409 workspace_scheduled_deletion` |
| `deleted` | nothing | `410 workspace_deleted` |

The export exception is keyed on the route's own `data_export.request` / `data_export.download` permission, so it
cannot drift from the route file. Each refusal writes one `workspace.retention_block` deny row. Responses are RFC 9457
`application/problem+json` and carry `retention_state` plus `retention_expires_at` so a client can offer restore/export.

**What P2 does not do yet (honest):**

1. **Plan-specific windows.** Billing does not exist (D5), so every workspace uses the Free-with-health-data floor:
   30 days read-only + 11 months archived, or the 60-day row when the workspace never held health data. The windows
   live in `retention.state_windows_days`, so assigning a plan later is configuration plus a lookup, not a rewrite.
2. **The final arrow.** `scheduled_deletion -> deleted` is not automated: the member's "delete now" is P3 and the
   archive deletion is P4. `retention:advance` reports `waiting` and leaves the account in place, because marking it
   deleted while its data still exists would be a lie.
3. **No notices** (P3), **no separate archive store** (P4), and no self-service "pay to restore" screen - restore is
   the audited support command.

Tests are `RET-S01..S08` (`RetentionStateTest`: windows, arrows, hold pinning, restore, terminal state, commands) and
`RET-G01..G07` (`WorkspaceStateGateTest`: 409/410 per state, export exception, metadata read, audit row, isolation
between workspaces).

### P2b: scheduled work respects the state (2026-10-25)

`Workspace::acceptingBackgroundWork()` is the one query scope that decides whether an account is still processed.
The reminder dispatcher, the recurring-task materialiser, the escalation ladder and the inactivity scan all start from
it, so a lapsed or archived account receives no reminder, generates no occurrence and escalates nothing. The scope is
deliberately **not** used by the retention jobs themselves: `retention:purge`, `:compact` and `:advance` must process
lapsed accounts.

A queued notification is a second window. `DispatchNotificationJob` re-checks the state inside its own transaction and
drops the job (logging `notification.dispatch.skipped_workspace_state`, never the payload) when the account is no
longer active, so a job queued a minute before a lapse cannot deliver afterwards. The semantic predicate is
`RetentionState::acceptsBackgroundWork()`, shared by both places.

Tests: `WIRE-T12` (due-soon reminders), `WIRE-T13` (a queued job is dropped), `REC-T14` (materialisation), `ESC-T13`
(escalation), `RET-T04b` (inactivity scan). Each test also proves the work happens once the account is restored again,
so the state - not a broken fixture - is what suppressed it.

### P3a: the 30/7/1-day notices (as built, 2026-10-25)

`retention:notices` runs nightly at 04:20 UTC. For every workspace in `scheduled_deletion` it computes the calendar
days left, picks the most urgent due notice (`retention.notice_1` / `_7` / `_30`), and dispatches it to every active
member through the normal pipeline:

- **in-app and email** (`channels: ['in_app', 'email']`): the email channel was added to the delivery ledger (new
  check value) and to `EmailSender`, which renders only the retention templates and fails closed for anything else;
- **no preference can suppress it**: an explicit `channels` option marks the dispatch as a legal notice, so
  `channelEnabled()` is not consulted. A member who turned in-app off still gets the notice;
- **reaches a lapsed account**: the job carries `reachLapsedAccounts: true`, the one documented exception to the P2b
  delivery guard;
- **idempotent through the ledger**: the key is template + workspace id as the subject, so a second run the same night
  sends nothing. One notice per workspace per run: a missed night does not replay three mails at once.
- **no PHI and no name**: the only placeholder is `days_left`; the email body says "บัญชีของท่าน" and the deadline.

Tests: `RET-N01..N08` (`RetentionNoticeTest`): 30/7/1-day selection, in-app + email, idempotency, active workspace
excluded, dry run writes nothing, a disabled preference is overridden, a missing address marks only the email
delivery failed (in-app still sent), and the lapse offer is sent once.

The email transport itself is deployment configuration (`MAIL_*`); with the default `log` mailer the notice is
rendered and written to the log, which is why the test asserts the Mailable rather than an SMTP handshake.

### P3b: the lapse export offer (as built, 2026-10-25)

`WorkspaceLifecycle::lapse()` dispatches a `WorkspaceLapsed` domain event after the transition. The listener
`SendLapseExportOffer` sends `retention.export_offer` to every active member, **in-app only** (email is reserved for
the legal notices), with `reachLapsedAccounts: true` and `channels: ['in_app']` - the account is `read_only` by the
time the job runs, so the P2b guard would otherwise drop it, and forcing in-app avoids pointless failed push rows.

The event fires only on a real transition: a second lapse is a no-op and sends nothing (`RET-N08`). This is the
"export offered on day 1" promise of §4 case 1; the 30-day notice then repeats the offer by email.

### UI surface (2026-10-26)

The web app reads the state from the workspace metadata endpoint it is already allowed to call in every non-deleted
state (`WorkspaceService.loadLifecycle()`), and the shell renders one banner for `read_only`, `archived` or
`scheduled_deletion` with a link to the export card in `/family`. The mobile app does the same through
`workspaceRetentionProvider` and a banner above the tab shell. An unknown state or a failed read shows no banner - the
client never invents a state - and the server's 409/410 stays the authority: the banner explains the refusal, it does
not replace it (`FE5-T10/T11`, `RET-M01..M04`).

**Still open for P3:** the cancellation choice screen (P3c). It needs a permission ID for "cancel my account" that the
baseline catalog does not contain - `workspace.update` is configuration, not cancellation - so it waits for an
owner-approved change record; inventing a permission ID is forbidden.

### P4a: the archive inventory (as built, 2026-10-26)

Entering `archived` now writes a `workspace_archive_manifests` row: per-table row counts (measurements, conditions,
allergies, medication plans and records, appointments, care tasks, task events and evidence, medical documents,
timeline entries, notification deliveries, AI conversations - only the tables that exist) plus a sha256 over the
canonical JSON of the workspace id and those counts. `retention:archive-manifest {workspace}` prints the latest one
and re-verifies the hash.

Why counts and not the data: the inventory must be safe to read in support, so it carries no value, name, note or
title (N-02). It answers "what did the account hold when it was archived?" without loading the archive, which is what
a restore decision and a later deletion both need. `jsonb` does not preserve key order, so verification re-sorts the
counts before hashing - otherwise every untouched manifest would fail.

A restore keeps the manifest; a second lapse and archive appends a second one, so the history is a sequence, not an
overwrite. A failure to write the manifest aborts the archive transition: an archive without an inventory would be a
claim nobody can verify.

Tests: `RET-A01..A04` (manifest on archive with the fixture counts, command + tamper detection, restore keeps it and a
second archive appends, read_only/active have none).

### P4b-1: the encrypted bundle (as built, 2026-10-26)

Right after the inventory, the same transition writes the account's rows as NDJSON - one row per line, parents before
children (`persons`, `health_profiles`, conditions, allergies, measurements, medication, appointments, care tasks and
their events/evidence, documents, timeline, notifications, AI conversations) - gzips it and encrypts it with the
application key. The ciphertext goes to `RETENTION_ARCHIVE_DISK` (default: the private `local` disk) under
`archives/{tenant}/{workspace}/manifest-{id}.json.gz.enc`; the manifest gains `archive_path`, `archive_sha256` and
`archive_bytes`. Adding a table to the archive means adding it to `ArchiveTables::ALL`, the single list both the
inventory and the bundle read, so counts and content cannot drift.

The file name carries ids only - no profile name, no document title, no date of care (N-02) - and a test asserts the
raw ciphertext contains neither the measurement value nor the person's name.

`retention:archive-drill {workspace}` is the verification: file present, ciphertext hash equal to the manifest's,
decryptable with the current `APP_KEY`, and the row count per table equal to the inventory. It exits non-zero on any
mismatch, so an archive that was never verified is not reported as one that was (`RET-B01..B04`).

**What P4b-1 does not do:** it does not restore rows, does not use a per-archive key, and does not delete the archive
when the account is deleted. Those are P4b-2, and until they exist the final `scheduled_deletion -> deleted` arrow
stays closed. The archive disk is **not** part of the nightly database dump; a deployment must back it up separately
(`BACKUP-COVERAGE.md`). The P4b-2 design - move versus copy, per-archive keys, restore, crypto-shred,
export-from-bundle and its acceptance tests - is in `RETENTION-ARCHIVE-DESIGN.md`; it waits on two owner decisions:
whether archiving moves the rows out of the active tables, and whether an archived account may be absent from them for
the whole window.

## 9. Notice copy (Thai, for the privacy notice)

> เราเก็บข้อมูลสุขภาพของท่านไว้ตามวัตถุประสงค์ในการให้บริการ และตามระยะเวลาที่กฎหมายกำหนด
> เมื่อบัญชีสิ้นสุดลง เราจะเก็บข้อมูลไว้ในสถานะอ่านอย่างเดียวเป็นเวลา 30 วัน และเก็บสำรองไว้ตามระยะเวลาของแพ็กเกจ
> ของท่าน (สูงสุด 48 เดือน) เพื่อให้ท่านกลับมาใช้ต่อได้ ท่านสามารถขอสำเนาข้อมูลหรือขอลบข้อมูลได้ทุกเมื่อโดยไม่เสีย
> ค่าใช้จ่าย หากท่านไม่ดำเนินการใด ๆ เราจะแจ้งเตือนก่อนลบ 30 วัน 7 วัน และ 1 วัน และจะลบข้อมูลออกจากระบบ
> ภายใน 30 วันหลังสิ้นสุดระยะเวลา ทั้งนี้ ข้อมูลอาจยังคงอยู่ในไฟล์สำรองไม่เกิน 7 วันหลังจากนั้น
> บันทึกการเข้าถึงและการลบข้อมูลจะถูกเก็บไว้เพื่อการตรวจสอบ โดยไม่มีข้อมูลสุขภาพ

Every sentence above is a promise the system must keep. P1 is what makes the last sentence about deletion true.

### Timing question: resolved 2026-10-25 (R8 - grace 28 days)

The nightly jobs run at 03:00 (purge - sets `deleted_at`) and 03:20 (compaction) UTC. For a row whose retention
period ends at time E:

1. the purge soft-deletes it at the next 03:00, up to 24 h after E;
2. the compaction removes it at the first 03:20 that is at least the grace after `deleted_at`, up to 24 h after
   that boundary.

With the original 30-day grace that came to **30 to 32 days after E**, while the third sentence promises
"ภายใน 30 วันหลังสิ้นสุดระยะเวลา" (within 30 days). The owner chose **grace 28 days** (R8), so the worst case is
24 h + 28 d + 24 h = **exactly 30 days** and the sentence stays true as written. The value lives in
`config/retention.php` (`compact_grace_days`), not in the schedule, and `RET-C08` asserts the command uses it.

The backup sentence is separate and already says the copies can lag by up to 7 days.

## 10. PDPA mapping

| Requirement | Where it is answered |
|---|---|
| §23 purpose limitation | purposes are fixed in the notice; the archive is the same purpose, a different availability |
| §37 deletion / retention | this document, per-state windows, plus P1 for physical deletion |
| §19 data subject rights | export free in every state; delete-now free in every state |
| §28 cross-border | unchanged - the archive must not move to a jurisdiction the notice does not name |
| §37(4) audit trail | audit rows are kept forever and carry no health content |

## 11. Sign-off (owner)

| # | Decision | Answer | Date |
|---|---|---|---|
| R1 | The five states and the arrows between them | approved | 2026-10-25 |
| R2 | Plan windows in §3 (including Free-with-data = 12 months) | approved | 2026-10-25 |
| R3 | Case 2 (death) is manual, never automatic | approved | 2026-10-25 |
| R4 | Free account without health data deletes after 60 days | approved | 2026-10-25 |
| R5 | `audit_*` stays out of scope forever | approved | 2026-10-25 |
| R6 | P1 (hard delete) ships before the notice is published | approved - P1 built 2026-10-25 | 2026-10-25 |
| R7 | Archive notices at 30/7/1 days, delivered by email and in-app | approved | 2026-10-25 |
| R8 | Grace before physical deletion, so the notice's "within 30 days" is true | **28 days** (`retention.compact_grace_days`) | 2026-10-25 |

**Signed off by:** product owner (recorded from the 2026-10-25 session) · **Date:** 2026-10-25
