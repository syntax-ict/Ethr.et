# ETHR Security Audit — OWASP Top 10 (2021)

**Phase 9 · S36 Security Hardening — evidence of coverage**

_Last reviewed: 2026-07-30_

This document maps each OWASP Top 10 (2021) category to the concrete controls in
ETHR and the automated test or code location that proves the control is in place.
It is the "test evidence" required by the Phase 9 S36 exit criterion.

Unless noted otherwise, test references are Pest tests under `api/tests/`. Run the
full suite with:

```bash
./scripts/gates.sh backend
```

`memory_limit=-1` is required either way — the DomPDF payslip tests exhaust the
default and take the suite down with them.

> **Do not run `vendor/bin/pest` over a Docker bind mount.** On Docker Desktop
> for Windows, PHP's recursive directory scan returns partial results: measured
> 2026-08-21, it collected 21 of 132 test classes, ran them, and exited 0 with a
> green summary. A security-evidence document must not prescribe a command that
> can report a pass while proving almost nothing. `scripts/gates.sh` routes
> around it and fails on an undercount.
>
> Run natively against a local disk and the problem does not occur — measured
> 2026-09-15 at 140/140 classes collected. The trap is the bind mount, not the
> command.

**Status as of the last full run (2026-09-15): 1673 passing tests, 4966
assertions.** The figure below is kept as it stood when this audit was written.

*Status at time of audit (2026-07-30): 954 passing tests, 3,092 assertions.*

---

## A01 — Broken Access Control

| Control | Evidence |
|---------|----------|
| Every tenant-scoped model carries `tenant_id` + `BelongsToTenant` global scope | `tests/Feature/TenantIsolationTest.php` (dynamic model sweep) |
| Tenant A cannot read Tenant B data | `SecurityHardeningTest`: cross-tenant tests for employees, attendance, leave types, payroll runs, holidays, announcements, webhooks |
| Role escalation blocked | `SecurityHardeningTest`: employee → HR / payroll / executive dashboard / reports all denied; HR admin → admin console denied |
| IDOR via another tenant's `public_id` in the URL | `SecurityHardeningTest`: "cannot access employee from another tenant via public_id in URL"; "cannot approve leave request from another tenant via public_id" |
| Numeric primary keys never exposed | `SecurityHardeningTest`: "employee response never exposes numeric id"; "payroll response never exposes numeric id" |
| All protected endpoints require auth | `SecurityHardeningTest`: "all protected endpoints return 401 without auth" |
| Tenant scope defaults to deny | `BelongsToTenant` applies `whereRaw('0 = 1')` when no tenant is resolved (`api/app/Traits/BelongsToTenant.php:23`) |

---

## A02 — Cryptographic Failures

| Control | Evidence |
|---------|----------|
| Sensitive fields encrypted at rest (AES-256 via Laravel `encrypted` cast) | `Employee.tin`, `EmployeeBankDetail.account_number`, `User.mfa_secret`, `Device.connection_config`, `SsoSetting.idp_certificate` |
| TLS enforced | `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` (`api/app/Http/Middleware/SecurityHeaders.php:19`); HTTP→HTTPS redirect in `infrastructure/nginx.conf` |
| Passwords hashed with bcrypt | Laravel default (`config/hashing.php`) |
| No sensitive data leaked in responses | Numeric-id and cross-tenant tests above; API resources expose `public_id` only |

---

## A03 — Injection

| Control | Evidence |
|---------|----------|
| Eloquent/query builder throughout; no unbound raw SQL | Codebase grep: raw SQL limited to constant aggregations (`selectRaw`/`orderByRaw` with hard-coded columns) and the tenant guard |
| The only user-input raw query is bound | `Employee::scopeSearch` — FULLTEXT `MATCH … AGAINST (? IN BOOLEAN MODE)` with a bound parameter and boolean-operator sanitization (`api/app/Models/Employee.php:80-86`) |
| No XSS sink on the frontend | Grep of `src/src`: **0** occurrences of `dangerouslySetInnerHTML`, `eval(`, or `new Function(` |
| SQL injection on search | Exercised by employee search feature tests; input is parameterized |

---

## A04 — Insecure Design

