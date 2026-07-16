# Phase 0 — Platform Foundation (v2.0)

## Prerequisites
- None (first phase)

## Objective
Establish the complete development environment, core infrastructure, authentication, multi-tenancy, permission system, billing, design system, and enterprise component library. Everything subsequent phases build upon.

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
- [x] Docker Compose: Laravel (PHP-FPM), MariaDB 10.11, Redis 7, MinIO, Nginx
- [x] Nginx config: subdomain routing (`*.ethr.test` → Laravel, `localhost` → frontend)
- [x] `.env.example` with all service connections
- [x] `docker-compose.yml` (dev) + `docker-compose.prod.yml` (production)
- [x] PHP-FPM tuning (dev: dynamic PM, prod: ondemand PM)
- [x] Laravel 12 project in `/api`
- [x] Horizon config for queue workers (7 named queues)
- [x] Scheduler config (cron container)
- [x] Health check endpoint: `GET /api/health`
- [x] Production Dockerfile

### Frontend
- [x] Next.js 15 project in `/src`
- [x] Tailwind CSS 4 config
- [x] TypeScript strict mode
- [x] Prettier config
- [x] Path aliases (`@/` → `src/`)
- [x] Environment variable setup

### Infrastructure
- [x] Production Nginx with SSL/TLS
- [x] Supervisor config (worker + scheduler + reverb + horizon)
- [x] Deployment scripts (deploy, rollback, backup, restore)
- [x] Production env template

### Tests
- [x] Laravel responds at `http://localhost/api/health`
- [x] MariaDB, Redis accessible from Laravel
- [x] Pest runs successfully — 547 tests pass
- [ ] `docker compose up` boots all services (verify on target environment)
- [ ] Next.js responds at `http://localhost` (verify on target environment)

---

## S02 — Multi-Tenancy, Authentication & Permission System

### Backend — Tenancy Core
- [ ] Verify/migrate: `tenants` table, `BelongsToTenant` trait, `TenantScope`, `CurrentTenant`
- [ ] Verify/migrate `resolve.tenant` middleware (subdomain → CurrentTenant binding)
- [ ] Add `TenantIsolationTest` (CI-enforced):
  - Dynamically discover all Eloquent models
  - Assert each non-global model has `tenant_id` and `BelongsToTenant` trait
  - Assert cross-tenant queries return empty results
  - Assert `DB::getQueryLog()` includes `tenant_id` in all queries
- [ ] Add PHPStan rule: error if migration has `tenant_id` but model lacks `BelongsToTenant`
- [ ] Ensure `JsonResource::withoutWrapping()` is global
- [ ] Ensure RFC-7807 error handler is registered
- [ ] Add `schema_version` column to `tenants` table

### Backend — Authentication
- [ ] Verify/migrate Sanctum auth: login, OTP, logout, refresh (15-min access + 7-day refresh)
- [ ] Verify/migrate `UserRole` enum, `users` table, `otp_codes` table
- [ ] Login rate limiting: 5 attempts/min per email per IP
- [ ] Account lockout after 10 failed attempts (15-min cooldown, notify tenant admin)
- [ ] Add `login_histories` table and recording (IP, device fingerprint, status, approximate location)

### Backend — Permission System
- [ ] `permissions` table: `id, name, module, action, description`
- [ ] `roles` table: `id, tenant_id, name, slug, is_system, description`
- [ ] `role_permissions` pivot: `role_id, permission_id`
- [ ] `user_roles` pivot: `user_id, role_id`
- [ ] Seed system roles with default permissions (tenant_admin, hr_admin, finance_admin, department_head, supervisor, employee)
- [ ] `HasPermissions` trait on User model: `hasPermission(string $permission): bool`
- [ ] Permission caching in Redis: `user:{id}:permissions` (15-min TTL)
- [ ] Cache invalidation on role/permission change
- [ ] Migrate all existing Policies from role-string checks to `hasPermission()` calls

### Backend — Audit Log
- [ ] Verify/migrate `audit_log` table and `AuditLog::record()` helper
- [ ] Ensure audit log is append-only (no UPDATE or DELETE on audit_log table)
- [ ] Add `impersonated_by` nullable column for impersonation tracking

