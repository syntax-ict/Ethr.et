# Phase 9 — Production Readiness

## Prerequisites
- All phases (0-8) complete

## Objective
Harden the platform for production: security audit, performance optimization, accessibility, end-to-end testing, deployment configuration, and documentation.

---

## S35 — Security Hardening

### Backend
- [ ] OWASP Top 10 audit:
  - A01 Broken Access Control: verify every endpoint has Policy/Gate, test cross-tenant access on all modules
  - A02 Cryptographic Failures: verify encrypted fields (bank details, TIN, TOTP secrets), TLS enforced
  - A03 Injection: verify no raw SQL queries (only Eloquent), parameterized queries for any raw DB usage
  - A04 Insecure Design: review approval workflows for bypass, verify state machine transitions
  - A05 Security Misconfiguration: remove debug mode, remove default credentials, set secure headers
  - A06 Vulnerable Components: run `composer audit`, `npm audit`, update any vulnerable packages
  - A07 Authentication Failures: rate limit login attempts (5/min per IP), account lockout after 10 failures
  - A08 Data Integrity: verify HMAC on offline attendance, verify idempotency keys
  - A09 Security Logging: verify audit log covers all sensitive operations
  - A10 SSRF: validate webhook URLs (no internal IPs)
- [ ] Security headers (Nginx):
  - `X-Frame-Options: DENY`
  - `X-Content-Type-Options: nosniff`
  - `X-XSS-Protection: 1; mode=block`
  - `Strict-Transport-Security: max-age=31536000; includeSubDomains`
  - `Content-Security-Policy: default-src 'self'; ...`
  - `Referrer-Policy: strict-origin-when-cross-origin`
- [ ] CORS: restrict to known origins (tenant subdomains)
- [ ] Rate limiting review: ensure all write endpoints rate-limited
- [ ] Login attempt rate limiting: 5 attempts/minute per email per IP, lockout after 10 (15-min cooldown)
- [ ] File upload validation: re-verify content type (not just extension), virus scan (ClamAV if available)
- [ ] SQL injection testing: automated scan with SQLMap or manual review
- [ ] Dependency audit: `composer audit` + `npm audit` -> fix all critical/high
- [ ] Secrets management: no secrets in code, all from environment variables
- [ ] Cookie security: `httpOnly`, `Secure`, `SameSite=Lax` on all cookies

### Frontend
- [ ] CSP compliance: no inline scripts, no `eval()`
- [ ] XSS prevention: verify no `dangerouslySetInnerHTML` usage
- [ ] Sensitive data handling: no PII in localStorage, no tokens in URL params
- [ ] Form security: CSRF tokens on all forms

### Tests
- [ ] Cross-tenant access attempts on all modules (Pest: comprehensive sweep)
- [ ] Login rate limiting (Pest)
- [ ] Account lockout (Pest)
- [ ] Webhook URL validation (no internal IPs) (Pest)
- [ ] File upload content-type spoofing (Pest)
- [ ] Security header verification (Pest)

### Exit Criteria
- OWASP top 10 checklist complete
- Security headers set
- Rate limiting on all write endpoints
- Dependency vulnerabilities resolved
- No secrets in code

---

## S36 — Performance Optimization

### Backend
- [ ] Database optimization:
  - Review all queries with `EXPLAIN` — ensure index usage
  - Add missing indexes (based on slow query log)
  - N+1 query detection: run `barryvdh/laravel-debugbar` in dev, fix eager loading
  - Large table queries: verify pagination (no `->get()` on unbounded queries)
  - Employee list at 1000+ rows: < 200ms
  - Attendance list (30 days): < 300ms
  - Payroll processing (500 employees): < 30s
- [ ] Caching optimization:
  - Review all Redis cache usage (TTL, invalidation)
  - Cache tenant configuration
  - Cache permission lookups
  - Cache dashboard queries (5-min TTL)
  - Cache org structure (30-min TTL)
- [ ] Queue optimization:
  - Review all job timeouts
  - Ensure long jobs (payroll, export) on dedicated queues
  - Failed job alerting
  - Horizon tuning (worker counts per queue)
