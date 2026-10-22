# 09 · SECURITY & PRIVACY — threat model และการปฏิบัติตาม PDPA

> อ้างอิง: `00-CONSTITUTION.md` (F-01…F-18), baseline `AUTHORIZATION_TEST_PLAN.md`, `DATA_ACCESS_MATRIX.md`

---

## 1. Threat model

แต่ละภัยคุกคามต้องมี control + test ID ที่พิสูจน์ได้

| ID | ภัยคุกคาม | ตัวอย่างจริง | Control | test |
|---|---|---|---|---|
| **T-01** | **IDOR** ข้ามprofile | เปลี่ยน `profile_id` ใน URL แล้วอ่านข้อมูลคนอื่น | workspace-scoped route + policy ตรวจ profile + UUIDv7 (ไม่ใช่ mitigation หลัก) | `ISO-*`, `AT-002` |
| **T-02** | **Cross-tenant** | token ของ workspace A เรียกข้อมูลของ tenant B | RLS สองชั้น (tenant + workspace) + middleware resolve จาก server context | `ISO-*`, `TEN-*` |
| **T-03** | **Privilege escalation ผ่าน role** | `workspace_admin` อ่านเวชระเบียน | F-03 + matrix test | `AUTH-*` |
| **T-04** | **Task-scope leak** | ผู้รับงานเห็นผลแล็บเพราะ "ต้องทำงานนี้" | need-to-know view (AS-004) | `CT-T0x` |
| **T-05** | **Stale grant/session** | เพิกถอนแล้วแต่ session ยังใช้ได้ | cache DEL + ตรวจ status ทุกครั้ง | `GRANT-*` |
| **T-06** | **Offline/queue replay** | job ที่สร้างก่อนเพิกถอนยังทำงาน | ตรวจสิทธิ์ ณ เวลา execute | `SEC-T05` |
| **T-07** | **Break-glass abuse** | ใช้สิทธิ์ฉุกเฉินเพื่อส่องข้อมูล | ADR-002/BG-001…010 + แจ้งเตือน + time-box | `SEC-T0x` |
| **T-08** | **Export leakage** | PDF มีข้อมูลที่ผู้ส่งออกไม่มีสิทธิ์ | filter ตามสิทธิ์ผู้ขอ + audit | `EXPORT-*` |
| **T-09** | **Signed URL sharing** | ส่งลิงก์ไฟล์ให้คนนอก | อายุ ≤15 นาที + ผูก grant + revocable | `SEC-T06` |
| **T-10** | **Notification/screenshot leak** | push แสดงชื่อโรคบน lock screen | scrub payload + copy guideline | `SEC-T03` |
| **T-11** | **Prompt injection** | ข้อความในบันทึกสั่งให้ AI เปิดเผยข้อมูล | untrusted block + output filter | `AIG-*` |
| **T-12** | **Insider / support access** | staff อ่านข้อมูลผู้ใช้ | F-10 + approval + audit + ไม่มี standing access | `AUDIT-*` |
| **T-13** | **Mass assignment** | ส่ง `workspace_id`/`role` ใน body | FormRequest allowlist + AP-002 | `SEC-*` |
| **T-14** | **Backup theft** | ขโมย backup | เข้ารหัส + key แยก + restore test | ops |
| **T-15** | **Malicious document upload** | อัปโหลดไฟล์อันตราย/สคริปต์ | MIME sniff + ขนาด + virus scan + serve แบบ attachment | `SEC-*` |
| **T-16** | **AI memorization / vendor training** | provider เก็บข้อมูลไป train | DPA + no-training config + pseudonymization | ops |

---

## 2. การป้องกันเฉพาะจุด