### Frontend — Auth
- [ ] Login page (email/password)
- [ ] OTP verification page
- [ ] Auth context provider (token management, refresh interceptor)
- [ ] Protected route wrapper (redirect to login if unauthenticated)
- [ ] Token storage in memory (not localStorage, not URL params)
- [ ] Login rate limit error handling (show countdown to retry)

### Tests
- [ ] Login/logout flow (Pest)
- [ ] OTP request and verify (Pest)
- [ ] Token refresh (Pest)
- [ ] Tenant isolation — cross-tenant access denied (Pest)
- [ ] TenantIsolationTest — model sweep (Pest)
- [ ] Auth guard caching handled (`forgetGuards()`)
- [ ] Login rate limiting triggers at 5 attempts (Pest)
- [ ] Account lockout after 10 failures (Pest)
- [ ] Permission check: `hasPermission()` works with caching (Pest)
- [ ] System roles seeded correctly per tenant (Pest)
- [ ] Login page renders (Vitest)

### Exit Criteria
- User can login, receive OTP, verify, get tokens, and access tenant-scoped endpoints
- Cross-tenant access returns 404
- TenantIsolationTest passes in CI
- Permission system functional with caching
- Login rate limiting enforced

---

## S03 — MFA, Device Management & Session Security

### Backend
- [ ] TOTP setup: `POST /auth/mfa/setup` (generate secret + QR URI)
- [ ] TOTP verify: `POST /auth/mfa/verify` (validate TOTP code)
- [ ] MFA toggle: `POST /auth/mfa/enable`, `POST /auth/mfa/disable`
- [ ] Login flow modification: if MFA enabled, return `mfa_required: true` + short-lived `mfa_token` (5-min expiry)
- [ ] `trusted_devices` table: skip MFA for trusted devices (30-day expiry, stored as signed cookie)
- [ ] Active sessions list: `GET /auth/sessions` (device fingerprint, IP, last active, approximate location)
- [ ] Revoke session: `DELETE /auth/sessions/{id}`
- [ ] Revoke all sessions: `POST /auth/sessions/revoke-all`
- [ ] Password policy: configurable min length, complexity, expiry (tenant setting)

### Frontend
- [ ] MFA setup wizard (show QR code, enter verification code, show backup codes)
- [ ] MFA verification page (TOTP input after login, auto-focus, 6-digit numpad on mobile)
- [ ] Active sessions page (list with device icon, IP, location, last active, revoke button)
- [ ] "Trust this device" checkbox on MFA verify page
- [ ] Password strength indicator (on registration and password change)

### Tests
- [ ] MFA setup + login flow (Pest)
- [ ] MFA token expiry (5 min) (Pest)
- [ ] Trusted device skips MFA (Pest)
- [ ] Trusted device expires after 30 days (Pest)
- [ ] Session revocation (Pest)
- [ ] Password policy enforcement (Pest)
- [ ] MFA setup UI renders (Vitest)

### Exit Criteria
- User can enable TOTP MFA, login requires TOTP code, trust device to skip
- Active sessions manageable
- Password policy configurable per tenant

---

## S04 — File Storage, Rate Limiting & Localization Infrastructure

### Backend
- [ ] `FileService` class wrapping Laravel Storage (S3 driver → MinIO)
- [ ] Presigned upload URL: `POST /api/v1/files/presign`
- [ ] Upload confirmation with content verification: `POST /api/v1/files/confirm`
  - Verify magic bytes match declared content-type (JPEG: `FF D8 FF`, PNG: `89 50 4E 47`, PDF: `25 50 44 46`)
  - Strip EXIF data from images (privacy protection)
  - CSV: verify valid UTF-8, no embedded scripts
  - Reject mismatched content-type
- [ ] File download (signed URL, 5-min expiry): `GET /api/v1/files/{id}`
- [ ] Content-type whitelist: `image/jpeg`, `image/png`, `image/gif`, `application/pdf`, `text/csv`
- [ ] Max file size validation (2MB photos, 10MB documents, 500KB selfies, 50MB CSV imports)
- [ ] Per-tenant bucket organization: `tenant-{public_id}/`
- [ ] Rate limiting middleware per policy (see CLAUDE.md Rate Limiting Policy)
- [ ] Throttle response includes `Retry-After` header
- [ ] Localization setup: `en` + `am` translation files — full coverage:
  - `common`, `auth`, `validation`, `employee`, `attendance`, `leave`, `payroll`, `shift`, `device`, `notification`, `correction`, `organization`, `settings`, `kiosk`, `general`, `dashboard`
