<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo mode for OTP delivery
    |--------------------------------------------------------------------------
    |
    | When enabled, the OTP request endpoint returns the freshly issued code in
    | the HTTP response (`demo_code`) so a Flutter client can walk the verify
    | flow on a device that has no SIM card and no way to read server logs.
    |
    | This is a DEVELOPMENT aid, not a feature:
    |  - it is OFF by default;
    |  - it is refused outright in the production environment (the guard throws,
    |    so a misconfigured deployment fails loudly instead of leaking codes);
    |  - the code is still hashed in the database and verification stays real;
    |  - it is never written to logs (N-02).
    |
    | Enable it in backend/.env for local device testing:
    |     OTP_DEMO_MODE=true
    |
    | Reference: `.ai/09-SECURITY-PRIVACY.md` §2.3, `backend/docs/DEV-OTP.md`.
    |
    */

    'demo_mode' => env('OTP_DEMO_MODE', false),

];
