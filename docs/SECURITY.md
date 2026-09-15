# ETHR — Security Policy (v2.0)

## Security Principles

1. **Tenant isolation is the first line of defense.** A single cross-tenant data leak is a company-ending event.
2. **Defense in depth.** No single security control is trusted alone. Every layer has a backup.
3. **Least privilege by default.** Users get the minimum permissions needed. Deny by default.
4. **Audit everything sensitive.** If it touches money, PII, or access control, it goes in the immutable audit log.
5. **No security through obscurity.** All security measures must work even if the attacker knows the architecture.

---

## Tenant Isolation

### Application Layer

| Mechanism | Implementation |
|---|---|
| Hostname | `{tenant}.ethr.et` is the only tenant selector honoured in production. `X-Tenant` is accepted in local/testing only; `admin`, `api`, `www`, `app` are reserved and never resolve to a tenant |
| Request authorization | `EnsureUserBelongsToTenant` rejects a valid token used against another tenant's host (super admins exempt — they operate across tenants by design) |
| Database queries | `BelongsToTenant` trait adds `WHERE tenant_id = ?` via global scope; degrades to `WHERE 0 = 1` when no tenant is resolved |
| File storage | Single MinIO bucket, per-tenant **path prefix** `tenants/{public_id}/` applied by `FileStorageService`. Isolation is enforced in application code, not by bucket policy — a path built from unvalidated input would cross it |
| Cache keys | **No tenant prefix is applied.** Keys are collision-free because they are built from globally-unique surrogate ids (e.g. `custom_role_permissions:{id}`). A key derived from a tenant-local identifier would collide silently |
| Queue jobs | `tenant_id` serialized with every job, re-resolved on execution |
| API responses | JsonResource filters by tenant, no cross-tenant data |
| Encryption | Per-tenant encryption key derivation for sensitive fields |

### Automated Verification

**TenantIsolationTest — run by `./scripts/gates.sh`, by a person:**

> **There is no CI.** This section previously said "CI — every commit". No
> pipeline of any kind exists in this repository (`docs/audit/BASELINE.md` §15);
> the test is real and passing, but nothing runs it automatically. Corrected in
> Phase 1 rather than left asserting a control that does not exist. Restore the
> original wording when Phase 3 wires the pipeline.

1. Dynamically discovers all Eloquent models
2. Asserts non-global models have `tenant_id` column
3. Asserts non-global models use `BelongsToTenant` trait
4. Creates data for Tenant A, authenticates as Tenant B, asserts empty results
5. Verifies `DB::getQueryLog()` includes `tenant_id` WHERE clause

**PHPStan Rule:**
- Errors if migration adds `tenant_id` but model lacks `BelongsToTenant`
- Errors if `withoutGlobalScopes()` used without explicit `tenant_id` WHERE

**Global Model Exemptions (no tenant scope):**
- `Tenant`, `Plan`, `FeatureFlag` (when tenant_id is null), `SuperAdmin`

---

## Authentication

### Token Strategy

| Token | Lifetime | Storage | Rotation |
|---|---|---|---|
| Access token | 15 minutes | `access_token` httpOnly cookie, `path=/api`, `SameSite=Lax`, `Secure` in production, **no `Domain`** (host-only) | On refresh |
| Refresh token | 7 days | httpOnly, Secure, SameSite=Lax cookie | Rotated on use (old revoked) |
| MFA token | 5 minutes | Response body | Single use |
| OTP code | 5 minutes | Server-side (Argon2id hash) | Single use |
| API key | Configurable (or none) | Displayed once on creation | Manual revocation |

The access token lives in an httpOnly cookie rather than a JavaScript variable, which this
table previously described. The SPA sends **no** `Authorization` header at all
(`src/api/client.ts` uses `withCredentials`); `AuthenticateFromCookie` promotes the cookie to
a bearer token on the way in. This resists XSS better than an in-memory token, at the cost of
needing CSRF protection — which Sanctum's stateful middleware provides.

Two consequences worth stating, because both have caused defects:

- The cookie is **excluded from cookie encryption** (`bootstrap/app.php`). `AuthenticateFromCookie`
  runs at position 1 of the api group and `EncryptCookies` only at position 8, so an encrypted
  value would reach the middleware as ciphertext and never authenticate. The value is an opaque
  Sanctum token verified by hashed lookup, so a forged one simply fails.
- The cookie is **host-only**. Any flow that moves a browser between hosts — impersonation,
  apex login — must hand the session over explicitly rather than assume the cookie follows.

### Login Security

