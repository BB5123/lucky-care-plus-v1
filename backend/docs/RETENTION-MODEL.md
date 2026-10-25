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
| **Hard delete / compaction** | **no** - nothing in the codebase (`grep forceDelete` finds nothing) |
| **The five states above** | **no** - there is only "purged or not" |
| **Notices (30/7/1 day)** | **no** |
| **Archive store** | **no** |

So today the product *hides* expired rows forever. That is the gap this document proposes to close.

## 8. Implementation, phased

| Phase | Work | Effort | Unblocks |
|---|---|---|---|
| **P1** | `retention:compact --older-than=<grace>` : physical delete of rows already soft-deleted, audit row, legal hold respected, idempotent | ~1 day | "we actually delete" |
| **P2** | `retention_state` column + the five states + the lapse trigger | ~2 days | plan windows |
| **P3** | Notices (email + in-app), export push, the cancellation choice screen | ~2 days | the notices and the choice |
| **P4** | Archive store + restore action + access audit | ~3 days | the long windows |

P1 is the honest minimum: without it, no wording of the privacy notice is true. **Do P1 before publishing the
notice**, then P2-P4 behind their own review.

### P1 implementation notes (for whoever writes it)

Everything needed is already in `app/Modules/Retention/Services/PurgeService.php`:

- `TYPE_SPECS` (private const) maps each type to `{table, date_column, model, soft_deletes, objects?}`. Only the
  entries with `soft_deletes: true` can ever have compactable rows - for the rest, purge already hard-deletes.
- Per-type work happens per **workspace**, and holds are read with `$this->holds->activeHolds($workspaceId)`; a
  hold with `profile_id === null` is workspace-wide and must skip the whole workspace.
- The append-only trigger on `task_events` only allows DELETE when `set_config('app.retention_purge', 'on')` has
  been set for the connection - the compaction must do the same or it will fail on that table.
- Object-backed rows (`objects: true`) must delete the private object before the row, and keep the row when the
  object delete fails, so the next run can retry (fail closed) - same rule as purge.

Signature to add: `compactBatch(string $type, int $graceDays, int $limit = 500): array` returning
`['compacted' => n, 'held' => n, 'skipped' => ?string]`, plus a `retention:compact {--type=} {--older-than=30}
{--limit=500}` command that refuses `--older-than` below 1 day and writes one audit row per batch
(`action = retention.compact`). Tests mirror `RET-T01/T02/T05/T07`: inside the grace window nothing is removed,
past it the row is gone, an active hold blocks it, a second run removes nothing.

## 9. Notice copy (Thai, for the privacy notice)

> เราเก็บข้อมูลสุขภาพของท่านไว้ตามวัตถุประสงค์ในการให้บริการ และตามระยะเวลาที่กฎหมายกำหนด
> เมื่อบัญชีสิ้นสุดลง เราจะเก็บข้อมูลไว้ในสถานะอ่านอย่างเดียวเป็นเวลา 30 วัน และเก็บสำรองไว้ตามระยะเวลาของแพ็กเกจ
> ของท่าน (สูงสุด 48 เดือน) เพื่อให้ท่านกลับมาใช้ต่อได้ ท่านสามารถขอสำเนาข้อมูลหรือขอลบข้อมูลได้ทุกเมื่อโดยไม่เสีย
> ค่าใช้จ่าย หากท่านไม่ดำเนินการใด ๆ เราจะแจ้งเตือนก่อนลบ 30 วัน 7 วัน และ 1 วัน และจะลบข้อมูลออกจากระบบ
> ภายใน 30 วันหลังสิ้นสุดระยะเวลา ทั้งนี้ ข้อมูลอาจยังคงอยู่ในไฟล์สำรองไม่เกิน 7 วันหลังจากนั้น
> บันทึกการเข้าถึงและการลบข้อมูลจะถูกเก็บไว้เพื่อการตรวจสอบ โดยไม่มีข้อมูลสุขภาพ

Every sentence above is a promise the system must keep. P1 is what makes the last sentence about deletion true.

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
| R1 | The five states and the arrows between them | | |
| R2 | Plan windows in §3 (including Free-with-data = 12 months) | | |
| R3 | Case 2 (death) is manual, never automatic | | |
| R4 | Free account without health data deletes after 60 days | | |
| R5 | `audit_*` stays out of scope forever | | |
| R6 | P1 (hard delete) ships before the notice is published | | |
| R7 | Archive notices at 30/7/1 days, delivered by email and in-app | | |

**Signed off by:** ____________ (owner) · **Date:** ____________
