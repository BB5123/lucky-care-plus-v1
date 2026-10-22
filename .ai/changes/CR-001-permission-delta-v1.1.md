# CR-001 · Baseline v1.1 — Permission Delta (Wave 1)

| | |
|---|---|
| **Change Record ID** | `CR-001` |
| **Target baseline** | `AUTH-BASELINE-001` v1.0.0 → **v1.1.0** |
| **Date raised** | 2026-10-22 |
| **Status** | ✅ **APPLIED** — baseline bumped to **v1.1.0** on 2026-10-22 |
| **Approver** | Product owner (ตอบ Q-4: Wave 1 = A+B) |
| **Related** | [ADR-012](../DECISIONS.md#adr-012--baseline-v11-delta-ต้องมี-permission-เพิ่ม-4-โดเมน), Q-4 ใน [TRACEABILITY](../TRACEABILITY.md#4-open-questions) |
| **Scope** | +4 permission ID, 2 โดเมน (`notification`, `audit_event`), +2 resource scope, +1 frozen decision |
| **ไม่รวมในรอบนี้** | `care_case.*` (4), `interaction_log.*` (2) → **Wave 2 / CR-002** |

> **สถานะปัจจุบัน:** **มีผลแล้ว** — แก้ `PERMISSION_CATALOG.md`, `DATA_ACCESS_MATRIX.md`, `AUTHORIZATION_TEST_PLAN.md`, `AUTHORIZATION_BASELINE.md` พร้อมกันและ bump เป็น **v1.1.0** ตาม `ABD-015`
> **ผลที่ตามมา:** permission ID ทั้ง 4 ตัว **ใช้งานในโค้ด/API ได้แล้ว** เพราะมี matrix row (Matrix E) และ test (`AT-NOTIF-*`, `AT-AUDIT-004/005`) รองรับตาม `ABD-013`
> **โฟลเดอร์ `docs/baseline-v1.0.0/` คงชื่อเดิม** เพื่อไม่ให้ลิงก์เดิมเสีย — เนื้อหาข้างในคือ v1.1.0

---

## 1. สรุปการเปลี่ยนแปลง

| | ก่อน (v1.0.0) | หลัง (v1.1.0) |
|---|---|---|
| จำนวน permission | 89 | **93** |
| โดเมน | 25 | **26** (+ `notification`; `audit_event` มีอยู่แล้ว) |
| Frozen decision | `ABD-001`…`ABD-015` | + **`ABD-016`** |
| Role | 8 | 8 (**ไม่เพิ่ม**) |
| Resource scope | ตาม matrix §10 | + `self_membership`, `owned_profile_audit` |

**ไม่มีการเปลี่ยนชื่อ/ลบ permission เดิม** — เป็นการเพิ่มเท่านั้น จึงไม่กระทบ endpoint ที่มีอยู่

---

## 2. เหตุผล

**ชุด A · Notification (3 ตัว)**
- Phase 1 มี notification แล้ว (`12-ROADMAP`) แต่ `communication_preferences` เป็น entity ในโดเมน (`02 §5`) ที่ **ไม่มีเจ้าของสิทธิ์**
- ถ้าผู้ใช้ปิด notification ไม่ได้ในแอป เขาจะไปปิดที่ระดับ OS → ระบบแจ้งเตือน (ซึ่งเป็นหัวใจของ Care Coordination) พังและกู้คืนไม่ได้
- ต้องแยกจาก `consent.*` อย่างชัดเจน: การเลือกช่องทาง **ไม่ใช่** การถอนความยินยอม (`ABD-008`, `F-12`)

**ชุด B · Audit ของเจ้าของ (1 ตัว)**
- roadmap Phase 1 ระบุหน้าจอ "ใครดูข้อมูลฉัน" ไว้แล้ว
- `audit_event.read` ที่มีอยู่เป็นระดับ staff/platform — การขยายให้ผู้ใช้ทั่วไปใช้ตัวเดิมจะเสี่ยงเกินไป (จะเห็นกิจกรรมของคนอื่นใน workspace)
- เป็นจุดขายด้านความไว้วางใจ และสอดคล้อง `SUMMARY_2 §3.12` + `F-11`

---

## 3. Patch · Permission catalog (เพิ่มใน §4)

| Permission ID | Meaning | Sensitive | Notes | Scope ที่ต้องมี |
|---|---|---|---|---|
| `notification.preference.read` | Read the actor's own notification/channel preferences | **Low** | เปิดเผยเฉพาะช่องทาง ภาษา และช่วงเวลาที่สะดวก — **ไม่** เผยความสัมพันธ์ในครอบครัวหรือข้อมูลสุขภาพ | `self.membership` |
| `notification.preference.update` | Update the actor's own notification/channel preferences | **Low** | **ไม่ใช่**การให้หรือถอน consent; การเปลี่ยนช่องทางห้ามเปลี่ยนสถานะ consent (ABD-008) | `self.membership` |
| `notification.delivery.read` | Read delivery status of notifications addressed to the actor | **Low** | คืนเฉพาะ metadata: channel, status, attempts, sent_at, error_code — **ห้าม**คืนเนื้อข้อความหรือ PHI | `self.membership` |
| `audit_event.read_own` | Read audit events concerning the actor's own data access | **Medium** | เฉพาะ event ที่ actor เป็น subject หรือ profile ∈ (โปรไฟล์ของตนเอง / โปรไฟล์ที่กำกับตามกฎหมาย) — ห้ามเห็นกิจกรรมของสมาชิกคนอื่น | `owned_profile_audit` |

**หลีกเลี่ยงการตั้งชื่อซ้ำกับของเดิม:** `audit_event.read_own` ≠ `audit_event.read`
- `audit_event.read` = staff/platform review ทั้ง workspace
- `audit_event.read_own` = มุมมองของเจ้าของข้อมูลเท่านั้น

---

## 4. Patch · Resource scopes (เพิ่มใน §5)

| scope | นิยาม | ใช้กับ |
|---|---|---|
| `self_membership` | actor เข้าถึงได้เฉพาะแถวที่เป็นของ membership ของตนเอง (`self_membership:<membership_id>`) | `notification.preference.*`, `notification.delivery.read` |
| `owned_profile_audit` | audit entry ที่ `subject_profile_id` อยู่ในชุดโปรไฟล์ที่ actor เป็นเจ้าของหรือกำกับตามกฎหมาย (`owned_profile_audit:<person_id>`) | `audit_event.read_own` |

---

## 5. Patch · Role × permission matrix (Matrix E ใหม่: self-service)

| Permission | profile_owner | legal_guardian | workspace_admin | caregiver | viewer | healthcare_professional | platform_support | platform_admin |
|---|---|---|---|---|---|---|---|---|
| `notification.preference.read` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅* | ✅* |
| `notification.preference.update` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅* | ✅* |
| `notification.delivery.read` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅* | ✅* |
| `audit_event.read_own` | ✅ (own) | ✅ (represented) | — | — | — | — | — | — |

`*` staff ใช้ได้เฉพาะเมื่อเป็นสมาชิก workspace ในฐานะส่วนตัว (ไม่ใช่สิทธิ์จากการเป็น staff — `F-10`)
`—` = deny by default; staff ใช้ `audit_event.read` สำหรับงาน review

**ผลต่อ matrix เดิม:** ไม่มี row ใดถูกลบหรือลดสิทธิ์ — เป็นการเพิ่ม Matrix E ทั้งก้อน

---

## 6. Patch · Authorization test plan

| Test ID | Given | When | Expected | Rule |
|---|---|---|---|---|
| `AT-NOTIF-001` | สมาชิกทั่วไป | อ่าน preference ของตนเอง | 200 + ค่าที่ถูกต้อง | `AUTH-002` |
| `AT-NOTIF-002` | สมาชิก A | อ่าน preference ของสมาชิก B | 404 (ไม่เปิดเผยการมีอยู่) | `AUTH-003` |
| `AT-NOTIF-003` | ผู้ใช้ปิดช่องทาง LINE | ตรวจ `consent_records` | **ไม่เปลี่ยน** สถานะ consent | `ABD-008` |
| `AT-NOTIF-004` | มี delivery ที่ล้มเหลว 3 ครั้ง | อ่าน delivery ของตน | เห็นเฉพาะ metadata, ไม่มีเนื้อข้อความ | `F-11` |
| `AT-AUDIT-004` | เจ้าของโปรไฟล์ | อ่าน `audit_event.read_own` | เห็นเฉพาะ event ของตน/ผู้แทน | `F-11`, `ABD-001` |
| `AT-AUDIT-005` | caregiver (ไม่มีสิทธิ์) | อ่าน audit ของเจ้าของ | 403/404 ตามนโยบาย `07 §2` | `AUTH-001` |

**เพิ่มใน §11 Traceability:** ทุก ID ใหม่ต้อง map กลับไปยัง `F-11`, `ABD-008`, `ABD-013`

---

## 7. Security & privacy impact assessment

| ประเด็น | การประเมิน | มาตรการ |
|---|---|---|
| ขยายการเข้าถึงข้อมูลสุขภาพ? | **ไม่** — ไม่มี permission ใหม่ใดอ่าน health record | — |
| ความเสี่ยง privacy ของบุคคลอื่น | `notification.delivery.read` อาจเผยว่า "มีคนส่งหาคุณ" | คืนเฉพาะ metadata ของ *ผู้รับ* ที่เป็น actor |
| ความเสี่ยงจาก `audit_event.read_own` | อาจเผยชื่อ/บทบาทของสมาชิกที่เข้าถึง | แสดง role label; ชื่อจริงเฉพาะเมื่อมี `workspace.member.read`; staff แสดงเป็น "platform support" |
| Consent confusion | ผู้ใช้อาจเข้าใจว่าปิดแจ้งเตือน = ถอนความยินยอม | UI ต้องระบุชัด + `AT-NOTIF-003` |
| Privilege escalation | ไม่มี — scope จำกัดที่ self | deny by default + 404 policy |
| Meta-audit | การอ่าน audit เองก็ควรถูกบันทึก | log `security_audit_logs` (action=`audit_event.read_own`) |

**สรุป:** ความเสี่ยงระดับ **Low–Medium** ไม่กระทบ `ABD-001`…`ABD-015`

---

## 8. Patch · Frozen decision log (เพิ่ม ABD-016)

| Decision ID | Frozen decision | Rationale |
|---|---|---|
| `ABD-016` | Notification preferences and self-audit visibility are **not** authorization or consent state: they never grant, widen, or withdraw data access. | Prevents users/system from mistaking channel settings for consent withdrawal, and keeps `audit_event.read_own` from becoming a data-access path. |

---

## 9. ไฟล์ที่ต้องแก้พร้อมกันเมื่อ "apply" (ตาม `ABD-015`)

| ไฟล์ | สิ่งที่ต้องเพิ่ม |
|---|---|
| `docs/baseline-v1.0.0/PERMISSION_CATALOG.md` | ✅ §4.10 `audit_event.read_own`, §4.11 Notification, §5 scopes 2 ตัว, v1.1.0 |
| `docs/baseline-v1.0.0/DATA_ACCESS_MATRIX.md` | ✅ §8 Matrix E, §10 scope, v1.1.0 |
| `docs/baseline-v1.0.0/AUTHORIZATION_TEST_PLAN.md` | ✅ §6.10 `AT-NOTIF-001…004` + `AT-AUDIT-004/005`, §11 mapping (+ แก้เลขหัวข้อย่อย 5.x → 6.x) |
| `docs/baseline-v1.0.0/AUTHORIZATION_BASELINE.md` | ✅ `ABD-016`, v1.1.0, Change history |
| `.ai/05-CARE-COORDINATION.md` §7 | ✅ เปลี่ยนหมายเหตุ `care_case.*` เป็น "Wave 2" |
| `.ai/07-API-CONTRACT.md` | ✅ เพิ่ม §3.10 self-service endpoints |
| `.ai/06-DATA-MODEL.md` | ✅ `DM-A05` อ้าง CR-001 |
| `.ai/12-ROADMAP.md` | ✅ Phase 1: audit viewer ไม่มีเงื่อนไขแล้ว |
| `.ai/TRACEABILITY.md` | ✅ §5 delta → Wave 1 applied |

> ✅ **APPLIED 2026-10-22** — แก้ทั้ง 4 ไฟล์ใน baseline พร้อมกันและ bump เป็น v1.1.0 ทำให้ permission ทั้ง 4 ตัวมีผลใช้ได้จริงตาม `ABD-013` / `ABD-015`

---

## 10. Rollback

- ลบ 4 permission ID + Matrix E + `ABD-016` คืน v1.0.0
- ไม่มี data migration (permission เป็น code-level)
- endpoint ที่ใช้ ID ใหม่ต้องถูก feature-flag ปิดก่อน rollback

---

## 11. Wave 2 (ยังไม่อนุมัติ)

| permission | ทำไมยังไม่ทำ |
|---|---|
| `care_case.create/read/update/close` | โมดูล Case ยังไม่มีดีไซน์ lifecycle/state machine (Phase 2) |
| `interaction_log.create/read` | ยังไม่ตัดสินว่าใช้ `task_events` แทนได้หรือไม่ |

จะเปิด **CR-002** เมื่อ `05 §7.5` และ handoff design เสร็จ

---

## 12. บันทึกการอนุมัติ

| วันที่ | ผู้ตัดสิน | คำตัดสิน | อ้างอิง |
|---|---|---|---|
| 2026-10-22 | Product owner | อนุมัติ **Wave 1 (A+B)** = 4 permission ID; เลื่อน Wave 2 | Q-4 |
| 2026-10-22 | Engineering | **APPLIED** — แก้ baseline 4 ไฟล์พร้อมกัน + bump เป็น v1.1.0 | §9 |
