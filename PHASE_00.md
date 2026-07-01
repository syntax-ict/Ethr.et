# Phase 0 — Platform Foundation

## Prerequisites
- None (first phase)

## Objective
Establish the complete development environment, core infrastructure, authentication, multi-tenancy, billing, and design system. Everything subsequent phases build upon.

## Existing Work (from ethr1 S1-S5)
The following is already built and should be migrated/verified:
- Tenant model, BelongsToTenant trait, CurrentTenant singleton, subdomain resolution middleware
- Sanctum authentication (login, OTP, logout, refresh tokens)
- Billing service (plans, subscriptions, invoices, proration)
- Employee onboarding (4-step wizard, CSV parser, employee importer)
- Super admin console (tenant listing, revenue, feature flags)

---

## S01 — Docker Environment & Project Scaffold ✅ COMPLETE

### Backend
- [x] Docker Compose: Laravel (PHP-FPM), MariaDB 10.11, Redis 7, MinIO, Nginx — `docker-compose.yml`
- [x] Nginx config: subdomain routing (`*.ethr.test` → Laravel, base `localhost` → frontend) — `docker/nginx/default.conf`
- [x] `.env.example` with all service connections — `api/.env.example`
- [x] `docker-compose.yml` (dev) + `docker-compose.prod.yml` (production, fixed 2026-07-01)
- [x] PHP-FPM tuning for development — `docker/php/www.conf` (dynamic PM, 20 max children)
- [x] PHP-FPM tuning for production — `docker/php/www.prod.conf` (ondemand PM, 40 max children)
- [x] Laravel 12 project in `/api`
- [x] Horizon config for queue workers — `api/config/horizon.php` (attendance, payroll, notifications, exports, devices, sync, default)
- [x] Horizon service provider registered — `api/bootstrap/providers.php`
- [x] Scheduler config (cron container) — `scheduler` service in docker-compose
- [x] Health check endpoint: `GET /api/health` — `HealthController`, also `php artisan health:check`
- [x] Production Dockerfile — `api/Dockerfile.prod` (with config/route/view cache)

### Frontend
- [x] Next.js 15 project in `/src`
- [x] Tailwind CSS 4 config
- [x] TypeScript strict mode — `tsconfig.json`
- [x] Prettier config
- [x] Path aliases (`@/` → `src/`)
- [x] Environment variable setup — `src/.env.local`, `src/.env.local.example`, `src/.env.production`

### Infrastructure
- [x] Production Nginx with SSL/TLS — `infrastructure/nginx.conf`
- [x] Supervisor config (worker + scheduler + reverb + horizon) — `infrastructure/supervisor.conf`
- [x] Deployment scripts — `scripts/deploy.sh`, `rollback.sh`, `backup.sh`, `restore.sh`
- [x] Production env template — `api/.env.production`

### Tests
- [x] Laravel responds at `http://localhost/api/health` (HealthController + health:check artisan command)
- [x] MariaDB, Redis accessible from Laravel (health endpoint checks both)
- [x] Pest runs successfully — 547 tests pass
- [ ] `docker compose up` boots all services (verify on target environment)
- [ ] Next.js responds at `http://localhost` (verify on target environment)

### Exit Criteria
- `docker compose up` brings up the full stack in < 2 minutes ← verify on host
- All services healthy and communicating ← verify on host

---

## S02 — Multi-Tenancy & Authentication (verify existing)

### Backend
- [ ] Verify/migrate tenancy core: `tenants` table, `BelongsToTenant` trait, `TenantScope`, `CurrentTenant`
- [ ] Verify/migrate `resolve.tenant` middleware (subdomain -> CurrentTenant binding)
- [ ] Verify/migrate Sanctum auth: login, OTP, logout, refresh (15-min access + 7-day refresh)
- [ ] Verify/migrate `UserRole` enum, `users` table, `otp_codes` table
- [ ] Verify/migrate `audit_log` table and `AuditLog::record()` helper
- [ ] Add `login_histories` table and recording
- [ ] Ensure `JsonResource::withoutWrapping()` is global
- [ ] Ensure RFC-7807 error handler is registered

### Frontend
- [ ] Login page (email/password)
- [ ] OTP verification page
- [ ] Auth context provider (token management, refresh interceptor)
- [ ] Protected route wrapper (redirect to login if unauthenticated)
- [ ] Token storage in memory (not localStorage)

### Tests
- [ ] Login/logout flow (Pest)
- [ ] OTP request and verify (Pest)
- [ ] Token refresh (Pest)
- [ ] Tenant isolation (cross-tenant access denied)
- [ ] Auth guard caching handled (`forgetGuards()`)
- [ ] Login page renders (Vitest)

### Exit Criteria
- User can login via email/password, receive OTP, verify, get tokens, and access tenant-scoped endpoints
- Cross-tenant access returns 404

---

## S03 — MFA, Device Management & Session Security

### Backend
- [ ] TOTP setup: `POST /auth/mfa/setup` (generate secret + QR URI)
- [ ] TOTP verify: `POST /auth/mfa/verify` (validate TOTP code)
- [ ] MFA toggle: `POST /auth/mfa/enable`, `POST /auth/mfa/disable`
- [ ] Login flow modification: if MFA enabled, return `mfa_required: true` + short-lived `mfa_token`
- [ ] `trusted_devices` table: skip MFA for trusted devices (30-day expiry)
- [ ] Active sessions list: `GET /auth/sessions` (device, IP, last active)
- [ ] Revoke session: `DELETE /auth/sessions/{id}`
- [ ] Revoke all sessions: `POST /auth/sessions/revoke-all`
- [ ] Password policy: configurable min length, complexity (tenant setting)

