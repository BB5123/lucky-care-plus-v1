# 12 · ROADMAP — ลำดับการสร้าง, gate, deliverable

> หลักการ: **ความปลอดภัยก่อนฟีเจอร์** (baseline + `SUMMARY.md §10`) และ **ทดสอบผู้ใช้ก่อนขาย** (`01-PRODUCT.md §7`)
> ทุก Phase ต้องผ่าน gate ใน [`10-TESTING.md §5`](10-TESTING.md#5-release-gates) ก่อนไปต่อ

---

## ภาพรวม

| Phase | เป้าหมาย | ระยะ (ประมาณ) | Gate |
|---|---|---|---|
| **0 · Foundation** | โครง + ระบบสิทธิ์ + test ที่พิสูจน์ได้ | 3–4 สัปดาห์ | G0 |
| **1 · Core MVP** | บันทึกสุขภาพ + งานดูแล + Dashboard + LINE notify | 6–8 สัปดาห์ | G1, G2 |
| **2 · Coordination** | Delegation เต็มรูป, recurring, escalation, break-glass, Doctor Brief | 6–8 สัปดาห์ | G3 |
| **3 · AI** | Gateway + สรุป + OCR | 4–6 สัปดาห์ | G4 |
| **4 · Scale & Compliance** | Performance, security review, legal, DPA | ต่อเนื่อง | G5, G6 |

> ตัวเลขสัปดาห์เป็น **ประมาณการ** ไม่ใช่ commitment จนกว่าจะมีทีมจริง

---

## Phase 0 · Foundation (ต้องผ่านก่อนทุกอย่าง)

**Deliverable**
- repo + CI + Docker compose (app, PostgreSQL, Redis, MinIO)
- auth: register, login, PIN, OTP, session
- workspace + membership + invitation (QR/code)
- `tenants` + **default tenant** + RLS สองชั้น (tenant + workspace) + composite FK — [ADR-013](DECISIONS.md#adr-013--tenancy-shared-schema--tenant_id-ตั้งแต่-phase-0)
- **Authz service** ตาม [`03`](03-AUTHORIZATION.md) + Policy + 4 tests ต่อ endpoint
- `persons`, `health_profiles`, `care_relationships`, `access_grants`, `consent_records` + RLS
- `security_audit_logs` + hash chain
- Retention policy config (รายประเภท) + `data_holds` + purge job แบบ **dry-run** — [ADR-016](DECISIONS.md#adr-016--retention-ตามวัตถุประสงค์-pdpa--tenant-override)
- seed 3 personas + dependent profile

**Exit criteria (G0)**
- [ ] `ISO-*`, `GRANT-*`, `CONSENT-*`, `TEN-*` ผ่าน 100%
- [ ] checklist §7 ของ baseline ครบ (10 ข้อ) **และมีหลักฐานผลทดสอบ**
- [ ] ไม่มี endpoint ที่ไม่มี permission annotation
- [ ] PHI leak scan ผ่าน

> **ห้ามเริ่ม Phase 1 ถ้า G0 ไม่ผ่าน** — เพราะทุกฟีเจอร์หลังจะพึ่ง authorization ที่พิสูจน์แล้ว

---

## Phase 1 · Core MVP

**Deliverable**
- Health Vault: วัดค่า (BP, glucose, pulse, weight, SpO2), โรคประจำตัว, แพ้ยา
- Medication: plan ง่าย + record + adherence 7 วัน
- Care Task tier `routine` + `delegated` (ยังไม่ต้อง clinical)
- Dependent / Proxy profile + **L1 kinship** (มอบหมายงานได้ แต่ไม่มีสิทธิ์ข้อมูลสุขภาพ) — [ADR-015](DECISIONS.md#adr-015--หลักฐานอำนาจผู้แทน-3-ระดับตามความเสี่ยง)
- Appointment พื้นฐาน
- Dashboard 5 โซน (ยกเว้น Doctor Brief)
- Notification in-app + push + **LINE OA (ยืนยันงาน routine)** — ADR-003
- Elder Mode
- Audit viewer สำหรับเจ้าของข้อมูล (`audit_event.read_own` — **ใช้งานได้แล้ว** ใน baseline v1.1.0 ตาม [CR-001](changes/CR-001-permission-delta-v1.1.md))
- Inactivity flow (แจ้ง 30/14/7 → export → purge) + เปิด purge จริงหลัง dry-run ผ่าน — [ADR-016](DECISIONS.md#adr-016--retention-ตามวัตถุประสงค์-pdpa--tenant-override)

**Exit criteria (G1)**
- [ ] `CT-*`, `AP-*` ผ่าน
- [ ] dashboard p95 < 1.2s (cold)
- [ ] ทดสอบกับ **10–15 ครอบครัว** (G2) → ตัดสิน positioning + ราคา (ADR-011)
- [ ] ไม่มี critical/high vulnerability

**ยังไม่ทำใน Phase นี้:** recurring, escalation อัตโนมัติ, break-glass, clinical tier, Doctor Brief, AI
**ปิดโดยเจตนา:** scope `mental_health` และ `lab_results` (structured) — ใบแล็บเก็บเป็น `documents.category='lab_report'` ([ADR-014](DECISIONS.md#adr-014--sensitive-scopes-ปิดใน-mvp-mental_health-lab_results))

---

## Phase 2 · Coordination

**Deliverable**
- Recurring tasks (template + occurrence + idempotency)
- Escalation ladder + policy config
- Clinical tier + evidence + verify
- Temporary caregiver access (ตามกะ)
- **Legal representative L2/L3** — ยืนยันอำนาจ + ops review + dual control + re-verify 12 เดือน ([ADR-015](DECISIONS.md#adr-015--หลักฐานอำนาจผู้แทน-3-ระดับตามความเสี่ยง))
- **Break-glass** (ADR-002) + SOS card — **เปิดให้ทุกแพ็กเกจ ปิดเป็นค่าเริ่มต้น** ([ADR-017](DECISIONS.md#adr-017--break-glass-เปิดให้ทุกแพ็กเกจ-ไม่ผูกกับราคา))
- **Doctor Brief** (PDF + QR 30 นาที)
- Case management (**รอ CR-002 / ADR-012 Wave 2**)
- Interaction log / handover
- Web admin (Angular): จัดการสมาชิก, audit, support console (ไม่เห็น PHI)

> **ถ้าจะเปิด `lab_results` / `mental_health`** ต้องผ่านเงื่อนไข 5 ข้อใน [ADR-014](DECISIONS.md#adr-014--sensitive-scopes-ปิดใน-mvp-mental_health-lab_results) ก่อน — ไม่เปิดพร้อม Phase 2 โดยอัตโนมัติ

**Exit criteria (G3)**
- [ ] `RC-*`, `ES-*`, `AS-*`, `DB-*`, `SEC-*`, `LEG-*`, `BG-*` ผ่าน
- [ ] break-glass ทดสอบ end-to-end + แจ้งเตือนครบ
- [ ] QR หมดอายุ/เพิกถอนได้จริง

---

## Phase 3 · AI

**Deliverable**
- AI Context Gateway 5 ขั้น + audit
- สรุปแนวโน้ม + คำถามสำหรับแพทย์
- OCR: หน้าจอเครื่องวัดความดัน + ซองยา
- Quota/cost tracking + provider routing
- Evaluation suite ตาม [`08 §6`](08-AI-GATEWAY.md#6-evaluation-ก่อนเปิดใช้)

**Exit criteria (G4)**
- [ ] leakage/scope/injection/refusal/grounding tests ผ่านตามเกณฑ์
- [ ] ไม่มี PII ออกนอกใน 100% ของชุดทดสอบ
- [ ] DPA กับ provider ครบ

---

## Phase 4 · Scale & Compliance (ต่อเนื่อง)

- Load test + partition + index tuning
- Penetration test ภายนอก
- Legal review (PDPA, ข้อกำหนดเวชระเบียน)
- Backup/restore drill
- Incident runbook + on-call
- SOC2-like controls (ถ้าต้องการขายองค์กร)

---

## ลำดับงานภายใน Phase (แนวทาง)

1. **Schema + migration** (ตามลำดับใน `06 §9`)
2. **Domain + tests** (unit) — ก่อนมี UI
3. **API + Policy + feature tests**
4. **Flutter feature** (data → domain → presentation)
5. **Design system component** ที่ใช้ซ้ำ ≥2 หน้า
6. **E2E happy path**
7. **Observability** (log/trace/metric ที่ไม่ leak)

> ห้ามเริ่มจากหน้าจอ — `SUMMARY.md` เตือนตรงๆ ว่า *"อย่าเริ่มจากการเขียนหน้าจอ Task Management ก่อนออกแบบ Domain"*

---

## ความเสี่ยงตาม Phase

| Phase | ความเสี่ยงหลัก | ลดความเสี่ยง |
|---|---|---|
| 0 | authorization ออกแบบผิดแล้วแก้ยาก | ทำ test ก่อน UI, review โดยคนที่ 2 |
| 1 | ผู้ใช้ไม่เห็นคุณค่า (ไม่ได้ผลจริง) | ทดสอบ 10–15 ครอบครัวก่อนขยาย |
| 2 | escalation รบกวนจนปิด notification | policy ปรับได้ + จำกัดความถี่ |
| 3 | AI ให้ข้อมูลผิด/หลุด PII | gateway + eval + disclaimer + kill switch |
| 4 | ต้นทุน/กฎหมาย | วางแผน DPA + retention ตั้งแต่ Phase 0 |

---

## สิ่งที่ **ไม่** อยู่ใน roadmap นี้

- Telemedicine / video call
- เชื่อม HIS/HL7/FHIR
- Marketplace จ้างพยาบาล
- ระบบชำระเงินจริง (จนกว่า ADR-011 จะปิด)
- Watch app / AR

---

## Definition of "พร้อมใช้จริง" (Production readiness)

- [ ] G0–G6 ผ่านครบ
- [ ] ไม่มี high/critical vulnerability ค้าง
- [ ] backup restore ทดสอบสำเร็จในไตรมาสล่าสุด
- [ ] incident runbook + on-call พร้อม
- [ ] เอกสาร `.ai/` ตรงกับโค้ด (ไม่มี drift)
- [ ] legal/PDPA review ลงนามแล้ว
