# 00 · CONSTITUTION — กฎที่หักล้างไม่ได้

> ทุกเอกสารใน `.ai/` ทุก policy ทุก endpoint ทุกหน้าจอ ต้องไม่ขัดกับไฟล์นี้
> ถ้าขัด → **ไฟล์นี้ชนะ** และต้องเขียน ADR เพื่อแก้เอกสารอื่นตาม

ที่มา: `docs/baseline-v1.0.0/AUTHORIZATION_BASELINE.md` (frozen decisions) + `docs/baseline-v1.0.0/README.md` + `docs/SUMMARY_2.md §7`

---

## 1. กฎหลัก 12 ข้อ (F-xx)

| ID | กฎ | ความหมายเชิงปฏิบัติ | ที่มา |
|---|---|---|---|
| **F-01** | **Deny by default** | ทุกคำขอถูกปฏิเสธ เว้นแต่มีกฎอนุญาตที่ตรงกันชัดเจน ไม่มี "โดยปริยายอนุญาต" | baseline |
| **F-02** | **Workspace membership ≠ health access** | เป็นสมาชิกครอบครัวไม่ได้แปลว่าอ่านข้อมูลสุขภาพได้ | baseline |
| **F-03** | **Admin ≠ health access** | `workspace_admin` จัดการสมาชิก/คำเชิญได้ แต่ไม่มีสิทธิ์อ่านเวชระเบียนอัตโนมัติ | baseline |
| **F-04** | **Task assignment ≠ data access** | ได้รับมอบหมายงานดูแล ไม่ได้สิทธิ์อ่านข้อมูลสุขภาพทั้งหมดของผู้รับการดูแล | baseline |
| **F-05** | **Relationship ≠ authority** | เป็นลูก/พ่อ/แม่/คู่สมรส/ผู้ติดต่อฉุกเฉิน ไม่ได้อำนาจทางกฎหมายหรือสิทธิ์เข้าถึงอัตโนมัติ | `SUMMARY_2 §3.3` |
| **F-06** | **Read ≠ export ≠ share ≠ AI use** | สี่การกระทำนี้เป็น permission คนละตัว ต้องตรวจแยกทุกครั้ง | baseline |
| **F-07** | **Grant cannot exceed grantor** | ผู้มอบสิทธิ์มอบได้ไม่เกินขอบเขต/อำนาจที่ตนเองมี ณ ขณะนั้น | baseline |
| **F-08** | **Revocation must bite on every path** | เพิกถอนแล้วต้องมีผลกับ API, cache, queue job, ไฟล์ที่ส่งแล้ว, และลิงก์ที่ยัง active | baseline |
| **F-09** | **Fail closed** | ถ้าตรวจสิทธิ์ที่จำเป็นไม่ได้ (ระบบล่ม/timeout/ข้อมูลไม่ครบ) → ปฏิเสธและไม่เปิดเผยข้อมูลสุขภาพ | baseline |
| **F-10** | **Platform staff have no standing access** | `platform_support` / `platform_admin` ไม่มีสิทธิ์อ่านข้อมูลสุขภาพเป็นค่าเริ่มต้น ต้องมี approval + audit เป็นรายกรณี | baseline |
| **F-11** | **Audit meaningful actions** | เปลี่ยนสิทธิ์, อ่านข้อมูลอ่อนไหว, export, share, ใช้ AI, break-glass → ต้องมี audit ที่ตรวจย้อนหลังได้ | baseline |
| **F-12** | **Consent ≠ Delegation ≠ Legal authority** | สามอย่างนี้แยกกัน ไม่สามารถใช้แทนกันได้ (รายละเอียด [`04-CONSENT-DELEGATION.md`](04-CONSENT-DELEGATION.md)) | baseline + `SUMMARY.md §5` |

---

## 2. ข้อห้าม 8 ข้อสำหรับ Agent (N-xx)

| ID | ห้าม | เพราะ |
|---|---|---|
| **N-01** | ห้ามเชื่อ `workspace_id`, `profile_id`, `role`, `scope`, `consent` ที่ client ส่งมา | `AUTH-012` — ต้องอ่านจาก DB ณ เวลาที่ร้องขอ |
| **N-02** | ห้ามใส่ข้อมูลสุขภาพ/consent ใน log, notification payload, ชื่อไฟล์, URL, error message | รั่วผ่านช่องที่ไม่ผ่าน authorization |
| **N-03** | ห้ามให้ AI/LLM ต่อฐานข้อมูลสุขภาพตรง | ต้องผ่าน [`08-AI-GATEWAY.md`](08-AI-GATEWAY.md) เท่านั้น |
| **N-04** | ห้ามแก้ `docs/baseline-v1.0.0/*` เงียบๆ | ต้องมี Change Record + version bump |
| **N-05** | ห้ามสร้างตาราง/คอลัมน์ใหม่โดยไม่อ้าง permission ID ที่ใช้กับมัน | ทุกข้อมูลต้องมีเจ้าของสิทธิ์ |
| **N-06** | ห้าม query ข้าม bounded context ตรงๆ (ข้ามตารางของโมดูลอื่น) | ต้องผ่าน service contract / domain event |
| **N-07** | ห้ามใช้ auto-increment PK กับตารางข้อมูลสุขภาพและเอกสาร | IDOR + enumeration |
| **N-08** | ห้ามทำ break-glass ที่ไม่มี time-box, reason, re-auth, alert, audit | จะกลายเป็นประตูหลังถาวร |

