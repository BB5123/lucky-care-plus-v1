<?php

/**
 * Medical-document pipeline configuration (ADR-014, `.ai/09` §2.2).
 *
 * The disk is private (`storage/app/private` for the local driver). Objects
 * are stored under `documents/{tenant_uuid}/{document_uuid}` with no user
 * filename, no extension and no PHI in the key (N-02).
 *
 * `scan_required` defaults to false because no scanner is deployed. It is a
 * fail-closed switch: setting it to true makes upload return 503 and store
 * nothing, rather than accepting an unscanned object. When a scanner exists,
 * wire it in `DocumentService` and only persist a document after a clean scan.
 */
return [

    'disk' => env('DOCUMENTS_DISK', 'local'),

    // 10 MiB. Enforced against the actual bytes by `DocumentService` so the
    // 422 response can name the limit in Thai instead of PHP's KB wording.
    'max_bytes' => (int) env('DOCUMENTS_MAX_BYTES', 10 * 1024 * 1024),

    // Actual bytes are sniffed with finfo; the client-declared MIME type is
    // never trusted. A client type that is in this list but disagrees with the
    // sniffed type is rejected as a mismatch.
    'allowed_mimes' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/heic',
    ],

    // Signed capability lifetime (minutes). The baseline caps this at 15.
    'url_ttl_minutes' => 5,
    'url_max_ttl_minutes' => 15,

    // No scanner is configured in this deployment. Keep the fail-closed
    // behaviour explicit rather than silently treating documents as clean.
    'scan_required' => filter_var(env('DOCUMENTS_SCAN_REQUIRED', false), FILTER_VALIDATE_BOOLEAN),

    // Placeholder for a future antivirus/CDR implementation. A null value is
    // why a scan-required upload cannot succeed today.
    'scanner' => null,

    'kinds' => [
        'lab_result',
        'prescription',
        'discharge_summary',
        'photo',
        'other',
    ],

    // Extensions are used only for the download filename, never for storage.
    'extensions' => [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/heic' => 'heic',
    ],

];
