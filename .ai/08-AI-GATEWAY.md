# 08 · AI GATEWAY — context pipeline, guardrails, audit

> อ้างอิง: `SUMMARY.md §7` (AI ต้องไม่ต่อ DB ตรง), `PDF1 §4` (5-stage gateway), ADR-003 (LINE ไม่ใช่ช่องทางข้อมูล)
> กฎแม่: **F-06** อ่าน ≠ ใช้กับ AI · **F-09** fail closed · **N-03** ห้ามต่อ DB ตรง

---

## 1. หลักการ

1. AI **ไม่มี** credential ของ DB และ **ไม่มี** network path ไปยังตารางสุขภาพ
2. ทุกคำขอเดินผ่าน pipeline 5 ขั้น — ข้ามขั้นใดไม่ได้
3. ข้อมูลที่ส่งออกนอกเซิร์ฟเวอร์ต้องผ่าน pseudonymization
4. ผลลัพธ์ต้องอ้างอิง record id ที่ใช้ (traceability) และมี disclaimer
5. ถ้าตรวจสิทธิ์/consent ไม่ได้ → ไม่เรียกโมเดล และไม่ดึงข้อมูล (F-09)

---

## 2. Pipeline

```
User Request
  → [1] Policy & Consent Verifier
  → [2] Scoped Data Fetcher
  → [3] PII Scrubber & Pseudonymizer
  → [4] Clinical Guardrail Prompting
  → [5] Output Validation & Audit Log
  → User
```

### ขั้นที่ 1 — Policy & Consent Verifier (ตรวจ 2 ชั้น)

```
allowed = authz.can(actor, 'ai.health_context.use', profile)
       && consent.satisfied(profile, purpose='ai_processing')
       && entitlement.has(workspace, 'ai_enabled')
if (!allowed) → 403 (ไม่ดึงข้อมูลใดๆ)
```
- ถ้าผู้ใช้มี `health_measurement.read` แต่ไม่มี `ai.health_context.use` → **ปฏิเสธ** (ห้ามใช้สิทธิ์อ่านแทนสิทธิ์ AI — F-06)
- ตรวจซ้ำทุกครั้ง ไม่ cache consent เกิน 60 วินาที

### ขั้นที่ 2 — Scoped Data Fetcher

- ดึงเฉพาะ scope ที่ผู้ใช้ถือ (`vitals`, `medications`, …) **และ** อยู่ในช่วงเวลาที่ขอ (ค่าเริ่มต้น 30 วัน)
- บังคับ `workspace_id` + `profile_id` ในทุก query
- ห้ามดึง `mental_health` / `lab_results` / เอกสาร `category = lab_report` โดยปริยาย — scope สองตัวแรกปิดใน MVP (ADR-014) เอกสาร lab ถือเป็น sensitive
- ใส่ `source_refs[]` ของทุก record ที่ดึง (id, type, วันที่) เพื่อให้ตรวจย้อนหลังได้

### ขั้นที่ 3 — PII Scrubber & Pseudonymizer

| ข้อมูล | การจัดการ |
|---|---|
| ชื่อ-นามสกุล | ลบ → `Subject` |
| เลขบัตรประชาชน | ลบ |
| เบอร์โทร / email / line id | ลบ |
| ชื่อโรงพยาบาล/คลินิก | ลบ → `Facility_A` |
| ที่อยู่ | ลบ → ระดับจังหวัด (ถ้าจำเป็น) |
| วันที่เกิด | แปลงเป็น `Age_Group` |
| วันที่ | เก็บแบบ relative (`Day -7`) |
| ชื่อยา | **เก็บ** (จำเป็นต่อการสรุป) |
| ข้อความอิสระของผู้ใช้ | ผ่าน classifier ก่อน — ถ้าพบชื่อ/เบอร์ → ลบ |

**ตัวอย่างผลลัพธ์ที่ถูกต้อง**
```
Subject: Female | Age_Group: 75-80 | Allowed_Scopes: [Vitals, Medications]
BP_Trend_7d: Avg 158/92 mmHg (Max 165/95 on Day -2)
Med_Adherence: Amlodipine 5mg missed 3 consecutive doses
```
> ถ้าผู้ขอไม่มี scope `mental_health` ข้อมูลซึมเศร้า **ต้องไม่ปรากฏ** แม้จะถูกพูดถึงในบันทึกอิสระ

### ขั้นที่ 4 — Clinical Guardrail Prompting

**System prompt บังคับ (ต้องมีทุก call):**
- ❌ ห้ามวินิจฉัยโรค / ห้ามแนะนำปรับขนาดยา / ห้ามตีความผลแล็บแทนแพทย์
- ✅ สรุปแนวโน้มให้แพทย์อ่านใน 30 วินาที
- ✅ เสนอรายการคำถามที่ควรถามแพทย์
- ✅ ระบุชัดว่าเป็นข้อมูลที่ผู้ใช้บันทึก ไม่ใช่ผลตรวจทางการแพทย์
- ✅ ถ้าพบสัญญาณอันตราย (เช่น ค่าที่สูงมาก) → แนะนำให้ติดต่อแพทย์/ฉุกเฉินทันที **ไม่วิเคราะห์ต่อ**
- ✅ ตอบเป็นภาษาไทยตาม locale ของผู้ใช้

**ห้ามส่งข้อมูลอิสระจากผู้ใช้เป็น instruction** (prompt injection) — ข้อความจากผู้ใช้ต้องถูกห่อใน block ข้อมูล:
```
<untrusted_record type="user_note">...</untrusted_record>
```