| Control | Threshold | Action |
|---|---|---|
| Rate limit | 5 attempts / min / email / IP | 429 with Retry-After |
| Account lockout | 10 consecutive failures | 15-min cooldown, notify tenant admin |
| MFA enforcement | Tenant policy: optional / required / disabled | TOTP required on login if enabled |
| Trusted devices | 30-day cookie | Skip MFA for trusted device |
| Session listing | Unlimited | User can view and revoke sessions |

### Password Policy (Configurable Per Tenant)

| Setting | Default | Range |
|---|---|---|
| Minimum length | 8 | 8-128 |
| Require uppercase | true | boolean |
| Require number | true | boolean |
| Require special char | false | boolean |
| Password expiry | disabled | 0-365 days |

---

## Authorization

### Permission-Based RBAC

All authorization uses `$user->hasPermission('module.action')`. **Never compare role strings directly** (`$user->role === 'admin'` is banned).

### Permission Resolution

```
1. User → user_roles pivot → role(s)
2. Role → role_permissions pivot → permission(s)
3. Union of all permissions from all roles
4. Cached in Redis: user:{id}:permissions (15-min TTL)
5. Policy calls hasPermission() against cached set
```

### Principle of Least Privilege

- New employees get `employee` role only
- Custom roles start with zero permissions
- System roles have sensible defaults but cannot be weakened below minimum
- API keys have explicit ability scoping (read-only, module-specific)

---

## Data Encryption

### At Rest

| Data | Method | Key Management |
|---|---|---|
| Bank account numbers | AES-256-CBC (Laravel Crypt) | Per-tenant derived key |
| Tax ID (TIN) | AES-256-CBC (Laravel Crypt) | Per-tenant derived key |
| TOTP secrets | AES-256-CBC (Laravel Crypt) | Per-tenant derived key |
| Device passwords | AES-256-CBC (Laravel Crypt) | Per-tenant derived key |
| Passwords | bcrypt (60 rounds) | N/A (one-way hash) |
| OTP codes | Argon2id | N/A (one-way hash) |
| Sanctum tokens | SHA-256 | N/A (one-way hash) |

### In Transit

- TLS 1.2+ enforced (HTTP redirects to HTTPS)
- HSTS header: `max-age=31536000; includeSubDomains`
- MinIO presigned URLs: HTTPS only, 5-minute expiry

### What Is Never Stored

- Plain-text passwords
- Plain-text OTP codes
- Plain-text Sanctum tokens
- Full credit card numbers (no payment processing in v1.0)
- GPS coordinates of employees' homes (only workplace GPS for attendance)

---

## Input Validation

### Server-Side (FormRequest)

Every endpoint has a dedicated FormRequest class. No inline `$request->validate()`.

| Validation | Method |
|---|---|
| SQL injection | Eloquent ORM only. No raw SQL without parameterized bindings. |
| XSS | React auto-escapes. No `dangerouslySetInnerHTML`. |
| Mass assignment | Explicit `$fillable` on every model. |
| CSRF | Sanctum token-based (SPA mode). |
| File uploads | Type whitelist + magic byte verification + EXIF stripping. |
| Email | RFC-compliant validation. |
| Phone | Ethiopian format validation (+251...). |
| Amounts | Integer validation (no float/string currency input). |

### File Upload Security

1. Client-side: file type and size check before upload
2. Server presign: validate content-type against whitelist
3. Server confirm: download file from MinIO temporarily
4. Verify magic bytes match content-type:
   - JPEG: `FF D8 FF`
   - PNG: `89 50 4E 47`
   - PDF: `25 50 44 46`
   - GIF: `47 49 46`
5. Strip EXIF metadata from images (GPS, device info)
6. CSV: verify valid UTF-8, scan for embedded scripts/formulas
7. Re-upload cleaned file
8. Size limits enforced: photos 2MB, documents 10MB, selfies 500KB, CSV 50MB

---

## Rate Limiting

| Endpoint Category | Limit | Scope |
|---|---|---|
| `POST /auth/login` | 5/min | Per IP |
| `POST /auth/otp/request` | 3/min | Per phone |
| `POST /payroll/process` | 2/hour | Per tenant |
| `POST /employees/import/*` | 5/hour | Per tenant |
| `GET /dashboard/*` | 30/min | Per user |
| `POST /webhooks/*/test` | 10/hour | Per tenant |
| All writes (trial) | 60/min | Per tenant |
| All writes (paid) | 300/min | Per tenant |
| All reads (trial) | 120/min | Per tenant |
| All reads (paid) | 600/min | Per tenant |
| SMS sending | 5/day | Per user |

All throttled responses include `Retry-After` header.

---

## Webhook Security

### HMAC Signing

