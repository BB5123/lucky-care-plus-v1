# TRACEABILITY — แหล่งที่มา, ดัชนี Rule ID, open questions, baseline delta

---

## 1. แหล่งที่มา → กลายเป็นเอกสารไหน

| แหล่ง | สิ่งที่ได้ | กลายเป็น |
|---|---|---|
| `docs/SUMMARY.md` | ผลิตภัณฑ์, คู่แข่ง, สถาปัตยกรรม, permission matrix, test suite | `01`, `03`, `05`, `10` |
| `docs/SUMMARY_2.md` | CRM domain 12 โมดูล, entity, 10 กฎเหล็ก | `02`, `05`, `CRM_DOMAIN_ARCHITECTURE`, `MOBILE_DESIGN` |
| `docs/baseline-v1.0.0/PERMISSION_CATALOG.md` | 89 permission / 25 โดเมน | **Normative** — อ้างตรง ไม่คัดลอก |
| `docs/baseline-v1.0.0/DATA_ACCESS_MATRIX.md` | 8 role, Matrix A–D, scope | **Normative** — `03` |
| `docs/baseline-v1.0.0/AUTHORIZATION_TEST_PLAN.md` | test ID + gate | **Normative** — `10` |
| `docs/baseline-v1.0.0/AUTHORIZATION_BASELINE.md` | frozen decisions + change control | `00`, `README` |
| `docs/MOBILE_DESIGN.txt` | flow register/login, 5 แท็บ | `MOBILE_DESIGN` (ตัดสินใหม่ ADR-004) |
| `docs/PDFs/…Architecture_and_Design_Plan.pdf` | วิจารณ์ + break-glass + AI gateway + DB practices + roadmap | `08`, `04`, `06`, `12`, ADR-001/002/003/007 |
| `docs/PDFs/…Module_and_Mobile_Dashboard_Design.pdf` | CRM 5 โมดูล, care rings, dashboard 5 โซน, permission-aware UI | `05`, `MOBILE_DESIGN`, ADR-010 |
| `references/source/paotang` | แพตเทิร์นโครงสร้าง Flutter/Laravel | `11` (เฉพาะแพตเทิร์น) |

**ไม่ได้นำมาใช้เป็นข้อกำหนด:** ตัวเลขราคาใน PDF1 (อ่านไม่ได้), คำวิจารณ์ใน PDF1 ที่ `SUMMARY_2` แก้ไปแล้ว (เช่นเรื่อง Managed Profile — ดู ADR-009)

---

## 2. ดัชนี Rule ID

| กลุ่ม | ไฟล์ | ช่วง ID | จำนวน |
|---|---|---|---|
| F (fundamental) | `00-CONSTITUTION` | F-01…F-18 | 18 |
| N (never) | `00-CONSTITUTION` | N-01…N-08 | 8 |
| DM (domain) | `02-DOMAIN-MODEL` | DM-001…012 | 12 |
| CD (consent/delegation) | `04` | CD-001…005, 010…016, 020…023 | 16 |
| BG (break-glass) | `04` | BG-001…010 | 10 |
| CT (care task) | `05` | CT-001…010 | 10 |
| RC (recurring) | `05` | RC-001…006 | 6 |
| AS (assignment) | `05` | AS-001…005 | 5 |
| ES (escalation) | `05` | ES-001…006 | 6 |
| APT (appointment) | `05` | APT-001…005 | 5 |
| DB (doctor brief) | `05` | DB-001…005 | 5 |
| DM-A (audit) | `06` | DM-A01…A05 | 5 |
| DM-R (retention) | `06` | DM-R01…R08 | 8 |
| CRM (architecture) | `CRM_DOMAIN_ARCHITECTURE` | CRM-001…024 | 24 |
| AP (api) | `07` | AP-001…010 | 10 |
| AIG | `08` | AIG-001…010 | 10 |
| ES (engineering) | `11` | ES-01…06, ES-10…17 | 14 |