### 2.1 IDOR / enumeration
- UUIDv7 **ไม่ใช่** มาตรการความปลอดภัย (ระบุใน `SUMMARY.md`) — ต้องตรวจสิทธิ์ทุกครั้ง
- ทุก route ต้องมี `{workspace}` และ `{profile}` ใน path ที่ middleware ตรวจได้
- List/Search/Pagination/Export ต้องถูก scope — รวมถึง `total` count (ห้ามคืนจำนวนของทั้งระบบ)

### 2.2 File & document
```
อัปโหลด → virus scan → strip metadata (EXIF/GPS) → เก็บ private bucket
ดาวน์โหลด → ตรวจสิทธิ์ + สร้าง signed URL (≤15m, ผูก document_id + grant_id)
แชร์ → ต้องมี medical_document.share + บันทึก audit + แจ้งเจ้าของ
```
- ชื่อไฟล์ที่เก็บ = uuid + extension (ห้ามใช้ชื่อจริง)
- `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff`

### 2.3 Authentication
| ข้อ | กำหนด |
|---|---|
| PIN | 6 หลัก, เก็บเป็น hash (Argon2id) ใน secure storage ฝั่งเครื่อง + server-side hash; ล็อกหลังผิด 5 ครั้ง |
| OTP | อายุ ≤5 นาที, ใช้ครั้งเดียว, rate limit ต่อเบอร์ |
| Biometric | ใช้เป็น convenience เท่านั้น — fallback เป็น PIN ได้ |
| Token | Sanctum, อายุ 30 วัน, rotate เมื่อเปลี่ยนรหัส; revoke ได้รายอุปกรณ์ |
| Session | ผูก device_id; แจ้งเตือนเมื่อ login จากอุปกรณ์ใหม่ |

### 2.4 Logging
- ห้าม log: ชื่อ, เบอร์, ค่าแล็บ, ข้อความอิสระ, token, OTP, PIN
- log ได้: `user_id`, `workspace_id`, `permission`, `result`, `resource_type`, `resource_id` (uuid), `trace_id`
- log ต้องมี retention + access control

### 2.5 Secret management
- `.env` **ห้าม commit**; ใช้ secret manager
- key ของ AI provider เก็บใน DB แบบเข้ารหัส (per SUMMARY.md) + rotate ได้
- ห้าม hardcode ใน mobile/web bundle

---

## 3. PDPA mapping

> **ข้อจำกัด:** นี่คือ mapping เชิงวิศวกรรม **ไม่ใช่คำรับรองทางกฎหมาย** — ต้องผ่านผู้เชี่ยวชาญก่อนใช้งานจริง

| หลักการ PDPA | สิ่งที่ระบบทำ | หลักฐาน |
|---|---|---|
| **ฐานการประมวลผล** | เก็บ `legal_basis_type` ใน `consent_records`; แยก consent ออกจากสัญญา | `consent_records` |
| **ข้อมูลอ่อนไหว (มาตรา 26)** | health data ต้องมี consent ชัด + encrypt + fail closed + audit | §2, `06 §6` |
| **วัตถุประสงค์จำกัด** | `purpose` enum + ตรวจทุกครั้งที่ใช้ AI/export | `04 §2`, `08 §2` |
| **ลดข้อมูลเท่าที่จำเป็น** | scope + field-level minimization + pseudonymization | `03 §5`, `08 §3` |
| **เก็บรักษาตามกำหนด** | retention รายประเภท + inactivity + legal hold + purge job (dry-run ก่อน) | `06 §8`, ADR-016 |
| **สิทธิ์ของเจ้าของข้อมูล** | เข้าถึง / แก้ไข / ลบ / ถอน consent / ขอลำเนา → workflow + SLA 30 วัน | `06 §8` (DM-R03) |
| **แจ้งเหตุละเมิด** | incident runbook + แจ้งภายใน 72 ชม. | §4 |
| **ผู้ประมวลผลช่วง** | DPA กับ AI provider / LINE / hosting; ห้าม train | `AIG-010` |
| **การโอนข้อมูลข้ามประเทศ** | ระบุ region ของ provider + DPA + SCC ถ้าจำเป็น | ops |
| **Audit / accountability** | `security_audit_logs` hash chain + review รายไตรมาส | `06 §5` |