### Frontend
- [ ] MFA setup wizard (show QR code, verify backup code)
- [ ] MFA verification page (TOTP input after login)
- [ ] Active sessions page (list + revoke buttons)
- [ ] "Trust this device" checkbox on MFA verify

### Tests
- [ ] MFA setup + login flow (Pest)
- [ ] Trusted device skips MFA (Pest)
- [ ] Session revocation (Pest)
- [ ] MFA setup UI (Vitest)

### Exit Criteria
- User can enable TOTP MFA, login requires TOTP code, trust device to skip

---

## S04 — File Storage, Rate Limiting & Localization Infrastructure

### Backend
- [ ] `FileService` class wrapping Laravel Storage (S3 driver -> MinIO)
- [ ] Presigned upload URL: `POST /api/v1/files/presign`
- [ ] Upload confirmation: `POST /api/v1/files/confirm`
- [ ] File download (signed URL): `GET /api/v1/files/{id}`
- [ ] Content-type validation (whitelist: image/*, application/pdf, text/csv)
- [ ] Max file size validation (2MB photos, 10MB documents, 500KB selfies)
- [ ] Per-tenant bucket organization: `tenant-{public_id}/`
- [ ] Rate limiting middleware: 60/min (trial), 300/min (paid) based on plan
- [ ] Throttle response includes `Retry-After` header
- [ ] Localization setup: `en` + `am` translation files for `common`, `auth`, `validation`
- [ ] Ethiopian calendar service: `CalendarService` (Gregorian <-> Ethiopian conversion)
- [ ] Ethiopian holiday auto-detection (from calendar computation)
- [ ] Date formatting helpers: display in EAT timezone, Ethiopian calendar when enabled
- [ ] Currency formatting helper: `formatETB(cents)` -> `"1,234.56 ETB"`

### Frontend
- [ ] `FileUpload` component (drag-and-drop, preview, progress)
- [ ] i18n setup (next-intl or react-i18next)
- [ ] `en.json` + `am.json` with `common.*` keys
- [ ] Language switcher in header
- [ ] Ethiopian calendar utility (`toEthiopian`, `toGregorian`, `formatEthiopian`)
- [ ] `DatePicker` component with dual calendar support
- [ ] `CurrencyDisplay` component for ETB formatting
- [ ] Offline detection hook (`useOfflineStatus`)

### Tests
- [ ] File upload + download flow (Pest)
- [ ] Content-type rejection (Pest)
- [ ] Rate limiting triggers at threshold (Pest)
- [ ] Calendar conversion correctness (Pest + Vitest)
- [ ] Language switching (Vitest)
- [ ] File upload component (Vitest)

### Exit Criteria
- Files upload to MinIO and download via signed URLs
- Rate limiting works per plan tier
- Calendar converts correctly between Gregorian and Ethiopian
- Language switches between EN and AM

---

## S05 — Design System & Dashboard Shell

### Frontend
- [ ] shadcn/ui installation and configuration
- [ ] Theme tokens (CSS variables): primary, secondary, accent, muted, destructive
- [ ] Color palette: professional blues/teals suitable for enterprise HR
- [ ] Typography: system font stack with Amharic support (Noto Sans Ethiopic)
- [ ] `DashboardLayout`: sidebar + header + content area
  - Sidebar: collapsible, icon + label, active state, nested groups
  - Header: tenant logo, user menu, language switch, notification bell, dark mode toggle
  - Mobile: hamburger -> slide-out sidebar
- [ ] `AuthLayout`: centered card, marketing background
- [ ] `MarketingLayout`: marketing nav + footer
- [ ] `OnboardingLayout`: wizard progress bar + content
- [ ] Shared components:
  - `DataTable` (sortable columns, pagination, row selection, search, filters, empty/loading states)
  - `PageHeader` (title, breadcrumb, actions)
  - `EmptyState` (icon, message, CTA button)
  - `LoadingSkeleton` (matches final layout shapes)
  - `ErrorBoundary` (error message + retry)
  - `ConfirmDialog` (destructive action confirmation)
  - `StatusBadge` (colored pills for status values)
  - `SearchInput` (debounced, with clear button)
  - `AvatarGroup` (employee photos in a row)
- [ ] Dark mode support (system preference + manual toggle)
- [ ] Responsive verification: 375px, 768px, 1280px
- [ ] Dashboard home page (placeholder KPI cards, recent activity)

### Tests
- [ ] DataTable renders with data (Vitest)
- [ ] Layout components render (Vitest)
- [ ] Dark mode toggles (Vitest)
- [ ] Responsive sidebar behavior (Vitest)

### Exit Criteria
- Dashboard shell renders with sidebar navigation
- All shared components available and themed
- Dark mode and responsive design working
- Amharic text renders correctly

---

## Phase 0 Exit Criteria (All Slices)

- [ ] `docker compose up` boots full stack
- [ ] Login -> MFA -> dashboard flow works end-to-end in browser
- [ ] Files upload/download via MinIO
- [ ] Rate limiting enforced
- [ ] Ethiopian calendar converts correctly
- [ ] EN/AM language switching works
- [ ] Design system with dark mode + responsive
- [ ] All Pest tests passing
- [ ] All Vitest tests passing
- [ ] PHPStan level 6 clean
- [ ] Pint + Prettier clean
