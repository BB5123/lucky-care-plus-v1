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

    /*
    |--------------------------------------------------------------------------
    | Retrieval aliases
    |--------------------------------------------------------------------------
    |
    | Explicit, hand-checked synonyms. Retrieval first searches the normalised
    | query; only when that returns nothing does it retry the terms below and
    | mark the hits `matched_by: alias`. A query that already has hits is never
    | widened, so the common case keeps its precision. Keep this list small and
    | only add a spelling that actually occurs in the corpus.
    |
    */

    'aliases' => [
        'ฉี่หนู' => ['เลปโตสไปโรซิส', 'leptospirosis'],
        'มือเท้าปาก' => ['HFM', 'Hand Foot and Mouth'],
        'ฝุ่น' => ['PM2.5', 'PM 2.5'],
        'ไข้เลือดออก' => ['เด็งกี่', 'dengue'],
    ],

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
            'multimedia.anamai.moph.go.th',
            'www.thaihealth.or.th',
            'thaihealth.or.th',
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
    |
    | Thai user-facing copy. These strings are rendered verbatim in every
    | answer, so they are written as single lines: an earlier version of this
    | file held double-encoded mojibake here and users saw garbage. The
    | repository rule is that Thai text is only ever written through a file
    | editor, never a shell (`AGENTS.md` section 7).
    |
    */

    'disclaimer' => 'ข้อมูลนี้เป็นข้อมูลทั่วไปเพื่อการเรียนรู้ ไม่ใช่การวินิจฉัยหรือคำแนะนำทางการแพทย์ หากมีอาการผิดปกติหรือมีข้อสงสัยเกี่ยวกับสุขภาพ ควรปรึกษาแพทย์หรือสถานพยาบาลใกล้บ้าน',

    'unreviewed_warning' => 'หมายเหตุ: แหล่งข้อมูลนี้ยังไม่ผ่านการตรวจทานโดยผู้เชี่ยวชาญ ควรใช้ประกอบการพิจารณาเท่านั้น',

    'no_answer_message' => 'ไม่พบข้อมูลที่เกี่ยวข้องในฐานความรู้สุขภาพ กรุณาลองใช้คำค้นอื่น หรือปรึกษาแพทย์หรือเจ้าหน้าที่สาธารณสุข',

    'unavailable_message' => 'ฐานความรู้สุขภาพยังไม่พร้อมใช้งานในขณะนี้ กรุณาลองใหม่อีกครั้งภายหลัง',
];
