# 02 · DOMAIN MODEL — ขอบเขตโดเมน, aggregate, ตารางมาตรฐาน

> อ้างอิง ADR: [ADR-006](DECISIONS.md#adr-006--ชื่อตารางมาตรฐานชุดเดียว), [ADR-009](DECISIONS.md#adr-009--dependent-profile-ไม่ต้องเพิ่ม-role-ใหม่)
> Permission ID ทั้งหมดอ้างจาก [`docs/baseline-v1.0.0/PERMISSION_CATALOG.md`](../docs/baseline-v1.0.0/PERMISSION_CATALOG.md) — ห้ามประกาศชุดใหม่ในไฟล์นี้

---

## 1. Bounded Contexts (15)

| # | Context | ความรับผิดชอบ | โมดูล Laravel | เหตุผลที่ต้องแยก |
|---|---|---|---|---|
| C1 | Identity & Access | บัญชี, session, token, การยืนยันตัวตน (PIN/biometric/OTP) | `Accounts` | ความลับของ credential ไม่ควรอยู่ในโดเมนสุขภาพ |
| C2 | Tenant & Workspace | tenant, workspace, membership, invitation, การตั้งค่า workspace | `Workspace` | ขอบเขตข้อมูลทั้งหมดขึ้นกับที่นี่ (tenant = ชั้นนอกสุด, ADR-013) |
| C3 | Person & Relationship | person, contact points, kinship, care ring | `People` | บุคคล≠บัญชี (F-14) |
| C4 | Authorization | ตัดสินอนุญาต/ปฏิเสธ, policy evaluation | `Authz` (package) | ต้องเป็นจุดเดียว (F-01) |
| C5 | Consent & Delegation | consent record, access grant, legal authority, break-glass | `Consent` | คนละกลไกกับ permission (F-12) |
| C6 | Health Profile | ข้อมูลประจำตัวสุขภาพ, โรคประจำตัว, แพ้ยา | `HealthRecord` | ข้อมูลอ่อนไหวที่สุด |
| C7 | Health Measurement | ค่าวัด (ความดัน/น้ำตาล/ชีพจร/น้ำหนัก/SpO2), trend | `HealthRecord` | ปริมาณมาก ต้อง partition/rollup |
| C8 | Medication | แผนยา, บันทึกการกิน, คงเหลือ, adherence | `HealthRecord` | state เยอะ (plan vs record) |
| C9 | Care Coordination (CRM) | care plan, task, assignment, recurring, escalation, handover, case | `CareCrm` | หัวใจของผลิตภัณฑ์ — รายละเอียดที่ [`CRM_DOMAIN_ARCHITECTURE.md`](CRM_DOMAIN_ARCHITECTURE.md) |
| C10 | Appointment & Records | นัด, ผู้พาไป, เอกสาร, ผลตรวจ | `CareCrm` + `HealthRecord` | นัด≠เวชระเบียน |
| C11 | Timeline | เหตุการณ์รวมแบบอ้างอิงต้นทาง | `Timeline` (read model) | read model เท่านั้น ห้ามเป็น source of truth |
| C12 | Notification | in-app, push, LINE, สถานะการส่ง, preference | `Notifications` | ต้อง retry/ dedupe ได้ |
| C13 | AI Orchestration & Safety | context gateway, guardrail, provider routing, quota | `AiGateway` | ควบคุม PHI ออกนอกเซิร์ฟเวอร์ |
| C14 | Audit & Compliance | audit event, security log, การขอข้อมูล, retention | `Audit` | append-only (F-15) |
| C15 | Subscription & Entitlement | แพ็กเกจ, quota, feature flag | `Billing` | ต้องแยกจาก permission |

**กฎการพึ่งพา:** C4 ถูกเรียกจากทุก context; C13 ห้ามเรียก DB ของ C6–C8 ตรง — ต้องขอผ่าน C13 gateway ที่เรียก C4 ก่อน (N-03, N-06)

---

## 2. แนวคิดที่ต้องแยกจากกัน (F-14)

| แนวคิด | ตาราง | มีบัญชีผู้ใช้? | ให้สิทธิ์ข้อมูล? | หมายเหตุ |
|---|---|---|---|---|
| User | `users` | ✅ | ❌ | บัญชีสำหรับเข้าสู่ระบบ |
| Person | `persons` | ไม่จำเป็น | ❌ | ตัวบุคคลจริง (อาจเป็นเด็ก/ผู้ป่วยติดเตียง) |
| Health Profile | `health_profiles` | ❌ | ❌ | ข้อมูลสุขภาพของ person 1 คน |
| Workspace Member | `workspace_memberships` | ✅ | ❌ | สมาชิกของพื้นที่ (F-02) |
| Care Relationship | `care_relationships` | ❌ | ❌ | ความสัมพันธ์/วงดูแล (F-05) |
| Access Grant | `access_grants` | ❌ | ✅ | **ให้สิทธิ์จริง** มีขอบเขต+อายุ |
| Consent Record | `consent_records` | ❌ | ❌ | เงื่อนไขตามวัตถุประสงค์ (F-12) |
| Care Task | `care_tasks` | ❌ | ❌ | งาน (F-04) |

---

## 3. Aggregate Roots

| Context | Aggregate | ประกอบด้วย | Invariant หลัก |
|---|---|---|---|
| C1 | `User` | credentials, devices, sessions | token ผูก device; PIN ไม่เก็บ plaintext |
| C2 | `Workspace` | memberships, invitations, settings | ทุก entity ที่ workspace-scoped ต้องมี `workspace_id` ตรง |
| C3 | `Person` | contact_points, relationships | person 1 คนมี health profile ได้ ≤1 |
| C4 | — (stateless service) | — | ห้าม cache ผลอนุมัติเกิน 60 วินาที |
| C5 | `AccessGrant` | scope, actions, validity | **ไม่เกินสิทธิ์ผู้ให้** (F-07) |
| C5 | `ConsentRecord` | purpose, policy version, evidence | เวอร์ชันใหม่ไม่ทับหลักฐานเดิม |
| C6 | `HealthProfile` | conditions, allergies | ต้องมี owner ที่ระบุได้ (F-13) |
| C7 | `HealthMeasurement` | value, unit, source, recorded_by | ต้องมี `recorded_at` + `source` + ผู้บันทึก |
| C8 | `MedicationPlan` | items, schedule | plan ≠ record; discontinue ต้องมีเหตุผล |
| C9 | `CareTask` | assignments, events, evidence | 1 ผู้รับผิดชอบหลัก (F-18 เสริม) |
| C9 | `CareTaskTemplate` | recurrence rule | occurrence ต้อง unique ต่อ (template, scheduled_for) |
| C10 | `Appointment` | participants, documents | ผู้เข้าร่วมต้องมีสิทธิ์เห็นนัดนั้น |
| C11 | `TimelineEntry` | reference ไปต้นทาง | **ห้ามเก็บสำเนาข้อมูลสุขภาพ** |
| C13 | `AiContextRequest` | scope, redaction map, provider | ต้องมี consent + permission ทุกครั้ง |
| C14 | `AuditEvent` | actor, action, target, result | append-only, hash chain |
| C15 | `Subscription` | plan, entitlements, quota | entitlement ไม่ใช่ permission |

---

## 4. ความสัมพันธ์หลัก (ข้อความ ไม่ใช่ ERD เต็ม)

```
tenants 1 ─── n workspaces
workspaces 1 ─── n workspace_memberships ─── 1 users
workspaces 1 ─── n persons
persons 1 ─── 0..1 health_profiles
persons n ─── n persons            (person_relationships: kinship)
health_profiles 1 ─── n care_relationships     (ring: inner|support|professional)
health_profiles 1 ─── n access_grants ─── users
health_profiles 1 ─── n consent_records
persons 1 ─── n care_task_templates 1 ─── n care_tasks
care_tasks 1 ─── n task_assignments (1 primary, n assistants)
care_tasks 1 ─── n task_events, 0..n task_evidence
care_tasks n ─── 0..1 appointments
health_profiles 1 ─── n documents
health_profiles 1 ─── n health_measurements / medication_plans / medication_records
```

---

## 5. ตารางมาตรฐาน (Canonical Tables)

> **กฎ:** ชื่อในคอลัมน์แรกคือชื่อเดียวที่ใช้ใน migration, model, API, และเอกสารทุกฉบับ
> คอลัมน์ alias ไว้ให้ค้นเอกสารเก่าเท่านั้น **ห้ามใช้ alias ในโค้ด**

| ตารางมาตรฐาน | Context | alias ในเอกสารเดิม | หมายเหตุ |
|---|---|---|---|
| `tenants` | C2 | — | 1 แถวใน MVP (`slug='default'`); ทุกตาราง tenant-scoped อ้างถึง (ADR-013) |
| `workspaces` | C2 | — | |
| `workspace_memberships` | C2 | `workspace_members` | ADR-006 |
| `invitations` | C2 | — | QR / code, มี expiry |
| `users` | C1 | — | |
| `persons` | C3 | — | ไม่ผูก user เสมอ |
| `contact_points` | C3 | — | phone/email/line_id, มี `is_primary` |
| `person_relationships` | C3 | — | kinship ระหว่าง person |
| `health_profiles` | C6 | — | 1:1 กับ person |
| `care_relationships` | C3 | — | role ในวงดูแล + `ring` |
| `delegations` | C5 | — | อำนาจตามกฎหมายของผู้แทน: `level ∈ L1/L2/L3`, หลักฐาน, วันหมดอายุ (ADR-015) |
| `access_grants` | C5 | `delegation_grants`, `caregiver_assignments` | **ให้สิทธิ์ข้อมูล** |
| `consent_records` | C5 | `consents` | append-only versioned |
| `health_conditions` | C6 | — | โรคประจำตัว |
| `allergies` | C6 | — | |
| `health_measurements` | C7 | `vital_measurements` | ADR-006 |
| `medication_plans` | C8 | — | |
| `medication_records` | C8 | — | 1 แถว = 1 ครั้งที่กิน/ข้าม |
| `care_plans` | C9 | — | |
| `care_task_templates` | C9 | — | recurring rule |
| `care_tasks` | C9 | — | |
| `task_assignments` | C9 | — | ผู้รับผิดชอบหลัก/ผู้ช่วย |
| `task_events` | C9 | — | transition log |
| `task_evidence` | C9 | — | รูป/ค่า/บันทึก |
| `appointments` | C10 | — | |
| `documents` | C10 | `document_references`, `medical_documents` | metadata + object ref; `category ∈ general/lab_report/imaging/prescription/referral/identity/legal_authority/appointment` (ADR-014) |
| `care_cases` | C9 | — | เรื่องที่ต้องปิดให้จบ |
| `interaction_logs` | C9 | — | บันทึกการติดต่อ |
| `communication_preferences` | C12 | — | ช่องทาง/ภาษา/เวลา |
| `notification_deliveries` | C12 | `notification_records` | 1 แถว = 1 ความพยายามส่ง |
| `timeline_entries` | C11 | `crm_timeline_events` | read model |
| `audit_events` | C14 | — | ธุรกิจ (ดูว่าใครทำอะไร) |
| `security_audit_logs` | C14 | — | ความปลอดภัย, hash chain, append-only |
| `data_holds` | C14 | — | legal hold: บล็อกการ purge (ADR-016) |
| `ai_conversations` | C13 | `AIConversation` | |
| `ai_context_requests` | C13 | `AIContextRequest` | audit ของการดึงบริบท |
| `ai_audit_logs` | C13 | — | prompt hash + scope ที่ใช้ |
| `ai_usage_counters` | C13 | — | quota/ต้นทุน |
| `subscriptions`, `entitlements` | C15 | — | |
| `feature_flags` | C15 | — | |

**ตารางที่ตั้งใจไม่ทำ:** `crm_timeline_events` แบบเก็บข้อมูลซ้ำ (ใช้ `timeline_entries` ที่อ้างต้นทาง), `workspace_permissions` (สิทธิ์คำนวณจาก grant/relationship ไม่ใช่ตารางสำเนา)

---

## 6. กฎระดับโดเมน (DM-xx)

| ID | กฎ | test |
|---|---|---|
| **DM-001** | ทุกตารางที่ workspace-scoped ต้องมี `workspace_id NOT NULL` และ FK ไป `workspaces` | `DM-T01` |
| **DM-002** | `health_profiles` ต้องมี `person_id` ที่มีอยู่จริง และ person ต้องมี `owner_person_id` หรือ `managed_by_person_id` | `DM-T02` |
| **DM-003** | การย้าย profile ระหว่าง workspace ต้องเป็น workflow (สร้างคำขอ + audit) ไม่ใช่ UPDATE `workspace_id` | `DM-T03` |
| **DM-004** | `health_measurements` ต้องมี `recorded_at`, `source`, `recorded_by` (user หรือ system) | `DM-T04` |
| **DM-005** | `access_grants` ห้าม `expires_at <= valid_from` และห้าม grant ที่กว้างกว่าผู้ให้ (F-07) | `GRANT-T01` |
| **DM-006** | `consent_records` ห้าม UPDATE ทับ — ใช้ row ใหม่ + `superseded_by_id` | `CONSENT-T01` |
| **DM-007** | `timeline_entries` เก็บเฉพาะ reference + summary ที่ปลอดภัย ไม่เก็บค่าแล็บ/ผลวินิจฉัย | `DM-T05` |
| **DM-008** | `notification_deliveries` ห้ามเก็บ payload ที่มี PHI (เก็บ template key + params ที่ scrub แล้ว) | `SEC-T03` |
| **DM-009** | `audit_events` และ `security_audit_logs` เป็น append-only | `AUDIT-T01` |
| **DM-010** | ทุกตารางต้องมี `created_at/updated_at` และตารางข้อมูลสุขภาพมี `version` สำหรับ optimistic locking | `DM-T06` |
| **DM-011** | ทุกตาราง tenant-scoped ต้องมี `tenant_id NOT NULL` + FK composite `(workspace_id, tenant_id) → workspaces(id, tenant_id)` — ยกเว้น `tenants`, `users`, `feature_flags` (ADR-013) | `TEN-T01` |
| **DM-012** | scope ที่ประกาศแต่ปิดใช้งาน (`mental_health`, `lab_results`) **ห้ามมี** endpoint, คอลัมน์, หรือตาราง จนกว่าจะครบเงื่อนไข 5 ข้อใน ADR-014 | `SEN-T01` |

---

## 7. สิ่งที่ห้ามทำ (สรุปสำหรับ agent)

- ❌ รวม `persons` เข้ากับ `users` "เพื่อความง่าย"
- ❌ เพิ่มคอลัมน์ `role` บน `users` เป็นการถาวร (role ขึ้นกับ workspace — ดู `03`)
- ❌ เก็บสำเนาข้อมูลสุขภาพใน `timeline_entries` หรือ `interaction_logs` โดยไม่จำเป็น
- ❌ สร้างตารางใหม่โดยไม่อัปเดตไฟล์นี้และ `06-DATA-MODEL.md`
- ❌ ใช้ชื่อ alias จากคอลัมน์ที่ 3 ในโค้ด
