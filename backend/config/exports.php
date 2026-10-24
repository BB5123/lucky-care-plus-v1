<?php

/**
 * PDPA data-export configuration (`.ai/09` §4, permission `data_export.*`).
 *
 * The export is written to the private object store, never to a public bucket
 * and never mailed. `disk` defaults to the same private disk as documents; a
 * production deployment must point it at a store whose driver provides
 * encryption at rest (S3 SSE, an encrypted volume, or equivalent). The
 * application deliberately does not wrap the JSON in `Crypt` because the
 * signed URL is the delivery path: an app-level envelope would hand the user
 * ciphertext instead of their data.
 *
 * `link_ttl_minutes` is capped at 30 minutes to mirror the Doctor Brief share
 * window (DB-003). `retention_days` is the export lifetime, not the record
 * retention policy.
 */
return [

    'disk' => env('EXPORTS_DISK', env('DOCUMENTS_DISK', 'local')),

    'format_version' => 1,

    // A data export is available for seven days after it is built.
    'retention_days' => (int) env('EXPORTS_RETENTION_DAYS', 7),

    // One request per profile per 24 hours (privacy control, not politeness).
    'rate_limit_hours' => (int) env('EXPORTS_RATE_LIMIT_HOURS', 24),

    // Short-lived signed link. The blueprint caps a share at 30 minutes.
    'link_ttl_minutes' => (int) env('EXPORTS_LINK_TTL_MINUTES', 30),
    'max_link_ttl_minutes' => 30,

];
