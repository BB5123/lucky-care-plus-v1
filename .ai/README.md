# `.ai/` — Project Blueprint · Lucky Care Plus (Family Health CRM)

| | |
|---|---|
| **Blueprint version** | `1.0.0` |
| **Effective date** | 2026-10-22 |
| **Status** | Draft for implementation — *not* legally certified, *not* production-ready |
| **Target** | AI coding agents + human team |
| **Supersedes** | n/a (first consolidated blueprint) |

> เอกสารชุดนี้คือ **single source of truth ด้านการออกแบบ** ของโปรเจกต์ ใช้แทนการตีความจาก `docs/SUMMARY*.md` และ PDF ทั้งสองฉบับ
> เอกสารเดิมไม่ได้ถูกลบ — เก็บไว้เป็น **historical input** และทุกข้อที่ขัดกันถูกบันทึกไว้ใน [`DECISIONS.md`](DECISIONS.md)

---

## 1. ลำดับชั้นของเอกสาร (Precedence)

เมื่อเอกสารขัดกัน **ให้ยึดตามลำดับนี้** (บนชนะล่าง):

| ลำดับ | แหล่ง | ใช้เป็นอะไร | ห้ามทำอะไร |
|---|---|---|---|
| 1 | [`00-CONSTITUTION.md`](00-CONSTITUTION.md) | กฎที่หักล้างไม่ได้ | ห้าม agent "ตีความใหม่" |
| 2 | [`docs/baseline-v1.0.0/`](../docs/baseline-v1.0.0/) | **Normative IDs**: permission ID, role, matrix, test ID | ห้ามแก้เงียบๆ (ดู change control ในไฟล์นั้น) |
| 3 | `.ai/*.md` (ชุดนี้) | โมเดลโดเมน, สถาปัตยกรรม, API, UI, data model | ห้ามสร้างกฎสิทธิ์ใหม่เอง |
| 4 | `docs/SUMMARY.md`, `docs/SUMMARY_2.md` | ที่มาของแนวคิด (historical) | ห้ามใช้เป็นข้อกำหนดเมื่อขัดกับข้อ 1–3 |
| 5 | `docs/PDFs/*.pdf` | ข้อเสนอ/วิจารณ์ (advisory) | ห้ามใช้ตัวเลข/ตารางที่ขัดกับข้อ 3 |
| 6 | `references/source/*` | ตัวอย่างแพตเทิร์นโค้ด | ห้าม copy สิทธิ์/โดเมนของโปรเจกต์อื่นมาใช้ |

**เหตุผลที่ต้องมีลำดับชั้น:** เอกสาร 6 แหล่งนี้ถูกเขียนต่างเวลา ต่างบริบท และขัดกันจริงใน 8 ประเด็น (ดู [`DECISIONS.md`](DECISIONS.md))

---

## 2. วิธีใช้สำหรับ AI Agent

