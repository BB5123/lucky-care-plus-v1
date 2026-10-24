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

];