- [ ] Ethiopian calendar service: `CalendarService`
  - Gregorian ↔ Ethiopian conversion
  - Pagumen handling (13th month, 5-6 days)
  - Ethiopian New Year calculation (Meskerem 1 = Sep 11 or 12)
  - Leap year detection (both calendars)
- [ ] Ethiopian holiday auto-detection (from calendar computation):
  - Ethiopian New Year, Timkat, Adwa Day, Good Friday/Easter (computed), Patriots Day, Labour Day, Downfall of Derg, Eid al-Fitr (computed), Eid al-Adha (computed), Mawlid (computed), Meskel, Genna
- [ ] Date formatting helpers: display in EAT timezone (UTC+3, constant — no DST)
- [ ] Currency formatting helper: `formatETB(cents)` → `"1,234.56 ETB"`

### Frontend
- [ ] `FileUpload` component (drag-and-drop, preview, progress, type/size validation client-side)
- [ ] i18n setup (next-intl or react-i18next)
- [ ] `en.json` + `am.json` — full coverage matching all backend translation files
- [ ] Language switcher in header (dropdown with flag icons)
- [ ] Ethiopian calendar utility (`toEthiopian`, `toGregorian`, `formatEthiopian`, `getPagumenDays`)
- [ ] `DualCalendarPicker` component (Gregorian + Ethiopian side by side, when tenant enables dual display)
- [ ] `CurrencyDisplay` component for ETB formatting (reads from integer cents)
- [ ] `CurrencyInput` component (ETB-formatted input, stores integer cents)
- [ ] `PhoneInput` component (Ethiopian format: +251...)
- [ ] Offline detection hook (`useOfflineStatus`)
- [ ] `OfflineBanner` component (persistent banner when offline, not a disappearing toast)

### Tests
- [ ] File upload + download flow (Pest)
- [ ] Content-type spoofing rejection (magic byte mismatch) (Pest)
- [ ] EXIF stripping verification (Pest)
- [ ] Rate limiting triggers at threshold (per endpoint) (Pest)
- [ ] Calendar conversion correctness — standard months + Pagumen (Pest)
- [ ] Calendar conversion for leap years (Pest)
- [ ] Ethiopian holiday detection for known year (Pest)
- [ ] Language switching (Vitest)
- [ ] File upload component (Vitest)
- [ ] DualCalendarPicker renders both calendars (Vitest)
- [ ] CurrencyInput stores cents correctly (Vitest)

### Exit Criteria
- Files upload to MinIO and download via signed URLs
- Content verification (magic bytes) rejects spoofed files
- Rate limiting works per endpoint policy
- Calendar converts correctly (including Pagumen and leap years)
- Language switches between EN and AM with full coverage
- All 15+ translation files present for both languages

---

## S05 — Design System & Dashboard Shell

### Frontend — Design Tokens
- [ ] Semantic CSS variables defined (see CLAUDE.md Visual Identity section):
  - Surface tokens: `--color-surface-primary`, `--color-surface-secondary`, `--color-surface-elevated`, `--color-surface-sidebar`
  - Text tokens: `--color-text-primary`, `--color-text-secondary`, `--color-text-inverse`, `--color-text-on-sidebar`
  - Border tokens: `--color-border-default`, `--color-border-strong`
  - Interactive tokens: `--color-interactive-primary`, `--color-interactive-hover`, `--color-interactive-focus`
  - Accent: `--color-accent` (Ethiopian gold)
  - Status tokens: `--color-status-success`, `--color-status-warning`, `--color-status-error`, `--color-status-info`
- [ ] Dark mode tokens defined (see CLAUDE.md)
- [ ] Tailwind config extended to reference semantic tokens (no raw color classes in components)
- [ ] Typography: Inter (Latin), Noto Sans Ethiopic (Amharic), JetBrains Mono (codes/amounts)
- [ ] Spacing scale: 4px base (4, 8, 12, 16, 20, 24, 32, 40, 48, 64, 80, 96)
- [ ] Border radius scale: 4px, 6px, 8px, 12px, 9999px
- [ ] Elevation scale: level 0 (none), level 1, level 2, level 3

