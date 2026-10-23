# DEV-OTP — getting the OTP code on a device

The mobile app (`mobile/`, Flutter) and any external client need the six-digit code that the backend
just issued. In a real deployment that arrives by SMS; during development there is no SMS provider.

This repository therefore supports an explicit **demo mode** that returns the code in the API response.

It is modelled on the reference projects in `references/source/` (`mohpromt-clone`'s `DemoOtpSender` +
`config('mohprompt.demo_mode')` returning `demoCode`, and `paotang`'s `OtpController`), with two
additional guards:

| Guard | Reference behaviour | Here |
|---|---|---|
| Default | on (`MOHPROMPT_DEMO_MODE` default `true`) | **off** (`OTP_DEMO_MODE` default `false`) |
| Production | not guarded | **throws** at first use — a misconfigured deploy fails loudly instead of leaking codes |
| Code in logs | logged by `DemoOtpSender` | **never logged** (N-02) |
| Audit | none | `security_audit_logs` row `auth.otp.demo_issued` |

## Enable it

```dotenv
# backend/.env
OTP_DEMO_MODE=true
```

Then restart the API (`php artisan serve` picks it up automatically on a new request; a long-running
worker needs a restart).

## Flow

```http
POST /api/v1/auth/otp/request
Authorization: Bearer <sanctum token>
{ "phone": "0812345678" }
```

```json
{
  "data": {
    "channel": "sms",
    "expires_in": 300,
    "message": "ส่งรหัสยืนยันแล้ว",
    "demo_mode": true,
    "demo_code": "123456"
  }
}
```

```http
POST /api/v1/auth/otp/verify
{ "phone": "0812345678", "code": "123456" }
```

`demo_code` only exists when the flag is on. With the flag off the response has no `demo_code` and no
`demo_mode` key at all, so a client may safely ignore the field in production.

## What stays real

- the code is still generated with `random_int`, stored as a SHA-256 hash, valid for 5 minutes, single
  use, and dead after 5 wrong attempts;
- verification still compares against the hash and the stored destination;
- the rate limiter (`throttle:6,1,otp-request`) is unchanged.

In other words, demo mode only changes **how the code reaches the developer**, never how it is
validated.

## Tests

`backend/tests/Feature/Auth/OtpDemoModeTest.php`

- off by default → no `demo_code` in the response, flow still works through the sender;
- on → `demo_code` is a 6-digit string, identical to what the sender received, different from the
  stored hash, and it verifies successfully;
- on in `production` → `RuntimeException` (`OTP_DEMO_MODE must not be enabled in production`);
- off in `production` → silent and safe.
