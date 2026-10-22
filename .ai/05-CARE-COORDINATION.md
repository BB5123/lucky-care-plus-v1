# 05 · CARE COORDINATION (CRM) — งานดูแล, state machine, CRM modules

> อ้างอิง ADR: [ADR-001](DECISIONS.md#adr-001--รวม-care-task-state-machine-เป็นโมเดลเดียวแบบ-3-ระดับ), [ADR-012](DECISIONS.md#adr-012--baseline-v11-delta-ต้องมี-permission-เพิ่ม-4-โดเมน)
> กฎแม่: **F-04** งาน ≠ สิทธิ์ข้อมูล · **F-18** escalation ≠ ข้อสรุปการดูแล
> ไฟล์นี้เป็นข้อกำหนดหลักของโดเมน CRM — `SUMMARY_2.md` เป็นที่มาของแนวคิด แต่รายละเอียดที่นี่ชนะ
> **สถาปัตยกรรมระดับโดเมน** (boundary, aggregate, event, API, invariant) อยู่ที่ [`CRM_DOMAIN_ARCHITECTURE.md`](CRM_DOMAIN_ARCHITECTURE.md)

---

## 1. หลักการ

1. **1 งาน = 1 ผู้รับผิดชอบหลัก** ในทุกขณะ (มีผู้ช่วยได้หลายคน) — กัน "ทุกคนคิดว่าคนอื่นทำ"
2. **งาน routine ต้องเสร็จใน 1 แตะ** — ถ้าใช้เกิน 2 แตะ ถือว่างานนั้นออกแบบผิด
3. **หลักฐานผูกกับความเสี่ยง** — งาน clinical บังคับหลักฐาน, งาน routine ไม่บังคับ
4. **ทุกการเปลี่ยนสถานะผ่าน Domain Service** (ไม่ใช่ update ตรง)
5. **สถานะ ≠ ผลลัพธ์ ≠ การตรวจรับ** (3 คอลัมน์แยก — ADR-001)
6. **งาน ≠ สิทธิ์ข้อมูล** — การเห็นงานไม่ให้สิทธิ์เห็นเวชระเบียน (F-04)

---

## 2. State machine

### 2.1 คอลัมน์ที่ใช้

| คอลัมน์ | ค่า |
|---|---|
| `workflow_tier` | `routine` · `delegated` · `clinical` |
| `status` | `scheduled` · `open` · `assigned` · `accepted` · `in_progress` · `blocked` · `completed` · `cancelled` |
| `outcome` | `null` · `done` · `skipped` · `missed` · `failed` |
| `verification` | `not_required` · `pending` · `verified` · `rejected` |

`overdue` **ไม่ใช่สถานะ** — คำนวณจาก `due_at < now()` และ `not terminal`
`escalation_level` เป็น integer ที่เพิ่มขึ้นตามนโยบาย ไม่เปลี่ยน `status`

### 2.2 Tier `routine` — งานกิจวัตร (1 แตะ)

```
scheduled ──confirm(done)──────────────> completed(outcome=done, verification=not_required)
scheduled ──confirm(skipped,reason)────> completed(outcome=skipped)
scheduled ──due_at + grace─────────────> completed(outcome=missed)   [system]
scheduled ──cancel─────────────────────> cancelled
```

ตัวอย่าง: กินยาเช้า, วัดความดันประจำวัน, บันทึกน้ำหนัก
**ไม่มี** `open/assigned/accepted/in_progress` — ไม่ต้องรับงานก่อนทำ

### 2.3 Tier `delegated` — งานที่ต้องมีคนรับผิดชอบ

```
open ──assign(user)──> assigned ──accept──> accepted ──start──> in_progress ──complete──> completed
  │                       │                    │                    │
  │                       └──decline(reason)───┴──> open (คืนกองกลาง)
  └──claim(user)──────────┘                    └──block(reason)──> blocked ──unblock──> in_progress
open/assigned/accepted ──cancel(reason)──> cancelled
```

ตัวอย่าง: พาไปตรวจตา, ไปรับยา, ติดต่อ รพ.
`decline`/`reassign` ต้องเก็บ `task_events` ทุกครั้ง (รักษาประวัติผู้รับผิดชอบเดิม)

### 2.4 Tier `clinical` — งานความเสี่ยงสูง

```
assigned ──start──> in_progress ──complete(evidence บังคับ)──> completed(verification=pending)
                                                                   │
                                              verify(permission care_task.verify) ──> verified
                                              reject(reason) ─────────────────────────> rejected
rejected ──reopen(reason)──> in_progress
```

ตัวอย่าง: ฉีดอินซูลิน, ล้างแผลกดทับ, ให้อาหารทางสายยาง
**หลักฐานบังคับ:** `value` (เช่น น้ำตาลปลายนิ้ว) และ/หรือ `photo` — บังคับตาม `care_plans.evidence_policy`

### 2.5 Transition ที่ต้องถูกปฏิเสธเสมอ

| พยายาม | ผล | test |
|---|---|---|
| `routine` → `accepted` | 409 | `CT-T01` |
| `completed` → `in_progress` ยกเว้นผ่าน `reopen` + permission | 409 | `CT-T02` |
| `verify` โดยไม่มี `care_task.verify` | 403 | `CT-T03` |
| ผู้ที่ไม่มีสิทธิ์ complete (ไม่ใช่ผู้รับผิดชอบ/ไม่มี grant) | 403 | `CT-T04` |
| complete `clinical` โดยไม่มี evidence | 422 | `CT-T05` |
| เปลี่ยน tier หลังสร้างแล้ว | 409 | `CT-T06` |

---

## 3. กฎงานดูแล (CT-xx)

| ID | กฎ |
|---|---|
| **CT-001** | 1 งานมี primary assignee ≤1 คน ณ เวลาใดๆ |
| **CT-002** | ผู้ช่วย (assistant) เห็นงานได้ แต่ complete ได้เฉพาะเมื่อมี `care_task.complete` + ได้รับอนุญาตใน `task_assignments.can_complete` |
| **CT-003** | `verify` แยกจาก `complete` — คนเดียวกันทำทั้งสองไม่ได้ถ้า `verification_requires_other = true` (ค่าเริ่มต้นสำหรับ clinical) |
| **CT-004** | ทุก transition ผ่าน `CareTaskTransitionService` และบันทึก `task_events` (who, from, to, reason, at) |
| **CT-005** | งานที่ `blocked` ต้องมี `block_reason` และ `review_at` |
| **CT-006** | งานในกองกลาง (`pool`) ต้องมี `claim_deadline`; ถ้าไม่มีใคร claim → แจ้ง coordinator (ไม่ปล่อยค้าง) |
| **CT-007** | การมอบหมายใหม่ต้องไม่ลบผู้รับผิดชอบเดิมออกจากประวัติ |
| **CT-008** | งานที่เกี่ยวกับผู้รับการดูแล 1 คน ต้องมี `profile_id` เสมอ |
| **CT-009** | งานที่ผูกกับ appointment ต้องสร้าง follow-up task อัตโนมัติเมื่อ attended |
| **CT-010** | ห้ามให้ AI เปลี่ยนสถานะงาน, มอบหมาย, หรือ verify (AI เป็นได้แค่ผู้เสนอ) |

---

## 4. Recurring tasks

**แยก template ออกจาก occurrence** — ผู้ใช้แก้ตารางอนาคตได้โดยไม่กระทบประวัติ

`care_task_templates`:
| ฟิลด์ | ตัวอย่าง |
|---|---|
| `rrule` | `FREQ=DAILY;BYHOUR=8;BYMINUTE=0` (RFC 5545 subset) |
| `timezone` | `Asia/Bangkok` (เก็บ IANA ไม่ใช่ offset) |
| `starts_on`, `ends_on` | |
| `skip_dates` | วันที่ข้าม (เช่น นัดตรวจ) |
| `lead_time` | สร้าง occurrence ล่วงหน้ากี่วัน (ค่าเริ่มต้น 7) |
| `workflow_tier` | สืบทอดค่าเริ่มต้น |

**กฎ**
| ID | กฎ |
|---|---|
| **RC-001** | occurrence ต้อง unique ต่อ `(template_id, scheduled_for)` — ใช้ DB unique index ไม่ใช่ check ในโค้ด |
| **RC-002** | การสร้าง occurrence ต้อง idempotent ด้วย `idempotency_key = template_id:scheduled_for` |
| **RC-003** | แก้ "ครั้งนี้เท่านั้น" → แก้ occurrence; แก้ "ครั้งต่อๆ ไป" → สร้าง template ใหม่ + ปิดของเดิม (`ends_on`) |
| **RC-004** | DST/เปลี่ยน offset → ยึดเวลาท้องถิ่น (08:00 ยังเป็น 08:00) เก็บ `scheduled_for` เป็น timestamptz UTC + `local_time` |
| **RC-005** | หยุดชั่วคราว = `paused_from/paused_until` ไม่ใช่ลบ template |
| **RC-006** | สร้าง occurrence ล่วงหน้าไม่เกิน `lead_time` และไม่สร้างย้อนหลังเกิน 1 วัน |

---

## 5. Assignment

| ID | กฎ |
|---|---|
| **AS-001** | มอบหมายตรง (`assigned`) หรือเข้ากองกลาง (`pool`) — ทั้งคู่จบที่ผู้รับผิดชอบหลัก 1 คน |
| **AS-002** | ผู้รับงานปฏิเสธได้ด้วยเหตุผล → กลับกองกลางหรือ reassign |
| **AS-003** | ผู้รับงานขอความช่วยเหลือ (assistant) ได้ โดยไม่ transfer สิทธิ์ข้อมูล |
| **AS-004** | งานที่เกี่ยวกับ `profile` ที่ผู้รับไม่มี grant → มอบหมายได้ แต่ผู้รับเห็นเฉพาะ "ข้อมูลจำเป็นต่องาน" (need-to-know view) |
| **AS-005** | การมอบหมายไม่สร้าง access grant อัตโนมัติ (F-04) — ถ้างานต้องใช้ข้อมูลเพิ่ม ต้องขอ grant แยกและแสดงให้ผู้ใช้เห็นตอนขอ |

> **Need-to-know view:** การ์ดงานแสดงได้: ชื่อผู้รับการดูแล, ชื่องาน, เวลา, สถานที่, ผู้ติดต่อ — **ห้าม** แสดงผลแล็บ/การวินิจฉัย/ประวัติจิตเวช

---

## 6. Escalation

### 6.1 บันไดมาตรฐาน (ปรับได้)

| ขั้น | เวลา | การกระทำ | กลุ่มเป้าหมาย |
|---|---|---|---|
| L0 | `due_at` | แจ้งผู้รับผิดชอบ | assignee |
| L1 | +30 นาที | แจ้งซ้ำ + เสียง/ป้ายแดง (เฉพาะ `clinical` และงาน "critical") | assignee |
| L2 | +60 นาที | **Family Escalation** ถึง coordinator | `workspace_admin` + ผู้ให้ grant |
| L3 | +4 ชม. (งาน clinical) | แจ้งผู้ติดต่อฉุกเฉิน (ไม่เปิดเผยข้อมูลสุขภาพ) | emergency contact |

### 6.2 กฎ

| ID | กฎ |
|---|---|
| **ES-001** | นโยบาย escalation เก็บที่ `escalation_policies` ต่อ `care_task_templates`/`task_type` — **ห้าม hardcode** |
| **ES-002** | ข้อความ escalation ต้องไม่มี PHI: `"ยังไม่มีบันทึกยืนยันงาน X (เลยกำหนด 1 ชม.)"` ไม่ใช่ `"ยังไม่ได้ให้ยาลดความดัน"` |
| **ES-003** | escalation ไม่เปลี่ยน `status` และไม่สร้างงานใหม่ |
| **ES-004** | ห้ามตีความว่า "ไม่มีการดูแล" — ระบบรู้แค่ "ไม่มีบันทึก" (F-18) |
| **ES-005** | เหตุฉุกเฉินทางการแพทย์ต้องใช้ break-glass/เบอร์ฉุกเฉิน **ไม่ใช่** escalation (F-18) |
| **ES-006** | ผู้รับงานที่ถูก escalate หลายรอบผิดปกติ → แจ้งผู้ใช้ (burnout signal) ไม่ใช่เพิ่มความถี่ |

### 6.3 ข้อความตัวอย่าง

```
[ต้องยืนยัน] งาน "วัดความดันเช้า" ของคุณพ่อ ยังไม่มีบันทึก (เลยเวลา 30 นาที)
→ เปิดแอป
```
(ไม่ระบุค่า, ไม่ระบุโรค)

---

## 7. CRM 5 โมดูลย่อย

| # | โมดูล | ตาราง | permission ที่ใช้ |
|---|---|---|---|
| 7.1 | **Care Circle & Relationship Directory** | `care_relationships`, `persons`, `contact_points`, `person_relationships` | `care_relationship.*`, `person.*` |
| 7.2 | **Care Plan & Routine Engine** | `care_plans`, `care_task_templates` | `care_plan.*`, `care_task.create` |
| 7.3 | **Care Task & Delegation Engine** | `care_tasks`, `task_assignments`, `task_events`, `task_evidence` | `care_task.*` |
| 7.4 | **Handoff & Communication** | `interaction_logs`, `task_events`, `documents` | `timeline.*`, `care_task.*` |
| 7.5 | **Case & Service Request** | `care_cases` | **`care_case.*` — Wave 2 ยังไม่อนุมัติ** (ADR-012, CR-002) |

### 7.1 Care Circle (3 วง)

| ring | ใคร | ค่าเริ่มต้นสิทธิ์ |
|---|---|---|
| `inner` | ลูกหลานสายตรง, คู่สมรส, เจ้าของ | ได้รับเชิญให้เป็น caregiver/owner |
| `support` | ญาติ, เพื่อนบ้าน, หลาน | grant เฉพาะกิจ + มีอายุ |
| `professional` | พยาบาล, ผู้ช่วยพยาบาล, กายภาพบำบัด | grant ตามกะ + `healthcare_professional` |

`ring` **ไม่มีผลต่อสิทธิ์โดยตัวเอง** (F-05) — เป็นข้อมูลเพื่อ UX และการจัดกลุ่ม

### 7.4 Handoff

| องค์ประกอบ | ข้อกำหนด |
|---|---|
| Shift handover note | ข้อความอิสระ + ต้องเตือนว่า "ห้ามใส่ข้อมูลที่ละเอียดเกินจำเป็น" + scan ง่าย |
| Contextual comment | ผูกกับ `task_id`/`measurement_id` — ไม่ใช่แชตทั่วไป |
| Symptom & incident pinning | `task_evidence` type=`symptom` + `pinned=true` → รวบเข้า Doctor Brief |
| การเก็บรักษา | ค่าเริ่มต้น 2 ปี หรือตาม retention ของ workspace |

---

## 8. Appointment

| ID | กฎ |
|---|---|
| **APT-001** | นัด ≠ เวชระเบียน — บันทึกผลตรวจต้องเป็น `documents`/`health_measurements` |
| **APT-002** | สถานะ: `planned → confirmed → attended` + `rescheduled`, `cancelled`, `missed`, `needs_followup` |
| **APT-003** | ต้องระบุผู้พาไป (ถ้ามี) และต้องมี grant เฉพาะนัดถ้าไม่ใช่ caregiver หลัก |
| **APT-004** | `attended` → สร้าง follow-up task ตาม `follow_up_policy` (เป็นค่าเริ่มต้น: เตือนบันทึกผลใน 3 วัน) |
| **APT-005** | เอกสารที่ต้องเตรียมเป็น checklist ที่ผูกกับ appointment ไม่ใช่ข้อความลอย |

---

## 9. Doctor Brief (Killer feature)

| ID | กฎ |
|---|---|
| **DB-001** | 1 หน้า A4: กราฟ 30 วัน (ความดัน/น้ำตาล), adherence %, อาการที่ปักหมุด, ยาปัจจุบัน, โรคประจำตัว, แพ้ยา |
| **DB-002** | ต้องตรวจ `data_export`/`health_report.*` permission ก่อนสร้าง **และ** ก่อนแสดงไฟล์ |
| **DB-003** | QR ต้องเป็น signed token อายุ ≤30 นาที, ผูกกับ `report_id` + `grant` — เพิกถอนได้ |
| **DB-004** | ห้ามฝัง PHI ใน URL/QR |
| **DB-005** | ไฟล์ที่สร้างแล้วเก็บใน object storage แบบ private + signed URL อายุ ≤15 นาที |

---

## 10. อะไรที่ AI ทำได้/ไม่ได้กับ CRM

| ทำได้ | ทำไม่ได้ |
|---|---|
| สรุป timeline ที่ผู้ใช้มีสิทธิ์เห็น | เปลี่ยนสถานะ/มอบหมาย/verify (CT-010) |
| เสนอ prioritization ของงานค้าง | ปิดงานแทนผู้ใช้ |
| ร่างข้อความ handover จาก event | ส่งข้อความถึงสมาชิกโดยไม่ให้ผู้ใช้ยืนยัน |
| เตือนว่างานประเภทเดียวกันถูกเลื่อนบ่อย | สรุปข้อมูลของผู้รับการดูแลที่ผู้ใช้ไม่มีสิทธิ์ |