Every webhook delivery includes:
- `X-ETHR-Signature: sha256={hmac}` — HMAC-SHA256 of payload JSON using webhook secret
- `X-ETHR-Event: {event_name}`
- `X-ETHR-Delivery-ID: {uuid}`
- `X-ETHR-Timestamp: {iso8601}`

Recipients verify: `hmac_sha256(secret, payload_json) === received_signature`

### SSRF Prevention

Before registration AND before every delivery:

1. Parse URL — reject non-HTTPS (except localhost in development)
2. Resolve hostname to IP address
3. **Block private IP ranges:**
   - `10.0.0.0/8`
   - `172.16.0.0/12`
   - `192.168.0.0/16`
   - `127.0.0.0/8`
   - `169.254.0.0/16`
4. **Block IPv6 private:**
   - `::1`
   - `fc00::/7`
   - `fe80::/10`
5. **Block cloud metadata:** `169.254.169.254`
6. **Block internal hostnames:** `localhost`, `*.ethr.et`, `*.internal`, `*.local`
7. Re-validate on every delivery (DNS records can change)

### Failure Handling

- Timeout: 10 seconds per attempt
- Retry: 1 min → 5 min → 30 min → 2 hours → 24 hours (5 attempts)
- Auto-disable after 10 consecutive failures
- Admin notification on auto-disable

---

## Impersonation

### Guardrails

| Control | Implementation |
|---|---|
| Entry | Requires MFA verification before starting |
| Duration | 30-minute session, non-renewable |
| Audit | Every action logged with `impersonated_by` field |
| Banner | Persistent red/amber bar at page top, cannot be dismissed |

### Blocked Actions During Impersonation

- Password changes (own or any user)
- MFA changes (enable/disable/setup)
- API key creation or revocation
- Subscription/plan changes
- Data deletion (employees, payroll, attendance)
- Settings that affect billing
- Webhook creation (SSRF risk from super admin context)
- Role/permission changes

---

## Security Headers (Nginx)

```nginx
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self' wss:; font-src 'self';" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(self), microphone=(), geolocation=(self)" always;
```

### Removed Headers

- `X-Powered-By` (PHP version exposure)
- `Server` (Nginx version exposure)
- Laravel-specific debug headers in production

---

## OWASP Top 10 Checklist

| # | Category | Controls | Verification |
|---|---|---|---|
| A01 | Broken Access Control | BelongsToTenant, Policies with hasPermission(), TenantIsolationTest | CI test suite, cross-tenant sweep |
| A02 | Cryptographic Failures | AES-256 encrypted fields, TLS enforced, no secrets in logs | Grep audit, deployment checklist |
| A03 | Injection | Eloquent ORM only, parameterized bindings, no raw SQL | Codebase grep, PHPStan rule |
| A04 | Insecure Design | State machine enforcement, approval chain validation | Feature tests per workflow |
| A05 | Security Misconfiguration | APP_DEBUG=false, secure headers, no default creds | Deployment checklist, Pest test |
| A06 | Vulnerable Components | composer audit + npm audit | **Not automated — no CI exists.** Planned for Phase 2/3 |
| A07 | Authentication Failures | Rate limiting, lockout, MFA, token rotation | Pest test suite |
| A08 | Data Integrity | HMAC on offline attendance, idempotency keys, immutable audit log | Pest assertions |
| A09 | Security Logging | Audit log on all sensitive ops, failed login tracking | Audit log completeness test |
| A10 | SSRF | Webhook URL validation, no user-controlled URL fetching | Pest: test all private IP ranges |

---

## Incident Response

### Detection

- Failed login monitoring (> 50 failures/hour across all tenants → alert)
- Cross-tenant access attempts (any occurrence → alert)
- Audit log anomalies (unusual action patterns)
- Queue failure spikes
- Device offline clusters (> 3 devices same tenant → alert)

### Response Procedures

1. **Data breach suspicion:** Immediately suspend affected tenant, notify super admin, preserve audit logs
2. **Account compromise:** Lock account, revoke all tokens, force password reset, enable MFA
3. **Tenant isolation failure:** Emergency patch, rotate affected tenant encryption keys, full audit review
4. **SSRF attempt:** Block source IP, review webhook configuration, disable affected webhook

### Data Retention

| Data | Retention | Deletion Method |
|---|---|---|
| Audit logs | Configurable (default 365 days) | Hard delete after retention period |
| Login histories | 90 days | Hard delete |
| Notifications | 90 days | Hard delete |
| Webhook deliveries | 30 days | Hard delete |
| API key logs | 30 days | Hard delete |
| Failed syncs | Until resolved + 7 days | Hard delete |
| Import staging | 7 days | Hard delete |
| Export files | 30 days | Hard delete from MinIO |
