# 10 · TESTING — กลยุทธ์ทดสอบ, matrix, release gates

> **Normative:** test ID ของ authorization มาจาก [`docs/baseline-v1.0.0/AUTHORIZATION_TEST_PLAN.md`](../docs/baseline-v1.0.0/AUTHORIZATION_TEST_PLAN.md) (`AUTH-`, `ISO-`, `GRANT-`, `CONSENT-`, `AI-`, `EXPORT-`, `AUDIT-`)
> ไฟล์นี้เพิ่ม test ID ของโดเมนอื่น (`CT-`, `CRM-`, `DM-`, `AP-`, `SEC-`, `AIG-`, `MOB-`, `RC-`, `ES-`) โดย**ไม่ออก ID ทับ baseline**

---

## 1. หลักการ

1. **Test ทุก boundary** — workspace, profile, data type, purpose, เวลา
2. **Negative test มีน้ำหนักเท่า positive** — ถ้าไม่มี negative แปลว่ายังไม่ทดสอบสิทธิ์
3. รันด้วย **PostgreSQL จริง** (พฤติกรรม transaction/constraint ต่างจาก SQLite)
4. Test ต้อง **อ้าง Rule ID** ในชื่อหรือ annotation เพื่อตรวจ traceability
5. CI ต้อง fail ถ้าแตะ authorization แล้วไม่มี test เปลี่ยน

---

## 2. Test pyramid

| ชั้น | เครื่องมือ | จำนวน (เป้าหมาย) | ความเร็ว |
|---|---|---|---|
| Unit (domain rules) | PHPUnit | หนาแน่น | <5s |
| Feature (API + Policy + DB) | PHPUnit + PostgreSQL | หนาแน่น | <2 นาที |
| Security (IDOR/cross-tenant/mass assignment) | PHPUnit | ทุก endpoint | <1 นาที |
| Integration (queue, outbox, AI gateway stub, storage) | PHPUnit + containers | ตาม flow | <5 นาที |
| Contract | OpenAPI + Flutter client test | ทุก endpoint | <1 นาที |
| E2E mobile | Flutter integration_test | happy path หลัก | ~10 นาที |
| Load | k6 | dashboard, list, complete | ก่อน release |

---

## 3. Requirement → Test matrix

### 3.1 Authorization (อ้าง baseline)

| Requirement | Test ID (baseline) |
|---|---|
| workspace isolation | `ISO-*` |
| grant/consent/revocation | `GRANT-*`, `CONSENT-*` |
| role boundaries | `AUTH-*` |
| AI context | `AI-*` |
| export | `EXPORT-*` |
| audit | `AUDIT-*` |
| notification / self-audit (v1.1.0) | `AT-NOTIF-001…004`, `AT-AUDIT-004…005` |

### 3.2 โดเมนใหม่ (ไฟล์นี้)