> **หมายเหตุการตั้งชื่อ:** appointment ใช้ `APT-` เพื่อไม่ชนกับ `AP-` ของ API rule
> **หมายเหตุ:** `ES-` ถูกใช้ทั้ง Escalation (`05`) และ Engineering Standard (`11`) — ใช้บริบทของไฟล์แยกได้ แต่เวลาเขียน test ให้ใช้ `ES-T*` (escalation) และ `ENG-*` (standard)
> **Test prefix ใหม่ (ไม่ใช่ rule):** `TEN-T01…T04` = tenancy isolation (ADR-013, ดู `10 §4.6`), `SEN-T01…T03` = sensitive scope ที่ปิดใน MVP (ADR-014, ดู `10 §4.8`), `LEG-T01…T05` = อำนาจผู้แทน (ADR-015, ดู `10 §4.9`), `RET-T01…T05` = retention/purge (ADR-016, ดู `10 §4.10`), `BG-T01…T05` = break-glass (ADR-017, ดู `10 §4.11`) — เสริม `ISO-*` ของ baseline ไม่ทับเลข

---

## 3. Permission ที่ใช้บ่อย → เอกสาร

| permission | อยู่ที่ | ใช้ใน |
|---|---|---|
| `care_task.*` (12) | `05`, `07` | state machine, endpoints |
| `access_grant.*` (4) | `04`, `07` | delegation |
| `consent.*` (3) | `04`, `07` | consent UI/API |
| `break_glass.*` (3) | `04`, `09` | emergency |
| `health_measurement.*` (4) | `06`, `07` | vault |
| `medical_document.*` (5) | `06`, `09` | file handling |
| `ai.*` (5) | `08` | gateway |
| `data_export.*` (3), `health_report.*` (3) | `05 §9`, `07` | Doctor Brief |

---

## 4. Open Questions

> **ห้ามเดา** — ถ้างานต้องพึ่งข้อใด ให้หยุดและถามเจ้าของผลิตภัณฑ์

| ID | คำถาม | ผลกระทบ | ต้องตัดสินก่อน |
|---|---|---|---|
| **Q-1** | ~~`AP-` ชนกันระหว่าง Appointment กับ API~~ → **แก้แล้ว**: appointment = `APT-`, API = `AP-` | — | ✅ resolved |
| **Q-2** | ราคา 3 ชั้น (ADR-011) และจะเปิดขายเมื่อใด | billing, marketing | G2 |
| **Q-3** | ~~Break-glass เปิดให้ทุก workspace หรือเฉพาะแพ็กเกจสูง~~ → **ทุกแพ็กเกจ ไม่ผูกกับราคา** + เงื่อนไขเปิด 4 ข้อ (ADR-017) | — | ✅ resolved |
| **Q-4** | ~~ADR-012 อนุมัติหรือไม่~~ → **อนุมัติ Wave 1 (4 ID)**, Wave 2 เลื่อน — ดู [CR-001](changes/CR-001-permission-delta-v1.1.md) | — | ✅ resolved |
| **Q-5** | ~~Retention เวชระเบียน (10 ปี?)~~ → **purpose-based ตาม PDPA** + tenant override min/max (ADR-016) | — | ✅ resolved |
| **Q-12** | ตัวเลข retention ทุกตัว + ข้อความ Privacy Notice ต้องผ่าน legal review ก่อนเปิดใช้จริง | `06 §8`, Privacy Notice | ก่อน G6 |
| **Q-13** | ฐานทางกฎหมาย + ข้อความแจ้ง/in-app disclosure ของการเข้าถึงฉุกเฉิน (break-glass) ต้องผ่าน legal review | `04 §4`, `09 §4.2` | ก่อน G3 |
| **Q-6** | ~~Legal guardian ต้องใช้หลักฐานอะไร~~ → **3 ระดับ L1/L2/L3** ตามความเสี่ยง (ADR-015); รายละเอียดเอกสารต้องรอ legal ยืนยัน → ย้ายไป Q-11 | — | ✅ resolved |
| **Q-7** | LINE OA มีต้นทุนต่อข้อความ — งบต่อเดือนเท่าไร | escalation frequency | Phase 1 |
| **Q-8** | ต้องมี eKYC (เทียบ NDID) ใน Phase ไหน | auth flow | Phase 2+ |
| **Q-9** | ~~`mental_health` / `lab_results` เปิดใน MVP ไหม~~ → **ปิดทั้งคู่ใน MVP**; ใบแล็บเก็บเป็น `documents.category='lab_report'` (ADR-014) | — | ✅ resolved |
| **Q-10** | ~~Multi-tenant จริงหรือ single-tenant~~ → **shared schema + `tenant_id` ตั้งแต่ Phase 0**, default tenant เดียว (ADR-013) | — | ✅ resolved |
| **Q-11** | รายการเอกสารที่ "เพียงพอ" ต่อ L1/L2/L3 + อายุบรรลุนิติภาวะ + กรณีผู้เสมือนไร้ความสามารถ — ต้องให้ที่ปรึกษากฎหมายยืนยัน | flow ผู้แทน (ADR-015), ข้อความ consent | ก่อน Phase 2 |

