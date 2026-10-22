# DECISIONS — Architecture Decision Records

> ทุกจุดที่เอกสารต้นทางขัดกัน ถูกตัดสินที่นี่ **หนึ่งครั้ง** แล้วทุกเอกสาร `.ai/` อ้าง ADR แทนการถกกันใหม่
> สถานะ: `Accepted` = มีผลแล้ว · `Superseded` = ถูกแทนด้วย ADR ใหม่ · `Open` = ยังไม่ตัดสิน (ห้าม implement ส่วนนั้น)

| ADR | เรื่อง | สถานะ | แทนที่ |
|---|---|---|---|
| [ADR-001](#adr-001--รวม-care-task-state-machine-เป็นโมเดลเดียวแบบ-3-ระดับ) | Care Task state machine | ✅ Accepted | — |
| [ADR-002](#adr-002--break-glass-implement-แต่ปิดเป็นค่าเริ่มต้น) | Break-glass | ✅ Accepted | — |
| [ADR-003](#adr-003--line-เป็นช่องทางแจ้งเตือนใน-phase-1-ไม่ใช่-ui-หลัก) | LINE timing | ✅ Accepted | — |
| [ADR-004](#adr-004--โครงสร้างแท็บมือถือ-5-แท็บ-chat-ai-ไม่ใช่แท็บ) | Mobile IA | ✅ Accepted | `docs/MOBILE_DESIGN.txt` |
| [ADR-005](#adr-005--api-เป็น-workspace-scoped-ทั้งหมด) | API shape | ✅ Accepted | — |
| [ADR-006](#adr-006--ชื่อตารางมาตรฐานชุดเดียว) | Table naming | ✅ Accepted | SUMMARY + SUMMARY_2 + PDF2 |
| [ADR-007](#adr-007--postgresql-rls-ใช้เฉพาะ-tenant-isolation) | RLS | ✅ Accepted | — |
| [ADR-008](#adr-008--uuidv7-เก็บเป็น-native-uuid-ไม่ใช่-string) | UUID storage | ✅ Accepted | SUMMARY.md |
| [ADR-009](#adr-009--dependent-profile-ไม่ต้องเพิ่ม-role-ใหม่) | Dependent profile | ✅ Accepted | — |
| [ADR-010](#adr-010--dashboard-ใช้-endpoint-รวมตัวเดียว) | Dashboard API | ✅ Accepted | — |
| [ADR-011](#adr-011--ราคาเป็นสมมติฐาน-ยังไม่ตัดสิน) | Pricing | ⏸ Open | — |
| [ADR-012](#adr-012--baseline-v11-delta-ต้องมี-permission-เพิ่ม-4-โดเมน) | Baseline delta | ✅ Accepted (Wave 1) · Wave 2 open | — |
| [ADR-013](#adr-013--tenancy-shared-schema--tenant_id-ตั้งแต่-phase-0) | Tenancy model | ✅ Accepted | — |
| [ADR-014](#adr-014--sensitive-scopes-ปิดใน-mvp-mental_health-lab_results) | Sensitive scopes | ✅ Accepted | — |
| [ADR-015](#adr-015--หลักฐานอำนาจผู้แทน-3-ระดับตามความเสี่ยง) | Legal representative evidence | ✅ Accepted | — |
| [ADR-016](#adr-016--retention-ตามวัตถุประสงค์-pdpa--tenant-override) | Retention policy | ✅ Accepted | — |
| [ADR-017](#adr-017--break-glass-เปิดให้ทุกแพ็กเกจ-ไม่ผูกกับราคา) | Break-glass access | ✅ Accepted | — |
| [ADR-018](#adr-018--g0-scoping--capability-dependent-checklist-ย้ายไป-gate-ของ-feature-นั้น) | G0 scoping | ✅ Accepted | — |
| [ADR-019](#adr-019--เริ่ม-phase-1-ขณะ-g0-ข้อ-10-ยังเปิด-time-boxed-override) | Phase 1 start override | ⚠️ Accepted (time-boxed) | — |

---

## ADR-001 · รวม Care Task state machine เป็นโมเดลเดียวแบบ 3 ระดับ

**บริบท** — มีสามแบบที่ขัดกัน:
1. `SUMMARY.md` — 7 สถานะรวม `Completed` แยกจาก `Verified`
2. `SUMMARY_2.md §3.6` — 4 สถานะ + Cancelled/Overdue ไม่มี `Verified`
3. `PDF1` — วิจารณ์ว่า 7–9 สถานะทำให้เกิด Click Fatigue เสนอ 3 ระดับตามความเสี่ยง

**การตัดสิน** — รวมเป็น **1 โมเดล** โดยแยกเป็น 3 คอลัมน์ (ไม่ใช้ `status` คอลัมน์เดียว):

- `workflow_tier` ∈ `routine | delegated | clinical` (กำหนดตอนสร้างงานตามประเภทงาน)
- `status` ∈ `scheduled | open | assigned | accepted | in_progress | blocked | completed | cancelled`
- `outcome` ∈ `null | done | skipped | missed | failed`
- `verification` ∈ `not_required | pending | verified | rejected`

โดยแต่ละ tier ใช้ transition subset ต่างกัน (รายละเอียด [`05-CARE-COORDINATION.md`](05-CARE-COORDINATION.md#2-state-machine))

**เหตุผล**
- ได้ประโยชน์จาก PDF1 (งาน routine 1 คลิก) โดยไม่ทิ้ง `care_task.verify` ที่มีอยู่ใน baseline (ยังจำเป็นสำหรับงาน clinical — ตรงกับ `AT-008` ที่ต้องมีสิทธิ์เพิ่มเติมในการ complete งานของคนอื่น)
- การแยก 3 คอลัมน์ตอบข้อกำหนด `SUMMARY.md §4` ตรงๆ: *"อย่าใช้ `status` เพียงคอลัมน์เดียวแทนทุกเรื่อง"*
- `overdue` **ไม่ใช่สถานะ** แต่เป็นสถานะที่คำนวณจาก `due_at` + `escalation_level` (มิฉะนั้นต้องมี cron ย้ายสถานะทุกงาน)

**ทางเลือกที่ตัดทิ้ง**
- *ใช้ 7 สถานะเดิมทุกงาน* — ผู้ใช้จะเลิกใช้ (เหตุผลของ PDF1)
- *ใช้ 3 tier เป็น enum เดียว* — ต้องมี 20+ สถานะรวมกัน และทดสอบ transition ไม่ไหว
- *ใช้ `Verified` เป็น boolean* — สูญเสีย `rejected` + reason ที่ต้องมีเพื่อ audit

**ผลกระทบ**: `05-CARE-COORDINATION.md`, `06-DATA-MODEL.md` (`care_tasks`), `07-API-CONTRACT.md` (transition endpoints), `10-TESTING.md` (`CT-*`)

---

## ADR-002 · Break-glass: implement แต่ปิดเป็นค่าเริ่มต้น

**บริบท** — `SUMMARY.md §6` บอก "ถ้าจะรองรับในอนาคต", `SUMMARY_2.md` ไม่กล่าวถึง, แต่ **baseline มี `break_glass.request/approve/use` อยู่แล้ว 3 ตัว** → ถ้าไม่ทำ จะผิดกฎ "ทุก permission ที่เปิดใช้ต้องมี matrix + entry point + test" และ PDF1 ชี้ว่าเป็นช่องโหว่ชีวิตจริง (ผู้สูงอายุหมดสติ)

**การตัดสิน** — **ทำ** แต่:
- ปิดเป็นค่าเริ่มต้น**ต่อ workspace** (`workspaces.break_glass_enabled = false`)
- ต้องมี **ผู้ดูแลที่ยืนยันอำนาจแล้ว** (legal_guardian หรือ profile_owner) เป็นผู้อนุมัติ หรือใช้ dual-control
- เปิดได้เฉพาะ **Critical Summary** (กรุ๊ปเลือด, แพ้ยา, โรคประจำตัว, ยาปัจจุบัน) — ไม่เปิดเวชระเบียนเต็ม
- อายุไม่เกิน `4 ชั่วโมง` (ค่าเริ่มต้น) ต่อครั้ง และไม่ต่ออายุอัตโนมัติ
- ต้องระบุเหตุผล + re-auth (OTP/biometric) ทุกครั้ง
- แจ้งเตือนระดับสูงสุดให้เจ้าของข้อมูล + สมาชิกหลักทุกคนทันที
- ลง `security_audit_logs` แบบ append-only + hash chain

**ทางเลือกที่ตัดทิ้ง** — *ไม่ทำเลย* (ขัดกับ permission ที่ freeze ไว้ + เสี่ยงชีวิต), *เปิดตลอด* (ขัด F-01)

**ผลกระทบ**: `04-CONSENT-DELEGATION.md` (§4), `09-SECURITY-PRIVACY.md` (T-07), `10-TESTING.md` (`SEC-*`, `GRANT-*`), `MOBILE_DESIGN.md` (SOS card)

---

## ADR-003 · LINE เป็นช่องทางแจ้งเตือนใน Phase 1 ไม่ใช่ UI หลัก

**บริบท** — `SUMMARY.md` วาง LINE ไว้ Phase 2 (in-app ก่อน เพื่อพิสูจน์ความต้องการและต้นทุน) แต่ `PDF1` แย้งว่า LINE คือหัวใจของผู้ใช้ไทยและควรเป็น Phase 1

**การตัดสิน** — แยกให้ชัดว่า **LINE = notification channel ไม่ใช่ interface ของผลิตภัณฑ์**:
- **Phase 1 ทำ**: LINE OA ส่งแจ้งเตือนงาน/นัด + **กดยืนยันด้วย rich message (postback)** สำหรับงาน `routine` เท่านั้น
- **Phase 1 ไม่ทำ**: อ่านข้อมูลสุขภาพผ่าน LINE, กรอกค่า, จัดการสิทธิ์, ดูกราฟ
- **Phase 2**: LIFF (LINE Front-end Framework) สำหรับงาน delegated และการรับงาน
- ข้อมูลที่ส่งผ่าน LINE ถูกจำกัดตาม `N-02` — ไม่มีค่าแล็บ/ผลวินิจฉัย/ชื่อโรคในข้อความแจ้งเตือน

**เหตุผล** — ตอบโจทย์ adoption ของ PDF1 โดยไม่ทิ้งข้อควรระวังของ SUMMARY.md (ต้นทุน/ความต้องการที่ยังไม่พิสูจน์) และลดพื้นที่โจมตี (LINE เป็นช่องทางที่อยู่นอก authorization boundary ของแอป)

**ผลกระทบ**: `01-PRODUCT.md`, `07-API-CONTRACT.md` (webhook), `08-AI-GATEWAY.md` (ไม่เกี่ยว), `MOBILE_DESIGN.md` (§6), `12-ROADMAP.md`

---

## ADR-004 · โครงสร้างแท็บมือถือ 5 แท็บ, Chat AI ไม่ใช่แท็บ

**บริบท** — `docs/MOBILE_DESIGN.txt` ระบุ 5 แท็บ: Home · **Health Metric** · **Chat (AI)** · **Appoint** · CRM
แต่ `PDF2 §4` ระบุ 5 แท็บ: Dashboard · Care CRM · **(+)** · Health Vault · Family & Access

**การตัดสิน** — ยึดชุดของ PDF2 เพราะผูกกับ baseline (Family & Access = ที่จัดการ consent/grant) แล้ว **ย้าย Chat AI ไปเป็น entry ระดับบน** (ไอคอนใน top bar + การ์ดใน Home) และ **รวม Appoint เข้า Care CRM** เป็นแท็บเดียว

**เหตุผล**
- 5 แท็บต้องครอบ "งานที่ทำบ่อยที่สุด" — Chat AI ไม่ใช่การใช้งานรายวันของครอบครัวไทย แต่การยืนยันงาน/ดูคิวคือของจริง
- ถ้า Chat เป็นแท็บ จะกินพื้นที่ 20% ของ navigation แต่อัตราการใช้ต่ำ (และถูกจำกัดด้วย entitlement/consent)
- Appoint กับ Care Task เป็นงานเดียวกันในเชิง UI (งานที่มีวันเวลาและผู้รับผิดชอบ) — แยกแท็บจะทำให้ผู้ใช้สับสนว่าต้องบันทึกที่ไหน

**ทางเลือกที่ตัดทิ้ง** — *6 แท็บ* (เกิน guideline และทำให้ Elder Mode ยาก), *ยึด MOBILE_DESIGN.txt เดิม* (จะไม่มีที่ให้ Access & Consent)

**ผลกระทบ**: `MOBILE_DESIGN.md` (§3), `docs/MOBILE_DESIGN.txt` ถูกทำเครื่องหมายเป็น historical

---

## ADR-005 · API เป็น workspace-scoped ทั้งหมด

**บริบท** — `SUMMARY.md` มีสองแบบในไฟล์เดียวกัน: `/api/v1/workspaces/{workspace}/care-tasks` และ `/api/v1/profiles/{profile}/care-tasks`

**การตัดสิน** — **ทุก resource route อยู่ใต้ workspace**:
```
/api/v1/workspaces/{workspace}/profiles/{profile}/care-tasks
/api/v1/workspaces/{workspace}/care-tasks/{task}/complete
/api/v1/workspaces/{workspace}/dashboard
```
ยกเว้น route ที่ไม่ผูก workspace: `/api/v1/me`, `/api/v1/auth/*`, `/api/v1/invitations/{token}`

**เหตุผล** — ทำให้**ทุก** request มี workspace context อยู่ใน path → middleware ตรวจ tenant isolation ได้ที่จุดเดียว, ลดโอกาส IDOR ข้ามครอบครัว (`AUTH-003`), และทำให้ URL สื่อความหมายว่าเป็นข้อมูลของครอบครัวไหน

**ทางเลือกที่ตัดทิ้ง** — *profile-scoped* (profile อยู่ข้าม workspace ได้ในอนาคต → สับสน), *flat + header* (ซ่อน context ทำให้ audit อ่านยาก)

**ผลกระทบ**: `07-API-CONTRACT.md`, `10-TESTING.md` (`AP-*`, `ISO-*`)

---

## ADR-006 · ชื่อตารางมาตรฐานชุดเดียว

**บริบท** — สามเอกสารใช้ชื่อต่างกัน 8 คู่

**การตัดสิน** — ใช้ชุดเดียวนี้ (คอลัมน์ "alias" ไว้ค้นเอกสารเก่า):

| ตารางมาตรฐาน | alias ที่พบในเอกสารเดิม |
|---|---|
| `workspace_memberships` | `workspace_members` (SUMMARY.md) |
| `consent_records` | `consents` (SUMMARY.md) |
| `access_grants` | `delegation_grants`, `caregiver_assignments` (SUMMARY_2) |
| `notification_deliveries` | `notification_records` (SUMMARY_2) |
| `health_measurements` | `vital_measurements` (PDF2) |
| `documents` | `document_references` (SUMMARY_2), `medical_document` (permission domain) |
| `interaction_logs` | — (SUMMARY_2 เท่านั้น) |
| `audit_events` (ธุรกิจ) vs `security_audit_logs` (ความปลอดภัย) | `audit_events` (SUMMARY.md + SUMMARY_2) |

รายการเต็มอยู่ใน [`02-DOMAIN-MODEL.md §5`](02-DOMAIN-MODEL.md#5-ตารางมาตรฐาน-canonical-tables)

**เหตุผล** — `workspace_memberships` สื่อว่าเป็นความสัมพันธ์ (membership) ไม่ใช่ตัวบุคคล; `health_measurements` กว้างกว่าและครอบคลุมกรณีไม่ใช่ vital sign (เช่น น้ำหนัก, SpO2); `access_grants` ตรงกับ permission domain ที่ freeze ไว้ (`access_grant.*`) จึงลดการแปลชื่อ

**ผลกระทบ**: `02`, `06`, `07`, `10`, `TRACEABILITY`

---

## ADR-007 · PostgreSQL RLS ใช้เฉพาะ tenant isolation

**บริบท** — `PDF1 §5.1` เสนอ RLS "เพื่อให้มั่นใจว่าไม่มี Query ใดหลุดการกรองสิทธิ์" แต่ `SUMMARY.md` กำหนดให้ Policy เป็นจุดบังคับใช้เดียว

**การตัดสิน** — ใช้ RLS **เฉพาะการกันข้าม workspace/tenant** (`workspace_id`) เป็น defense-in-depth ชั้นที่ 2 **ไม่ใช่**กลไกตัดสินสิทธิ์ระดับบุคคล/ประเภทข้อมูล
- ทุก request ต้อง `SET LOCAL app.user_id = ...; SET LOCAL app.workspace_id = ...` ใน transaction เดียวกับ query
- Policy ระดับแถว: `workspace_id = current_setting('app.workspace_id')::uuid`
- การตัดสินว่า "อ่านฟิลด์นี้ได้ไหม" ยังเป็นหน้าที่ของ Policy/Gate ใน Laravel ตาม baseline

**เหตุผล** — RLS ทำ fine-grained (ต่อ profile/ต่อ field) ได้แต่ต้นทุนสูงมาก (policy ระเบิด, debug ยาก, ทดสอบกับ SQLite ไม่ได้) แต่การกัน cross-tenant เหมาะกับ RLS มากเพราะเป็นเงื่อนไขเดียวที่ทุก query ต้องผ่าน

**ทางเลือกที่ตัดทิ้ง** — *RLS ทั้งหมด* (ซับซ้อนเกิน MVP), *ไม่ใช้ RLS เลย* (พลาดชั้นป้องกันเมื่อนักพัฒนาลืม `where`)

**ผลกระทบ**: `06-DATA-MODEL.md` (§4), `09-SECURITY-PRIVACY.md`, `10-TESTING.md` (ต้องใช้ PostgreSQL จริงในเทสต์)

---

## ADR-008 · UUIDv7 เก็บเป็น native uuid ไม่ใช่ string

**บริบท** — `SUMMARY.md` ระบุ "UUIDv7 เป็น String ตามมาตรฐานของโปรเจกต์" แต่ยังไม่มีโปรเจกต์/มาตรฐานให้อ้างอิง

**การตัดสิน** — ใช้คอลัมน์ชนิด `uuid` ของ PostgreSQL เก็บ UUIDv7 (สร้างฝั่งแอป) และ serialize เป็น string ใน JSON

**เหตุผล** — 16 bytes vs 36 bytes ต่อแถว (ตารางหลักหลายสิบล้านแถวในอนาคต), index เล็กลง, เปรียบเทียบเร็วขึ้น; UUIDv7 ยังเรียงตามเวลา → ลด page split

**ผลกระทบ**: `06-DATA-MODEL.md`

---

## ADR-009 · Dependent Profile ไม่ต้องเพิ่ม role ใหม่

**บริบท** — `PDF1` ต้องการ "Managed Profile" สำหรับผู้ป่วยติดเตียง/ผู้สูงอายุสมองเสื่อม และ `SUMMARY_2` แยก Person/User แล้ว แต่ baseline มี 8 role ตายตัว ไม่มี `dependent` หรือ `proxy`

**การตัดสิน** — ไม่เพิ่ม role ใหม่ใน baseline ใช้ของที่มีอยู่ประกอบกัน:
- `persons` ที่ **ไม่มี** `user_id` = Dependent Profile
- `legal_guardian` + `legal_authority.*` permission + `access_grant` (มี `valid_from`/`expires_at`) = ผู้จัดการแทน
- `health_profiles.managed_by_person_id` ระบุผู้ดูแลตามกฎหมาย
- UI ใช้ persona "Passive/Dependent" แต่ในระบบสิทธิ์ยังเป็น `legal_guardian`

**เหตุผล** — เพิ่ม role = ต้อง bump baseline + แก้ matrix + test ทุกชุด; ของเดิมรองรับได้ครบและสอดคล้อง `F-05` (ความสัมพันธ์ไม่ให้อำนาจ — ต้องมี `legal_authority` ยืนยัน)

**ผลกระทบ**: `03-AUTHORIZATION.md` (§4), `04-CONSENT-DELEGATION.md` (§3), `MOBILE_DESIGN.md`

---

## ADR-010 · Dashboard ใช้ endpoint รวมตัวเดียว

**บริบท** — `PDF2 §6` เสนอ `GET /api/v1/{id}/dashboard` aggregator เพื่อไม่ให้แอปยิง 10 API แต่ `SUMMARY.md` ไม่มีแนวคิดนี้ และ ADR-005 บังคับ workspace-scoped

**การตัดสิน** — `GET /api/v1/workspaces/{workspace}/dashboard?profile_id={id}` เป็น **read-only aggregate** ที่:
- ตรวจสิทธิ์ **ต่อการ์ด** ก่อนใส่ใน payload (ไม่ใช่ตรวจครั้งเดียวแล้วส่งทั้งหมด)
- cache สั้น (≤30 วินาที) และ invalidate เมื่อ grant/consent เปลี่ยน
- ไม่มี side effect, ไม่มี write ใดๆ

**ผลกระทบ**: `07-API-CONTRACT.md`, `MOBILE_DESIGN.md` (§4), `10-TESTING.md` (`AP-*`)

---

## ADR-011 · ราคาเป็นสมมติฐาน ยังไม่ตัดสิน

**บริบท** — `SUMMARY.md`: Free / ฿179 / ฿499 / ฿699 (โปร ฿159/399/599) · `PDF1`: 3 ชั้น (ตัวเลขอ่านไม่ได้จาก text layer)

**การตัดสิน** — **Open** — ยังไม่ล็อกราคา ห้าม implement การคิดเงินจริงจนกว่าจะผ่าน gate `G-2` ใน [`12-ROADMAP.md`](12-ROADMAP.md)
ระหว่างนี้ใช้ **feature flag ตามชื่อแพ็กเกจ** (`free`, `family`, `care_team`) และเก็บ `entitlements` แบบไม่ผูกตัวเลข

**เหตุผล** — ไม่มีข้อมูล willingness-to-pay จากผู้ใช้จริง (SUMMARY.md กำหนดให้ทดสอบ 10–15 ครอบครัวก่อน)

---

## ADR-012 · Baseline v1.1 delta ต้องมี permission เพิ่ม 4 โดเมน

**บริบท** — Blueprint นี้มีแนวคิดที่ baseline ยังไม่มี permission รองรับ: Notification preferences, Case management, Interaction log, และการอ่าน Audit ของผู้ใช้ทั่วไป

**การตัดสิน** — ✅ **Accepted (Wave 1)** เมื่อ 2026-10-22 · Wave 2 ยัง Open

แยกเป็น 2 รอบ เพราะชุดนี้ไม่ได้เร่งด่วนเท่ากัน:

### Wave 1 — ✅ Applied in baseline **v1.1.0** (CR-001)

| permission | เหตุผล | ใช้เมื่อ |
|---|---|---|
| `notification.preference.read` / `.update` | ต้องแยกจาก `consent.*` (ตั้งค่าช่องทาง ≠ ถอนความยินยอม) และไม่มีเจ้าของสิทธิ์ใน v1.0.0 | Phase 1 |
| `notification.delivery.read` | ให้ผู้ใช้เห็นสถานะการส่ง/ล้มเหลวของตน | Phase 1 |
| `audit_event.read_own` | ให้เจ้าของข้อมูลดูประวัติการเข้าถึงของตนเอง (SUMMARY_2 §3.12) — `audit_event.read` เดิมเป็นระดับ staff | Phase 1 |

→ รายละเอียดครบ (catalog patch, matrix, scope, test, impact, rollback) อยู่ที่ **[`changes/CR-001`](changes/CR-001-permission-delta-v1.1.md)**
→ **ยังไม่มีผลจริง** จนกว่าจะแก้ 4 ไฟล์ใน baseline พร้อมกัน + bump เป็น v1.1.0 (`ABD-013`, `ABD-015`)

### Wave 2 — ยังไม่อนุมัติ

| permission | เหตุผลที่เลื่อน |
|---|---|
| `care_case.create` / `.read` / `.update` / `.close` | โมดูล Case & Service Request (SUMMARY_2 §3.10) ยังไม่มี lifecycle design — Phase 2 |
| `interaction_log.create` / `.read` | ยังไม่ตัดสินว่าใช้ `task_events` แทนได้หรือไม่ — Phase 2 |

→ จะเปิด **CR-002** เมื่อ [`05-CARE-COORDINATION.md §7.5`](05-CARE-COORDINATION.md) และ handoff design เสร็จ

**ผลกระทบ**: `TRACEABILITY.md` (§5), `10-TESTING.md`, `07-API-CONTRACT.md`

**ทางเลือกถ้า Wave 2 ไม่อนุมัติ** — ตัด 2 โมดูล (Case / Interaction log) ออก และใช้ `task_events` แทน interaction log

---

## ADR-013 · Tenancy: shared schema + `tenant_id` ตั้งแต่ Phase 0

**บริบท** — [02 §1](02-DOMAIN-MODEL.md) มี context "Tenant & Workspace" และตาราง `tenants` แล้ว แต่ [06 §4](06-DATA-MODEL.md) บังคับใช้ RLS เฉพาะ `workspace_id` — คือ *เผื่อไว้แต่ยังไม่ตัดสิน*
ผลิตภัณฑ์วางตำแหน่งเป็น **B2C (ครอบครัว)** ยังไม่มีแผนขายองค์กร แต่มี ~40 ตาราง tenant-scoped ถ้าจะเพิ่ม `tenant_id` ภายหลังจะต้อง migrate + backfill + สร้าง index ใหม่ + เขียน RLS policy ใหม่ทั้งชุด

**การตัดสิน** — ใช้ **single deployment + shared schema** และมี `tenant_id` **ตั้งแต่ Phase 0**:

1. ทุกตาราง tenant-scoped มี `tenant_id uuid not null` (denormalized จาก workspace)
2. **FK แบบ composite** `(workspace_id, tenant_id) → workspaces(id, tenant_id)` เพื่อบังคับความสอดคล้องเชิงโครงสร้าง (ไม่พึ่ง trigger)
3. RLS ตรวจ **สองชั้น**: `tenant_id = app.tenant_id AND workspace_id = app.workspace_id`
4. MVP รันด้วย **default tenant เดียว** (`tenants.slug = 'default'`) สร้างตอน bootstrap
5. **ไม่สร้าง**: tenant provisioning UI, tenant admin console, billing ระดับ tenant, cross-tenant admin
6. `users` เป็น identity ระดับ global (คนเดียวเป็นสมาชิกได้หลาย tenant) แต่ **ทุกแถวข้อมูล** ต้องมี tenant_id
7. การข้าม tenant = `404` ตามนโยบายเดียวกับ out-of-scope ([07 §2](07-API-CONTRACT.md#2-error-model))

**ตารางที่ยกเว้น** (ไม่มี `tenant_id`): `tenants` (ตัวเอง), `users` (global identity), `feature_flags` (ค่า default ระดับระบบ)

**เหตุผล**
- ต้นทุนตอนนี้ = 1 คอลัมน์ + 1 clause ของ RLS + 1 test suite
- ต้นทุนถ้าเพิ่มทีหลัง = migration ~40 ตาราง + backfill + rebuild index + policy rewrite + regression ทั้งหมด
- ทำให้ RLS เป็น equality ตรงๆ (เร็ว, ตรวจง่าย) แทนการ join ไปที่ workspace ทุกแถว
- เปิดทาง B2B / white-label / โรงพยาบาลโดยไม่ต้องรื้อ schema และยัง**ไม่ผูกมัด**ให้ต้องสร้างฟีเจอร์ B2B

**ทางเลือกที่ตัดทิ้ง**
| ทางเลือก | เหตุผล |
|---|---|
| Single-tenant ไม่มี `tenant_id` | schema สะอาดกว่า แต่ถ้าต้องขายองค์กรภายหลังต้อง migrate ใหญ่ และ RLS จะตรวจ cross-tenant ไม่ได้ตรงๆ |
| Instance per tenant | แยกข้อมูลแข็งแรงที่สุด แต่ ops cost สูง และทำ self-serve B2C ไม่ได้ |
| Multi-tenant SaaS เต็มรูปแบบ (provisioning + billing) | ยังไม่มีลูกค้าองค์กรแม้รายเดียว = over-engineering ตามที่ `SUMMARY.md` เตือน |

**ความเสี่ยง & mitigation**
| ความเสี่ยง | mitigation |
|---|---|
| ลืมใส่ `tenant_id` ตอน insert | `NOT NULL` + factory + test `TEN-T01` |
| `tenant_id` ไม่ตรงกับ workspace | composite FK (ข้อ 2) — insert จะ fail |
| ลืม `SET LOCAL app.tenant_id` | RLS คืน false → deny (fail closed, F-09) + test `TEN-T02` |
| client ส่ง `tenant_id` มาเพื่อหลอก | path/context มาจาก server เท่านั้น (N-01) + `TEN-T03` |
| index โตขึ้น | ~5–8% ต่อตาราง — ยอมรับได้ |

**ผลกระทบ**: `02 §6` (DM-011), `06 §3/§4/§7/§9/§10`, `03 §2/§7`, `10` (`TEN-T01…T04`), `12` (Phase 0), `TRACEABILITY`

**ไม่กระทบ baseline**: ไม่มี permission/role เปลี่ยน — เป็นการกำหนดขอบเขตข้อมูล ไม่ใช่สิทธิ์ จึงไม่ต้องมี Change Record

---

## ADR-014 · Sensitive scopes ปิดใน MVP (`mental_health`, `lab_results`)

**บริบท** — [03 §5](03-AUTHORIZATION.md#5-scope-model) ประกาศ scope `mental_health` และ `lab_results` ไว้พร้อมป้าย sensitive แต่ทั้งคู่ **ไม่มีใน Phase 1 deliverables** ของ [12](12-ROADMAP.md)
ข้อมูลสุขภาพจิตและผลแล็บเป็นข้อมูลอ่อนไหวตาม PDPA มาตรา 26 และเป็นข้อมูลที่ถ้า "เปิดแล้วภายหลังปิด" จะทำได้ยาก เพราะผู้ใช้จะฝากข้อมูลไว้แล้ว
ในทางปฏิบัติ ข้อมูลแล็บของครอบครัวไทยส่วนใหญ่ที่เก็บกันจริงคือ **ไฟล์/รูปใบแล็บ** ไม่ใช่ตัวเลขที่ผู้ใช้พิมพ์เอง — และ `documents` + `medical_document.*` รองรับอยู่แล้ว

**การตัดสิน**

1. **`mental_health` — ปิดสนิทใน MVP**: ไม่มี endpoint, ไม่มีคอลัมน์, ไม่มี UI, ไม่มี grant ที่ระบุ scope นี้ได้
2. **`lab_results` — ปิดใน MVP (structured)**: ใบแล็บเก็บเป็น **`documents` ที่ `category = 'lab_report'`** ไม่ใช่ค่าที่ตีความแล้ว
3. scope ทั้งสอง **คงไว้ในเอกสาร** ในสถานะ `reserved — not enabled` เพื่อไม่ให้สูญหายและเพื่อให้เปิดได้ภายหลังอย่างมีวินัย
4. การพยายามใช้ scope ที่ปิด → `403`/`404` ตามนโยบาย; การสร้าง `access_grant` ที่ระบุ scope ปิด → `422`
5. เอกสาร `category = lab_report` **ถือเป็น sensitive**: แสดงเฉพาะผู้มี scope `documents` และ AI gateway **ตัดออกโดยปริยาย** (ดู [08 §2](08-AI-GATEWAY.md))

**เงื่อนไขก่อนเปิด scope ใดก็ตาม (ต้องครบทั้ง 5 — ห้ามเปิดทีละข้อ)**

| # | เงื่อนไข | หลักฐานที่ต้องมี |
|---|---|---|
| 1 | consent purpose แยก + UI อธิบายความอ่อนไหวชัดเจน | ข้อความ consent + `consent_records.purpose` ใหม่ |
| 2 | audit ทุกการอ่าน (ไม่ใช่แค่การแก้ไข) | `AUDIT-*` test ผ่าน |
| 3 | AI gateway ตัดข้อมูลโดยปริยาย + test พิสูจน์ว่าไม่หลุด | `AIG-*` leakage test ผ่าน |
| 4 | สิทธิ์ไม่ inherit จาก `caregiver`/`workspace_admin` — ต้อง grant ชัดเจนรายบุคคล | `GRANT-*` negative test |
| 5 | legal review ลงนาม (PDPA มาตรา 26) | บันทึกการอนุมัติ |

**เหตุผล**
- ยังไม่มีข้อมูลจากผู้ใช้จริง (gate G2 ยังไม่ผ่าน) — เปิด scope ที่อ่อนไหวที่สุดก่อนพิสูจน์ core value = รับความเสี่ยงโดยไม่มีความจำเป็น
- ต้นทุน "ปิดตอนนี้" ≈ 0 เพราะ `documents` ให้คุณค่า timeline ได้แล้ว
- ต้นทุน "ปิดภายหลัง" สูง: ต้องย้ายข้อมูล, แจ้งผู้ใช้, และเสี่ยงข้อกล่าวหาว่าลดระดับการคุ้มครอง

**ทางเลือกที่ตัดทิ้ง**
| ทางเลือก | เหตุผล |
|---|---|
| เปิด `lab_results` structured | ต้องมี validation + disclaimer + test AI exclusion; ผู้ใช้อาจตีความค่าผิด — ทำได้ใน Phase 2/3 เมื่อมีเงื่อนไข 5 ข้อ |
| เปิดทั้งคู่แบบ opt-in ต่อ workspace | ยืดหยุ่น แต่ต้องสร้าง UI/consent/audit ครบตั้งแต่ MVP = งานมากกว่าที่ gate ปัจจุบันสนับสนุน |
| เปิดเต็มรูปแบบ | ต้อง legal review ก่อน และเบี่ยงโฟกัสจาก core coordination |

**ผลกระทบ**: `01` (out-of-scope), `02` (DM-012, `documents.category`), `03 §5` (สถานะ scope), `06` (คอลัมน์ documents), `08 §2` (AI exclusion), `10` (`SEN-T01…T03`), `12` (Phase 1/2)

**ไม่กระทบ baseline**: scope ไม่ใช่ permission — ไม่ต้องมี Change Record
**ชื่อที่จองไว้สำหรับอนาคต** (ยังไม่สร้าง): ตาราง `lab_results`, `lab_result_items`, `mental_health_assessments`

---

## ADR-015 · หลักฐานอำนาจผู้แทน — 3 ระดับตามความเสี่ยง

**บริบท** — [ADR-009](DECISIONS.md#adr-009--dependent-profile-ไม่ต้องเพิ่ม-role-ใหม่) วาง Dependent Profile ผ่าน `legal_guardian` และบอกว่า *"ต้องผ่าน `legal_authority.*`"* แต่ **ไม่เคยนิยามว่าผ่านด้วยอะไร**
- **F-05** กำหนดว่า "ความเป็นญาติไม่ได้ให้อำนาจ" — แต่ถ้าไม่มี flow ยืนยันอำนาจ persona P3 (ผู้ป่วยติดเตียง/สมองเสื่อม/เด็ก) จะใช้ระบบไม่ได้เลย
- ข้อเท็จจริงทางกฎหมายที่ต้องแยก (ต้องยืนยันกับที่ปรึกษากฎหมาย):
  - **ผู้เยาว์** — บิดามารดาเป็นผู้แทนโดยชอบธรรมโดยผลของกฎหมาย → หลักฐานคือสูติบัตร/ทะเบียนบ้าน + บัตรประชาชน
  - **ผู้ใหญ่ไร้ความสามารถ** — ต้องมี **คำสั่งศาล** ตั้งผู้อนุบาล/ผู้พิทักษ์ → การเป็นลูกไม่ได้ให้อำนาจ
  - **ผู้ใหญ่ที่ยังมีความสามารถ** — ต้องมี **หนังสือมอบอำนาจ** ที่รับรอง; "ทะเบียนบ้าน" พิสูจน์แค่ความเป็นญาติ ไม่ใช่อำนาจตัดสินใจแทน

**การตัดสิน** — แบ่งเป็น **3 ระดับตามความเสี่ยงของ action** (ไม่ใช่ตามตัวบุคคล)

| ระดับ | ทำอะไรได้ | หลักฐานขั้นต่ำ | ผู้ตรวจ | permission |
|---|---|---|---|---|
| **L1 · Kinship** | มอบหมายงาน/นัด/เตือน, จัดการ care task ของ dependent ได้ | คำประกาศความสัมพันธ์ + OTP ยืนยัน · **ไม่ต้องอัปโหลด** | ระบบ (อัตโนมัติ) | `care_relationship.*`, `care_task.*` |
| **L2 · Documented** | อ่านเวชระเบียนตาม scope, ให้/ถอน consent แทน, สร้าง grant ให้ผู้อื่นในขอบเขต | ผู้เยาว์: สูติบัตร/ทะเบียนบ้าน + บัตร ปชช. ผู้แทน<br>ผู้ใหญ่: **คำสั่งศาล** หรือ **หนังสือมอบอำนาจที่รับรอง** | ops review ≤2 วันทำการ | `legal_authority.verify` (ops) + `consent.*`, `access_grant.*` (guardian) |
| **L3 · High-risk** | อนุมัติ break-glass, มอบสิทธิ์ให้บุคคลนอกครัวเรือน (ring ≠ inner), ขอลำเนา/ลบข้อมูลแทน, เพิกถอน grant ของผู้อื่น | L2 + เอกสารฉบับเต็ม + **dual control (ผู้ตรวจ 2 คน)** | ops + reviewer 2 | `break_glass.approve`, `data_export.request`, `access_grant.revoke` |

**หลักการ**
1. **L1 ไม่ให้สิทธิ์ข้อมูลสุขภาพใดๆ** — เป็นเพียงการยอมรับว่า "คนนี้ช่วยประสานงาน" (สอดคล้อง F-04/F-05)
2. การยกระดับต้องเป็น **คำขอ + การตรวจสอบ** ไม่ใช่ declare เอง
3. หลักฐานเก็บเป็น `documents.category = 'legal_authority'` (private, ไม่ public, ไม่ส่งเข้า AI)
4. **มีอายุ 12 เดือน** → ต้องยืนยันซ้ำ (แจ้งล่วงหน้า 30 วัน)
5. **ผู้เยาว์บรรลุนิติภาวะ** (20 ปีบริบูรณ์ หรือสมรสตามกฎหมาย — *ต้องยืนยันตัวเลขกับที่ปรึกษา*) → ต้องยืนยันอำนาจใหม่ + แจ้งเจ้าของข้อมูล
6. **เจ้าของข้อมูลคัดค้าน** → ระงับ L2/L3 ทันที และเข้าสู่ review (ห้ามให้ผู้แทนปิดเสียงเจ้าของ)
7. **เจ้าของสร้างบัญชีของตนเอง** → L2/L3 ที่มีอยู่ **ไม่ถูกเพิกถอนอัตโนมัติ** แต่แสดงแบนเนอร์ให้เจ้าของยืนยัน/เพิกถอนภายใน 30 วัน (แทนที่ CD-023 เดิม)
8. ทุกการยกระดับ/ลดระดับ/เพิกถอน → `audit_events` + `security_audit_logs`

**เหตุผล**
- ทำให้ P3 usable ได้ตั้งแต่ L1 โดยไม่รับความเสี่ยงข้อมูล
- บังคับหลักฐานตาม *ความเสียหายที่อาจเกิดขึ้น* ไม่ใช่ตาม *ความสะดวกของผู้ใช้*
- ตรงกับ F-05: L1 = ความสัมพันธ์, L2/L3 = อำนาจที่พิสูจน์แล้ว

**ทางเลือกที่ตัดทิ้ง**
| ทางเลือก | เหตุผล |
|---|---|
| 2 ระดับ (ญาติ / ผู้แทน) | รวม "อ่านเวชระเบียน" กับ "อนุมัติ break-glass" ไว้ด้วยกัน — เสี่ยงเกินไป |
| คำสั่งศาลเท่านั้น | ครอบครัวไทยส่วนใหญ่ไม่มีคำสั่งศาล → Dependent Profile ใช้ไม่ได้จริง |
| ไม่รับรองผู้แทนเลย | ตัด persona P3 ออก และขัด ADR-009 |

**ข้อจำกัดที่ต้องปิด (Q-11)** — รายการเอกสารที่ "ถือว่าเพียงพอ" ต่อแต่ละระดับต้องให้ที่ปรึกษากฎหมายยืนยัน รวมถึงอายุบรรลุนิติภาวะและกรณีผู้เสมือนไร้ความสามารถ

**ผลกระทบ**: `04 §5` (CD-020…029), `02`/`06` (`delegations`), `03 §4`, `10` (`LEG-T01…T05`), `12` (Phase 1 = L1, Phase 2 = L2/L3), [`MOBILE_DESIGN §2.3–2.4`](MOBILE_DESIGN.md)

**ไม่กระทบ baseline**: ใช้ `legal_authority.verify` + `consent.*` + `access_grant.*` ที่มีอยู่แล้ว — ไม่มี permission ใหม่

---

## ADR-016 · Retention ตามวัตถุประสงค์ (PDPA) + tenant override

**บริบท** — `06 §8` เดิมตั้ง "health data 10 ปี" พร้อมหมายเหตุ *"ต้องยืนยันกับข้อกำหนดเวชระเบียน"* ซึ่งไม่เคยยืนยัน

**จุดที่ต้องแยกให้ถูก** (ต้องยืนยันกับที่ปรึกษากฎหมาย):
| กฎ | ใช้กับ | กับเรา |
|---|---|---|
| ข้อกำหนดเก็บเวชระเบียนของ **สถานพยาบาล** (ตัวเลขที่มักอ้างคือ 10 ปี) | ผู้ออกใบอนุญาตสถานพยาบาล | **ไม่ตรง** — เราเป็นแอปสุขภาพครอบครัว ไม่ใช่สถานพยาบาล |
| **PDPA** — เก็บเท่าที่จำเป็นตามวัตถุประสงค์ | ผู้ควบคุมข้อมูลส่วนบุคคล | **ใช่** — กฎหลักที่ผูกพัน |

การนำเลข 10 ปีของโรงพยาบาลมาใช้กับแอปผู้บริโภค = เก็บข้อมูลอ่อนไหวไว้นานเกินจำเป็น เพิ่มความเสียหายเมื่อเกิด breach และขัดหลัก minimization

**การตัดสิน** — ใช้ **purpose-based retention** พร้อมช่อง override ต่อ tenant (ใช้ `tenant_id` จาก ADR-013)

### 1. ตาราง retention (ค่าเริ่มต้นระดับแพลตฟอร์ม)

| ประเภท | ค่าเริ่มต้น | นับจาก | หมายเหตุ |
|---|---|---|---|
| `health_measurements`, `health_conditions`, `allergies` | **60 เดือน** | `recorded_at`/`updated_at` | ผู้ใช้ลบเองได้ก่อนกำหนด |
| `medication_plans`, `medication_records` | **60 เดือน** | `updated_at` | |
| `appointments` | **60 เดือน** | `scheduled_at` | |
| `care_tasks`, `task_events`, `task_evidence` | **36 เดือน** | `created_at` | ข้อมูลปฏิบัติการ |
| `documents` (medical) | **60 เดือน** | `created_at` | |
| `documents` (identity / `legal_authority`) | **12 เดือนหลัง delegation สิ้นสุด** | `revoked_at` | เก็บเท่าที่จำเป็นต่อการพิสูจน์อำนาจ |
| `ai_conversations` | **6 เดือน** | `created_at` | ผู้ใช้ลบได้ทันที |
| `notification_deliveries` | payload **90 วัน** → metadata **24 เดือน** | `sent_at` | |
| `audit_events`, `security_audit_logs` | **60 เดือน (fixed)** | `occurred_at` | **ห้ามผู้ใช้แก้/ลบ** |
| backups | 35 วัน rolling + 12 monthly | | |

### 2. Inactivity policy

```
ไม่มีการ login 24 เดือน
  → แจ้งเตือน 3 ครั้ง (ล่วงหน้า 30 / 14 / 7 วัน)
  → เสนอ "ดาวน์โหลดข้อมูลของฉัน" (export ฟรี)
  → ครบกำหนด → purge ข้อมูลสุขภาพของ workspace
  → audit ยังอยู่ (Legal hold ยังบล็อกได้)
```

### 3. Legal hold

- ตาราง `data_holds` (workspace/profile, reason, `until`, created_by)
- มี hold → **ห้าม purge** ทุกกรณี แม้ผู้ใช้ขอ
- hold มีวันหมดอายุ และต้องมีเหตุผล + audit

### 4. Tenant override (สำหรับ B2B ในอนาคต)

- `tenants.retention_override` (JSONB) — แต่ละประเภทมี **min/max** บังคับ
- ตัวอย่าง: health data 24–120 เดือน (ลูกค้าองค์กรที่ต้องเก็บ 10 ปีทำได้โดยไม่บังคับทั้งแพลตฟอร์ม)
- **ห้ามตั้งต่ำกว่า 24 เดือน** สำหรับข้อมูลสุขภาพ (กันการลบเร็วเกินจนกระทบ continuity)
- override ทุกครั้ง → audit + แสดงในหน้าตั้งค่า workspace

### 5. Purge job

| ข้อกำหนด | ค่า |
|---|---|
| ตารางเวลา | รายวัน 03:00 UTC |
| Batch | 500 แถว/รอบ, resumable |
| Dry-run | บังคับเปิด **30 วันแรก** (`RETENTION_DRY_RUN=true`) |
| ลำดับ | เลือกผู้เข้าเกณฑ์ → ตัด legal hold → purge → เขียน audit → metric |
| ห้าม | purge `audit_events` / `security_audit_logs` / แถวที่มี hold |
| Idempotent | รันซ้ำต้องไม่ทำให้ข้อมูลเสียหายหรือนับซ้ำ |

**เหตุผล**
- PDPA เป็นกฎที่ผูกพันจริง; 10 ปีของโรงพยาบาลไม่ใช่
- `tenant_id` ที่มีอยู่แล้วทำให้รองรับลูกค้าองค์กรในอนาคตได้โดยไม่ต้องบังคับทุกคน
- มี legal hold ตั้งแต่ต้น = ไม่ติดกับดัก "ลบไปแล้วแต่มีคดี"

**ทางเลือกที่ตัดทิ้ง**
| ทางเลือก | เหตุผล |
|---|---|
| 10 ปีแบบโรงพยาบาล | ขัด minimization, breach impact สูง, ไม่มีฐานกฎหมายรองรับสำหรับแอปผู้บริโภค |
| ผู้ใช้ลบได้ทันทีทั้งหมด | เสีย continuity ของประวัติครอบครัว และขัดสัญญา B2B ในอนาคต |
| แบ่งตามความอ่อนไหวเพียงอย่างเดียว | อธิบายให้ผู้ใช้ยาก และไม่ครอบคลุมข้อมูลปฏิบัติการ (task/document) |

**ข้อจำกัด (Q-12)**: ตัวเลขทุกตัวในตารางเป็น **ค่าเริ่มต้นเชิงนโยบาย** ต้องผ่านการยืนยันทางกฎหมายและ Privacy Notice ก่อนเปิดใช้จริง

**ผลกระทบ**: `06 §8/§9` (ตาราง + `data_holds` + job), `02` (`data_holds`), `09 §3` (PDPA mapping), `10` (`RET-T01…T05`), `12` (Phase 0/1), `01` (in-scope), `TRACEABILITY`

**ไม่กระทบ baseline**: ไม่มี permission ใหม่ (การลบ/ขอลำเนาใช้ `data_export.*`, `health_profile.archive` ที่มีอยู่)

---

## ADR-017 · Break-glass เปิดให้ทุกแพ็กเกจ (ไม่ผูกกับราคา)

**บริบท** — [ADR-002](DECISIONS.md#adr-002--break-glass-implement-แต่ปิดเป็นค่าเริ่มต้น) ตัดสินว่า "ทำ แต่ปิดเป็นค่าเริ่มต้น" แต่ไม่ระบุว่า **ใครเปิดได้**
- baseline `ABD-012`: *"Break-glass is disabled in MVP until separately approved and tested"* — ห้ามเปิดโดยไม่มีกระบวนการอนุมัติ
- [ADR-015](DECISIONS.md#adr-015--หลักฐานอำนาจผู้แทน-3-ระดับตามความเสี่ยง) ทำให้มี **ผู้แทน L2 ที่ผ่านการตรวจเอกสาร** ซึ่งทำหน้าที่เป็นประตูด่านตามธรรมชาติอยู่แล้ว
- ราคา 3 ชั้นยังเปิด (ADR-011) — จึงต้องตัดสินว่า break-glass จะเป็น *feature ขาย* หรือ *safety feature*

**การตัดสิน** — **เปิดให้ทุกแพ็กเกจ ไม่ผูกกับราคา**

1. `workspaces.break_glass_enabled` เป็น **workspace setting ไม่ใช่ entitlement** — layer Billing **ห้าม** ตรวจค่านี้ (ถ้าตรวจ จะกลายเป็นการขายความปลอดภัย)
2. **ปิดเป็นค่าเริ่มต้นเสมอ** — ไม่มี auto-enable แม้แพ็กเกจสูง
3. **เงื่อนไขการเปิด (ต้องครบทั้ง 4 — มิฉะนั้นเปิดไม่ได้)**
   | # | เงื่อนไข | เหตุผล |
   |---|---|---|
   | 1 | มีผู้รับการดูแลที่ยืนยันตัวตนแล้ว ≥1 โปรไฟล์ | ต้องมี "ข้อมูลที่จะช่วยชีวิต" จริง |
   | 2 | มีผู้แทน L2 (verified) หรือ `profile_owner` ที่มีบัญชี ≥1 คน | ต้องมีคนอนุมัติ และมีคนรับผิดชอบ |
   | 3 | ผู้ติดต่อฉุกเฉินครบ | ใช้ใน L3 escalation |
   | 4 | `workspace_admin` เปิดเอง + ยอมรับข้อตกลง (บันทึก `policy_version`) | ไม่มีใครถูกเปิดโดยไม่รู้ตัว |
4. **guardrails เดิมยังใช้ครบ** (BG-001…BG-010): 4 ชม. · เหตุผล · re-auth · critical summary เท่านั้น · แจ้งทุกคน · audit ที่ลบไม่ได้
5. **Abuse guard**: เปิดเกิน **2 ครั้ง/โปรไฟล์/30 วัน** → ระบบ flag + แจ้ง ops + DPO review อัตโนมัติ
6. **ห้าม auto-approve** — ต้องมีมนุษย์อนุมัติเสมอ; ถ้าไม่มีผู้อนุมัติตามเงื่อนไข → ปฏิเสธ (ห้ามระบบอนุมัติแทน)

**เหตุผล**
- break-glass เป็น *safety feature* — การคิดเงินเพื่อเปิดสิทธิ์ช่วยชีวิตสร้างแรงจูงใจผิด และเป็นความเสี่ยงเชิงภาพลักษณ์/กฎหมาย
- เงื่อนไขข้อ 1–2 ทำหน้าที่เป็น gate ที่แข็งกว่าการคิดเงิน: คนที่ยังไม่ผ่านการตรวจเอกสารเปิดไม่ได้อยู่แล้ว
- ต้นทุนต่ำ: เปิดเฉพาะ Critical Summary, จำกัดเวลา, และเกิดขึ้นไม่บ่อย

**ทางเลือกที่ตัดทิ้ง**
| ทางเลือก | เหตุผล |
|---|---|
| ตั้งแต่ `family` ขึ้นไป | ผู้ใช้ free ที่พ่อแม่เข้า ER จะเจอทางตัน |
| เฉพาะ `care_team` | เสี่ยงถูกมองว่า "ขายสิทธิ์ช่วยชีวิต" |
| ปิดทั้งหมดใน MVP | ทิ้งเคส ER ที่เป็นช่องโหว่ชีวิตจริงตาม PDF1 |

**ข้อจำกัด (Q-13)**: ฐานทางกฎหมาย/ข้อความแจ้งในการเข้าถึงฉุกเฉิน ต้องผ่าน legal review

**ผลกระทบ**: `04 §4.2` (BG-011…BG-015), `01` (features), `09` (ops runbook), `10` (`BG-T01…T05`), `12` (Phase 2), `TRACEABILITY`

**ไม่กระทบ baseline**: ใช้ `break_glass.request/approve/use` ที่มีอยู่แล้ว

---

## ADR-018 · G0 scoping — capability-dependent checklist ย้ายไป gate ของ feature นั้น

**บริบท** — `docs/SUMMARY.md §7` เขียน "Authorization Readiness Checklist" 10 ข้อไว้ก่อนเริ่มพัฒนา **ฟีเจอร์ทั้งหมด**
แต่ `.ai/10-TESTING.md` §5 นำมาใช้เป็นเงื่อนไขของ **G0 (Foundation)** ตรงๆ ผลคือ:

- ข้อ 8 (*AI Context และ Export authorization tests*) ต้องการ AI gateway / export endpoint ที่ **ยังไม่มีใน Phase 0** — เป็นการกันไม่ให้ *เปิดใช้* ฟีเจอร์นั้น ไม่ใช่การพิสูจน์ foundation
- ข้อ 2 (*Matrix ครบทุก role/ข้อมูล*) ทำได้ใน Phase 0 เพราะเป็นงานทดสอบล้วน แต่ยังไม่ได้ทำ

การปล่อยให้ G0 ค้างเพราะข้อ 8 = gate ตันด้วยเหตุผลผิด (phase mismatch) และเสี่ยงที่จะถูก "แก้" ด้วยการลดความเข้มงวดในภายหลัง

**การตัดสิน**

1. **G0 = foundation เท่านั้น** — พิสูจน์ว่า *โครง* ปลอดภัย: สิทธิ์, สิทธิ์ระดับข้อมูล, audit, isolation, CI
2. checklist ข้อที่ **ขึ้นกับ capability ที่ยังไม่ถูกสร้าง** ให้ประเมินที่ gate ของ capability นั้น:
   - ข้อ 8 (AI/export) → **G4 (AI gate)** และเพิ่ม `AI-*` / `EXPORT-*` เป็นเงื่อนไขของ G4
3. **ข้อ 2 (Matrix conformance) ยังอยู่ใน G0** — เป็นงานทดสอบ ไม่ต้องรอฟีเจอร์ จึงต้องปิดที่ G0
4. **การเลื่อนไม่ใช่การผ่าน** — ข้อที่เลื่อนต้องแสดงสถานะ `⏭ deferred → G4 (ADR-018)` และต้องมี gate ปลายทางรับไว้ ห้ามติ๊ก ✅
5. ห้ามใช้ ADR นี้เพื่อผ่อนข้ออื่น — ถ้ามีข้อใหม่ที่ต้องเลื่อน ต้องมี ADR ของตัวเอง

**เหตุผล**
- ทำให้ G0 ตอบคำถามที่มันตั้งใจถาม ("โครงปลอดภัยพอจะเริ่มสร้างฟีเจอร์หรือยัง") ไม่ใช่ "ทำทุกอย่างเสร็จหรือยัง"
- ข้อ 8 ยังถูกบังคับ — แค่ย้ายไปจุดที่มันมีความหมาย (ก่อน *เปิด* AI/export ไม่ใช่ก่อน *เริ่ม* Phase 1)
- ลดแรงจูงใจที่จะ "ลดเกณฑ์" เมื่อ gate ค้าง

**ทางเลือกที่ตัดทิ้ง**
| ทางเลือก | เหตุผล |
|---|---|
| ปล่อยให้ G0 ค้างจนถึง Phase 3 | หยุด development 3 เฟสด้วยเหตุผลที่ไม่เกี่ยวกับ foundation |
| ติ๊กข้อ 8 ว่าผ่าน | เป็นการโกหก — ไม่มีเทสต์และไม่มีฟีเจอร์ |
| ลบข้อ 8 ออกจาก checklist | สูญเสียข้อกำหนดจริงที่ต้องบังคับตอนเปิด AI |

**ผลกระทบ**: `.ai/10-TESTING.md` (§5 G0/G4), `backend/docs/G0-CHECKLIST.md` (สถานะข้อ 2 และ 8 + คะแนนรวม)

**ไม่กระทบ baseline**: checklist มาจาก `SUMMARY.md` (historical) ไม่ใช่ baseline ที่ freeze

---

## ADR-019 · เริ่ม Phase 1 ขณะ G0 ข้อ 10 ยังเปิด (time-boxed override)

**บริบท** — หลัง ADR-018, G0 เหลือเพียงข้อ 10: **branch protection** ที่ยังไม่ถูกเปิดบน GitHub
(CI เขียวแล้ว 2 รอบ, workflow ทำงานจริง, สิทธิ์ admin ของ repo อยู่นอกมือทีมพัฒนา)
`.ai/12-ROADMAP.md` เขียนว่า "ห้ามเริ่ม Phase 1 จนกว่า G0 จะผ่าน" — การเริ่มตอนนี้จึงเป็นการข้าม gate
ถ้าทำเงียบๆ จะกลายเป็นบรรทัดฐานที่อันตราย (gate ถูกข้ามได้เรื่อยๆ)

**การตัดสิน** — อนุญาตให้เริ่ม Phase 1 **แบบมีเงื่อนไขและมีวันหมดอายุ**:

1. **ข้อยกเว้นนี้จำกัดเฉพาะข้อ 10 และเฉพาะ branch protection** — ไม่ใช่ใบอนุญาตทั่วไป
2. **ต้องมีหลักฐานว่า CI เขียวบน commit ล่าสุด** ก่อน merge ทุกครั้ง (ทำด้วยมือจนกว่าจะเปิด protection)
3. **วันหมดอายุ: 14 วัน** (ถึง 2026-11-06) — ถ้ายังไม่เปิด branch protection ภายในวันนั้น ต้องหยุดพัฒนา feature ใหม่
   และกลับไปปิด gate
4. **ทุก PR/commit ในช่วงนี้ต้องอ้าง ADR-019** เพื่อให้ตรวจย้อนหลังได้ว่าอยู่ภายใต้ข้อยกเว้น
5. สถานะ G0 ใน [`backend/docs/G0-CHECKLIST.md`](../../backend/docs/G0-CHECKLIST.md) ต้องแสดง `⬜` ไว้ **ห้ามเปลี่ยนเป็น ✅**
   จนกว่าจะเปิด protection จริง
6. เมื่อเปิดแล้ว → ปิด ADR-019 (Superseded) และบันทึกวันที่

**เหตุผล**
- ข้อที่เหลือเป็นการตั้งค่าที่ไม่กระทบโค้ด และไม่มีทาง "แก้ด้วยโค้ด" ได้
- ความเสี่ยงจริงของการข้ามข้อนี้คือ "merge ที่ CI แดงหลุดเข้า main" ซึ่งลดได้ด้วยวินัย 3 (ตรวจสถานะ CI ก่อน merge ด้วยมือ)
- การมีวันหมดอายุทำให้ไม่กลายเป็นสถานะถาวร

**ทางเลือกที่ตัดทิ้ง**
| ทางเลือก | เหตุผล |
|---|---|
| รอจนเปิด branch protection | ถูกต้องที่สุด แต่บล็อกงานทั้งPhase ด้วยการตั้งค่า 5 นาทีที่ขึ้นกับเจ้าของ repo |
| ติ๊กข้อ 10 ว่าผ่าน | เป็นการโกหกในเอกสาร |
| ข้าม gate โดยไม่บันทึก | ทำให้ gate ทั้งชุดเสื่อมความหมาย |

**ผลกระทบ**: `.ai/12-ROADMAP.md` (หมายเหตุที่ Phase 1), `backend/docs/G0-CHECKLIST.md` (§6 override log)
