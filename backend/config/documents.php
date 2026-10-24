<?php

/**
 * Medical-document pipeline configuration (ADR-014, `.ai/09` §2.2).
 *
 * The disk is private (`storage/app/private` for the local driver). Objects
 * are stored under `documents/{tenant_uuid}/{document_uuid}` with no user
 * filename, no extension and no PHI in the key (N-02).
 *
 * `scan_required` defaults to false because no scanner is deployed by default.
 * It is a fail-closed switch: setting it to true while no scanner is reachable
 * makes an upload return 503 and store nothing, rather than accepting an
 * unscanned object. `scanner` selects the implementation (`null` = unavailable,
 * `clamav` = unix-socket adapter); a document is only persisted after a clean
 * scan when the gate is on.
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

    // No scanner is configured by default. Keep the fail-closed behaviour
    // explicit rather than silently treating documents as clean.
    'scan_required' => filter_var(env('DOCUMENTS_SCAN_REQUIRED', false), FILTER_VALIDATE_BOOLEAN),

    // `null` keeps the fail-closed UnavailableScanner; `clamav` selects the
    // unix-socket adapter. An unknown value also falls back to unavailable.
    'scanner' => env('DOCUMENTS_SCANNER'),

    'clamav' => [
        // The adapter speaks INSTREAM over this unix socket; it never shells
        // out to `clamdscan`. A native Linux install uses the path below. A
        // container deployment must share the socket path with the backend
        // (see backend/docs/MALWARE-SCANNING.md).
        'socket' => env('DOCUMENTS_CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),
        'timeout_seconds' => (float) env('DOCUMENTS_CLAMAV_TIMEOUT', 10),
    ],

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
