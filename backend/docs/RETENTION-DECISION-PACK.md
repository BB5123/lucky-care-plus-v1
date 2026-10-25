# Retention decision pack (D3 / Q-12)

> **Update 2026-10-25:** the owner approved R1-R7 of [`RETENTION-MODEL.md`](RETENTION-MODEL.md) (the successor to this
> pack's R1-R5) and added **R8: a 28-day grace** before physical deletion, so the notice's "within 30 days" holds.
> P1 (physical deletion) is built and rehearsed. `retention.dry_run` is still `true` **in the repository** on purpose
> - a deployment turns it on with `RETENTION_DRY_RUN=false`; see `RETENTION-TURN-ON.md`.

Retention is implemented and **has never deleted anything in any deployment**: `retention.dry_run` is `true`. This
document is what the owner needs in order to turn it on: the current policy, what the dry run proves today, the exact
decisions required, and the notice that must accompany them.

Nothing here changes the policy. It is a decision aid, and `RetentionDecisionPackTest` fails if the numbers in
this file drift from `config/retention.php`.

## 1. Current policy (platform defaults, months)

| Type | Default | Tenant range | Notes |
|---|---|---|---|
| `health_measurements` | 60 | 24-120 | blood pressure, weight, glucose, ... |
| `health_conditions` | 60 | 24-120 | |
| `allergies` | 60 | 24-120 | |
| `medication_plans` | 60 | 24-120 | |
| `medication_records` | 60 | 24-120 | dose log |
| `appointments` | 60 | 24-120 | |
| `care_tasks` | 36 | 12-120 | |
| `task_events` | 36 | 12-120 | |
| `task_evidence` | 36 | 12-120 | photos, values, notes |
| `medical_documents` | 60 | 24-120 | canonical name; `documents_medical` is a compatibility alias |
| `documents_identity` | 12 | 6-36 | counted from the end of the delegation |
| `ai_conversations` | 6 | 1-24 | |
| `notification_payload_days` | 90 **days** | 30-365 **days** | the payload, not the notification row |
| `notification_metadata_months` | 24 | 6-120 | |
| `audit_months` | 60 | **fixed** | never user-configurable, never purged |

Other constants: inactivity starts at **24 months** without a login, reminders go out **30, 14 and 7 days**
before the purge due date, and the `dry_run` flag defaults to **true**.

## 2. What the dry run proves, and what it cannot

`php artisan retention:purge --dry-run` walks every purgeable type, selects rows past their period, respects
legal holds, and writes an audit row with `reason_code = dry_run` **without deleting**. From that you can read:

- how many rows *would* be deleted per type, and how many are protected by a hold;
- that the query and the audit path work against the real schema and RLS;
- that a purge never touches `audit_*` (a hard rule, asserted by tests).

It **cannot** tell you whether deleting those rows is lawful or clinically wise. That is the decision below,
and it is not a technical question.

## 3. Decisions required from the owner (D3)

> **Superseded** by R1-R7 (approved 2026-10-25) plus R8 (28-day grace) in [`RETENTION-MODEL.md`](RETENTION-MODEL.md)
> section 11. Kept here as the history of what was asked.

| # | Decision | Why it cannot be guessed |
|---|---|---|
| R1 | Confirm the default months per type, or set different ones within the ranges above | The ranges exist so a tenant cannot accidentally destroy a record the law expects to keep; the *value* inside the range is a clinical and legal judgement |
| R2 | Confirm the `documents_identity` clock (12 months **from the end of the delegation**, not from upload) | It is the shortest period in the policy and the easiest to argue about |
| R3 | Approve the privacy-notice wording (section 5) | PDPA requires telling people before you delete |
| R4 | Approve the first real run date and the rollback expectation | Once purged, data is gone; the only rollback is the nightly dump, and its retention is 7 days |
| R5 | Confirm that `audit_*` stays out of scope forever | Auditing law usually requires keeping audit trails longer than the data they describe |

## 4. Turning it on, when the answers exist

1. Set `RETENTION_DRY_RUN=false` in the deployment environment (**not** in the repository).
2. Run `php artisan retention:purge --type=<one type> --limit=100` for a single type first, and read the audit
   rows (`reason_code` will be null on a real delete).
3. Verify with `php artisan retention:purge --dry-run` that nothing is left in that type.
4. Rehearse the physical delete the same way: `php artisan retention:compact --type=<one type> --dry-run`, then for
   real with `RETENTION_DRY_RUN=false`. Read the `retention.compact` audit row and prove a second run finds nothing
   (the full recipe is in `RETENTION-TURN-ON.md`).
5. Only then let the scheduler run all types.

Step 2 is deliberately manual: the failure mode of getting this wrong is irreversible deletion of a family's
health history.

## 5. Privacy-notice text (draft, needs R3 approval)

> **Superseded** by the notice draft in [`RETENTION-MODEL.md`](RETENTION-MODEL.md) section 9, which adds the
> per-plan windows and the 30/7/1-day notices. Kept here as the simpler earlier draft.

> เราเก็บข้อมูลสุขภาพของสมาชิกในครอบครัวตามระยะเวลาที่จำเป็นต่อการดูแล เช่น ค่าที่วัดได้และบันทึกการทานยา
> เก็บ 5 ปี งานที่ได้รับมอบหมายเก็บ 3 ปี และเอกสารยืนยันตัวตนเก็บ 1 ปีนับจากสิ้นสุดการมอบอำนาจ
> เมื่อครบกำหนด ระบบจะลบข้อมูลนั้นอย่างถาวร เว้นแต่มีเหตุทางกฎหมายให้เก็บต่อ (เช่น ข้อพิพาทหรือคำสั่งศาล)
> บันทึกการเข้าถึงข้อมูล (audit) ถูกเก็บแยกและไม่ถูกลบด้วยกระบวนการนี้
> ท่านสามารถขอดู แก้ไข หรือลบข้อมูลก่อนถึงกำหนดได้ตามสิทธิในพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล

The notice is written in Thai because it is user-facing (the repository rule: product copy is Thai, technical
docs are English).

## 6. Why the repository still ships it off

`dry_run = true` is not an oversight. The repository must never delete health data on its own: a checkout, a test run
or a demo stack has no business destroying records, and `RetentionDecisionPackTest` fails if the committed default is
flipped. The approved policy is turned on **per deployment** with `RETENTION_DRY_RUN=false` (section 4), after the
rehearsal in `RETENTION-TURN-ON.md` - that keeps the decision with the people accountable for the data instead of
with a clone.
