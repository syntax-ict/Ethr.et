# ETHR v1.0 Security Audit Checklist

**Status:** ✅ Enhanced with automated middleware and exception handling

---

## ✅ Completed Security Enhancements

### Middleware Layer
- ✅ **SecurityHeaders.php** — RFC-compliant headers
  - `X-Frame-Options: DENY` — Prevent clickjacking
  - `X-Content-Type-Options: nosniff` — Prevent MIME sniffing
  - `X-XSS-Protection: 1; mode=block` — Enable XSS protection
  - `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` — Force HTTPS
  - `Content-Security-Policy: default-src 'self'` — Strict CSP
  - `Referrer-Policy: strict-origin-when-cross-origin` — Control referrer info
  - `Permissions-Policy: ...` — Disable unnecessary features

- ✅ **RateLimitLoginAttempts.php** — Login attempt throttling
  - 5 attempts per minute per email:IP combination
  - RFC-7807 error response on rate limit

- ✅ **Enhanced Exception Handling**
  - Validation exceptions → 422 with detailed error objects
  - Authentication exceptions → 401
  - Authorization exceptions → 403
  - HTTP exceptions → appropriate status codes

### Code Changes
- ✅ Updated `bootstrap/app.php`:
  - Added SecurityHeaders middleware (global)
  - Added RateLimitLoginAttempts middleware (global)
  - Added exception renderers for all exception types

---

## 🔄 In-Progress Security Items

### OWASP Top 10 Audit

- [ ] **A01 Broken Access Control**
  - Verify: Every endpoint has Policy or Gate
  - Test: Cross-tenant access attempts
  - Status: Endpoints exist, needs comprehensive testing

- [ ] **A02 Cryptographic Failures**
  - Verify: Sensitive fields encrypted (bank details, TIN, TOTP secrets)
  - Verify: TLS enforced (HSTS header ✅)
  - Verify: Passwords hashed with bcrypt (Laravel default)

- [ ] **A03 Injection**
  - Verify: No raw SQL queries (Eloquent only)
  - Test: SQLMap scan on parameters
  - Status: Code review needed

- [ ] **A04 Insecure Design**
  - Review: Approval workflow bypass scenarios
  - Review: State machine transition validation
  - Test: Workflow edge cases

- [ ] **A05 Security Misconfiguration**
  - Verify: APP_DEBUG=false in production
  - Verify: No default credentials
  - Verify: Security headers set ✅
  - Verify: CORS properly restricted

- [ ] **A06 Vulnerable Components**
  - Run: `composer audit`
  - Run: `npm audit`
  - Fix: All critical/high severity issues

- [ ] **A07 Authentication Failures**
  - Rate limiting ✅ (in LoginRequest + middleware)
  - Account lockout: 10 failures → 15-min cooldown
  - Session timeout: Configurable
  - MFA support ✅

- [ ] **A08 Data Integrity**
  - Verify: HMAC on offline attendance
  - Verify: Idempotency keys required
  - Test: Replay attack scenarios

- [ ] **A09 Security Logging**
  - Audit log covers: Login, data changes, approvals, exports
  - Verify: Sensitive data not logged

- [ ] **A10 SSRF**
  - Validate: Webhook URLs (no 127.0.0.1, ::1, 169.254.*.*, 10.*.*.*, etc.)
  - Test: Malicious webhook URLs

---

## 🔧 Remaining Security Tasks

### Data Security
- [ ] Encrypt sensitive fields at rest:
  - Bank account details
  - Tax ID numbers
  - TOTP secrets
  - Use Laravel's encryption: `Encrypted` cast

- [ ] SSL/TLS Configuration
  - [ ] Nginx: Setup Let's Encrypt with certbot
  - [ ] Force HTTPS redirect
  - [ ] HSTS preload (already in headers ✅)

### API Security
- [ ] CORS Configuration
  - Restrict to known tenant subdomains
  - No wildcard origins

- [ ] Rate Limiting Review
  - Write endpoints: 60 req/min per IP
  - Read endpoints: 300 req/min per IP
  - Login: 5 attempts/min per email:IP

- [ ] File Upload Security
  - Validate MIME type (not just extension)
  - Scan for viruses if possible (ClamAV)
  - Store outside web root

### Secrets Management
- [ ] Environment Variables
  - APP_KEY in .env (never in code)
  - Database credentials in .env
  - Redis password in .env
  - MinIO keys in .env
  - SMS API keys in .env

- [ ] .env.example
  - Updated with all required variables
  - No real values

### Dependency Audit
```bash
# Backend
composer audit

# Frontend
npm audit
```

---

## Testing Strategy

### Security Testing

**Unit Tests:**
```bash
# Test LoginAttemptService
php artisan test --filter LoginAttemptService

# Test rate limiting
php artisan test --filter RateLimit
```

**Feature Tests:**
```bash
# Test cross-tenant access denial
php artisan test --filter CrossTenant

# Test authentication/authorization
php artisan test --filter Auth

# Test file upload validation
php artisan test --filter FileUpload
```

**Manual Testing:**
```bash
# 1. Attempt SQLi on login endpoint
curl -X POST http://localhost/api/v1/auth/login \
  -d 'email=user@test.com\' OR 1=1 --&password=pass'

# 2. Test rate limiting
for i in {1..10}; do
  curl -X POST http://localhost/api/v1/auth/login \
    -d 'email=user@test.com&password=wrong'
done

# 3. Verify security headers
curl -I http://localhost

# 4. Test cross-tenant access
curl http://localhost/api/v1/employees \
  -H "Authorization: Bearer token-for-tenant-a" \
  -H "X-Tenant: tenant-b"
```

---

## Production Checklist

Before deploying to production:

- [ ] APP_DEBUG=false
- [ ] APP_ENV=production
- [ ] SANCTUM_STATEFUL_DOMAINS configured for production domain
- [ ] CORS_ALLOWED_ORIGINS restricted
- [ ] SSL certificates installed (Let's Encrypt)
- [ ] Database backed up
- [ ] Redis password configured
- [ ] MinIO SSL enabled
- [ ] SMTP configured for production
- [ ] SMS credentials configured
- [ ] Firewall configured (UFW):
  - [ ] Port 80 (HTTP → HTTPS redirect)
  - [ ] Port 443 (HTTPS)
  - [ ] Port 22 (SSH, restricted to known IPs)
- [ ] Fail2ban configured for brute force protection
- [ ] Log rotation configured
- [ ] Monitoring alerts set up

---

## Security Headers Summary

All responses include:

```
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-...'; ...
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: accelerometer=(), camera=(), geolocation=(), ...
```

---

## References

- OWASP Top 10 2021: https://owasp.org/Top10/
- OWASP API Security: https://owasp.org/www-project-api-security/
- RFC 7807 Problem Details: https://tools.ietf.org/html/rfc7807
- MDN Security Headers: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers
- NIST Cybersecurity Framework: https://www.nist.gov/cyberframework/
