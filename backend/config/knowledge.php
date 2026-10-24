<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Health knowledge base (RAG corpus)
    |--------------------------------------------------------------------------
    |
    | The corpus is a separate, read-only SQLite file: public reference data,
    | not tenant health data, so it has no tenant_id/workspace_id and is not
    | protected by RLS. It can be rebuilt from `database/knowledge/sources`
    | and shipped with a deployment (or copied into storage) so a fresh
    | installation can restore it without crawling the internet again.
    |
    | Why SQLite: the deployment already has the extension (PDO SQLite) and
    | FTS5 with the trigram tokenizer handles Thai substrings without an
    | embedding provider. Qdrant/pgvector can be added behind
    | `KnowledgeRetriever` later; the gateway only talks to the interface.
    |
    */

    'sqlite_path' => env('KNOWLEDGE_SQLITE_PATH', database_path('knowledge/health_kb.sqlite')),

    'sources_path' => env('KNOWLEDGE_SOURCES_PATH', database_path('knowledge/sources')),

    'top_k' => (int) env('KNOWLEDGE_TOP_K', 5),

    'max_question_chars' => (int) env('KNOWLEDGE_MAX_QUESTION_CHARS', 500),

    'chunk_chars' => (int) env('KNOWLEDGE_CHUNK_CHARS', 900),

    'chunk_overlap' => (int) env('KNOWLEDGE_CHUNK_OVERLAP', 150),

    /*
    |--------------------------------------------------------------------------
    | Sourcing rules
    |--------------------------------------------------------------------------
    |
    | Only public government sources are allowed. DDC's robots.txt disallows
    | /uploads/ (where its PDFs live), so the fetcher refuses that path; the
    | HTML article pages are allowed. PDF text extraction is not available in
    | this environment (no pdftotext/PyMuPDF and LibreOffice's PDF import is
    | unusable), so the corpus is HTML-first.
    |
    */

    'fetch' => [
        'user_agent' => 'LuckyCarePlus-KnowledgeBot/1.0 (+https://luckycare.example/health-knowledge)',
        'timeout_seconds' => (int) env('KNOWLEDGE_FETCH_TIMEOUT', 30),
        'delay_ms' => (int) env('KNOWLEDGE_FETCH_DELAY_MS', 1000),
        'allowed_hosts' => [
            'ddc.moph.go.th',
            'www.ddc.moph.go.th',
            'moph.go.th',
            'www.moph.go.th',
            'anamai.moph.go.th',
            'www.anamai.moph.go.th',
            'who.int',
            'www.who.int',
        ],
        'disallowed_path_prefixes' => ['/uploads/'],
        'max_html_bytes' => 5 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Answer copy (AIG-006 / N-02)
    |--------------------------------------------------------------------------
    */

    'disclaimer' => 'ข้อมูลนี้เป็นความรู้ทั่วไปจากเอกสารของหน่วยงานราชการที่อ้างอิงไว้ '
        .'ไม่ใช่คำวินิจฉัยหรือคำแนะนำการรักษาเฉพาะบุคคล และไม่สามารถใช้แทนการพบแพทย์ได้ '
        .'หากมีอาการรุนแรงหรือฉุกเฉิน กรุณาติดต่อแพทย์หรือสายด่วนฉุกเฉินทันที',

    'unreviewed_warning' => 'หมายเหตุ: เอกสารต้นทางชุดนี้ยังไม่ผ่านการตรวจทานโดยบุคลากรทางการแพทย์ '
        .'ระบบใช้เพื่อสาธิตการค้นคืนและอ้างอิงเท่านั้น',

    'no_answer_message' => 'ไม่พบข้อมูลที่เกี่ยวข้องในฐานความรู้สุขภาพสำหรับคำถามนี้ '
        .'กรุณาลองถามด้วยคำที่เฉพาะเจาะจงขึ้น หรือปรึกษาแพทย์/เจ้าหน้าที่สาธารณสุข',

    'unavailable_message' => 'ฐานความรู้สุขภาพยังไม่พร้อมใช้งานในขณะนี้',
];
