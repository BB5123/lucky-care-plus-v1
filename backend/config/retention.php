<?php

/*
|--------------------------------------------------------------------------
| Retention policy (ADR-016 / .ai/06-DATA-MODEL.md §8)
|--------------------------------------------------------------------------
|
| Periods are expressed in months. `null` means "keep indefinitely".
| Tenant overrides may only move a value inside `bounds`; anything outside
| is rejected by RetentionPolicy (DM-R06).
|
*/

return [

    // Platform defaults. These are the only values a tenant can extend or
    // shorten (within bounds), except for the non-overridable entries below.
    'defaults' => [
        'health_measurements' => 60,
        'health_conditions' => 60,
        'allergies' => 60,
        'medication_plans' => 60,
        'medication_records' => 60,
        'appointments' => 60,
        'care_tasks' => 36,
        'task_events' => 36,
        'task_evidence' => 36,
        'documents_medical' => 60,
        'documents_identity' => 12, // counted from delegation end
        'ai_conversations' => 6,
        'notification_payload_days' => 90,
        'notification_metadata_months' => 24,
        'audit_months' => 60, // fixed, never user-configurable
    ],

    // Minimum and maximum months a tenant may configure, per type.
    'bounds' => [
        'health_measurements' => [24, 120],
        'health_conditions' => [24, 120],
        'allergies' => [24, 120],
        'medication_plans' => [24, 120],
        'medication_records' => [24, 120],
        'appointments' => [24, 120],
        'care_tasks' => [12, 120],
        'task_events' => [12, 120],
        'task_evidence' => [12, 120],
        'documents_medical' => [24, 120],
        'documents_identity' => [6, 36],
        'ai_conversations' => [1, 24],
        'notification_payload_days' => [30, 365],
        'notification_metadata_months' => [6, 120],
    ],

    // Types a tenant must never be able to change (and purge must never touch).
    'non_overridable' => ['audit_months'],

    // No login for this many months starts the inactivity flow.
    'inactivity_months' => 24,

    // Reminder offsets (days before the purge due date), from ADR-016.
    'notification_days_before' => [30, 14, 7],

    // Forced on for the first 30 days of a deployment (ADR-016 §5).
    'dry_run' => filter_var(env('RETENTION_DRY_RUN', true), FILTER_VALIDATE_BOOLEAN),

];