- [ ] API response optimization:
  - Response compression (gzip)
  - Select only needed columns (`->select()`)
  - Eager load only needed relationships
  - Pagination enforced (max per_page = 100)
- [ ] File storage optimization:
  - Image compression on upload (profile photos, selfies)
  - Thumbnail generation for images
  - Presigned URL caching
- [ ] PHP-FPM tuning: adjust `pm.max_children`, `pm.start_servers` for production load

### Frontend
- [ ] Bundle optimization:
  - Code splitting per route (Next.js dynamic imports)
  - Lazy load heavy components (charts, report builder)
  - Analyze bundle with `next-bundle-analyzer`
  - Tree shaking verification
- [ ] Image optimization:
  - `next/image` for all images
  - WebP format where supported
  - Lazy loading for below-fold images
- [ ] Runtime optimization:
  - Memoize expensive computations (`useMemo`, `useCallback` where needed)
  - Virtual scrolling for long lists (employee list, attendance records)
  - Debounce search inputs (already done)
  - Skeleton screens load in < 200ms
- [ ] Cache optimization:
  - TanStack Query stale times tuned per resource
  - Service worker caches static assets
  - Employee directory cached in IndexedDB
- [ ] Performance measurement:
  - Lighthouse score: Performance > 80, Accessibility > 90
  - Core Web Vitals: LCP < 2.5s, FID < 100ms, CLS < 0.1
  - Measure TTFB, FCP, LCP for key pages

### Tests
- [ ] Load test: 100 concurrent users, key endpoints < 200ms p95
- [ ] Payroll processing: 500 employees < 30s
- [ ] Employee list: 1000 rows < 500ms
- [ ] Dashboard load: < 1.5s
- [ ] Lighthouse audit: Performance > 80

### Exit Criteria
- All performance targets met (see CLAUDE.md)
- No N+1 queries
- Caching effective (< 5ms for cached queries)
- Bundle size optimized
- Lighthouse Performance > 80

---

## S37 — Accessibility & Responsive Validation

### Frontend
- [ ] WCAG 2.1 AA audit:
  - All interactive elements keyboard accessible
  - Tab order logical on every page
  - Focus visible on all focusable elements
  - `aria-label` on icon-only buttons
  - `aria-live` regions for dynamic content (notifications, toasts)
  - Form labels associated with inputs (`htmlFor`)
  - Error messages announced to screen readers
  - Color contrast: 4.5:1 for normal text, 3:1 for large text
  - Images have alt text
  - Charts have text alternatives (data tables or descriptions)
  - Skip navigation link
  - Language attribute on `<html>` tag (switch with locale)
- [ ] Responsive validation sweep (every page):
  - 375px (iPhone SE)
  - 390px (iPhone 14)
  - 768px (iPad)
  - 1024px (iPad landscape)
  - 1280px (laptop)
  - 1440px+ (desktop)
  - Each page: verify layout, no overflow, readable text, touch targets 44px+, no horizontal scroll
- [ ] Dark mode validation sweep (every page):
  - All text readable
  - Charts visible
  - Status badges visible
  - Form inputs visible
  - No hardcoded colors (all CSS variables)
- [ ] RTL readiness: verify layout doesn't break (even though v1.0 languages are LTR)
- [ ] PWA finalization:
  - Service worker caches all static assets
  - Offline page (for non-cached routes)
  - App manifest with icons (all sizes)
  - Install prompt on mobile
  - Splash screen
  - Offline attendance queue working
  - Background sync working
  - Push notification permission request flow

### Tests
- [ ] Axe accessibility audit on key pages (automated)
- [ ] Keyboard navigation test (manual or Playwright)
- [ ] Screen reader test on key flows (manual)
- [ ] Responsive screenshots at each breakpoint (Playwright)
- [ ] PWA install and offline behavior (manual)

### Exit Criteria
- WCAG 2.1 AA compliance on all pages
- Every page responsive at all breakpoints
- Dark mode complete
- PWA installable and functional offline
- Keyboard navigation works throughout

---

## S38 — E2E Testing, Deployment & Documentation