| Control | Evidence |
|---------|----------|
| Cannot approve your own request | `SecurityHardeningTest`: "manager cannot approve their own leave request" |
| Payroll immutable after approval | `SecurityHardeningTest`: "payroll entry cannot be modified after run is approved" |
| Approved payroll run cannot be re-approved | `SecurityHardeningTest`: "approved payroll run cannot be re-approved" |
| Attendance corrections flow through approval, never silent edits | Correction records retained alongside originals (Phase 3) |

---

## A05 — Security Misconfiguration

| Control | Evidence |
|---------|----------|
| Security headers on every response | `api/app/Http/Middleware/SecurityHeaders.php`: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `X-XSS-Protection`, HSTS, CSP (`default-src 'none'` for the JSON API), `Referrer-Policy`, `Permissions-Policy` |
| Headers asserted in tests | `SecurityHardeningTest`: "api responses include required security headers" |
| `APP_DEBUG=false`, no default creds in prod | `api/.env.production.example` ships every secret blank with a `REQUIRED` marker; documented in `docs/DEPLOYMENT.md` |
| Frontend CSP / server hardening | `infrastructure/nginx.conf` (frontend CSP, directory listing off, version headers suppressed) |

---

## A06 — Vulnerable & Outdated Components

| Control | Evidence |
|---------|----------|
| Backend dependency audit | `composer audit` — **could not complete**: packagist advisory API returned HTTP 502 in this (network-restricted) environment. **Still not run: there is no CI** (`docs/audit/BASELINE.md` §15). Scheduled for Phase 2/3. |
| Frontend dependency audit | `npm audit --omit=dev` — **3 high-severity findings** (see below). Patched where possible; residual has no clean in-range fix. |

### npm audit findings (2026-07-30)

| Package | Severity | Advisory summary | Outcome |
|---------|----------|------------------|---------|
| `next` | High | DoS via Image Optimization API (SVG); unauthenticated disclosure of internal Server Function endpoints; plus SSRF / cache-confusion advisories | Bumped 16.2.9 → **16.2.12** (latest in `^16`). First non-vulnerable release is **16.3.0, preview-only** → no stable in-range fix yet. |
| `postcss` (via `next`) | High | XSS via unescaped `</style>`; path traversal / arbitrary `.map` file disclosure via `sourceMappingURL` | Top-level bumped → **8.5.25**; a copy nested under `next` remains until Next updates it upstream. |
| `sharp` (< 0.35.0) | High | Inherited libvips CVEs (CVE-2026-33327/33328/35590/35591) | Not fixable in-range: npm's only `--force` path downgrades Next to 9.3.3. Build/image-pipeline only, not attacker-facing runtime input. Deferred. |

**Status (2026-07-30): partially remediated; residual risk accepted with a
revisit trigger.**

`npm audit fix` (without `--force`) was applied and **persisted to the lockfile**,
bumping `next` **16.2.9 → 16.2.12** and `postcss` **8.5.15 → 8.5.25** (both the
latest patches inside the `^16` range). The dev server compiles and serves on
these versions (verified: `GET / → 200` on Next.js 16.2.12).

However, `npm audit` still reports all three advisories, and its **only** offered
remediation for each is `npm audit fix --force`, which resolves to
**`next@9.3.3` (a MAJOR downgrade)** plus `@lhci/cli@0.1.0`. That is not a fix —
it would gut the framework — so it must **not** be run. The root cause:

- The Next.js advisory range is `9.3.4-canary.0 – 16.3.0-preview.7`. The first
  non-vulnerable release is **16.3.0, which is currently preview-only**; npm will
  not select a prerelease to satisfy `^16.2.9`, so no stable in-range fix exists.
- `sharp ≥ 0.35.0` (the libvips-CVE fix) is not compatible with the current Next
  without a breaking change, so npm's solver can only satisfy it by downgrading
  Next.

**Decision:** stay on next 16.2.12 / postcss 8.5.25 (best available patches); do
**not** force. Accepted residual risk on `sharp` (used only in the build/image
pipeline, not on attacker-controlled runtime input) and the Next advisories
pending a stable 16.3.x.

**Revisit trigger:** when **Next.js 16.3.0 stable** ships, run
`npm install next@^16.3` + `npm audit --omit=dev`, expect zero high/critical, then
re-run `tsc`, Vitest, and `next build`.