| Rule | Test ID | ชนิด | สิ่งที่ต้องพิสูจน์ |
|---|---|---|---|
| CT-001…010 | `CT-T01`…`CT-T10` | Feature | transition ผิดถูกปฏิเสธ, 1 primary assignee, verify แยกคน |
| RC-001…006 | `RC-T01`…`RC-T06` | Feature + Unit | idempotent occurrence, DST, แก้ตารางไม่กระทบอดีต |
| ES-001…006 | `ES-T01`…`ES-T06` | Integration | ลำดับ escalation, ข้อความไม่มี PHI |
| AS-001…005 | `AS-T01`…`AS-T05` | Feature | pool → 1 owner, need-to-know view |
| DB-001…005 | `DB-T01`…`DB-T05` | Feature | Doctor Brief กรองตามสิทธิ์, QR หมดอายุ |
| DM-001…010 | `DM-T01`…`DM-T10` | Feature | workspace_id, version, append-only |
| AP-001…010 | `AP-T01`…`AP-T10` | Feature | 404 policy, idempotency, If-Match |
| SEC | `SEC-T01`…`SEC-T10` | Security | IDOR, cross-tenant, signed URL, mass assignment |
| AIG | `AIG-T01`…`AIG-T10` | Integration (mock provider) | scrubbing, scope, injection, refusal |
| TEN | `TEN-T01`…`TEN-T04` | Security/Integration | tenant_id ครบ, cross-tenant, missing context, composite FK (ADR-013) |
| SEN | `SEN-T01`…`SEN-T03` | Feature | scope ที่ปิดใน MVP ต้องปฏิเสธ, lab เก็บเป็น document ได้ (ADR-014) |
| LEG | `LEG-T01`…`LEG-T05` | Feature/Integration | ระดับอำนาจผู้แทน L1/L2/L3, หมดอายุ, คัดค้าน, dual control (ADR-015) |
| RET | `RET-T01`…`RET-T05` | Integration | retention รายประเภท, legal hold, tenant override, inactivity, dry-run (ADR-016) |
| BG | `BG-T01`…`BG-T05` | Feature | break-glass ปิด default, เงื่อนไขเปิด, critical-only, หมดอายุ, abuse guard (ADR-017) |
| CRM | `CRM-T01`…`CRM-T06` | Integration/Architecture | event idempotency, single-writer, projection rebuild, need-to-know ([CRM_DOMAIN_ARCHITECTURE §13](CRM_DOMAIN_ARCHITECTURE.md#13-test-responsibilities)) |
| APT | `APT-T01`…`APT-T03` | Feature | appointment state, follow-up task ครั้งเดียว, นัด ≠ เวชระเบียน |
| MOB | `MOB-T01`…`MOB-T06` | Widget/E2E | elder mode, permission-aware rendering |

---

## 4. Test cases ที่ต้องมี (ตัวอย่างบังคับ)

### 4.1 Care Task

| ID | Given | When | Expected |
|---|---|---|---|
| `CT-T01` | งาน tier=routine | POST `/accept` | `409 domain_rule` |
| `CT-T03` | ผู้ใช้มี `care_task.complete` แต่ไม่มี `.verify` | POST `/verify` | `403` |
| `CT-T04` | ผู้ใช้ไม่ใช่ assignee และไม่มี grant | POST `/complete` | `403` (ไม่ใช่ 404 ถ้าอยู่ใน scope) |
| `CT-T05` | งาน tier=clinical ไม่มี evidence | POST `/complete` | `422` |
| `CT-T07` | ลูกสาวมอบงานให้ญาติ แล้ว reassign | GET `/events` | เห็นประวัติผู้รับผิดชอบเดิมครบ |
| `CT-T08` | งาน `scheduled` เลย `due_at` | เรียก dashboard | ปรากฏใน `alerts` และไม่เปลี่ยน `status` |

### 4.2 Recurring

| ID | Given | When | Expected |
|---|---|---|---|
| `RC-T01` | template รายวัน 08:00 | รัน scheduler 2 ครั้ง | สร้าง occurrence เดียว (unique) |
| `RC-T02` | มี occurrence 7 วัน | แก้ template "ครั้งต่อๆ ไป" | occurrence เก่าไม่เปลี่ยน, มี template ใหม่ |
| `RC-T03` | timezone Asia/Bangkok, DST เปลี่ยน (จำลอง tz อื่น) | สร้าง occurrence | เวลาท้องถิ่นคงที่ |

### 4.3 Escalation

| ID | Given | When | Expected |
|---|---|---|---|
| `ES-T01` | งาน routine เลย 30 นาที | ประมวลผล job | แจ้ง assignee เท่านั้น (ไม่มี L2) |
| `ES-T02` | งาน clinical เลย 60 นาที | ประมวลผล job | แจ้ง coordinator + `escalation_level=2` |
| `ES-T03` | ข้อความ escalation | ตรวจ payload | **ไม่มี** ชื่อโรค/ค่าแล็บ/ชื่อยา |

### 4.4 Dashboard

| ID | Given | When | Expected |
|---|---|---|---|
| `AP-T05` | ผู้ใช้มีสิทธิ์ `vitals` แต่ไม่ `lab_results` | GET `/dashboard` | ไม่มี field ของ lab แม้แต่ `null` |
| `AP-T06` | เพิกถอน grant ระหว่าง cache 30s | GET `/dashboard` | ข้อมูลที่ถูกเพิกถอนหายทันที (ภายใน TTL สูงสุด 30s) |

### 4.5 Security

| ID | Given | When | Expected |
|---|---|---|---|
| `SEC-T01` | token A | GET profile ของ workspace B | `404` + ไม่มีข้อมูลใน body |
| `SEC-T02` | body มี `workspace_id` ไม่ตรง path | POST | `422` |
| `SEC-T05` | job แจ้งเตือนค้างในคิว | เพิกถอน grant แล้ว job รัน | job `failed(revoked)` + ไม่ส่ง |
| `SEC-T06` | signed URL ที่ยังไม่หมดอายุ | เพิกถอน grant แล้วเปิด URL | `403` |
| `SEC-T08` | อัปโหลดไฟล์ชื่อ `.php`/`.svg` | POST documents | ปฏิเสธ หรือบังคับดาวน์โหลดเป็น attachment |

### 4.6 Tenancy (ADR-013)

| ID | Given | When | Expected |
|---|---|---|---|
| `TEN-T01` | สร้างระเบียนใดๆ ในตาราง tenant-scoped | ตรวจแถวใน DB | `tenant_id NOT NULL` และตรงกับ tenant ของ workspace |
| `TEN-T02` | request ที่ไม่มี `SET LOCAL app.tenant_id` | query ตาราง tenant-scoped | ได้ 0 แถว / ถูกปฏิเสธ (fail closed) — ไม่ throw ที่ทำให้ข้อมูลรั่ว |
| `TEN-T03` | client ส่ง `tenant_id` ของ tenant อื่นมาใน body | POST/PATCH | `422` หรือถูกเมิน — ใช้ค่าจาก server context เท่านั้น |
| `TEN-T04` | พยายาม insert ด้วย `(workspace_id, tenant_id)` ที่ไม่คู่กัน | insert | FK composite ปฏิเสธ (`23503`) |

### 4.7 AI

| ID | Given | When | Expected |
|---|---|---|---|
| `AIG-T01` | scope = `vitals` | ขอสรุป | payload มีเฉพาะ vitals, ไม่มี lab/mental |
| `AIG-T02` | consent `ai_processing` ถูกถอน | ขอสรุป | `403 no_consent`, ไม่เรียก provider |
| `AIG-T03` | บันทึกอิสระมีชื่อ+เบอร์ | ขอสรุป | ชื่อ/เบอร์ถูกตัดออกจาก payload |
| `AIG-T04` | provider ตอบวินิจฉัยโรค | ตรวจ output | ถูกปฏิเสธ/แทนข้อความกลาง |

### 4.8 Sensitive scopes ที่ปิดใน MVP (ADR-014)

| ID | Given | When | Expected |
|---|---|---|---|
| `SEN-T01` | scope `mental_health` / `lab_results` ถูกปิดใน MVP | เรียก endpoint หรืออ่าน field ที่อ้าง scope เหล่านี้ | `403` (หรือ `404` ถ้าไม่มี endpoint นั้นเลย) — ไม่คืนข้อมูลใดๆ |
| `SEN-T02` | ผู้ใช้มี scope `documents` | อัปโหลดใบแล็บเป็น `documents.category='lab_report'` | สำเร็จ; timeline แสดงได้; **ผู้ที่ไม่มี scope `documents` เห็นไม่ได้** |
| `SEN-T03` | ผู้ใช้พยายามสร้าง `access_grant` ที่ระบุ `mental_health` หรือ `lab_results` | POST grants | `422` — scope ที่ปิดไม่สามารถมอบได้ |

### 4.9 Legal representative (ADR-015)

| ID | Given | When | Expected |
|---|---|---|---|
| `LEG-T01` | ผู้ใช้มีแค่ L1 (kinship) | อ่าน `health_measurement` ของ dependent | `403` — L1 ไม่ให้สิทธิ์ข้อมูลสุขภาพ |
| `LEG-T02` | ผู้แทน L2 ที่ verified แล้ว มี grant ตรง scope | อ่านเวชระเบียน | `200` ตาม scope |
| `LEG-T03` | L2 ที่ `verified_at` เกิน 12 เดือน | อ่านเวชระเบียน | `403` + สถานะลดเป็น L1 อัตโนมัติ (CD-024) |
| `LEG-T04` | เจ้าของข้อมูลสร้างบัญชีเอง / คัดค้าน | ตรวจสิทธิ์ผู้แทน L2 | ระงับหรือ banner 30 วันตาม CD-026/027 — **เจ้าของชนะเสมอ** |
| `LEG-T05` | ผู้แทน L2 (ไม่ใช่ L3) | `POST /break-glass/requests/{r}/approve` | `403` — ต้อง L3 + dual control |

### 4.10 Retention (ADR-016)

| ID | Given | When | Expected |
|---|---|---|---|
| `RET-T01` | measurement ที่ `recorded_at` เกิน 60 เดือน และไม่มี hold | รัน purge job | ถูกลบ + มี audit entry |
| `RET-T02` | ระเบียนเดียวกันแต่มี `data_holds` ที่ยังไม่หมดอายุ | รัน purge job | **ไม่ถูกลบ** |
| `RET-T03` | tenant ตั้ง `retention_override` = 120 เดือน | รัน purge job กับข้อมูล 70 เดือน | ยังอยู่ (override มีผล) |
| `RET-T04` | workspace ไม่มี login 24 เดือน | รัน inactivity job | แจ้งเตือน 30/14/7 → มีลิงก์ export ใช้ได้ → purge ตามกำหนด |
| `RET-T05` | เปิด `RETENTION_DRY_RUN=true` | รัน purge job | **ไม่มีข้อมูลถูกลบ** แต่มี log/metric ว่าจะลบอะไร |

### 4.11 Break-glass (ADR-017)

| ID | Given | When | Expected |
|---|---|---|---|
| `BG-T01` | workspace ปิด break-glass (ค่าเริ่มต้น) | `POST /break-glass/requests` | `403` — ต้องเปิดก่อน |
| `BG-T02` | เปิดแล้วแต่ยังไม่มีผู้แทน L2 / ยังไม่มีผู้ติดต่อฉุกเฉิน | เปิดใช้งาน | ปฏิเสธ + แจ้งเงื่อนไขที่ขาด (BG-012) |
| `BG-T03` | อยู่ในช่วง active | ขออ่าน `lab_results` หรือเอกสาร | `403` — active เปิดเฉพาะ Critical Summary (BG-005) |
| `BG-T04` | อนุมัติแล้ว 4 ชม. ผ่านไป | ขออ่านต่อ | `403` ทันที ไม่ต่ออายุอัตโนมัติ |
| `BG-T05` | เปิดครั้งที่ 3 ใน 30 วันของโปรไฟล์เดียวกัน | เปิดใช้งาน | สำเร็จแต่ **flag + แจ้ง ops/DPO** (BG-013) |

### 4.12 Appointment (APT)

| ID | Given | When | Expected |
|---|---|---|---|
| `APT-T01` | นัดยืนยันแล้ว | เปลี่ยนเป็น `attended` (และมี event ซ้ำ) | follow-up task ถูกสร้าง **ครั้งเดียว** (APT-004) |
| `APT-T02` | นัดถูก `cancelled` | พยายามเปลี่ยนเป็น `attended` | `409 domain_rule` (APT-002) |
| `APT-T03` | นัด `attended` | ตรวจข้อมูลสุขภาพที่ถูกสร้าง | **ไม่มี** measurement ถูกสร้างจากตัวนัดเอง (APT-001) |

---

## 5. Release gates

| Gate | เงื่อนไขผ่าน | ผ่านเมื่อ |
|---|---|---|
| **G0 · Foundation** | Unit + Feature ของ authorization ผ่าน 100%; `TEN-*` ผ่าน; checklist §7 baseline ครบ | ก่อนเริ่มฟีเจอร์สุขภาพ |
| **G1 · Core MVP** | `ISO-*`, `GRANT-*`, `CT-*`, `AP-*`, `SEN-*`, `RET-*` ผ่าน; ไม่มี high severity | ก่อน internal pilot |
| **G2 · Product validation** | ทดสอบกับ 10–15 ครอบครัว + ตัดสินราคา (ADR-011) | ก่อนขาย |
| **G3 · Coordination** | `RC-*`, `ES-*`, `AS-*`, `DB-*` ผ่าน | ก่อน Phase 2 release |
| **G4 · AI** | `AIG-*` + evaluation §7 ใน `08` ผ่านตามเกณฑ์ | ก่อนเปิด AI |
| **G5 · Scale** | load test ผ่าน (p95 < 400ms dashboard), backup restore test | ก่อน production |
| **G6 · Compliance** | security review + legal review + DPA ครบ | ก่อนเปิดขายจริง |

---

## 6. CI pipeline

```
1. lint + static analysis (PHPStan level 8, dart analyze)
2. unit tests
3. feature/security tests (PostgreSQL service container)
4. contract tests (OpenAPI diff → fail ถ้า breaking)
5. PHI leak scan (grep patterns + custom rule)
6. dependency audit
7. build artifacts
8. (nightly) load test + E2E
```

**Branch protection:** ห้าม merge ถ้า authorization test coverage ลดลง หรือมี test ที่ `skip`

---

## 7. ข้อมูลทดสอบ

- ใช้ **factory + fake ชื่อไทย** (ไม่ใช้ข้อมูลจริง)
- ต้องมี fixture: 3 personas, 1 dependent profile, grant ที่ active/expired/revoked, งานครบ 3 tier, measurement 30 วัน, document 1 ไฟล์, break-glass ที่ปิดอยู่
- ห้าม commit ข้อมูลผู้ใช้จริงแม้แต่ใน fixture
- Test ที่ใช้เวลา (DST, escalation) ต้องใช้ `Carbon::setTestNow` หรือ clock injection — **ห้าม sleep**

---

## 8. Coverage ที่ต้องการ (ขั้นต่ำ)

| ส่วน | เป้าหมาย |
|---|---|
| Authorization service | 100% branch |
| Domain services (care task, grants) | ≥90% |
| API controllers | ≥80% |
| AI gateway (mock) | 100% ของ branch การตัดสินใจ |
| Flutter (widget ที่มี logic) | ≥70% |

> Coverage เป็น **ตัวชี้วัด ไม่ใช่เป้าหมาย** — test ที่ไม่มี assertion ดีๆ ไม่นับ
