# DPA request — OKMD / KKU provider (AIG-010)

**Status:** draft ready to send · **Owner:** product owner · **Blocks:** `AI_ENABLED=true` in any environment
that holds real family data.

`PHASE-7-QUEUE.md` (`AIG-010`) requires written confirmation from the model provider before the AI gateway is
switched on for real data. This file is the letter to send and the record of the answer. It is **not** a legal
opinion; it is the minimum set of facts the owner needs in order to sign off.

## What we already do on our side

Recorded here so the provider can see what reaches them:

| Control | Implementation |
|---|---|
| Only scrubbed text leaves the system | `PromptEnvelope` carries an `<untrusted_record>` block after `AiScrubber`; identifiers are removed before the adapter is called |
| No images by default | The vision path is opt-in per purpose, not a default |
| No provider choice at runtime | `ProviderRouter` picks from `config/ai.php`; no user input reaches a base URL |
| Fail closed | `AI_ENABLED=false` answers `503 ai_unavailable`; a provider outage answers 503, never a fake answer |
| Audit | Every call writes one `ai_requests` row: purpose, provider, model, tokens, latency, error code — **no prompt and no completion text** |
| Retention | Prompt and completion are not stored; only the counters above |

## The five questions (send exactly these)

1. **Training.** Is data submitted through the API used to train, fine-tune, or otherwise improve any model?
   We need a plain "no", in writing.
2. **Retention.** How long are request/response bodies retained on your side, and are they deleted on a schedule
   or on request? If logs are kept for abuse monitoring, state the period.
3. **Location.** In which country/countries is the data processed and stored? If it leaves Thailand, name the
   jurisdictions — PDPA §28 (cross-border transfer) needs this on the record.
4. **Sub-processors.** Do you forward requests to any upstream model provider (for example a foundation-model
   vendor)? If yes, name them and confirm they are bound by the same terms.
5. **Agreement.** Can you provide a signed data processing agreement (or your standard API terms) that covers
   points 1–4? If your standard terms already answer them, quote the clause numbers.

Also ask for: the security contact, and the notice period for a material change to any of the above.

## Draft letter (English — send this)

> Subject: Data processing confirmation for API usage — Lucky Care Plus (family health records, Thailand)
>
> Dear [name],
>
> We are building a family health coordination service in Thailand and would like to use your model API to
> summarise records that a caregiver has already been authorised to see. Before we enable it for real data, we
> need written confirmation of five points:
>
> 1. Whether submitted data is used for training or model improvement (we need "no").
> 2. Your retention period for request and response bodies, and whether deletion is scheduled or on request.
> 3. Where the data is processed and stored, and any cross-border transfer involved.
> 4. Any sub-processors that receive the requests, by name.
> 5. Whether you can provide a signed data processing agreement covering points 1–4, or the clause numbers in
>    your standard terms that answer them.
>
> For context: we send only scrubbed text (identifiers removed before the call), we do not send images by
> default, we keep no prompt or completion content on our side, and we log only token counts and latency. Our
> gateway can be switched off centrally if your terms change.
>
> A short written reply is enough; we will file it as the basis for our own data protection assessment.
>
> Kind regards,
> [name, role, contact]

## Draft letter (Thai — สำหรับส่งถึงผู้ประสานงาน)

> เรื่อง ขอคำยืนยันการประมวลผลข้อมูลสำหรับการใช้งาน API — Lucky Care Plus (ระบบข้อมูลสุขภาพครอบครัว)
>
> เรียน [ชื่อ]
>
> ทีมเรากำลังพัฒนาบริการช่วยประสานการดูแลสุขภาพของครอบครัวในประเทศไทย และประสงค์จะใช้ API ของท่านสรุปข้อมูล
> ที่ผู้ดูแลได้รับสิทธิ์เข้าถึงอยู่แล้ว ก่อนเปิดใช้งานกับข้อมูลจริง ขอคำยืนยันเป็นลายลักษณ์อักษร 5 ข้อ
>
> 1. ข้อมูลที่ส่งเข้ามาถูกนำไปใช้ฝึกหรือปรับปรุงโมเดลหรือไม่ (ขอคำยืนยันว่า "ไม่ใช้")
> 2. ระยะเวลาการเก็บเนื้อหาคำขอและคำตอบ และลบตามรอบเวลาหรือลบเมื่อมีการร้องขอ
> 3. ข้อมูลถูกประมวลผลและจัดเก็บในประเทศใด และมีการส่งข้อมูลออกนอกประเทศหรือไม่
> 4. มีผู้ประมวลผลช่วง (sub-processor) รายใดได้รับข้อมูลบ้าง
> 5. สามารถจัดทำข้อตกลงการประมวลผลข้อมูล (DPA) ที่ครอบคลุมข้อ 1–4 ได้หรือไม่ หรืออ้างอิงข้อในเงื่อนไขมาตรฐาน
>
> เพื่อประกอบการพิจารณา: เราส่งเฉพาะข้อความที่ผ่านการปกปิดตัวตนแล้ว ไม่ส่งภาพเป็นค่าเริ่มต้น ไม่เก็บเนื้อหา
> คำขอหรือคำตอบไว้ฝั่งเรา บันทึกเฉพาะจำนวนโทเค็นและเวลาตอบสนอง และสามารถปิดการใช้งานจากศูนย์กลางได้ทันที
>
> ขอเพียงคำตอบเป็นลายลักษณ์อักษรสั้น ๆ เพื่อใช้ประกอบการประเมินของเรา
>
> ขอแสดงความนับถือ
> [ชื่อ ตำแหน่ง ช่องทางติดต่อ]

## When the answer arrives

1. File the reply (PDF or email export) under `backend/docs/evidence/` and link it here.
2. Fill the table below — this is the record the owner signs off on.
3. If **any** answer is unfavourable (training on our data, unclear retention, unknown jurisdiction), the
   decision is **not** to enable; the local/self-hosted path in `LOCAL-AI-PATHUMMA.md` is the fallback.
4. Only then: `AI_ENABLED=true` in **staging** first, then
   `php artisan ai:evaluate --live --model=<agreed model>` and record the result in
   `backend/docs/G4-LIVE-EVALUATION.md`.
5. Verify the key that will run in production with `php artisan ai:ping` (see `DEPLOYMENT.md` §12).

| Question | Answer (quote) | Date | Source |
|---|---|---|---|
| 1. Training | | | |
| 2. Retention | | | |
| 3. Location | | | |
| 4. Sub-processors | | | |
| 5. Agreement | | | |

**Signed off by:** ____________ (owner) · **Date:** ____________
