# 04 · CONSENT & DELEGATION — ความยินยอม การมอบสิทธิ์ และ Break-glass

> อ้างอิง ADR: [ADR-002](DECISIONS.md#adr-002--break-glass-implement-แต่ปิดเป็นค่าเริ่มต้น), [ADR-009](DECISIONS.md#adr-009--dependent-profile-ไม่ต้องเพิ่ม-role-ใหม่)
> กฎแม่: **F-12** Consent ≠ Delegation ≠ Legal Authority · **F-07** grant ไม่เกินผู้ให้ · **F-08** เพิกถอนต้องมีผลทุกเส้นทาง

---

## 1. สามกลไกที่ห้ามใช้แทนกัน

| กลไก | ตอบคำถาม | ให้สิทธิ์เข้าถึง? | ตาราง |
|---|---|---|---|
| **Consent** | เจ้าของข้อมูลยอมให้ประมวลผลเพื่อวัตถุประสงค์นี้หรือยัง | ❌ ไม่ให้ — เป็น *เงื่อนไข* ที่ต้องผ่าน | `consent_records` |
| **Delegation / Access Grant** | ผู้ใช้คนนี้ทำ action นี้กับข้อมูลนี้ได้ไหม | ✅ **ให้สิทธิ์จริง** | `access_grants` |
| **Legal Authority** | ผู้ใช้มีอำนาจตามกฎหมายแทนบุคคลนั้นไหม | ❌ ไม่ให้ — ต้องมี grant ต่อ | `delegations` |

**ตัวอย่างที่ต้องเข้าใจให้ตรงกัน**
- พี่ชายได้รับ grant ให้ scope `vitals` (permission `health_measurement.read`) → เห็นความดันได้ **แต่ถ้าไม่มี consent สำหรับ `ai_processing` ก็ยังส่งข้อมูลเข้า AI ไม่ได้**
- แม่ตั้งลูกสาวเป็นผู้แทนตามกฎหมาย → ลูกสาว**ยังต้องได้รับ grant** ถึงจะอ่านเวชระเบียนได้
- ผู้ใช้กด "ยอมรับข้อกำหนดการใช้งาน" → **ไม่เท่ากับ** ยินยอมให้ใช้ข้อมูลสุขภาพทุกวัตถุประสงค์

---

## 2. Consent

### 2.1 วัตถุประสงค์ (purpose enum)

| purpose | ใช้เมื่อ | บังคับ consent? |
|---|---|---|
| `care_coordination` | การดูแลตามปกติภายใน workspace | ไม่บังคับ (ฐานสัญญา/ประโยชน์สำคัญ) — แต่ต้องมี audit |
| `ai_processing` | ส่งข้อมูลเข้า AI gateway | **บังคับ** |
| `data_export` | ส่งออก PDF/ZIP | **บังคับ** ถ้าเกินขอบเขตการดูแล |
| `research` | (อนาคต) | **บังคับ** + opt-in แยก |
| `marketing` | (อนาคต) | **บังคับ** + แยกจากบริการ |
| `third_party_share` | แชร์ให้บุคคล/องค์กรภายนอก | **บังคับ** |

### 2.2 โครงสร้าง `consent_records`

| ฟิลด์ | ชนิด | กฎ |
|---|---|---|
| `id` | uuid (v7) | |
| `workspace_id`, `profile_id` | uuid | FK |
| `consent_type` | enum | `health_processing`, `ai_processing`, `data_export`, `third_party_share` |
| `purpose` | enum | §2.1 |
| `scope` | jsonb | รายการ scope ที่ยินยอม |
| `policy_version` | string | รุ่นเอกสาร/ข้อความที่ผู้ใช้ยอมรับ |
| `consenting_subject_id` | uuid → `persons` | ผู้ให้ (เจ้าของ หรือผู้แทนที่มีอำนาจ) |
| `legal_basis_type` | enum | `consent`, `vital_interest`, `contract`, `legal_obligation` |
| `granted_at`, `withdrawn_at` | timestamptz | |
| `evidence_reference` | string | OTP log / signature / UI version |
| `superseded_by_id` | uuid null | **ห้าม UPDATE ทับ** (DM-006) |

### 2.3 กฎ

| ID | กฎ |
|---|---|
| **CD-001** | consent ที่ `withdrawn_at` ไม่ null → ใช้ไม่ได้ทันที (ไม่รอรอบ) |
| **CD-002** | ถอน consent `ai_processing` → ล้าง cache + กันทุกคำขอใหม่ + งานคิวที่ยังไม่รันต้อง fail |
| **CD-003** | ถอน consent ไม่ย้อนลบข้อมูลที่ประมวลผลไปแล้ว แต่ต้องหยุดประมวลผลต่อและลบตาม retention |
| **CD-004** | การยอมรับ ToS/Privacy Policy **ไม่นับ** เป็น consent ด้านสุขภาพ |
| **CD-005** | consent ของผู้เยาว์/ผู้ไร้ความสามารถ ต้องมี `legal_authority` ที่ยังไม่หมดอายุ |

---

## 3. Access Grant (Delegation)

### 3.1 โครงสร้าง `access_grants`

| ฟิลด์ | หมายเหตุ |
|---|---|
| `id`, `workspace_id`, `profile_id` | ขอบเขต |
| `granted_by` | **ต้องเป็นเจ้าของหรือผู้มีอำนาจ** และต้องมีสิทธิ์นั้นเอง (F-07) |
| `grantee_user_id` | ผู้รับ (ต้องเป็นสมาชิก workspace) |
| `resource_scope` | jsonb array ของ scope token (`03-AUTHORIZATION §5`) |
| `allowed_actions` | jsonb: `read`, `create`, `update`, `export`, `share`, `delegate` |
| `purpose` | เหตุผลที่ให้ (audit) |
| `valid_from`, `expires_at` | `expires_at` **บังคับ** ยกเว้น owner self-grant |
| `status` | `pending`, `active`, `expired`, `revoked` |
| `revoked_at`, `revocation_reason`, `revoked_by` | |
| `constraints` | jsonb: กะเวลา, เฉพาะ task_id, ห้ามดาวน์โหลด ฯลฯ |

### 3.2 กฎ

| ID | กฎ | เหตุผล |
|---|---|---|
| **CD-010** | `allowed_actions ⊆ actions ของผู้ให้` | F-07 |
| **CD-011** | `resource_scope ⊆ scope ของผู้ให้` | F-07 |
| **CD-012** | `expires_at` บังคับสำหรับ caregiver (ไม่เกิน 1 ปีต่อครั้ง) | ลดสิทธิ์ค้าง |
| **CD-013** | grant ใหม่ต้องผ่านการยอมรับของผู้รับ (pending → active) | ไม่มีสิทธิ์ที่ไม่มีเจ้าตัวรู้ |
| **CD-014** | 1 grant = 1 profile (ห้าม bulk grant ข้าม profile) | ป้องกันการให้สิทธิ์เกิน |
| **CD-015** | grant ที่มี constraint `task_id` ใช้ได้เฉพาะงานนั้น | Delegated Helper (PDF2 §5) |
| **CD-016** | grant ประเภท `temporary` ผูกกับกะ: `valid_from`~`expires_at` ≤ 12 ชม. ต่อกะ | caregiver รายชั่วโมง |

### 3.3 ตัวอย่างชุดสิทธิ์ที่แนะนำ

| สถานการณ์ | scope | actions | อายุ |
|---|---|---|---|
| ลูกสาวช่วยดูแลหลัก | `profile.basic, vitals, medications, appointments, tasks, documents` | read, create, update | 1 ปี |
| ลูกชายช่วยเรื่องยา | `medications, tasks` | read, create | 1 ปี |
| ญาติช่วยขับรถพาไป รพ. | `appointments` + constraint `task_id` | read | 7 วัน |
| พยาบาลพาร์ทไทม์ | `vitals, medications, tasks` | read, create | กะละ 12 ชม. |
| ผู้ดูแลดูอย่างเดียว | `profile.basic, timeline` | read | 6 เดือน |
| จิตแพทย์ (ถ้ามี) | `mental_health` | read | 1 ปี + consent |

---

## 4. Break-glass (Emergency Access)

> **ADR-002:** ทำ แต่ **ปิดเป็นค่าเริ่มต้นต่อ workspace**

### 4.1 State machine

```
disabled ──(admin เปิด)──> enabled
enabled ──request(reason+reauth)──> requested
requested ──approve(guardian/dual)──> active (≤4h)
requested ──timeout 10m──> expired
active ──expire / revoke──> closed  (ทุกกรณีต้องมี audit)
```

### 4.2 กฎ

| ID | กฎ |
|---|---|
| **BG-001** | ใช้ได้เฉพาะเมื่อ `workspaces.break_glass_enabled = true` (ค่าเริ่มต้น false) |
| **BG-002** | ผู้ขอต้องเป็นสมาชิก workspace และมี `break_glass.request` |
| **BG-003** | ต้องระบุ **เหตุผล** (ข้อความอิสระ ≥20 ตัวอักษร) และ **ยืนยันตัวตนซ้ำ** (OTP หรือ biometric) |
| **BG-004** | ต้องมีผู้อนุมัติ: `legal_guardian` หรือ `profile_owner` (ถ้าไม่มี → dual-control: 2 สมาชิกที่ไม่ใช่ผู้ขอ) |
| **BG-005** | เปิดได้เฉพาะ **Critical Summary**: กรุ๊ปเลือด, แพ้ยา, โรคประจำตัว, ยาปัจจุบัน, ผู้ติดต่อฉุกเฉิน — **ไม่เปิด** ผลแล็บ, จิตเวช, เอกสาร |
| **BG-006** | อายุ ≤4 ชั่วโมง ไม่ต่ออายุอัตโนมัติ ต้องขอใหม่ |
| **BG-007** | แจ้งเตือนระดับสูงสุด (push + LINE + in-app) ให้เจ้าของข้อมูลและสมาชิกหลักทุกคน **ทันที** |
| **BG-008** | ทุกการอ่านระหว่าง active ลง `security_audit_logs` + `audit_events` |
| **BG-009** | แสดงแบนเนอร์ถาวรบนหน้าจอผู้ใช้ระหว่าง active: "กำลังใช้สิทธิ์ฉุกเฉิน · เหลือ X ชม." |
| **BG-010** | ห้ามใช้ break-glass กับ endpoint ที่ไม่ใช่ `break_glass.use` — endpoint อื่นยังต้องผ่าน authorization ปกติ |
| **BG-011** | เปิดให้ **ทุกแพ็กเกจ** — `workspaces.break_glass_enabled` เป็น setting ไม่ใช่ entitlement; layer Billing ห้ามตรวจค่านี้ (ADR-017) |
| **BG-012** | เปิดได้เมื่อครบ 4 เงื่อนไข: มีโปรไฟล์ที่ยืนยันแล้ว · มีผู้แทน L2/owner ≥1 · ผู้ติดต่อฉุกเฉินครบ · admin เปิดเอง + ยอมรับข้อตกลง |
| **BG-013** | Abuse guard: เปิดเกิน 2 ครั้ง/โปรไฟล์/30 วัน → flag + แจ้ง ops + DPO review |
| **BG-014** | ห้าม auto-approve — ต้องมีมนุษย์อนุมัติเสมอ; ไม่มีผู้อนุมัติ = ปฏิเสธ |
| **BG-015** | สรุปรายงานการใช้งานทั้งหมดให้ workspace admin **รายเดือน** (ใคร/เมื่อไร/เหตุผล/ระยะเวลา) |

### 4.3 Permission ที่ผูก

| permission | ใคร |
|---|---|
| `break_glass.request` | สมาชิก workspace ที่ต้องการ |
| `break_glass.approve` | `legal_guardian`, `profile_owner` (หรือ dual-control) |
| `break_glass.use` | ผู้อนุมัติแล้วเท่านั้น ระหว่าง active window |

---

## 5. Dependent / Proxy profile (P3)

```
persons (ไม่มี user_id)
  └─ health_profiles (managed_by_person_id → person ของผู้แทน)
       ├─ delegations        (legal authority: L1 | L2 | L3 + หลักฐาน)
       └─ access_grants      (สิทธิ์ข้อมูลที่ผู้แทนให้/ถือ)
```

### 5.1 สามระดับอำนาจ (ADR-015)

| ระดับ | ทำอะไรได้ | หลักฐานขั้นต่ำ | ผู้ตรวจ |
|---|---|---|---|
| **L1 · Kinship** | มอบหมายงาน/นัด/เตือน — **ไม่มีสิทธิ์ข้อมูลสุขภาพ** | คำประกาศความสัมพันธ์ + OTP | ระบบ |
| **L2 · Documented** | อ่านเวชระเบียนตาม scope, ให้/ถอน consent แทน, สร้าง grant | ผู้เยาว์: สูติบัตร/ทะเบียนบ้าน + บัตร ปชช.<br>ผู้ใหญ่: คำสั่งศาล หรือหนังสือมอบอำนาจที่รับรอง | ops ≤2 วันทำการ |
| **L3 · High-risk** | อนุมัติ break-glass, มอบสิทธิ์นอกครัวเรือน, ขอลำเนา/ลบข้อมูล, เพิกถอน grant ผู้อื่น | L2 + เอกสารฉบับเต็ม + dual control | ops + reviewer 2 |

### 5.2 กฎ

| ID | กฎ |
|---|---|
| **CD-020** | Dependent profile **ต้องมี** `managed_by_person_id` ชี้ไปยัง person ที่มีผู้ใช้จริง |
| **CD-021** | L1 **ไม่ให้สิทธิ์ข้อมูลสุขภาพใดๆ** — ใช้ได้เฉพาะงานประสาน (F-04) |
| **CD-022** | L2 ขึ้นไปต้องมี `delegations.status = verified` + `verified_by` + `verified_at` ก่อนจึงจะ grant/consent แทนได้ |
| **CD-023** | หลักฐานเก็บเป็น `documents.category='legal_authority'` — private, ห้าม public URL, ห้ามส่งเข้า AI gateway |
| **CD-024** | `delegations` มีอายุ **12 เดือน** → ต้องยืนยันซ้ำ (แจ้งล่วงหน้า 30 วัน) ไม่งั้นลดระดับเป็น L1 อัตโนมัติ |
| **CD-025** | ผู้เยาว์บรรลุนิติภาวะ → ต้องยืนยันอำนาจใหม่ + แจ้งเจ้าของข้อมูล (ห้ามต่ออายุอัตโนมัติ) |
| **CD-026** | เจ้าของข้อมูลคัดค้าน/system ตรวจพบว่าความสามารถกลับคืน → **ระงับ L2/L3 ทันที** และเข้าสู่ review |
| **CD-027** | เจ้าของข้อมูลสร้างบัญชีของตนเอง → L2/L3 **ไม่ถูกเพิกถอนอัตโนมัติ** แต่ต้องมี banner ให้เจ้าของยืนยัน/เพิกถอนภายใน 30 วัน |
| **CD-028** | เมื่อผู้แทนถูกเพิกถอน → grant ที่ผู้แทนให้ไว้ **ไม่ถูกเพิกถอนอัตโนมัติ** แต่ flag ว่าต้องยืนยันกับเจ้าของใหม่ภายใน 30 วัน |
| **CD-029** | ทุกการยกระดับ/ลดระดับ/เพิกถอน → บันทึก `audit_events` + `security_audit_logs` พร้อมเหตุผล |

> **CD-026/027 คือจุดที่ต้องทดสอบหนักที่สุด:** ระบบต้องไม่กลายเป็นเครื่องมือให้ผู้แทนยึดการควบคุมข้อมูลของเจ้าของ — ต้องมีทางออกที่เจ้าของชนะเสมอเมื่อความสามารถกลับคืน

### 5.3 สิ่งที่ระบบ **ไม่** ทำแทนการตัดสินทางกฎหมาย

- ระบบไม่ตัดสินว่าผู้ใดเป็นผู้แทนโดยชอบธรรม — ตัดสินจาก **เอกสารที่ผ่านการตรวจ** เท่านั้น
- ระบบไม่รับรองว่าผู้แทนทำเพื่อประโยชน์ของผู้ถูกแทน
- กรณีพิพาทในครอบครัว → ระบบระงับสิทธิ์และให้ติดต่อ ops/ที่ปรึกษากฎหมาย ไม่ใช่ให้ AI หรือ rule engine ตัดสิน

---

## 6. การเพิกถอน — ตารางผลกระทบ

การเพิกถอนต้องทำงานครบทุกช่อง (F-08):

| เส้นทาง | ต้องทำ | test |
|---|---|---|
| API ปกติ | ตรวจ `access_grants.status` + `consent_records.withdrawn_at` ทุกครั้ง | `GRANT-T03` |
| Cache | `DEL authz:*:{user}:{profile}:*` | `GRANT-T04` |
| Queue / job | job ตรวจสิทธิ์ใหม่ตอน execute ถ้าไม่ผ่าน → `failed(revoked)` | `SEC-T05` |
| Export ที่สร้างแล้ว | mark invalid + ลบไฟล์ตาม retention | `EXPORT-T03` |
| Signed URL | เพิกถอน grant → URL ถูกปฏิเสธแม้ยังไม่หมดอายุ (ผูก grant_id) | `SEC-T06` |
| Access token | **ไม่** revoke token ทั้งหมด (ผู้ใช้ยังใช้ส่วนอื่นได้) แต่ policy ต้องปฏิเสธ | `GRANT-T05` |
| AI context ที่ค้าง | ถอน `ai_processing` → ล้าง context cache | `AI-T04` |

**ข้อจำกัดที่ยอมรับ:** ไฟล์ที่ผู้รับดาวน์โหลดไปแล้ว **เรียกคืนไม่ได้** — จึงต้องจำกัดการดาวน์โหลดตั้งแต่ต้น (`documents.download_allowed`) และแสดงคำเตือนตอนแชร์
