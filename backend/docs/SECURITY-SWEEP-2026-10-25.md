# Security and supply-chain sweep — 2026-10-25

Scope: everything that can be checked from the repository without a penetration test. The goal was to answer
one question honestly: **is it safe to let someone else use this today?**

## What was checked, and the result

| Check | Command | Result |
|---|---|---|
| PHP dependency advisories | `composer audit` | **clean** — no advisories found |
| npm production dependencies | `npm audit --omit=dev` | **0 vulnerabilities** |
| npm including dev dependencies | `npm audit` | **2 found** (1 high, 1 low) — one advisory, two packages. Fixed, see below |
| Secrets in git **history** | `git log -p --all` over key-shaped patterns | **0 hits** |
| Secrets in the **working tree** | `git grep` over the same patterns | **0 hits** |
| Does the runtime image carry `.env`? | `ls /var/www/html/.env` inside the built image | **absent** (`.dockerignore` works) |
| Dangerous production defaults | `.env.production.example` | `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `OTP_DEMO_MODE=false`, `RETENTION_DRY_RUN=true`, `AI_ENABLED=false`, `DOCUMENTS_SCAN_REQUIRED=false` — all fail safe |

The patterns used for the secret scan cover everything that appeared in this project: `sk_…`, `gsk_…`,
`thk_…`, `sk-or-v1-…` and private-key headers.

## The one real finding, and its fix

`vite` 8.3.0 carries three advisories (the highest: `server.fs.allow` could serve files outside the project on
non-Windows hosts). It arrives through `@angular/build` 22.2.4, which **pins `vite` to exactly 8.3.0**, so
`npm audit fix` cannot help:

```
$ npm view @angular/build version        -> 22.2.4   (latest)
$ node -e "…dependencies.vite"           -> 8.3.0    (exact pin)
$ npm audit                              -> Patched versions: vite 8.3.3+
```

**Fix:** an npm `overrides` entry pinning `vite` to **8.3.5** (a patch release within the same minor). Verified
afterwards that nothing regressed:

```
$ node -e "require('./node_modules/vite/package.json').version"  -> 8.3.5
$ npm run build                                                  -> complete
$ npm test                                                       -> 135 passed / 43 files
$ npm audit                                                      -> 0 vulnerabilities
```

This is a deliberate, documented override of a framework pin, not a silent upgrade: if `@angular/build` starts
depending on new vite behaviour, the build or the tests will say so.

## Gates added to CI (so this cannot rot)

- `composer audit --no-interaction --abandoned=report` in the backend job
- `npm audit --audit-level=high` in the frontend job (high/critical block a PR; a moderate in a build tool does not)
- a **dependency-free secret scan** in the backend job: `git grep` for key-shaped patterns, excluding lockfiles.
  Proven both ways on 2026-10-25: it passes on the clean tree and exits 1 on a planted `sk_…` string.

## Residual risks (documented, not fixed)

1. **No image scanning** (Trivy/Grype) and no SBOM. The base image and OS packages are not audited.
2. **GitHub Actions are pinned by tag**, not by commit SHA. A compromised action tag would run in CI.
3. **API keys live in `backend/.env`** and were exposed in a chat transcript on 2026-10-24. Rotation is an owner
   action (decision D2) and has not been done.
4. **No edge protection**: no TLS termination, no WAF, no rate limiting in front of the API. The app has its own
   throttle rules, but nothing stops a flood before it reaches PHP.
5. **No dependency update automation** (Renovate/Dependabot), so advisories are found only when someone runs the
   audit manually or CI catches a new one on the next PR.
6. **No secret manager.** A production deployment needs one before more than one person can administer it.
7. **The audit gates need the network**; a GitHub Actions outage or an npm registry hiccup will fail the job.

None of these block a **closed pilot with the demo family and no real patient data**. Items 1-4 should be closed
before the first real family's records are stored.