---

## 5. Baseline v1.1 Delta

**Wave 1 = ✅ APPLIED in baseline v1.1.0** (2026-10-22) → [`changes/CR-001`](changes/CR-001-permission-delta-v1.1.md)
**สถานะ:** permission ทั้ง 4 ตัว **มีผลใช้ได้แล้ว** — มี Matrix E, scope และ test (`AT-NOTIF-*`, `AT-AUDIT-004/005`) รองรับครบตาม `ABD-013`

### Wave 1 · อนุมัติแล้ว (4 ID)

| permission | โดเมน | ใช้เมื่อ | สถานะ |
|---|---|---|---|
| `notification.preference.read` | notification | Phase 1 | ✅ approved (CR-001) |
| `notification.preference.update` | notification | Phase 1 | ✅ approved (CR-001) |
| `notification.delivery.read` | notification | Phase 1 | ✅ approved (CR-001) |
| `audit_event.read_own` | audit_event | Phase 1 | ✅ approved (CR-001) |

### Wave 2 · ยังไม่อนุมัติ (6 ID)

| permission | โดเมน | เหตุผลที่เลื่อน | ถ้าไม่อนุมัติ |
|---|---|---|---|
| `care_case.create/read/update/close` | care_case | ยังไม่มี lifecycle design — Phase 2 | ตัดฟีเจอร์ Case |
| `interaction_log.create/read` | interaction | อาจใช้ `task_events` แทนได้ — Phase 2 | ใช้ `task_events` |

**test ที่ต้องเพิ่มเมื่อ apply:** `AT-NOTIF-001…004`, `AT-AUDIT-004…005` (ดู CR-001 §6)

---

## 6. สิ่งที่ยัง **ไม่มี** ในชุดเอกสารนี้ (และควรมีต่อไป)

| ขาด | ทำไมสำคัญ | เสนอเป็น |
|---|---|---|
| ERD แบบภาพ | เข้าใจความสัมพันธ์เร็ว | `docs/diagrams/erd.svg` (สร้างจาก `02 §4`) |
| OpenAPI spec จริง | contract testing | `openapi/v1.yaml` (จาก `07`) |
| Threat model ระดับ data-flow | ตรวจช่องโหว่เชิงระบบ | ต่อยอด `09 §1` |
| Runbook ปฏิบัติการ | incident response | `ops/RUNBOOK.md` |
| Test case รายละเอียดครบทุก ID | ตรวจ traceability | ขยาย `10 §4` |
| Design tokens (JSON) | sync กับ Figma | `design/tokens.json` |
| Migration จริง | เริ่มโค้ด | ตาม `06 §9` |

---

## 7. ประวัติของชุดเอกสาร

| วันที่ | เวอร์ชัน | สิ่งที่เปลี่ยน |
|---|---|---|
| 2026-10-22 | 1.0.0 | รวม `SUMMARY.md` + `SUMMARY_2.md` + baseline v1.0.0 + PDF 2 ฉบับ เป็น Blueprint ชุดเดียว, แก้ conflict 8 ประเด็นด้วย ADR-001…012, เพิ่ม `MOBILE_DESIGN.md` |
