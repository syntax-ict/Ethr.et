# Phase 9 — Production Readiness (v2.0)

> **Design record — not a progress tracker.**
> The `- [ ]` checkboxes below are the original up-front specification and were
> never maintained against the code. They under-report reality badly: several
> phases read as 0% complete while the features they describe are live and
> covered by tests. **Do not use them to judge what is done.**
>
> The live, code-grounded status is [`ENTERPRISE_ROADMAP.md`](../ENTERPRISE_ROADMAP.md),
> and the measured state of the codebase is [`audit/BASELINE.md`](../audit/BASELINE.md).
> Per the project rule, the source of truth is the code — verify against it, not
> against this file.
>
> This header previously cited `ETHR_AUDIT.md` and `ETHR_AUDIT_2026-08-14.md`.
> Neither is in the tree and, as far as git history shows, neither ever was; the
> roadmap link was also written as a sibling path and resolved nowhere from this
> directory. Corrected in Phase 1 — a header whose only job is to route readers
> to current truth was routing them to nothing.
>
> Keep this document for its design intent: scope, data model, and acceptance
> criteria, which remain accurate and useful.

## Prerequisites
- All phases (0-8) complete

## Objective
Harden the platform for production: OWASP security audit, performance optimization with automated benchmarks, WCAG 2.1 AA accessibility, Amharic typography validation, E2E testing, deployment configuration, and documentation.

---

## Progress / Gap Closure Log

### 2026-07-30 — S37 File optimization ✅

Built while fixing the broken mobile selfie upload (see PHASE_03 GAP-FIX-10) — the same
image pipeline serves both. `FileStorageService` now:

- **Compresses on upload.** Longest edge capped (1600px photos / 1080px selfies), then JPEG
  quality steps down until the object fits its budget — 500 KB for photos, 200 KB for selfies.
  Falls back to shrinking the canvas rather than dropping below q45, where artefacts show.
  Non-photo images (transparent PNGs, GIFs) keep their format and are only EXIF-stripped.
- **Generates thumbnails** at 150px and 400px alongside every stored JPEG, at a deterministic
  `…/thumbs/{name}_{size}.jpg` key (`thumbnailPath()`) — no schema column needed.
