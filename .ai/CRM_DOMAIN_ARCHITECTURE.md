# CRM DOMAIN ARCHITECTURE — Care CRM (C9/C10/C11)

| | |
|---|---|
| **สถานะ** | Accepted · ใช้คู่กับ [`05-CARE-COORDINATION.md`](05-CARE-COORDINATION.md) |
| **ขอบเขต** | แปลงแนวคิดจาก `docs/SUMMARY_2.md` เป็นสถาปัตยกรรมระดับโดเมนที่สร้างโค้ดได้ |
| **อ้างอิง ADR** | [ADR-001](DECISIONS.md#adr-001--รวม-care-task-state-machine-เป็นโมเดลเดียวแบบ-3-ระดับ) · [ADR-004](DECISIONS.md#adr-004--โครงสร้างแท็บมือถือ-5-แท็บ-chat-ai-ไม่ใช่แท็บ) · [ADR-005](DECISIONS.md#adr-005--api-เป็น-workspace-scoped-ทั้งหมด) · [ADR-006](DECISIONS.md#adr-006--ชื่อตารางมาตรฐานชุดเดียว) · [ADR-010](DECISIONS.md#adr-010--dashboard-ใช้-endpoint-รวมตัวเดียว) · [ADR-012](DECISIONS.md#adr-012--baseline-v11-delta-ต้องมี-permission-เพิ่ม-4-โดเมน) · [ADR-013](DECISIONS.md#adr-013--tenancy-shared-schema--tenant_id-ตั้งแต่-phase-0) |
| **กฎแม่** | **F-04** งาน ≠ สิทธิ์ข้อมูล · **F-17** ตรวจสิทธิ์ซ้ำตอน execute · **F-18** escalation ≠ ข้อสรุปการดูแล · **N-06** ห้าม query ข้าม context |

> ไฟล์นี้ตอบคำถาม *"CRM ครอบครองอะไร เชื่อมกับใคร และห้ามทำอะไร"* — ส่วนรายละเอียด state machine / escalation / recurrence อยู่ใน [`05`](05-CARE-COORDINATION.md) และห้ามเขียนซ้ำที่นี่

---

## 1. ขอบเขตความเป็นเจ้าของข้อมูล (Boundary)

| กลุ่ม | ตาราง | CRM ทำอะไรได้ |
|---|---|---|
| **Owns (write)** | `care_plans`, `care_task_templates`, `care_tasks`, `task_assignments`, `task_events`, `task_evidence`, `appointments`, `interaction_logs`*, `care_cases`* | สร้าง/แก้/เปลี่ยนสถานะ |
| **Derives (write-through projection)** | `timeline_entries` | เขียนผ่าน subscriber เท่านั้น (ไม่เขียนตรงจาก use case) |
| **References (read-only)** | `persons`, `health_profiles`, `care_relationships`, `access_grants`, `consent_records`, `documents`, `health_measurements`, `medication_*` | อ่านเพื่อแสดง/ตรวจสอบ **ห้ามเขียน** |
| **Never** | ข้อมูลวัดค่า/ผลแล็บ/ยา, สถานะ consent, ผลการตัดสินสิทธิ์ | — |

`*` = Wave 2 (ยังไม่มี permission — [ADR-012](DECISIONS.md#adr-012--baseline-v11-delta-ต้องมี-permission-เพิ่ม-4-โดเมน) / CR-002)

**เหตุผลที่ต้องมีเส้นแบ่งนี้:** [SUMMARY_2 §2](../docs/SUMMARY_2.md) เตือนว่า CRM ต้องไม่กลายเป็น "ช่องทางลัดเข้าถึงเวชระเบียน" — ถ้า CRM เขียนตารางสุขภาพได้เอง การตรวจสิทธิ์จะหลุดจากจุดเดียว

---

## 2. Context → Module mapping

| Context | บทบาทใน CRM | Laravel module |
|---|---|---|
| **C9 Care Coordination** | เจ้าของ aggregate ทั้งหมด | `app/Modules/CareCrm` |
| **C10 Appointment & Records** | นัด + งานโลจิสติกส์ที่ผูกนัด | `CareCrm` (sub-module `Scheduling`) |
| **C11 Timeline** | read model รวมเหตุการณ์ | `app/Modules/Timeline` |
| **C12 Notification** | ผู้รับ event → ส่งข้อความ | `app/Modules/Notifications` |
| **C6/C7/C8 Health** | เจ้าของค่าที่วัดได้ | `app/Modules/HealthRecord` |
| **C4/C5 Authz/Consent** | ตัดสินสิทธิ์ | `app/Modules/Authz`, `Consent` |

**โครงภายใน `CareCrm`:**

```
CareCrm/
├── Domain/
│   ├── CarePlan/            # aggregate + rules
│   ├── CareTask/            # aggregate + tier transitions + rules
│   ├── Recurrence/          # template → occurrence
│   ├── Escalation/          # policy engine (config-driven)
│   └── Scheduling/          # appointment
├── Application/             # use cases: CreateTask, AssignTask, CompleteTask, ...
├── Infrastructure/          # eloquent repositories, outbox, event publishers
├── Http/                    # controllers, requests, resources
├── Policies/                # CareTaskPolicy, CarePlanPolicy
└── Contracts/               # HealthRecordContract, TimelineContract, NotifierContract
```

---

## 3. Aggregate Roots

| Aggregate | Root | Children | Invariant ที่ต้องบังคับในโดเมน |
|---|---|---|---|
| **CarePlan** | `care_plans` | — | 1 plan active ต่อ profile+ประเภท; เปลี่ยน plan ต้องมีเหตุผล + audit |
| **CareTaskTemplate** | `care_task_templates` | occurrences (อ้างด้วย `template_id`) | `rrule` valid; `timezone` IANA; ห้ามลบ template ที่มี occurrence |
| **CareTask** | `care_tasks` | `task_assignments`, `task_events`, `task_evidence` | 1 primary assignee; tier คงที่; evidence บังคับตาม tier; ทุก transition ผ่าน domain service |
| **Appointment** | `appointments` | อ้าง `care_tasks` (งานพาไป) | นัด ≠ เวชระเบียน; `attended` สร้าง follow-up task ครั้งเดียว |
| **CareCase*** | `care_cases` | `interaction_logs` | ต้องมี owner + สถานะปิด; **ยังออกแบบไม่ได้จนได้ permission** |
| **InteractionLog*** | `interaction_logs` | — | ข้อความอิสระ = อาจมี PHI → ต้องมีระดับการเข้าถึง |

**การนับ "ผู้รับผิดชอบ" ของนัด:** ระบบ**ไม่มี** `appointment_participants` — ผู้พาไปคือนิติบุคคลที่ผูกกับ `care_tasks` ที่เชื่อมกับ `appointments.id` ([ADR-004](DECISIONS.md#adr-004--โครงสร้างแท็บมือถือ-5-แท็บ-chat-ai-ไม่ใช่แท็บ): Appoint ถูกยุบเข้า Care CRM)

---

## 4. Lifecycle

### 4.1 Care Task

**canonical อยู่ที่ [`05 §2`](05-CARE-COORDINATION.md#2-state-machine)** — ห้ามเขียน state machine ซ้ำในไฟล์นี้

| Tier | ใครเปลี่ยนสถานะได้ |
|---|---|
| `routine` | assignee (หรือผู้มี `care_task.complete` ในขอบเขตงาน) — 1 แตะ |
| `delegated` | assignee: accept/start/complete/block · coordinator: assign/reassign/cancel · ระบบ: escalate |
| `clinical` | assignee: start/complete(พร้อม evidence) · **ผู้ตรวจ** (`care_task.verify`): verify/reject · coordinator: reopen |

### 4.2 Template → Occurrence

```
template (rrule) ──generate (lead_time)──> care_tasks (occurrence)
                        │
                        └─ unique (template_id, scheduled_for)  ← RC-001
```

- การแก้ "ครั้งต่อๆ ไป" = สร้าง template ใหม่ + ปิดของเดิม (`ends_on`) — **ห้ามแก้ template ที่มี occurrence ย้อนหลัง**
- Occurrence ที่สร้างแล้วเป็นอิสระ: แก้/ยกเลิกได้โดยไม่กระทบ template

### 4.3 Appointment

```
planned → confirmed → attended
   └→ rescheduled / cancelled / missed / needs_followup
```
- `attended` → **สร้าง follow-up task 1 ครั้ง** (idempotent ด้วย `appointment_id` เป็น key) — APT-004
- เอกสารที่ต้องเตรียมเป็น checklist ที่ผูกกับนัด ไม่ใช่ข้อความอิสระ

### 4.4 Case / Interaction (Wave 2)

⛔ **ห้าม implement** จนกว่าจะมี `care_case.*` / `interaction_log.*` ผ่าน [CR-002](changes/CR-001-permission-delta-v1.1.md) — ถูกล็อกโดย [ADR-012](DECISIONS.md#adr-012--baseline-v11-delta-ต้องมี-permission-เพิ่ม-4-โดเมน)

---

## 5. การเชื่อมกับ Health Record

**หลัก:** CRM ไม่เขียนค่าสุขภาพเอง — ส่ง **command** ผ่าน contract แล้วให้ `HealthRecord` เป็นผู้เขียน

```
CompleteTask(task, value=112 mg/dL)
   → CareCrm.CareTask.complete()
   → dispatch CareTaskCompletedWithMetric { task_id, profile_id, metric, value, unit, recorded_at, actor_id }
   → HealthRecord.CreateMeasurement (validate + persist)
   → ทั้งสองอยู่ใน DB transaction เดียว (single unit of work)
   → Timeline subscriber เขียน timeline_entries
```

| กฎ | รายละเอียด |
|---|---|
| **CRM-001** | ใช้ `HealthRecordContract` เท่านั้น — ห้าม `HealthMeasurement::create()` จากใน `CareCrm` (N-06) |
| **CRM-002** | ถ้า HealthRecord ปฏิเสธ (validation/สิทธิ์) → **rollback งานด้วย** งานต้องไม่ complete แบบไม่มีข้อมูล |
| **CRM-003** | ค่าที่บันทึกต้องมี `recorded_by` = ผู้กดยืนยัน และ `source = care_task` |
| **CRM-004** | การกรอกค่าที่ตัวเลขผิดปกติ **ห้าม** ให้ระบบตัดสินแทน — ใช้ threshold แจ้งเตือนเท่านั้น |

---

## 6. การเชื่อมกับ Authorization Baseline

ทุก use case ต้องเรียก `Authorize` ([`03 §2`](03-AUTHORIZATION.md#2-ลำดับตัดสินใจ-บังคับ)) **ก่อน**ทำงาน

| Operation | permission | scope/เงื่อนไข |
|---|---|---|
| ดูรายการงาน | `care_task.read` | ต้องมี grant `tasks` ของ profile หรือเป็น assignee |
| สร้างงาน | `care_task.create` | `tasks` scope |
| มอบหมาย | `care_task.assign` | ต้องเป็น coordinator หรือมีสิทธิ์มอบหมาย |
| รับ/เริ่มงาน | `care_task.accept`, `care_task.start` | assignee |
| บันทึกเสร็จ | `care_task.complete` | assignee หรือ `task_assignments.can_complete` |
| ตรวจรับ | `care_task.verify` | **ไม่ใช่ผู้ทำ** (ค่าเริ่มต้น clinical) — CT-003 |
| ตีกลับ/เปิดใหม่ | `care_task.reopen` | coordinator/verifier + เหตุผล |
| ยกเลิก | `care_task.cancel` | coordinator |
| แจ้งอุปสรรค | `care_task.block` | assignee |
| หลักฐาน | `care_task.evidence.create` / `.read` | ตาม scope ของงาน |
| แผนดูแล | `care_plan.read` / `.create` / `.update` | `tasks` scope |
| นัด | `appointment.read` / `.create` / `.update` / `.cancel` | `appointments` scope |
| Timeline | `timeline.read` | **ต้องตรวจ scope ของข้อมูลต้นทางซ้ำ** (F-06) |

**กฎที่ห้ามละเมิด**

| ID | กฎ |
|---|---|
| **CRM-005** | การมอบหมายไม่สร้าง access grant (AS-005 / F-04) |
| **CRM-006** | การเห็นการ์ดงาน ≠ เห็นเวชระเบียน — ใช้ **need-to-know projection** (AS-004): ชื่อ, ชื่องาน, เวลา, สถานที่, ผู้ติดต่อ เท่านั้น |
| **CRM-007** | `verify` ต้องไม่ใช่ผู้ทำคนเดียวกัน เว้นแต่ `care_plans.verification_policy` อนุญาต |
| **CRM-008** | งาน clinical ที่ไม่มี evidence → ปฏิเสธที่ระดับโดเมน **ก่อน** ถึง DB (CT-005) |
| **CRM-009** | AI ห้ามเปลี่ยนสถานะ/มอบหมาย/verify (CT-010) |
| **CRM-010** | ทุกคำสั่งของ CRM ต้องมี tenant + workspace context (ADR-013) |

---

## 7. Timeline (read model)

| กฎ | รายละเอียด |
|---|---|
| **CRM-011** | `timeline_entries` เป็น **projection** — rebuild ได้จาก event ทั้งหมด |
| **CRM-012** | เก็บเฉพาะ reference + summary ที่ปลอดภัย (DM-007) — ห้ามเก็บค่าที่วัดได้/ผลวินิจฉัย |
| **CRM-013** | การอ่าน timeline ต้องตรวจสิทธิ์ **ต่อรายการ** ไม่ใช่ตรวจครั้งเดียวทั้งหน้า |
| **CRM-014** | ถ้า entry ชี้ไปยังข้อมูลที่ผู้ใช้ไม่มีสิทธิ์ → แสดงว่า "มีเหตุการณ์" แต่ไม่เปิดรายละเอียด |

---

## 8. Notification hooks

| Event | ผู้รับ | template key | ห้ามมี |
|---|---|---|---|
| `CareTaskCreated` | assignee | `task.created` | ชื่อโรค/ค่าแล็บ |
| `CareTaskDueSoon` | assignee | `task.due` | ค่ายา/ขนาดยา |
| `CareTaskEscalated` | coordinator | `task.escalated` | ข้อมูลสุขภาพ |
| `AppointmentReminder` | ผู้พาไป + เจ้าของ | `appt.reminder` | แผนก/โรค |
| `BreakGlassActivated` | สมาชิกหลักทุกคน | `bg.activated` | **ไม่ระบุข้อมูลใด ๆ** |

> ข้อความต้องผ่านเกณฑ์ [`MOBILE_DESIGN §7`](MOBILE_DESIGN.md) และ `ES-002` — ระบบส่ง notification ผ่าน outbox เพื่อกัน "บันทึกสำเร็จแต่แจ้งไม่ถึง" ([`07 §5`](07-API-CONTRACT.md#5-idempotency--outbox))

---

## 9. API Responsibilities

CRM เป็นเจ้าของ endpoint กลุ่มนี้ (รายละเอียดเต็มอยู่ที่ [`07 §3.6`](07-API-CONTRACT.md#36-care-coordination)):

- `/workspaces/{w}/care-tasks` + actions (`assign`, `accept`, `decline`, `start`, `complete`, `verify`, `reject`, `reopen`, `cancel`, `block`)
- `/workspaces/{w}/care-task-templates`
- `/workspaces/{w}/appointments`
- `/workspaces/{w}/profiles/{profile}/care-plans`
- `GET /workspaces/{w}/dashboard` → **CRM เป็นผู้ประกอบการ์ดงาน** แต่ Health Vault ประกอบการ์ดค่าสุขภาพ (ADR-010)

**ข้อกำหนด:** ทุก action endpoint คืน `version` ใหม่ (AP-007) และ idempotent เมื่อมี `Idempotency-Key`

---

## 10. Domain Events

| Event | Payload (ขั้นต่ำ) | Publisher | Subscriber |
|---|---|---|---|
| `CareTaskCreated` | task_id, profile_id, tier, due_at | CareCrm | Notification, Timeline |
| `CareTaskAssigned` | task_id, from_user, to_user | CareCrm | Notification, Audit |
| `CareTaskAccepted` | task_id, user_id | CareCrm | Timeline |
| `CareTaskCompleted` | task_id, outcome, has_evidence, actor | CareCrm | Timeline, Notification |
| `CareTaskCompletedWithMetric` | task_id, metric, value, unit | CareCrm → HealthRecord | HealthRecord |
| `CareTaskVerified` | task_id, verifier_id, result | CareCrm | Timeline, Notification |
| `CareTaskEscalated` | task_id, level, target | CareCrm | Notification |
| `AppointmentAttended` | appointment_id, profile_id | CareCrm | CareCrm (follow-up), Timeline |
| `CarePlanChanged` | plan_id, profile_id, change_type | CareCrm | Timeline, Audit |

**กฎ**
| ID | กฎ |
|---|---|
| **CRM-015** | Event payload มีได้แค่ id + metadata ที่ไม่ใช่ PHI (N-02) |
| **CRM-016** | ผู้รับ event ต้อง idempotent (at-least-once delivery) |
| **CRM-017** | Event ถูกเขียนใน **outbox** ภายใน transaction เดียวกับข้อมูลธุรกิจ |

---

## 11. Invariants สรุประดับสถาปัตยกรรม

| ID | Invariant |
|---|---|
| **CRM-018** | 1 aggregate = 1 writer (ไม่มีสองโมดูลเขียนตารางเดียวกัน) |
| **CRM-019** | การเปลี่ยนสถานะทุกครั้งสร้าง `task_events` ที่ append-only |
| **CRM-020** | การลบงาน = soft delete ตาม retention เท่านั้น (ADR-016) — ไม่มี hard delete |
| **CRM-021** | `care_task_templates` ที่หยุดใช้ต้อง `ends_on` — ห้ามลบถ้ามี occurrence |
| **CRM-022** | Escalation policy อ่านจาก config ต่อ task type — ห้าม hardcode (ES-001) |
| **CRM-023** | Dashboard ต้องตรวจสิทธิ์ **ต่อการ์ด** ก่อนใส่ payload (ADR-010, AP-003) |
| **CRM-024** | ห้ามให้ module อื่นอ่านตาราง CRM ตรง ๆ — ใช้ contract หรือ event |

---

## 12. Non-goals / Anti-patterns

| ❌ ห้าม | เพราะ |
|---|---|
| รวม `care_tasks` กับ `health_measurements` เป็นตารางเดียว | ทำลาย F-04 และการตรวจสิทธิ์ |
| ให้ CRM คำนวณ adherence จากตารางสุขภาพเอง | adherence คำนวณใน HealthRecord/Medication แล้วส่งมา |
| ใช้ `timeline_entries` เป็น source of truth | จะกลายเป็นสำเนาข้อมูลสุขภาพที่ตรวจสิทธิ์ยาก |
| ใส่รายละเอียดสุขภาพใน event payload | รั่วผ่าน log/queue/notification |
| ให้ AI ปิดงานหรือ verify | CT-010 |
| ทำ Case/Interaction ก่อนได้ permission | ADR-012 / CRM-017 |

---

## 13. Test Responsibilities

| ชั้น | test ID | ขอบเขต |
|---|---|---|
| Domain | `CT-T0x` | transition, tier, primary assignee |
| Domain | `RC-T0x` | occurrence, idempotency, DST |
| Domain | `ES-T0x` | ladder, no-PHI message |
| Domain | `AS-T0x` | pool → 1 owner, need-to-know |
| Domain | `APT-T0x` | appointment state + follow-up |
| Domain | `DB-T0x` | doctor brief |
| **Architecture** | **`CRM-T01…T06`** | event idempotency, cross-module boundary, projection rebuild, single-writer |

**ชุดทดสอบระดับสถาปัตยกรรม (ต้องมีเสมอ):**

| ID | ทดสอบ |
|---|---|
| `CRM-T01` | complete task พร้อม metric → เขียน `care_tasks` + `health_measurements` สำเร็จหรือ rollback ทั้งคู่ |
| `CRM-T02` | ส่ง event ซ้ำ → consumer ไม่สร้างข้อมูลซ้ำ |
| `CRM-T03` | rebuild timeline จาก event → ได้ผลเท่ากับของจริง |
| `CRM-T04` | static/architecture test: ไม่มี query ข้าม module (N-06) |
| `CRM-T05` | need-to-know projection ไม่มีผลแล็บ/วินิจฉัย แม้ผู้ใช้มี `care_task.read` |
| `CRM-T06` | `AppointmentAttended` สร้าง follow-up task **ครั้งเดียว** แม้ event ซ้ำ |

---

## 14. รายการที่ยังเปิดอยู่

| # | เรื่อง | บล็อกโดย |
|---|---|---|
| 1 | `care_case.*` / `interaction_log.*` (Wave 2) | CR-002 / ADR-012 |
| 2 | รูปแบบ `escalation_policies` (schema + UI ตั้งค่า) | ยังไม่ออกแบบ — ต้องมีก่อน Phase 2 |
| 3 | `verification_policy` ต่อ plan (ใคร verify ได้) | ต้องตัดสินตอนทำ clinical tier |
| 4 | การรวม `prioritization` ของ AI (เสนอ ไม่สั่ง) | Phase 3 |