---

## 4. Incident response (ย่อ)

| ขั้น | การกระทำ | ผู้รับผิดชอบ |
|---|---|---|
| 1 ตรวจพบ | alert / รายงานผู้ใช้ / audit anomaly | ops |
| 2 จำกัดวง | revoke token/grant ที่เกี่ยวข้อง, ปิด feature flag, isolate | on-call |
| 3 ประเมิน | ขอบเขตข้อมูล, จำนวนเจ้าของข้อมูล, ชนิดข้อมูล | DPO + lead |
| 4 แจ้ง | เจ้าของข้อมูลที่กระทบ + หน่วยงานตามกฎหมาย (≤72 ชม.) | DPO |
| 5 แก้ | patch + test + postmortem (blameless) | ทีม |
| 6 ป้องกันซ้ำ | เพิ่ม test ใน [`10-TESTING.md`](10-TESTING.md) | ทีม |

**ต้องมี:** ทะเบียนเหตุ, ช่องทางติดต่อ DPO, เทมเพลตจดหมายแจ้ง

### 4.2 Break-glass review runbook (ADR-017)

| เมื่อไร | ทำอะไร | SLA |
|---|---|---|
| ทุกครั้งที่เปิดใช้งาน | เข้า queue ของ ops + DPO พร้อม context (ผู้ขอ/เหตุผล/ระยะเวลา) | ทันที |
| หลังปิดสิทธิ์ (หรือครบ 4 ชม.) | ตรวจ: เหตุผลสมเหตุสมผล · ผู้อนุมัติมีสิทธิ์จริง · ข้อมูลที่ถูกอ่านอยู่ใน Critical Summary · มี hold ไหม | ≤24 ชม. |
| เปิดเกิน 2 ครั้ง/โปรไฟล์/30 วัน | เปิด case ตรวจ abuse + แจ้ง workspace admin | ≤48 ชม. |
| รายเดือน | สรุปรายงานให้ admin (BG-015) + ตรวจ trend ผิดปกติ | วันที่ 1 ของเดือน |
| พบการใช้ผิด | ระงับสิทธิ์ผู้ใช้ + แจ้ง DPO + เปิด incident ตาม §4 | ทันที |

---

## 5. Secure defaults (ตรวจทุก release)

- [ ] ทุกตาราง tenant-scoped มี RLS
- [ ] ไม่มี endpoint ที่ไม่มี permission annotation
- [ ] ไม่มี public bucket / public URL
- [ ] `.env.example` ไม่มี secret จริง
- [ ] rate limit เปิดทุก endpoint ที่มีต้นทุน (AI, export, invite, OTP)
- [ ] `security_audit_logs` เขียนครบทุก action ใน F-11
- [ ] break-glass ปิดเป็นค่าเริ่มต้นทุก workspace และไม่ถูก gate ด้วย entitlement
- [ ] ไม่มี PHI ใน log/notification/error (สแกนอัตโนมัติใน CI)
- [ ] dependency audit ไม่มีช่องโหว่ระดับ high
- [ ] backup restore test ผ่านในไตรมาสล่าสุด

---

## 6. สิ่งที่ระบบนี้ **ไม่** รับประกัน

- ไม่รับประกันความถูกต้องทางการแพทย์ของคำแนะนำ AI
- ไม่ทดแทนการวินิจฉัยของแพทย์
- ไม่รับประกันว่าไฟล์ที่ผู้ใช้ดาวน์โหลดไปแล้วจะถูกเรียกคืนได้ (จำกัดได้แค่การเข้าถึงในระบบ)
- ไม่ใช่ระบบรับรองตัวตนระดับรัฐ (eKYC เต็มรูปแบบ) ใน Phase 1–2