> Note: a full `npm audit` (including dev dependencies) reports 27 findings
> (1 critical, 20 high, 4 moderate, 2 low). The critical/high items are in dev-only
> tooling (test/build chain), do not ship to production, and are gated behind the
> same `--force` MAJOR-downgrade problem; triage when the upstream fixes stabilise.

> Both audits should be wired as a CI job so dependency regressions surface on
> every change. `composer audit` needs registry access, which was unavailable in
> the local audit environment.

---

## A07 — Identification & Authentication Failures

| Control | Evidence |
|---------|----------|
| Login rate limiting: 5 failed attempts/min per email+IP | `tests/Feature/Auth/LoginRateLimitTest.php`: "allows up to 5 failed attempts per minute"; "blocks the 6th failed attempt within a minute with 429 and Retry-After"; "scopes the limit per email+IP, not globally" |
| Counter resets on success | `LoginRateLimitTest`: "clears the counter on a successful login" |
| Account lockout: 15 min after 10 cumulative failures | `LoginRateLimitTest`: "locks out for 15 minutes after 10 cumulative failures across burst windows" |
| MFA (TOTP) setup & verification with code validation | `tests/Feature/Auth/MfaTest.php`: enable/verify/disable each reject invalid codes; "verifies MFA code and returns new token" |
| `Retry-After` header on throttled responses | Asserted in `LoginRateLimitTest` |

---

## A08 — Software & Data Integrity Failures

| Control | Evidence |
|---------|----------|
| Idempotency keys on write endpoints | `Idempotency-Key` middleware; replay returns `was_duplicate: true` (CLAUDE.md §10) |
| Payroll `calculation_log` immutable after approval | `SecurityHardeningTest`: payroll immutability tests (see A04) |
| Audit log append-only | `SecurityHardeningTest`: "audit log entries cannot be deleted via API" |
| Offline attendance HMAC-signed | Phase 3 offline sync (S18) |

---

## A09 — Security Logging & Monitoring Failures

| Control | Evidence |
|---------|----------|
| Audit log written for sensitive operations | `SecurityHardeningTest`: "audit log is written for key operations" |
| Audit log cannot be tampered with | `SecurityHardeningTest`: "audit log entries cannot be deleted via API" |
| Impersonation logged with `impersonated_by` | `tests/Feature/ImpersonationTest.php`: "actions taken during impersonation are tagged with impersonated_by" |
| Impersonation guardrails (blocked actions) | `ImpersonationTest`: impersonated session cannot change password, create API key, or delete resources; requires MFA; token expires in 30 min |

---

## A10 — Server-Side Request Forgery (SSRF)

| Control | Evidence |
|---------|----------|
| Webhook URLs reject loopback | `SecurityHardeningTest`: "webhook url rejects localhost addresses" |
| Webhook URLs reject private IP ranges | `SecurityHardeningTest`: "webhook url rejects private ip ranges" |
| Valid external URLs still accepted | `SecurityHardeningTest`: "webhook url accepts valid external urls" |
| No server-side fetch of user-controlled URLs | File access uses presigned MinIO URLs (Phase 7) |

---

## File Upload Safety (cross-cutting)

| Control | Evidence |
|---------|----------|
| Disallowed file types rejected | `SecurityHardeningTest`: "document upload rejects disallowed file types" |
| Magic-byte verification + EXIF strip | Phase 0 upload pipeline (CLAUDE.md §15) |

---

## Summary

| Category | Status |
|----------|--------|
| A01 Broken Access Control | ✅ Covered by automated tests |
| A02 Cryptographic Failures | ✅ Covered |
| A03 Injection | ✅ Covered |
| A04 Insecure Design | ✅ Covered |
| A05 Security Misconfiguration | ✅ Covered |
| A06 Vulnerable Components | ⚠️ Patched next→16.2.12 / postcss→8.5.25 (persisted). 3 high (next, postcss, sharp) have **no clean in-range fix** — patched Next is preview-only; `--force` only offers a MAJOR downgrade. Residual risk accepted; revisit on Next 16.3.0 stable. `composer audit` blocked by network (run in CI). |
| A07 Authentication Failures | ✅ Covered |
| A08 Data/Integrity Failures | ✅ Covered |
| A09 Logging & Monitoring | ✅ Covered |
| A10 SSRF | ✅ Covered |
