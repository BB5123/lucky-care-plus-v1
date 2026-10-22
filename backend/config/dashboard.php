<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | ADR-010 caps the dashboard aggregate cache at 30 seconds. The cache key
    | includes the per-workspace `authz_version`, so a grant/consent mutation
    | invalidates it immediately even before this TTL elapses.
    |
    */

    'cache_ttl' => 30,

    /*
    |--------------------------------------------------------------------------
    | Vital thresholds
    |--------------------------------------------------------------------------
    |
    | ILLUSTRATIVE ONLY — not clinically validated. These are placeholders to
    | prove the ring/alert derivation until a clinical reviewer signs off the
    | real cut-offs. Do not present them to users as medical advice.
    |
    | `value` is the primary reading (systolic for blood pressure), and
    | `secondary_value` is the diastolic reading.
    |
    */

    'thresholds' => [
        'blood_pressure_systolic' => ['warning' => 140, 'critical' => 160],
        'blood_pressure_diastolic' => ['warning' => 90, 'critical' => 100],
        'blood_glucose' => ['warning_high' => 180, 'critical_high' => 250, 'critical_low' => 70],
        'spo2' => ['critical_low' => 92],
        'heart_rate' => ['warning_high' => 100, 'critical_high' => 130, 'critical_low' => 50],
    ],

    /*
    |--------------------------------------------------------------------------
    | Aggregation windows
    |--------------------------------------------------------------------------
    */

    // A vital in this window can turn the family ring red (a warning only
    // turns it yellow).
    'alert_window_hours' => 24,

    // Trend window for the vitals snapshot.
    'trend_days' => 7,

    // Upper bound on task cards per response, so a busy household cannot make
    // the first screen unbounded.
    'max_task_items' => 50,

];
