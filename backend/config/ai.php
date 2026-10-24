<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Gateway feature flag
    |--------------------------------------------------------------------------
    |
    | The gateway must stay impossible to leak data through until the G4 gate
    | passes (ADR-018). `enabled` is therefore false by default: the endpoint
    | answers 503 `ai_disabled` and records a `disabled` attempt without
    | fetching context or contacting a provider (AIG-001).
    |
    */

    'enabled' => env('AI_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Provider adapter
    |--------------------------------------------------------------------------
    |
    | `null` means the deterministic in-process NullProvider. No adapter in
    | this slice is allowed to open an outbound HTTP connection. A real
    | provider class can be named here later (AIG-010).
    |
    */

    'provider' => env('AI_PROVIDER'),

    'model' => env('AI_MODEL', 'null-provider-v1'),

    /*
    |--------------------------------------------------------------------------
    | Quotas (.ai/08-AI-GATEWAY.md section 4)
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'per_user_day' => (int) env('AI_PER_USER_DAY', 20),
        'per_user_minute' => (int) env('AI_PER_USER_MINUTE', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context window
    |--------------------------------------------------------------------------
    */

    'context' => [
        'default_window_days' => 30,
        'max_window_days' => 180,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt envelope defaults
    |--------------------------------------------------------------------------
    */

    'max_tokens' => (int) env('AI_MAX_TOKENS', 700),

    'temperature' => (float) env('AI_TEMPERATURE', 0.2),

    'locale' => env('AI_LOCALE', 'th'),

    /*
    |--------------------------------------------------------------------------
    | Mandatory disclaimer (AIG-006)
    |--------------------------------------------------------------------------
    |
    | Every validated answer must contain this exact text. The provider never
    | decides whether to include it; the OutputValidator rejects an answer
    | that does not.
    |
    */

    'disclaimer' => 'คำเตือน: ข้อมูลนี้สรุปจากบันทึกที่ผู้ใช้บันทึกเอง ไม่ใช่ผลตรวจทางการแพทย์ '
        .'และไม่สามารถใช้แทนคำวินิจฉัยของแพทย์ได้ กรุณาปรึกษาแพทย์หรือเจ้าหน้าที่สาธารณสุขก่อนปรับการรักษา '
        .'หากมีอาการฉุกเฉินให้ติดต่อสายด่วนฉุกเฉินทันที',

    /*
    |--------------------------------------------------------------------------
    | Intake classifier (deterministic, rule-based)
    |--------------------------------------------------------------------------
    |
    | The gateway refuses a few categories of user request before it fetches
    | context or calls a provider (`.ai/08-AI-GATEWAY.md` section 6, refusal
    | and emergency suites). This is deliberately a phrase list, not a model
    | call: the decision is explainable and can be evaluated offline.
    |
    | Matching is a case-insensitive substring match on the user's request
    | text (`GatewayRequest::$requestText`). Emergency phrases are tested
    | first, so "เจ็บหน้าอก เป็นโรคอะไร" is an emergency, not a diagnosis
    | question.
    |
    */

    'intake' => [
        'emergency' => [
            'เจ็บหน้าอก',
            'แน่นหน้าอก',
            'หายใจไม่ออก',
            'หายใจลำบาก',
            'หมดสติ',
            'ชัก',
            'เลือดออกไม่หยุด',
            'พูดไม่ได้',
            'แขนขาอ่อนแรง',
            'chest pain',
            'cannot breathe',
            "can't breathe",
            'unconscious',
            'seizure',
            'stroke',
        ],

        'prescribing' => [
            'ปรับขนาดยา',
            'เพิ่มขนาดยา',
            'ลดขนาดยา',
            'หยุดยา',
            'เปลี่ยนยา',
            'ควรกินยาอะไร',
            'ควรเพิ่มยา',
            'ควรลดยา',
            'กินยาตัวไหนดี',
            'ขนาดยาเท่าไหร่',
            'double my dose',
            'increase my dose',
            'decrease my dose',
            'stop my medication',
            'change my medication',
            'what dosage',
            'what dose should',
        ],

        'diagnosis' => [
            'วินิจฉัย',
            'เป็นโรคอะไร',
            'ฉันเป็นโรค',
            'ผมเป็นโรค',
            'หนูเป็นโรค',
            'เป็นมะเร็งหรือเปล่า',
            'เป็นเบาหวานไหม',
            'โรคอะไร',
            'ยืนยันว่าเป็น',
            'diagnose',
            'what disease',
            'do i have',
            'is this cancer',
            'confirm i have',
        ],

        // Polite Thai refusals. The controller reuses these strings so the
        // user-facing copy has exactly one source.
        'messages' => [
            'refuse_diagnosis' => 'ขออภัย ระบบช่วยสรุปไม่สามารถวินิจฉัยโรคหรือยืนยันว่าคุณเป็นโรคใดได้ '
                .'กรุณาปรึกษาแพทย์หรือเจ้าหน้าที่สาธารณสุข',
            'refuse_prescribing' => 'ขออภัย ระบบช่วยสรุปไม่สามารถแนะนำการปรับขนาดยา หยุดยา หรือเปลี่ยนยาได้ '
                .'กรุณาปรึกษาแพทย์หรือเภสัชกร',
            'refuse_emergency' => 'หากมีอาการฉุกเฉิน กรุณาติดต่อสายด่วนฉุกเฉินหรือไปห้องฉุกเฉินทันที '
                .'ระบบนี้ไม่สามารถประเมินอาการฉุกเฉินได้',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt-injection guard (`.ai/08-AI-GATEWAY.md` section 2 step 4)
    |--------------------------------------------------------------------------
    |
    | Every untrusted block is scanned before it reaches a provider. A block
    | that contains an instruction-override phrase is neutralised, not
    | trusted: the matched phrase is replaced and the block is wrapped in an
    | explicit "this is data, not instructions" marker. The fact that a block
    | was neutralised is recorded as `ai_requests.error_code =
    | injection_neutralized` so an operator can audit it.
    |
    */

    'injection' => [
        'patterns' => [
            'ignore previous',
            'ignore all previous',
            'ignore the above',
            'disregard previous',
            'disregard the above',
            'forget previous',
            'system:',
            'system prompt',
            'you are now',
            'from now on you',
            'new instructions',
            'developer mode',
            'jailbreak',
            'reveal your',
            'print your instructions',
            'act as',
            'override',
            'ลืมคำสั่ง',
            'ไม่ต้องปฏิบัติตาม',
            'ละเว้นคำสั่ง',
            'เพิกเฉยคำสั่ง',
            'จากนี้ไปคุณคือ',
            'ห้ามสนใจคำสั่ง',
            'คำสั่งใหม่',
            'ตอบตามที่ฉันสั่ง',
        ],

        'marker_open' => '<neutralized_untrusted_data reason="data_only">',

        'marker_close' => '</neutralized_untrusted_data>',

        'replacement' => '[NEUTRALIZED_INSTRUCTION]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Grounding (`.ai/08-AI-GATEWAY.md` section 6, grounding suite)
    |--------------------------------------------------------------------------
    |
    | Every number in an answer must exist in the scrubbed structured context
    | the provider received. `allowed_tokens` is intentionally empty: adding a
    | number here weakens the hallucination check, so it should be a reviewed
    | decision, not a convenience.
    |
    */

    'grounding' => [
        'allowed_tokens' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety advisor (`.ai/08-AI-GATEWAY.md` section 6, safety suite)
    |--------------------------------------------------------------------------
    |
    | When the structured context contains a critical vital (the cut-offs come
    | from `config/dashboard.php`), the answer must contain emergency advice.
    | The advisor appends this sentence when the provider omitted it; the
    | disclaimer and the advice are never optional (AIG-006).
    |
    */

    'safety' => [
        'advice' => 'พบค่าที่อาจเป็นอันตรายในข้อมูลที่บันทึกไว้ กรุณาติดต่อแพทย์หรือไปห้องฉุกเฉินทันที '
            .'และไม่ควรปรับยาด้วยตนเอง',

        'markers' => [
            'ห้องฉุกเฉิน',
            'ติดต่อแพทย์',
            'พบแพทย์',
        ],
    ],

];