### E2E Testing (Playwright)
- [ ] Test environment: Docker Compose with test database
- [ ] Critical path test suites:
  - **Signup flow**: visit landing -> register -> verify email -> setup wizard -> dashboard
  - **Employee flow**: login as HR -> create employee -> view -> edit -> transition status
  - **Attendance flow**: login as employee -> check in -> check out -> view attendance
  - **Leave flow**: login as employee -> apply leave -> login as manager -> approve -> verify balance
  - **Payroll flow**: login as finance -> run payroll -> review -> approve -> view payslip
  - **Admin flow**: login as super admin -> view tenants -> impersonate -> exit
- [ ] Responsive tests: run key flows at 375px and 1280px
- [ ] CI integration: Playwright runs on push to main branch

### Deployment
- [ ] Production Docker Compose:
  - `docker-compose.prod.yml` with production configs
  - Resource limits per service
  - Volume mounts for persistent data (MariaDB, Redis, MinIO)
  - Log rotation
  - Healthcheck for each service
- [ ] Nginx production config:
  - SSL/TLS (Let's Encrypt via certbot)
  - HTTP/2
  - Gzip compression
  - Static asset caching (1 year for hashed filenames)
  - Wildcard subdomain routing
  - Rate limiting
  - Security headers
- [ ] Supervisor config:
  - Queue workers (per queue)
  - Scheduler
  - Reverb WebSocket server
  - Auto-restart on failure
- [ ] Deployment scripts:
  - `scripts/deploy.sh` — pull, build, migrate, restart
  - `scripts/rollback.sh` — revert to previous version
  - `scripts/backup.sh` — database + MinIO backup to external storage
  - `scripts/restore.sh` — restore from backup
  - `scripts/seed.sh` — seed demo tenant with sample data
- [ ] Environment files:
  - `.env.example` — complete, documented
  - `.env.production` template — production values
- [ ] Database migrations: verify all run cleanly on empty database
- [ ] Demo tenant seeder: create sample tenant with realistic data (100 employees, 3 months attendance, 2 months payroll)
- [ ] Health check dashboard: `GET /api/health` returns all service statuses
- [ ] Monitoring setup:
  - Application logs -> centralized log file (Supervisor)
  - Error alerting: email on 500 errors (configurable)
  - Queue monitoring via Horizon dashboard
  - Database slow query log enabled

### Documentation
- [ ] Admin guide (for tenant admins):
  - Getting started
  - Organization setup
  - Employee management
  - Attendance configuration
  - Leave policies
  - Payroll setup and processing
  - Reports
  - Settings reference
- [ ] User guide (for employees):
  - Logging in
  - Checking attendance
  - Applying for leave
  - Viewing payslips
  - Profile management
  - Mobile/PWA usage
- [ ] API documentation: OpenAPI spec + interactive docs (from Phase 7)
- [ ] Deployment guide:
  - System requirements
  - Docker installation
  - Configuration
  - First-time setup
  - SSL setup
  - Backup and restore
  - Updating
  - Troubleshooting
- [ ] All documentation bilingual (EN + AM) or EN with AM as follow-up

### Tests
- [ ] All Playwright E2E suites passing
- [ ] `docker compose -f docker-compose.prod.yml up` boots successfully
- [ ] Fresh database migration succeeds
- [ ] Demo seeder creates valid sample data
- [ ] Backup and restore scripts work
- [ ] Health endpoint reports all services

### Exit Criteria
- Playwright E2E tests pass for all critical paths
- Production Docker config working
- SSL/TLS with Let's Encrypt
- Deployment scripts tested
- Backup/restore verified
- Documentation complete
- Demo tenant seeded
- Platform ready for first production deployment

---

## Phase 9 Exit Criteria (Platform Launch Readiness)

- [ ] OWASP top 10 security audit complete
- [ ] All performance targets met
- [ ] WCAG 2.1 AA accessibility complete
- [ ] Every page responsive and dark-mode ready
- [ ] PWA installable with offline attendance
- [ ] Playwright E2E tests for critical paths
- [ ] Production Docker Compose configuration
- [ ] Nginx with SSL, HTTP/2, security headers
- [ ] Deployment, rollback, backup scripts
- [ ] Demo tenant with sample data
- [ ] Admin guide, user guide, deployment guide, API docs
- [ ] All Pest + Vitest + Playwright tests passing
- [ ] **Platform is production-ready**