### Frontend — shadcn/ui Setup
- [ ] shadcn/ui installation and configuration
- [ ] Theme tokens mapped to semantic CSS variables
- [ ] Color palette: deep teal blue (#0F4C75) primary, Ethiopian gold (#E8A838) accent
- [ ] Ethiopian tibeb pattern SVG (for sidebar footer and section dividers)

### Frontend — Layouts
- [ ] `DashboardLayout`: sidebar + header + content area
  - Sidebar: collapsible, role-based navigation groups (Overview, People, Operations, Finance, Admin)
  - Notification count badges on relevant nav items
  - Ethiopian tibeb pattern border at sidebar footer
  - Command palette trigger (⌘K / Ctrl+K)
  - User menu, language switch, dark mode toggle at sidebar bottom
  - Header: breadcrumbs, page title, action buttons area
  - Mobile (< 768px): hamburger → slide-out sidebar overlay
- [ ] `MobileTabBar`: bottom tab navigation for employee role (Home, Attendance, Leave, Payslips, More)
- [ ] `AuthLayout`: centered card, professional background
- [ ] `MarketingLayout`: marketing nav + footer with tibeb dividers
- [ ] `OnboardingLayout`: wizard progress bar + content

### Frontend — Enterprise DataTable Component
- [ ] Built on TanStack Table v8
- [ ] Column pinning (pin employee name column)
- [ ] Column resizing (drag handles)
- [ ] Column visibility toggle (show/hide columns)
- [ ] Column reorder (drag-and-drop)
- [ ] Sticky header (visible while scrolling)
- [ ] Server-side pagination (URL query params: `?page=1&per_page=25`)
- [ ] Server-side sorting (URL query params: `?sort=-created_at`)
- [ ] Server-side filtering (URL query params: `?filter[status]=active`)
- [ ] Full-text search (debounced, URL query param: `?search=query`)
- [ ] Row selection (checkbox column, select all on page, select all matching filter)
- [ ] Row expansion (expand row to show quick details)
- [ ] Inline editing (for bulk operations — edit field in-place)
- [ ] Keyboard navigation (arrow keys, Enter to edit, Escape to cancel)
- [ ] Skeleton rows during loading (not a spinner)
- [ ] Empty state with contextual CTA
- [ ] Error state with retry button
- [ ] Export current view button (CSV)
- [ ] Saved views (save column config + filters per user per table, localStorage)
- [ ] Virtual scrolling (for 1000+ rows, ready from day one)
- [ ] Responsive: horizontal scroll on mobile with pinned first column
- [ ] Dark mode compatible

### Frontend — QueryBoundary Pattern
- [ ] `QueryBoundary` component wrapping TanStack Query:
  ```
  <QueryBoundary query={...} loading={<Skeleton />} empty={<EmptyState />} error={(e, retry) => <ErrorPanel />}>
    {(data) => <Content data={data} />}
  </QueryBoundary>
  ```
- [ ] Used on every data-fetching component — no component renders without handling all four states

### Frontend — Command Palette
- [ ] `CommandPalette` component (⌘K / Ctrl+K):
  - Search employees by name, code, department
  - Navigate to pages by typing page name
  - Quick actions: "Create employee", "Run payroll", "Check in"
  - Keyboard navigable (arrow keys, Enter to select, Escape to close)
  - Role-filtered: only show actions the current user has permission for
  - Recent items section

### Frontend — Shared Components
- [ ] `PageHeader` (title, breadcrumb, action buttons)
- [ ] `EmptyState` (icon, message, CTA button)
- [ ] `LoadingSkeleton` (matches final layout shapes — cards, tables, lists)
- [ ] `ErrorBoundary` (error message + retry button)
- [ ] `ErrorPanel` (inline error with retry, for use inside QueryBoundary)
- [ ] `ConfirmDialog` (destructive action confirmation with warning text)
- [ ] `StatusBadge` (colored pills for status values — uses semantic tokens)
- [ ] `SearchInput` (debounced, with clear button, search icon)
- [ ] `AvatarGroup` (employee photos in overlapping row)
- [ ] `Timeline` (vertical timeline for lifecycle events, approval history, audit trails)
- [ ] `StatCard` (value, label, trend indicator with arrow, optional sparkline)
- [ ] `ApprovalChain` (dot stepper showing approval workflow progress with names)
- [ ] `ComparisonCard` (before/after display for corrections, changes)
- [ ] `ProgressRing` (circular progress for scores and rates)
- [ ] `FilePreview` (PDF/image preview in lightbox modal)
- [ ] `PrintButton` (triggers browser print with print-optimized CSS)
- [ ] `UndoToast` (success message with 5-second "Undo" button for optimistic actions)
- [ ] `AddressForm` (Ethiopian address: region, city, subcity, woreda, kebele)
- [ ] `ChartWrapper` (consistent chart styling: semantic colors, dark mode, currency formatting, responsive)

### Frontend — Dark Mode
- [ ] System preference detection + manual toggle
- [ ] `data-theme="dark"` attribute on `<html>`
- [ ] All components use semantic CSS variables (no raw colors)
- [ ] All charts render correctly in dark mode
- [ ] Status badges visible in both modes
- [ ] Form inputs styled for both modes
- [ ] Sidebar contrast appropriate in both modes

### Frontend — Responsive
- [ ] Verification at: 375px, 390px, 768px, 1024px, 1280px, 1440px+
- [ ] Touch targets: minimum 48px
- [ ] No horizontal scroll (except DataTable with pinned columns)
- [ ] Text readable at all breakpoints

### Frontend — Dashboard Home
- [ ] Dashboard home page (role-based):
  - Employee: quick actions (Check In, Apply Leave, View Payslip), today's attendance, leave balance, announcements
  - Manager: team attendance today, pending approvals badge, team OT
  - Executive: KPI stat cards (headcount, attendance rate, payroll cost, overtime %)
- [ ] Placeholder data for now (will be connected to real APIs in later phases)

### Tests
- [ ] DataTable renders with data, handles all states (Vitest)
- [ ] DataTable keyboard navigation (Vitest)
- [ ] DataTable column pinning and resizing (Vitest)
- [ ] QueryBoundary renders correct state (loading/empty/error/success) (Vitest)
- [ ] Command palette search and navigation (Vitest)
- [ ] Layout components render (Vitest)
- [ ] Dark mode toggles all semantic tokens (Vitest)
- [ ] Responsive sidebar behavior (Vitest)
- [ ] MobileTabBar renders for employee role (Vitest)
- [ ] Timeline component renders events (Vitest)
- [ ] StatCard renders with trend (Vitest)

### Exit Criteria
- Dashboard shell renders with role-based sidebar navigation
- Command palette functional (⌘K)
- Enterprise DataTable with all features (pinning, resizing, virtual scroll, keyboard nav)
- QueryBoundary pattern enforced on all data components
- All shared components available and themed with semantic tokens
- Dark mode complete — every component verified
- Responsive at all breakpoints
- Mobile bottom tab bar for employee role
- Amharic text renders correctly with proper line-height
- Ethiopian tibeb pattern visible in sidebar footer

---

## Phase 0 Exit Criteria (All Slices)

- [ ] `docker compose up` boots full stack
- [ ] Login → MFA → dashboard flow works end-to-end in browser
- [ ] Permission system functional (system roles, hasPermission, caching)
- [ ] TenantIsolationTest passes in CI
- [ ] Files upload/download via MinIO with content verification
- [ ] Rate limiting enforced per endpoint policy
- [ ] Ethiopian calendar converts correctly (including Pagumen)
- [ ] EN/AM language switching with full translation coverage
- [ ] Design system with semantic tokens, dark mode, responsive
- [ ] Enterprise DataTable with keyboard nav and virtual scrolling
- [ ] Command palette (⌘K) functional
- [ ] QueryBoundary pattern on all data components
- [ ] All Pest tests passing (including TenantIsolationTest)
- [ ] All Vitest tests passing
- [ ] PHPStan level 6 clean
- [ ] Pint + Prettier clean