### ขั้นที่ 5 — Output Validation & Audit

- ตรวจว่า output ไม่มี PII ที่ควรถูกตัด (regex + ให้โมเดลตรวจซ้ำถ้าจำเป็น)
- ตรวจว่ามี disclaimer
- บันทึก `ai_audit_logs`: `prompt_hash`, `scope_used`, `record_refs`, `model`, `token_in/out`, `cost`, `actor`, `latency`, `result`
- **ห้ามเก็บ prompt/output เต็มใน log ปกติ** — เก็บเฉพาะ hash + metadata; เก็บข้อความใน `ai_conversations` ที่เข้ารหัสและมี retention 180 วัน

---

## 3. กฎ (AIG-xx)

| ID | กฎ |
|---|---|
| **AIG-001** | ห้ามเรียก provider ใดๆ ก่อนผ่านขั้น 1–3 |
| **AIG-002** | context ที่ดึงต้องมี `record_refs` ครบทุกใบ — ตรวจย้อนหลังได้ว่าสรุปมาจากอะไร |
| **AIG-003** | ถ้า scrubbing ล้มเหลว/ไม่แน่ใจ → **ไม่ส่ง** (fail closed) |
| **AIG-004** | ผู้ใช้ต้องเห็นว่าข้อมูลใดถูกส่ง (รายการ scope + ช่วงเวลา) ก่อนกดยืนยันครั้งแรก |
| **AIG-005** | ถอน consent → ล้าง cache + ยกเลิกคำขอที่ค้าง + ลบ conversation ตามคำขอ |
| **AIG-006** | คำตอบต้องมี `disclaimer` และปุ่ม "ติดต่อแพทย์/ฉุกเฉิน" เมื่อเข้าเกณฑ์ |
| **AIG-007** | ห้าม AI เขียนข้อมูลลง DB สุขภาพ (read-only context) |
| **AIG-008** | ห้าม AI เปลี่ยนสถานะงาน/มอบหมาย/verify (CT-010) |
| **AIG-009** | ทุก call ต้องมี `actor_id` + `workspace_id` — ไม่มี anonymous AI |
| **AIG-010** | Provider ต้องไม่อยู่ในรายการที่ห้าม (เช่น provider ที่ train ด้วยข้อมูลลูกค้าโดยไม่ปิด) และต้องมี DPA |

---

## 4. Quota & cost

| ระดับ | กลไก |
|---|---|
| ต่อผู้ใช้ | 20 คำขอ/วัน (ค่าเริ่มต้น), 5 คำขอ/นาที |
| ต่อ workspace | ตาม entitlement ของแพ็กเกจ |
| ต่อ provider | circuit breaker: error rate >20% ใน 5 นาที → stop + fallback |
| ต้นทุน | บันทึก `ai_usage_counters` (token, cost) รายวัน; แจ้งเตือนที่ 80% ของ quota |

**ห้าม:** retry อัตโนมัติเมื่อ prompt ถูกปฏิเสธด้วย policy (จะทำให้ต้นทุนบานและไม่แก้ปัญหา)

---

## 5. Provider abstraction

```
interface LlmProvider {
  public function complete(PromptEnvelope $e): LlmResponse;
}
```
- `PromptEnvelope` มี: system, untrusted_blocks[], structured_context[], locale, max_tokens, temperature
- Adapter ต่อ provider แปลง envelope → API ของเจ้านั้น
- **ห้าม** ให้ adapter เข้าถึง DB — รับเฉพาะ envelope ที่ scrub แล้ว
- Routing: model เล็กสำหรับสรุปสั้น, model ใหญ่สำหรับ Doctor Brief; fallback ตาม cost/latency policy

---

## 6. Evaluation (ก่อนเปิดใช้)

| ชุดทดสอบ | เกณฑ์ผ่าน |
|---|---|
| Leakage test | 100% ของชุดทดสอบ 20 เคส ต้องไม่มี PII หลุดออกไป |
| Scope test | 100% ต้องไม่ดึงข้อมูลนอก scope (รวม mental_health) |
| Refusal test | ≥95% ของคำถามห้ามตอบ (วินิจฉัย/ปรับยา) ถูกปฏิเสธอย่างสุภาพ |
| Injection test | ≥95% ของ payload ที่พยายาม override instruction ไม่สำเร็จ |
| Grounding test | ≥90% ของข้อความสรุปอ้างอิง record ที่มีอยู่จริง (ไม่มี hallucinated ค่า) |
| Safety test | 100% ของเคส "ค่าวิกฤต" ต้องมีคำแนะนำให้พบแพทย์/ฉุกเฉิน |

---

## 7. Failure modes ที่ต้องจัดการ

| อาการ | พฤติกรรม |
|---|---|
| consent service timeout | ปฏิเสธ + `503 authorization_unavailable` |
| provider timeout | คืนข้อความ "ระบบ AI ไม่พร้อม" + ไม่เก็บ partial output |
| output มี PII | ทิ้ง + log incident + แจ้งเตือน ops |
| provider ปฏิเสธ (safety) | แสดงข้อความกลาง ไม่ retry |
| quota หมด | 409/429 + ทางเลือก "ดูสรุปแบบไม่ใช้ AI" |
| context ใหญ่เกิน | ลดช่วงเวลา/ตัด field ตามลำดับความสำคัญ **ไม่ตัด scope ที่ผู้ใช้ขอโดยไม่แจ้ง** |