1. อ่าน [`00-CONSTITUTION.md`](00-CONSTITUTION.md) ก่อนทุกงาน
2. อ่านเอกสาร `.ai/` ที่เกี่ยวกับงานนั้น — **ห้ามอ่านข้ามไฟล์เดียวแล้วลงมือ** เพราะ permission/entity/ชื่อตารางอ้างกันทั้งชุด
3. ทุกครั้งที่เขียนโค้ด ต้องอ้าง **Rule ID** ที่รองรับ (เช่น `CT-004`, `SEC-002`) และ **Permission ID** จาก baseline
4. ก่อนเพิ่มตาราง/endpoint/หน้าจอ ต้องตรวจว่ามีอยู่ใน [`02-DOMAIN-MODEL.md`](02-DOMAIN-MODEL.md) / [`07-API-CONTRACT.md`](07-API-CONTRACT.md) / [`MOBILE_DESIGN.md`](MOBILE_DESIGN.md) แล้ว
5. ถ้าไม่พบคำตอบในเอกสาร **ห้ามเดา** — บันทึกลง [Open Questions](TRACEABILITY.md#4-open-questions) แล้วหยุดจุดนั้น
6. ห้ามแก้ `docs/baseline-v1.0.0/*` ภายใต้การทำงานปกติ — ต้องทำ Change Record ตามขั้นตอนใน `AUTHORIZATION_BASELINE.md`

### สิ่งที่ agent ต้องส่งออกทุกครั้ง (Definition of Done ของงานเอกสาร/โค้ด)

- อ้าง Rule ID + Permission ID
- ระบุไฟล์ `.ai/` ที่เป็นข้อกำหนดต้นทาง
- ถ้าเบี่ยงจาก Blueprint → ต้องมี ADR ใหม่ใน [`DECISIONS.md`](DECISIONS.md)

---

## 3. สารบัญ

| # | เอกสาร | เนื้อหา | สถานะ |
|---|---|---|---|
| — | [`README.md`](README.md) | ดัชนี, precedence, วิธีใช้ | ✅ |
| 00 | [`00-CONSTITUTION.md`](00-CONSTITUTION.md) | 12 กฎหลัก + 8 ข้อห้าม | ✅ |
| 01 | [`01-PRODUCT.md`](01-PRODUCT.md) | วิสัยทัศน์, persona, ขอบเขต, ราคา (สมมติฐาน), ตัวชี้วัด | ✅ |
| 02 | [`02-DOMAIN-MODEL.md`](02-DOMAIN-MODEL.md) | 15 bounded context, aggregate, **ชื่อตารางมาตรฐาน** | ✅ |
| 03 | [`03-AUTHORIZATION.md`](03-AUTHORIZATION.md) | โมเดลสิทธิ์ผสม, ลำดับตัดสินใจ 7 ขั้น, mapping role | ✅ |
| 04 | [`04-CONSENT-DELEGATION.md`](04-CONSENT-DELEGATION.md) | consent ≠ delegation ≠ legal authority, break-glass | ✅ |
| 05 | [`05-CARE-COORDINATION.md`](05-CARE-COORDINATION.md) | Care Task, state machine รวม, recurring, escalation, CRM 5 โมดูล | ✅ |
| 05a | [`CRM_DOMAIN_ARCHITECTURE.md`](CRM_DOMAIN_ARCHITECTURE.md) | สถาปัตยกรรมโดเมน CRM: boundary, aggregate, event, API, invariant | ✅ |
| 06 | [`06-DATA-MODEL.md`](06-DATA-MODEL.md) | UUIDv7, RLS, audit hash-chain, retention, encryption | ✅ |
| 07 | [`07-API-CONTRACT.md`](07-API-CONTRACT.md) | convention, endpoint, error model, idempotency, aggregator | ✅ |
| 08 | [`08-AI-GATEWAY.md`](08-AI-GATEWAY.md) | 5-stage pipeline, guardrail, logging, quota | ✅ |
| 09 | [`09-SECURITY-PRIVACY.md`](09-SECURITY-PRIVACY.md) | threat model, IDOR, PDPA mapping, incident | ✅ |
| 10 | [`10-TESTING.md`](10-TESTING.md) | test pyramid, requirement→test matrix, release gates | ✅ |
| 11 | [`11-ENGINEERING-STANDARDS.md`](11-ENGINEERING-STANDARDS.md) | stack, โครงโฟลเดอร์, naming, CI, DoD | ✅ |
| 12 | [`12-ROADMAP.md`](12-ROADMAP.md) | Phase 0–3, gate, deliverable | ✅ |
| — | [`MOBILE_DESIGN.md`](MOBILE_DESIGN.md) | flow, IA 5 แท็บ, Dashboard 5 โซน, Elder Mode, permission-aware UI | ✅ |
| — | [`DECISIONS.md`](DECISIONS.md) | ADR-001…ADR-012 (แก้ conflict ทั้งหมด) | ✅ |
| — | [`TRACEABILITY.md`](TRACEABILITY.md) | แหล่งที่มา, ดัชนี Rule ID, open questions, baseline delta | ✅ |
| — | [`changes/CR-001`](changes/CR-001-permission-delta-v1.1.md) | Change Record: permission delta v1.1 (Wave 1 อนุมัติแล้ว 4 ID) | ✅ |
| — | [`changes/CR-003`](changes/CR-003-permission-delta-v1.2.md) | Change Record: permission delta v1.2 (task templates + escalation, 6 ID) | ✅ |
| — | [`changes/CR-004`](changes/CR-004-task-evidence-scope-v1.3.md) | Change Record: evidence-read scope `task_evidence` (baseline v1.3.0) | ✅ |
| — | [`changes/CR-005`](changes/CR-005-platform-knowledge-review-v1.4.md) | Change Record: platform knowledge review + `is_platform_admin` (baseline v1.4.0) | ✅ |

---

## 4. ภาคผนวก Normative (อ้างอิง ไม่คัดลอก)

เอกสารใน `docs/baseline-v1.0.0/` **เป็นส่วนหนึ่งของ Blueprint นี้** ในฐานะภาคผนวกที่มีผลบังคับ
**เวอร์ชันปัจจุบัน: `v1.4.0`** (amend โดย [CR-001](changes/CR-001-permission-delta-v1.1.md), 2026-10-22, [CR-003](changes/CR-003-permission-delta-v1.2.md), 2026-10-24, [CR-004](changes/CR-004-task-evidence-scope-v1.3.md), 2026-10-24 และ [CR-005](changes/CR-005-platform-knowledge-review-v1.4.md), 2026-10-25) — ชื่อโฟลเดอร์คงเดิมเพื่อไม่ให้ลิงก์เสีย:

| ไฟล์ | สถานะใน Blueprint | ใช้เมื่อ |
|---|---|---|
| [`PERMISSION_CATALOG.md`](../docs/baseline-v1.0.0/PERMISSION_CATALOG.md) | **Normative** — **101 permission ID (v1.4.0)**, 26 โดเมน | ทุกครั้งที่ประกาศสิทธิ์/เขียน Policy |
| [`DATA_ACCESS_MATRIX.md`](../docs/baseline-v1.0.0/DATA_ACCESS_MATRIX.md) | **Normative** — 8 role, Matrix A–D, scope model | ออกแบบ endpoint/หน้าจอ |
| [`AUTHORIZATION_TEST_PLAN.md`](../docs/baseline-v1.0.0/AUTHORIZATION_TEST_PLAN.md) | **Normative** — test ID + release gate | เขียนเทสต์ |
| [`AUTHORIZATION_BASELINE.md`](../docs/baseline-v1.0.0/AUTHORIZATION_BASELINE.md) | **Normative** — frozen decisions + change control | เมื่อต้องการเปลี่ยนกฎ |
| [`README.md`](../docs/baseline-v1.0.0/README.md) | คำอธิบายชุด | ปฐมนิเทศ |

**หลักการไม่ทำซ้ำ:** Blueprint นี้อ้าง **permission ID** จาก catalog โดยตรง ไม่ประกาศชุดใหม่ซ้ำ เพราะจะทำให้เกิด drift
ข้อยกเว้นเดียวคือ [Baseline Delta ที่เสนอใน TRACEABILITY](TRACEABILITY.md#5-baseline-v11-delta-ข้อเสนอ--ยังไม่มีผล) ซึ่งต้องผ่าน change control ก่อนมีผล

---

## 5. Change control ของ Blueprint

| การเปลี่ยนแปลง | ต้องทำ |
|---|---|
| เพิ่ม/เปลี่ยน rule ID | แก้เอกสารเจ้าของ rule + [`10-TESTING.md`](10-TESTING.md) + [`TRACEABILITY.md`](TRACEABILITY.md) |
| เปลี่ยนชื่อ entity/ตาราง | แก้ [`02-DOMAIN-MODEL.md`](02-DOMAIN-MODEL.md) + [`06-DATA-MODEL.md`](06-DATA-MODEL.md) + อัปเดต alias |
| เปลี่ยน permission/role | **ห้ามแตะ baseline ตรงๆ** → เขียน Change Record ใน [`changes/`](changes/) + bump version ตาม `AUTHORIZATION_BASELINE.md` (ตัวอย่าง: [CR-001](changes/CR-001-permission-delta-v1.1.md)) |
| ขัดกับ ADR เดิม | เขียน ADR ใหม่ที่ supersede + ระบุเลข ADR เดิม |
| เปลี่ยน stack/โครงสร้างโค้ด | แก้ [`11-ENGINEERING-STANDARDS.md`](11-ENGINEERING-STANDARDS.md) + ADR |

---

## 6. ข้อจำกัดที่ต้องยอมรับ

- Blueprint นี้ออกแบบจากเอกสาร ไม่มีข้อมูลจากการทดสอบกับผู้ใช้จริง (10–15 ครอบครัวตาม [`01-PRODUCT.md`](01-PRODUCT.md)) — สมมติฐานผลิตภัณฑ์ยัง **ไม่ผ่านการพิสูจน์**
- ราคาทั้งหมดเป็น **สมมติฐาน** ไม่ใช่ข้อสรุป (ดู ADR-011)
- ข้อกำหนด PDPA/ฐานกฎหมายต้องผ่านการตรวจโดยผู้เชี่ยวชาญกฎหมายก่อนใช้งานจริง
- ตัวเลข/ตารางบางส่วนใน `docs/PDFs/` อ่านไม่ได้จาก text layer จึงไม่ถูกนำมาใช้เป็นข้อกำหนด

---

## 7. สถานะการตรวจสอบ (Audit)

**ตรวจล่าสุด:** 2026-10-22 · **ผล:** ผ่านทุกข้อ

| # | สิ่งที่ตรวจ | ผล |
|---|---|---|
| 1 | ไฟล์ครบและตรงกับสารบัญข้อ 3 | ✅ 19 ไฟล์ |
| 2 | ADR index ↔ heading (ADR-001…017) | ✅ ตรงกันทุกรายการ |
| 3 | Rule ID ที่ประกาศซ้ำในหลายไฟล์ | ✅ ไม่มี (ทุก ID ประกาศครั้งเดียว) |
| 4 | ลำดับหัวข้อ `## N.` ในทุกไฟล์ | ✅ เรียงต่อเนื่อง |
| 5 | ลิงก์ภายใน (relative) | ✅ ไม่มีลิงก์เสีย |
| 6 | Permission ID ที่อ้างถึง | ✅ ตรงกับ `PERMISSION_CATALOG.md` ทั้งหมด |
| 7 | ชื่อตารางที่ใช้ | ✅ ตรงกับรายการ canonical ใน `02 §5` (alias ใช้เฉพาะในตารางเทียบ) |
| 8 | Encoding ภาษาไทย | ✅ ไม่มี mojibake |
| 9 | ทุก Rule มี test ที่อ้างถึง | ✅ ยกเว้น Wave 2 ที่ยังไม่ implement |
| 10 | คำถามที่ยังเปิด (`TRACEABILITY §4`) | ✅ ระบุเจ้าของ/เงื่อนไขปิดครบ |

> การตรวจนี้เป็น **static consistency check** — ไม่ได้ยืนยันความถูกต้องทางกฎหมายหรือความถูกต้องของสมมติฐานผลิตภัณฑ์