- **Caches presigned URLs** for 5 minutes (bounded by the URL's own validity) instead of
  re-signing the same object on every request.

Fixed a GD handle leak in the process: the scaling helper replaced its image handle without
freeing the previous one, so a full-resolution photo exhausted a 128 MB PHP memory limit.

**Surfaced to clients (2026-07-30).** The thumbnails were generated but unreachable: every
resource returned only `photo_path`, an object key a browser cannot render, so employee photos
had never been displayed anywhere in the product — each avatar site fell back to initials.
`EmployeeResource`, `EmployeeSummaryResource`, `DirectoryResource`, `/profile` and `/auth/me`
now return `photo_url` + `photo_thumb_url` (signed, 5-min cached, null rather than throwing
when storage cannot sign) via the shared `ExposesPhotoUrls` trait. A new
`<EmployeeAvatar>` renders the thumbnail with a documented fallback chain — thumbnail →
full-size (for photos predating thumbnail generation) → initials — and replaces the initials
logic that had been copy-pasted across five components. Its initials helper splits on grapheme
boundaries, so Amharic syllables are no longer cut in half by code-unit indexing.

---

### 2026-07-30 — S38 Hardcoded colours eliminated ✅

**Gap:** 125 raw Tailwind palette classes (`bg-amber-50`, `dark:text-red-400`, …) remained
across 17 pages, violating convention #12 and the S38 dark-mode rule "no hardcoded colors".
They also had no High Contrast theme coverage at all — the `.high-contrast` palette swap
does not reach raw palette values, so those surfaces silently stayed low-contrast.

**Closed:**
- Added a **status soft-container token set** to `globals.css` — `{family}-soft`,
  `{family}-on-soft`, `{family}-edge` for `success`, `warning`, `destructive`, `info`,
  `brand`, `neutral` — defined in all three themes (Light / Dark / High Contrast) and
  bridged into Tailwind via `@theme`. Consumers no longer write `dark:` variants for status.
- Replaced all 125 raw classes plus the `text-white`-over-themed-background cases
  (now `*-foreground` / `text-text-inverse`, which invert correctly in dark mode).
- Re-keyed the KPI `color` props on the intelligence, overtime and device-dashboard pages
  from palette names (`amber`/`orange`/`purple`) to intent names (`warning`/`brand`/…);
  `amber` and `orange` had collapsed onto one token, making two KPI cards identical.
- Employee lifecycle dots: `probation` → `bg-brand-accent`, `retired` → `bg-primary` so
  every state stays visually distinct after the sweep.
- Guard test `src/test/semantic-color-tokens.test.ts` fails the suite if any raw palette
  class or redundant `dark:` semantic variant reappears.

**Verified:** measured contrast of every family × theme pair in the browser — all ≥ 5.7:1,
14 of 18 ≥ 7:1 (AAA). High Contrast `brand-on-soft` was darkened to `#4a3708` after the
initial `#b8860b` measured only ~3.5:1. Console clean; `tsc` and Vitest green.

---

### 2026-07-31 — S36 A06 dependency vuln + security-header evidence ✅

**Gap:** `composer audit` reported **6 advisories** (4 medium, 2 low) against the payslip
PDF renderer `dompdf/dompdf` v3.1.5 — including the Chroot Validation Bypass (CVE-2026-55554)
and a file-existence oracle — all fixed in 3.1.6. The A06 "Vulnerable Components" checklist
item was unclosed. Separately, the existing `SecurityHardeningTest` header assertion only
covered **4 of the 7** headers the `SecurityHeaders` middleware emits: HSTS, CSP, and
Permissions-Policy were unverified, so a regression that dropped them would pass CI.

**Closed:**
- Bumped `dompdf/dompdf` 3.1.5 → **3.1.6** (patch within the `barryvdh/laravel-dompdf ^3.1`
  wrapper's range — no wrapper change). `composer audit` now reports **no advisories**.
- Extended the security-header test to assert all seven: exact HSTS value, and the critical
  directives of CSP (`default-src 'none'`, `frame-ancestors 'none'`, `base-uri`, `form-action`)
  and Permissions-Policy (`microphone=()`, `camera=(self)`, `geolocation=(self)`, `payment=()`).
- **Verified:** `pest --filter="security headers|payslip|PayrollProcess"` → 36 passed
  (113 assertions), incl. payslip PDF download + voided-run watermark, confirming 3.1.6
  renders payslips unchanged. Frontend deps left untouched by design — a Next dev server was
  running, and npm install under it corrupts the SWC binary.

**Note (A06, still open):** the frontend `npm audit` could not run in this environment
(broken npm shim + running dev server). Re-run `npm audit --audit-level=high` on a clean box
before launch.

---

### 2026-07-31 — S39 Deployment-config verification ✅

**Verified against the S39 deployment checklist (found the config largely complete):**
- `docker compose -f docker-compose.prod.yml config` **parses cleanly** — all services carry
  resource limits, healthchecks, json-file log rotation, `restart: always`, and network
  isolation (`ethr-internal` is `internal: true`; only nginx is on the external network).
  Referenced build files (`api/Dockerfile.prod`, `docker/frontend/Dockerfile`) and the
  `php artisan health:check` command backing the api healthcheck all exist.
- `api/.env.production`: `APP_ENV=production`, `APP_DEBUG=false` (A05), `APP_KEY` empty with a
  `key:generate` comment, no baked secrets.
- Nginx prod config already had all 7 security headers, TLSv1.2/1.3, gzip, 1-year immutable
  static caching, wildcard `*.ethr.et`, three-tier rate limiting matching the CLAUDE.md policy,
  50M body limit, split proxy timeouts (payroll 120s via `@payroll_php`, else 30s),
  `server_tokens off`.

**Fixed (two real defects):**
- **HTTP/2 directive deprecation** — `listen 443 ssl http2;` is deprecated on the pinned
  `nginx:1.27-alpine` and warns on every start. Split into `listen 443 ssl;` + `http2 on;`.
- **Security headers dropped on static assets** — nginx `add_header` *replaces* (does not
  merge) inherited server-level headers, so the `expires`/`Cache-Control` static-asset block
  silently served JS/CSS without any security headers. Re-asserted `X-Content-Type-Options:
  nosniff` and HSTS there.

**Verified:** `nginx -t` on the edited config (sanitized upstreams + self-signed cert, in
`nginx:1.27-alpine`) → *syntax is ok, test is successful*, warning-free.

---

## S36 — Security Hardening

### Backend
- [ ] OWASP Top 10 audit:
  - **A01 Broken Access Control:**
    - Run TenantIsolationTest (exists from Phase 0) — verify every model
    - Verify every endpoint has Policy using `hasPermission()`
    - Test cross-tenant access on ALL modules (comprehensive sweep — 50+ endpoints)
    - Test role escalation attempts (employee trying admin endpoints)
    - Test IDOR (using other tenant's public_id in URL)
  - **A02 Cryptographic Failures:**
    - Verify encrypted fields: bank_account_number, tin, totp_secret (AES-256)
    - Verify TLS enforced (HTTP → HTTPS redirect)
    - Verify no sensitive data in logs
    - Verify no sensitive data in error responses
    - Verify no sensitive data in URL parameters
  - **A03 Injection:**
    - Verify no raw SQL (grep codebase for `DB::raw`, `DB::select`, `DB::statement`)
    - Any raw SQL must use parameterized bindings
    - Verify no shell command injection
    - Test SQL injection on search endpoints (FULLTEXT)
  - **A04 Insecure Design:**
    - Review approval workflows for bypass (skip steps, approve own request)
    - Verify employee lifecycle state machine cannot be bypassed
    - Verify payroll cannot be modified after approval (except void)
    - Verify attendance corrections cannot bypass approval chain
  - **A05 Security Misconfiguration:**
    - `APP_DEBUG=false` in production
    - Remove default credentials from all configs
    - Remove test routes (if any) from production
    - Set secure headers in Nginx
    - Disable directory listing
    - Remove version headers (PHP, Nginx, Laravel)
  - **A06 Vulnerable Components:**
    - [x] Run `composer audit` — fix all critical/high (dompdf → 3.1.6, audit clean, 2026-07-31)
    - Run `npm audit` — fix all critical/high (blocked locally; re-run on clean box)
    - Verify no deprecated packages in use
    - Document any known vulnerabilities with mitigations
  - **A07 Authentication Failures:**
    - Verify login rate limiting: 5/min per email per IP
    - Verify account lockout after 10 failures (15-min cooldown)
    - Verify MFA enforcement when tenant policy is "required"
    - Verify token expiry: 15-min access, 7-day refresh
    - Verify refresh token rotation (old token revoked)
    - Verify OTP expiry: 5 minutes
    - Verify trusted device expiry: 30 days
  - **A08 Data Integrity:**
    - Verify HMAC on offline attendance records
    - Verify idempotency keys on all write endpoints
    - Verify payroll calculation_log is immutable after approval
    - Verify audit_log is append-only (no UPDATE/DELETE)
  - **A09 Security Logging:**
    - Verify audit log covers: login, logout, employee CRUD, payroll, attendance, settings changes, impersonation
    - Verify failed login attempts logged
    - Verify impersonation logged with `impersonated_by`
  - **A10 SSRF:**
    - Verify webhook URL validation (no internal IPs) — test all private ranges
    - Verify file upload URLs are presigned (no user-controlled URLs fetched by server)
- [ ] Security headers (Nginx — verify all set):
  ```
  X-Frame-Options: DENY
  X-Content-Type-Options: nosniff
  X-XSS-Protection: 1; mode=block
  Strict-Transport-Security: max-age=31536000; includeSubDomains
  Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self' wss:; font-src 'self';
  Referrer-Policy: strict-origin-when-cross-origin
  Permissions-Policy: camera=(self), microphone=(), geolocation=(self)
  ```
- [ ] CORS: restrict to known origins (tenant subdomains only, no wildcards in production)
- [ ] Cookie security: `httpOnly=true`, `Secure=true`, `SameSite=Lax` on all cookies
- [ ] File upload re-verification: content-type, magic bytes, size, EXIF stripping (verify Phase 0 implementation)
- [ ] Secrets audit: scan codebase for hardcoded secrets, API keys, passwords — all must come from env vars
- [ ] Rate limiting audit: verify all write endpoints have rate limits per CLAUDE.md policy

### Frontend
- [ ] CSP compliance: no inline scripts, no `eval()`, no `Function()` constructor
- [ ] XSS prevention: grep for `dangerouslySetInnerHTML` — must be zero occurrences
- [ ] Sensitive data: verify no PII in localStorage, sessionStorage, or URL params
- [ ] Token handling: access token in memory only, refresh token in httpOnly cookie only
- [ ] Form security: CSRF tokens on all state-changing requests

### Tests
- [ ] Cross-tenant access sweep — test ALL controllers (50+ endpoints) (Pest)
- [ ] Login rate limiting at threshold (Pest)
- [ ] Account lockout at threshold (Pest)
- [ ] MFA enforcement when policy is "required" (Pest)
- [ ] Webhook URL validation — all private IP ranges rejected (Pest)
- [ ] File upload content-type spoofing — magic bytes mismatch rejected (Pest)
- [x] Security headers present in response (Pest) — all 7 asserted, 2026-07-31
- [ ] Impersonation guardrails — blocked actions list (Pest)
- [ ] Audit log completeness — verify all sensitive operations logged (Pest)
- [ ] IDOR prevention — access with wrong tenant's public_id denied (Pest)
- [ ] Approval chain bypass — cannot approve own request (Pest)
- [ ] Payroll immutability — cannot modify approved entry (Pest)

### Exit Criteria
- OWASP top 10 checklist complete with test evidence
- All security headers set and verified
- Rate limiting on all write endpoints
- Zero critical/high dependency vulnerabilities
- No secrets in code
- No PII in client-side storage
- Cross-tenant sweep passes on all endpoints

---

## S37 — Performance Optimization

### Backend
- [ ] Database optimization:
  - Run `EXPLAIN` on all queries for key endpoints:
    - Employee list (with search, filter, sort)
    - Attendance list (30-day range)
    - Payroll run with entries
    - Dashboard queries (all 4 endpoints)
    - Leave balance calculation
  - Add missing indexes based on slow query analysis
  - Verify FULLTEXT index on employees is effective for Amharic
  - N+1 detection: install `barryvdh/laravel-debugbar` in dev, fix all N+1 warnings
  - Verify no unbounded queries (all list endpoints paginated, max per_page=100)
- [ ] Automated performance benchmarks (Pest):
  - Seed 1000 employees, 3 months attendance, 2 months payroll in test DB
  - `Employee list (1000 rows, filtered+sorted): < 500ms`
  - `Employee FULLTEXT search: < 100ms`
  - `Attendance list (30 days, filtered): < 300ms`
  - `Dashboard executive endpoint: < 200ms`
  - `Dashboard manager endpoint: < 200ms`
  - `Payroll run detail with entries: < 300ms`
  - `Leave balance calculation: < 100ms`
  - Benchmarks run as separate CI job (non-blocking but visible)
- [ ] Caching optimization:
  - Verify all Redis cache keys, TTLs, and invalidation per ARCHITECTURE.md
  - Add cache warming on tenant login (pre-populate org structure, permissions)
  - Profile cache hit rates (target: > 80% for dashboard queries)
- [ ] Queue optimization:
  - Review all job timeouts (payroll: 120s, export: 60s, notification: 10s, attendance: 30s)
  - Verify long jobs on dedicated queues (payroll, export)
  - Failed job alerting functional
  - Horizon tuning: adjust worker counts based on queue depth monitoring
- [ ] API response optimization:
  - Gzip compression enabled in Nginx
  - `->select()` on all queries — no `SELECT *`
  - Eager load only needed relationships (`->with(['department:id,name', ...])`)
  - Pagination enforced (max per_page = 100)
- [x] File optimization:
  - [x] Image compression on upload (profile photos: max 500KB after compression, selfies: max 200KB)
  - [x] Thumbnail generation for profile photos (150x150 for avatars, 400x400 for profile page)
  - [x] Presigned URL caching (avoid regenerating for same file within 5 min)
- [ ] PHP-FPM tuning for production:
  - `pm = ondemand` (saves memory when idle)
  - `pm.max_children = 40` (based on available RAM)
  - `pm.process_idle_timeout = 10s`
  - OPcache: `opcache.jit=tracing`, `opcache.jit_buffer_size=100M`

### Frontend
- [ ] Bundle optimization:
  - Code splitting per route (Next.js dynamic imports)
  - Lazy load heavy components: charts, report builder, org chart, calendar
  - Analyze bundle with `@next/bundle-analyzer` — identify and fix largest modules
  - Tree shaking verification — no unused exports
  - Target: main bundle < 150KB gzipped
- [ ] Image optimization:
  - `next/image` for all images (automatic WebP, responsive srcset)
  - Lazy loading for below-fold images
  - Placeholder blur for employee photos
- [ ] Runtime optimization:
  - Virtual scrolling on DataTable for 100+ rows (verify from Phase 0)
  - `useMemo` on expensive computations (payroll calculations, chart data transforms)
  - `useCallback` on event handlers passed as props
  - Debounced search inputs (300ms, verify from Phase 0)
  - Skeleton screens render in < 200ms (no layout shift)
- [ ] Cache optimization:
  - TanStack Query stale times tuned per resource:
    - Dashboard: 5 min
    - Employee list: 2 min
    - Employee detail: 5 min
    - Attendance: 1 min (real-time needs)
    - Leave balance: 5 min
    - Settings: 30 min
  - Service worker caches static assets (CSS, JS, fonts)
  - Employee directory cached in IndexedDB for offline
- [ ] Performance measurement:
  - Lighthouse audit on key pages: dashboard, employee list, payroll, settings
  - Target: Performance > 80, Accessibility > 90
  - Core Web Vitals: LCP < 2.5s, FID < 100ms, CLS < 0.1
  - TTFB: < 200ms for cached pages, < 500ms for uncached

### Tests
- [ ] Automated performance benchmarks — all targets met (Pest)
- [ ] N+1 detection — zero N+1 queries (manual with debugbar)
- [ ] Lighthouse audit: Performance > 80, Accessibility > 90 (automated)
- [ ] Bundle size: main < 150KB gzipped (CI check)
- [ ] Virtual scrolling handles 1000 rows without jank (manual)

### Exit Criteria
- All performance targets met (see CLAUDE.md Performance Targets)
- Automated performance benchmarks in CI (non-blocking)
- Zero N+1 queries
- Caching effective (> 80% hit rate for dashboard)
- Bundle size < 150KB gzipped
- Lighthouse Performance > 80, Accessibility > 90

---

## S38 — Accessibility, Responsive & Amharic Validation

### Frontend — WCAG 2.1 AA Audit
- [ ] Keyboard accessibility:
  - All interactive elements focusable (buttons, links, inputs, checkboxes, toggles)
  - Tab order logical on every page (visual order matches DOM order)
  - Focus visible on all focusable elements (custom focus ring: 2px solid `--color-interactive-focus`)
  - Skip navigation link (hidden until focused, jumps to main content)
  - Command palette (⌘K) accessible via keyboard
  - DataTable keyboard navigation: arrow keys, Enter to edit, Escape to cancel
  - Modal/drawer focus trap (Tab cycles within open modal, Escape closes)
  - Dropdown menus: arrow keys to navigate, Enter to select, Escape to close
- [ ] ARIA:
  - `aria-label` on all icon-only buttons (e.g., notification bell, dark mode toggle)
  - `aria-live="polite"` on notification toast container
  - `aria-live="assertive"` on error messages
  - `aria-expanded` on accordion/collapsible elements
  - `aria-selected` on tabs
  - `aria-current="page"` on active sidebar nav item
  - `role="alert"` on form validation error summaries
- [ ] Forms:
  - All inputs have associated `<label>` with `htmlFor`
  - Required fields indicated (not just by color)
  - Error messages: announced to screen readers, associated with field via `aria-describedby`
  - Success messages: announced via `aria-live`
- [ ] Visual:
  - Color contrast: 4.5:1 for normal text, 3:1 for large text (verify with aXe)
  - Information not conveyed by color alone (icons + color for status badges)
  - Charts have text alternatives (data tables or `aria-label` descriptions)
  - No text in images
- [ ] Language:
  - `lang` attribute on `<html>` tag (switches with locale: `en` or `am`)
  - `lang` attributes on inline Amharic text when page is in English mode

### Frontend — Amharic Typography Validation
- [ ] Test every DataTable column with Amharic data:
  - Employee names (first_name_am, last_name_am)
  - Department names (name_am)
  - Position titles
  - Leave type names
  - Announcement content
- [ ] Verify Amharic line-height: 1.6-1.8 (not 1.5 — characters clip at 1.5)
- [ ] Verify text truncation: `text-overflow: ellipsis` works correctly with Amharic characters
- [ ] Verify `word-break` and `overflow-wrap` with Amharic (Amharic doesn't hyphenate like Latin)
- [ ] Verify column widths accommodate Amharic (wider characters than Latin)
- [ ] Verify search works with Amharic input (FULLTEXT + frontend search)
- [ ] Verify sorting works with Amharic text (collation: `utf8mb4_unicode_ci`)
- [ ] Add Amharic text to Vitest snapshot tests

### Frontend — Responsive Validation Sweep
- [ ] Every page verified at ALL breakpoints:
  - 375px (iPhone SE — smallest target)
  - 390px (iPhone 14)
  - 768px (iPad portrait)
  - 1024px (iPad landscape)
  - 1280px (laptop)
  - 1440px+ (desktop)
- [ ] Per-page checklist:
  - No horizontal scroll (except DataTable with pinned columns)
  - Text readable without zooming
  - Touch targets ≥ 48px on mobile
  - Images responsive (no overflow)
  - Modals/drawers usable on mobile (bottom sheets for < 768px)
  - Navigation appropriate: sidebar on desktop, MobileTabBar on mobile (employee role)
  - Forms usable: labels visible, inputs full-width on mobile

### Frontend — Dark Mode Validation Sweep
- [ ] Every page verified in dark mode:
  - All text readable against dark backgrounds
  - Charts visible (semantic colors adjust)
  - Status badges visible (lighter variants in dark mode)
  - Form inputs styled (borders visible, placeholder readable)
  - [x] No hardcoded colors (all CSS variables) — enforced by `semantic-color-tokens.test.ts`
  - Elevation shadows appropriate (more subtle in dark mode)
  - Print styles: force light mode for printing

### Frontend — PWA Finalization
- [ ] Service worker (from Phase 3 GAP-FIX-5 — verify):
  - Caches all static assets
  - Network-first for API calls
  - Offline page for non-cached routes
  - Background sync for offline attendance
- [ ] PWA manifest:
  - Icons at all sizes (72, 96, 128, 144, 152, 192, 384, 512)
  - Correct start_url, display, theme_color, background_color
  - App name and short_name
- [ ] Install prompt: custom banner on mobile (after 3rd visit)
- [ ] Splash screen: ETHR logo + loading indicator
- [ ] Offline features verified:
  - Attendance check-in/out works offline
  - Employee directory viewable offline (cached)
  - Offline indicator banner persistent
  - Background sync triggers on connectivity restoration

### Tests
- [ ] aXe accessibility audit on 10 key pages (automated: Playwright + @axe-core/playwright)
- [ ] Keyboard navigation test on key flows (Playwright)
- [ ] Screen reader test on login, dashboard, employee create, leave request (manual)
- [ ] Responsive screenshots at 375px, 768px, 1280px for 10 key pages (Playwright)
- [ ] Dark mode screenshots for 10 key pages (Playwright)
- [ ] Amharic text rendering in DataTable (Vitest snapshot)
- [ ] PWA install and offline behavior (manual in Chrome DevTools)
- [ ] Lighthouse accessibility > 90 (automated)

### Exit Criteria
- WCAG 2.1 AA compliance on all pages (aXe zero errors)
- Every page responsive at all 6 breakpoints
- Dark mode complete — every page verified
- Amharic typography: correct line-height, no truncation bugs, search works
- PWA installable and functional offline (attendance, directory)
- Keyboard navigation works throughout
- Lighthouse Accessibility > 90

---

## S39 — E2E Testing, Deployment & Documentation

### E2E Testing (Playwright)
- [ ] Test environment: Docker Compose with test database (seeded via DemoSeeder)
- [ ] Critical path test suites:
  - **Signup flow:** visit landing → register → verify email → setup wizard (select template, configure departments, skip to review) → launch dashboard
  - **Employee flow:** login as HR → create employee (fill all tabs) → view detail → edit → transition to active
  - **Attendance flow:** login as employee → check in (web) → wait → check out → view my attendance
  - **Leave flow:** login as employee → apply leave → login as manager → view approval center → approve → verify employee balance updated
  - **Payroll flow:** login as finance → run payroll → review entries → approve → login as employee → view payslip → download PDF
  - **Admin flow:** login as super admin → view tenants → impersonate tenant admin (with MFA) → verify restricted actions → exit impersonation
  - **Offline flow:** login as employee → enable airplane mode (devtools) → check in → disable airplane mode → verify sync
- [ ] Responsive tests: run signup + employee + attendance flows at 375px viewport
- [ ] Dark mode tests: run dashboard + employee list at 1280px in dark mode
- [ ] Accessibility tests: aXe audit integrated into each flow
- [ ] CI integration: Playwright runs on push to main branch (blocking)

### Deployment
- [x] Production Docker Compose (`docker-compose.prod.yml`): verified (`config` parses), 2026-07-31
  - Resource limits per service (memory, CPU)
  - Volume mounts for persistent data (MariaDB, Redis, MinIO)
  - Log rotation (max 10MB per file, max 3 files)
  - Healthcheck for each service (command, interval, timeout, retries)
  - Restart policy: `always` (spec said `unless-stopped`; `always` chosen deliberately)
  - Network isolation: internal network for inter-service, exposed ports only for nginx
- [x] Nginx production config: verified `nginx -t` + HTTP/2 & static-header fixes, 2026-07-31
  - SSL/TLS via Let's Encrypt (certbot auto-renewal)
  - HTTP/2 enabled
  - Gzip compression (text/html, application/json, text/css, application/javascript)
  - Static asset caching: 1 year for hashed filenames, no-cache for HTML
  - Wildcard subdomain routing (`*.ethr.et`)
  - Rate limiting per CLAUDE.md policy
  - Security headers (all from S36)
  - Request body size limit: 50MB (for CSV imports)
  - Proxy timeouts: 120s for payroll endpoints, 30s for everything else
- [ ] Supervisor config:
  - Queue workers per queue (attendance: 4, payroll: 2, notifications: 3, default: 2, exports: 2, devices: 2, sync: 3)
  - Scheduler: 1 process
  - Reverb: 1 process
  - Auto-restart on failure: startretries=5, startsecs=5
  - Log files per process
- [ ] Deployment scripts:
  - `scripts/deploy.sh` — pull latest, build frontend, run migrations, cache config/routes/views, restart services, health check
  - `scripts/rollback.sh` — revert to previous release tag, rollback migrations if needed, restart
  - `scripts/backup.sh` — MariaDB dump + MinIO sync to external storage (timestamped)
  - `scripts/restore.sh` — restore from backup file (MariaDB + MinIO)
  - `scripts/seed-demo.sh` — run DemoSeeder on target environment
  - All scripts with error handling, logging, and notification on failure
- [ ] Environment files:
  - `.env.example` — complete, documented, with comments explaining each variable
  - `.env.production` template — production values with `CHANGE_ME` placeholders for secrets
- [ ] Database verification:
  - Fresh migration run on empty database → verify zero errors
  - Verify all seeders run cleanly (system roles, default plans, tax brackets, holidays)
  - Verify schema_version tracking works
- [ ] Demo tenant:
  - `DemoSeeder` from Phase 1 verified on production-like environment
  - 150+ employees, 6 months data, all features demonstrable
  - Demo credentials documented
- [ ] Monitoring:
  - `GET /api/health` — comprehensive check (DB, Redis, MinIO, queue, disk, memory)
  - Error alerting: email on 500 errors (configurable in `.env`)
  - Queue monitoring: Horizon dashboard accessible at `{admin_subdomain}/horizon`
  - Slow query log: enabled in MariaDB (> 1s)
  - Application log: structured JSON format, rotated daily

### Documentation
- [ ] **Admin Guide** (for tenant administrators):
  - Getting started (first login, dashboard overview)
  - Organization setup (branches, departments, positions)
  - Employee management (create, import, lifecycle)
  - Attendance configuration (shifts, devices, methods)
  - Leave policies (types, accrual, approval chain)
  - Payroll setup (tax brackets, pension, allowances)
  - Running payroll (process, review, approve, void)
  - Reports (builder, pre-built, scheduling)
  - Settings reference (all settings pages)
  - Role and permission management
  - Billing and subscription
- [ ] **User Guide** (for employees):
  - Logging in (email/password, MFA)
  - Dashboard overview
  - Checking attendance (mobile, QR, web)
  - Applying for leave
  - Viewing payslips
  - Profile management
  - Mobile/PWA installation and offline usage
  - Organization directory
- [ ] **Manager Guide:**
  - Approval center
  - Team attendance monitoring
  - Team leave calendar
  - Overtime monitoring
  - Reports
- [ ] **API Documentation:** OpenAPI spec + interactive Swagger UI (from Phase 7)
- [ ] **Deployment Guide:**
  - System requirements (OS, Docker, resources)
  - Docker installation
  - Configuration (all environment variables documented)
  - First-time setup (migrate, seed, create super admin)
  - SSL setup (Let's Encrypt, certbot)
  - Backup and restore procedures
  - Updating to new versions
  - Troubleshooting (common issues + solutions)
  - Performance tuning
- [ ] Documentation language: English (primary), Amharic translations as follow-up

### Tests
- [ ] All 7 Playwright E2E suites passing
- [ ] Responsive E2E tests at 375px passing
- [ ] Dark mode E2E tests passing
- [ ] aXe accessibility integrated in E2E — zero errors
- [ ] `docker compose -f docker-compose.prod.yml up` boots successfully
- [ ] Fresh database migration succeeds on empty DB
- [ ] All seeders run cleanly
- [ ] DemoSeeder creates valid data (verified by API calls)
- [ ] Backup and restore scripts work (round-trip test)
- [ ] Health endpoint reports all services healthy
- [ ] Deploy script works on clean server

### Exit Criteria
- Playwright E2E tests pass for all 7 critical paths
- E2E tests run at 375px and 1280px
- aXe accessibility zero errors in all E2E flows
- Production Docker config boots successfully
- SSL/TLS with Let's Encrypt
- All deployment scripts tested and documented
- Backup/restore verified (round-trip)
- Demo tenant seeded with realistic data
- Admin guide, user guide, manager guide, deployment guide complete
- API documentation interactive and browsable
- All Pest + Vitest + Playwright tests passing
- **Platform is production-ready**

---

## Phase 9 Exit Criteria (Platform Launch Readiness)

- [ ] OWASP top 10 security audit complete with test evidence
- [ ] All performance targets met with automated benchmarks
- [ ] Zero N+1 queries, > 80% cache hit rate
- [ ] WCAG 2.1 AA accessibility complete (aXe zero errors)
- [ ] Every page responsive at 6 breakpoints
- [ ] Dark mode verified on every page
- [ ] Amharic typography verified (line-height, truncation, search, sorting)
- [ ] PWA installable with offline attendance and directory
- [ ] 7 Playwright E2E suites for critical paths
- [ ] Production Docker Compose configuration
- [ ] Nginx with SSL, HTTP/2, security headers, rate limiting
- [ ] Deployment, rollback, backup, restore scripts
- [ ] Demo tenant with 150+ employees and 6 months of data
- [ ] Admin guide, user guide, manager guide, deployment guide
- [ ] API documentation (OpenAPI + Swagger UI)
- [ ] All Pest + Vitest + Playwright tests passing
- [ ] Lighthouse: Performance > 80, Accessibility > 90
- [ ] **ETHR is production-ready for Ethiopian organizations**