---

## 3. ลำดับการตัดสินใจที่มีผลทุก request

```
1. ยืนยันตัวตน
2. ตรวจ Tenant / Workspace
3. ตรวจ Relationship + Role
4. ตรวจ Resource Scope (profile + ประเภทข้อมูล)
5. ตรวจ Consent / Purpose
6. ตรวจ Domain Rules (state, entitlement)
7. Audit → อนุญาต
   └─ ถ้าข้อใดไม่ผ่าน หรือตรวจไม่ได้ → DENY (F-09)
```

รายละเอียดการ implement อยู่ที่ [`03-AUTHORIZATION.md`](03-AUTHORIZATION.md)

---

## 4. กฎที่เกี่ยวกับข้อมูล (Data)

| ID | กฎ |
|---|---|
| **F-13** | ข้อมูลสุขภาพทุกแถวต้องมีเจ้าของที่ระบุได้ — แม้เจ้าของยังไม่มีบัญชีผู้ใช้ (Dependent/Proxy profile) |
| **F-14** | `User`, `Person`, `HealthProfile`, `WorkspaceMembership`, `CareRelationship`, `CareTask` เป็นคนละแนวคิด **ห้ามยุบรวม** |
| **F-15** | Audit log เป็น append-only: แอปเขียนได้อย่างเดียว แก้/ลบไม่ได้แม้แต่ admin (ระดับ DB grant) |
| **F-16** | การลบข้อมูลสุขภาพต้องผ่านนโยบาย retention + สร้าง audit ไม่ใช่ hard delete เงียบๆ |
| **F-17** | ทุกคิว/งานเบื้องหลังต้องตรวจสิทธิ์ซ้ำ **ณ เวลาที่ทำงาน** ไม่ใช้สิทธิ์ที่ snapshot ตอนสร้างงาน |
| **F-18** | Escalation/การแจ้งเตือนงานค้าง **ไม่ใช่** ข้อสรุปว่าผู้ป่วยไม่ได้รับการดูแล และไม่ใช่ช่องทางเหตุฉุกเฉินทางการแพทย์ |

---

## 5. นิยามที่ใช้ร่วมกันทั้งชุด

| คำ | นิยาม |
|---|---|
| **Workspace** | ขอบเขตพื้นที่ครอบครัว/ทีมดูแล ใช้กั้นข้อมูลและการเรียกใช้ API |
| **Person** | ตัวบุคคลที่ระบบรู้จัก อาจไม่มีบัญชีผู้ใช้ |
| **Health Profile** | ข้อมูลสุขภาพที่ผูกกับ Person 1 คน |
| **Care Relationship** | ความสัมพันธ์ในการดูแล (kinship + ring) — ไม่ให้สิทธิ์ข้อมูล |
| **Access Grant (Delegation)** | การมอบสิทธิ์แบบมีขอบเขต/อายุ — ให้สิทธิ์ข้อมูล |
| **Consent Record** | ความยินยอมตามวัตถุประสงค์ — ไม่ได้ให้สิทธิ์ข้อมูล แต่เป็นเงื่อนไขที่ต้องผ่าน |
| **Legal Authority** | อำนาจตามกฎหมาย (ผู้ปกครอง/ผู้แทน) — ต้องผ่านการยืนยันก่อนใช้ |
| **Sensitive** | ข้อมูลที่ต้อง fail-closed และมี audit: ผลแล็บ, จิตเวช, การตั้งครรภ์, HIV, เอกสารระบุตัวตน |
| **Break-glass** | การเข้าถึงฉุกเฉินแบบจำกัดเวลาเพื่อข้อมูลช่วยชีวิตเท่านั้น |

---

## 6. การบังคับใช้

- Rule ทุกข้อในหัวข้อ 1–4 ต้องมี **test ที่ผูกกับ Rule ID** — ดู [`10-TESTING.md`](10-TESTING.md)
- CI ต้อง fail ถ้า PR ที่แตะ authorization ไม่มี test เพิ่ม/อัปเดต
- การติ๊ก checklist ครบ **ไม่ใช่หลักฐาน** ว่าปลอดภัย — ต้องมีผลทดสอบ + code review
