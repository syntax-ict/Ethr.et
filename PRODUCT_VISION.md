# ETHR v1.0 — Product Vision (v2.0)

## Product Position

> **ETHR — Ethiopian Workforce Operating System**

Enterprise-grade, multi-tenant, offline-first Human Capital Management SaaS designed specifically for Ethiopian organizations. Replaces Excel, BioTime, Hikvision standalone software, and manual HR processes with a unified, modern platform.

---

## Strategic Release Goal

ETHR v1.0 is a **Minimum Lovable Enterprise Product (MLEP)** — not a stripped-down MVP, but a platform that feels complete, reliable, and enterprise-ready from day one. It must win trust with government ministries, banks, hospitals, and manufacturers who are skeptical of SaaS adoption.

The competitive moat is the **attendance platform**: multi-source, offline-first, device-agnostic, with confidence scoring and intelligence. No competitor in the Ethiopian market offers this.

---

## Design Identity

ETHR is not another generic SaaS dashboard. It is an Ethiopian product with visual authority.

### Visual Language

- **Primary palette:** Deep teal blue (#0F4C75) conveys trust and professionalism. Ethiopian gold (#E8A838) accent derived from the national flag.
- **Typography:** Inter for Latin text (clean, excellent number rendering), Noto Sans Ethiopic for Amharic (native rendering, no fallback artifacts).
- **Signature element:** Ethiopian geometric textile motif (tilf/tibeb pattern) as a subtle decorative border in the sidebar and as section dividers on the marketing site. One cultural element, used with restraint, makes the product unmistakably Ethiopian.
- **Dark mode:** Full dark mode with semantic color tokens. Every chart, badge, and status indicator designed for both themes.
- **Data density:** Enterprise HR users need information density. Avoid excessive whitespace. Tables should be compact, scannable, and keyboard-navigable.

### Mobile-First for Employees

Employees interact with ETHR primarily on their phones (attendance, leave, payslips). The employee experience must be native-feeling:
- Bottom tab navigation (not sidebar)
- Pull-to-refresh on all list views
- Swipe actions for quick approve/reject
- Large touch targets (48px minimum)
- Offline indicator banner (persistent, not a disappearing toast)
- Haptic feedback on check-in/check-out

Managers and HR admins use tablet and desktop. The dashboard experience is optimized for 768px+ viewports.

---

# 1. Platform Foundation (Mandatory)

This layer must be complete and hardened before business modules ship.

### Multi-Tenant SaaS

- Complete tenant isolation (BelongsToTenant trait + TenantIsolationTest in CI)
- Automatic tenant provisioning
- Custom subdomains (`company.ethr.et`)
- Tenant branding (logo, colors)
- Tenant configuration (calendar, locale, payroll rules, attendance methods)
- Tenant lifecycle (trial → active → suspended → cancelled)
- Tenant backup and restore
- Tenant audit logs (immutable, append-only)
- Per-tenant encryption keys for sensitive data

### Authentication & Security

- Email/password login
- Phone login via OTP (EthioTelecom SMS)
- Multi-factor authentication (TOTP)
- Trusted device management (skip MFA for 30 days)
- Configurable password policy (min length, complexity)
- Active session management (list, revoke individual, revoke all)
- Login rate limiting (5/min per IP, lockout after 10 attempts)
- API tokens (scoped Sanctum tokens)
- Permission-based RBAC (not role-string checks)
- Custom roles with granular permissions
- Audit logging for all sensitive operations
- Login history (last 50 logins)
- SSO-ready architecture (v2.0)

### Organization Structure

- Headquarters designation (one per tenant)
- Branches with geofence coordinates (latitude, longitude, radius)
- Departments (hierarchical, with parent-child relationships)
- Teams (under departments)
- Positions (with salary ranges)
- Grades (with level ordering)
- Cost centers
- Reporting hierarchy (supervisor chain)
- Approval hierarchy (configurable per module)
- Org chart visualization

---

# 2. Public Website & Marketing (Mandatory)

A professional web presence is critical for SaaS adoption in Ethiopia, where trust in software products must be earned.

### Marketing

- Professional landing page (hero, features, industry showcase, social proof, CTA)
- Pricing page (3 tiers with feature comparison)
- Features page (attendance, HR, payroll deep-dives)
- FAQ page (accordion)
- Contact page (form with email notification)
- All pages responsive (mobile, tablet, desktop)
- All pages bilingual (English + Amharic)
- SEO meta tags and Open Graph tags
- Ethiopian tibeb pattern as section dividers (cultural identity)

### Self-Service Sales

- Free trial signup (6 months, no credit card)
- Instant tenant creation (< 30 seconds)
- Email verification
- 7-step guided onboarding with organization templates
- 8 industry templates (Government, Bank, Hospital, Manufacturing, NGO, Hotel, University, General)
- Subscription upgrade in-app
- Trial expiration enforcement (402 response after expiry)

---

# 3. Localization (Mandatory)

Built from day one, not bolted on.

### Languages

- English (complete)
- አማርኛ / Amharic (complete — 15+ translation files matching all English files)
- Architecture supports: Afaan Oromoo, ትግርኛ, Soomaali, Sidama, Afar

### Ethiopian Localization

- Ethiopian Calendar with configurable Gregorian dual display
- Automatic Ethiopian public holiday detection (12+ holidays)
- Configurable regional/branch-specific holidays
- Pagumen (13th month) handling for payroll proration
- Configurable fiscal year start (Hamle 1 for government, Meskerem 1 for private)
- Ethiopian currency formatting (`X,XXX.XX ETB`, integer cents internally)
- Ethiopian phone format (+251...)
- Ethiopian address structure (Region, City, Subcity, Woreda, Kebele)
- Ethiopian payroll terminology
- Amharic typography support (Noto Sans Ethiopic, increased line-height)
- Ethiopia does not observe DST — UTC+3 constant

---

# 4. Employee Management (Mandatory)

### Employee Profile

- Personal information (name in English + Amharic, DOB, gender, marital status, nationality, religion, photo)
- Employment information (employee code, hire date, probation end, confirmation date, status)
- Organization assignment (department, branch, position, grade, team, cost center, supervisor)
- Documents (contracts, certificates, ID copies — uploaded to MinIO, expiry tracking)
- Emergency contacts (multiple, with relationship)
- Education history
- Bank details (encrypted: bank name, branch, account number, TIN)
- History timeline (all transitions, changes, audit events)
- Multiple import methods: manual entry, CSV import (with field mapping + validation preview), API

### Employee Lifecycle State Machine

- Draft → Probation → Active
- Active → Suspended → Active (reactivated)
- Active → Transferred → Active (in new assignment)
- Any active state → Departed (resign / retire / terminate)
- Departed → Probation (rehire — new employment record)
- Every transition creates an immutable `employee_transitions` record
- Transitions enforced by `EmployeeStatus::canTransitionTo()`

### Employee Self-Update

- Employees can update: phone, emergency contacts, address, photo
- Changes to payroll-affecting fields (bank details, name) require HR approval
- Creates `ProfileUpdateRequest` with approval workflow

---

# 5. Attendance Platform (Core Differentiator)

This is where ETHR wins. No competitor in the Ethiopian market offers this breadth.

### Attendance Sources (9 Sources)

- Biometric devices (Hikvision ISAPI, ZKTeco push/pull)
- Mobile (online — GPS + geofence + optional selfie)
- Mobile (offline — encrypted IndexedDB queue + background sync)
- QR code (time-limited, encrypted, auto-refreshing)
- Web clock (browser-based check-in)
- Tablet/shared kiosk (token-auth sessions, PIN + numpad)
- Manual HR entry (audit logged, lowest confidence score)
- CSV import (with validation preview)
- REST API (for third-party integrations)

### Attendance Intelligence

- Shift matching (employee → department → branch → tenant cascade)
- Late detection (configurable grace period)
- Early departure detection
- Overtime calculation (normal / night / holiday / holiday-night rates)
- Missing punch detection (daily job, alerts employee + supervisor)
- Duplicate detection (idempotency keys)
- Confidence scoring (50-100 scale based on source + GPS + geofence + selfie)
- Conflict resolution (formal state machine with deterministic outcomes)
- Per-tenant attendance method configuration (enable/disable each source)

### Biometric Integration

- Hikvision: ISAPI HTTP protocol (pull events + push webhook)
- ZKTeco: Push/pull SDK protocol
- Adapter pattern: new devices added without changing core attendance logic
- Device dashboard: real-time status, sync logs, health monitoring
- v2.0: Suprema, Anviz, FingerTec, Dahua

### Offline Attendance

- IndexedDB queue for offline storage
- HMAC-SHA256 validation (anti-tampering)
- Automatic background sync via service worker
- Encrypted records (employee ID, timestamps, GPS, device signature)
- Conflict handling via formal state machine
- Geofence validation (even offline — compare GPS to cached branch coordinates)
- Optional selfie (compressed JPEG, max 500KB, EXIF stripped)

---

# 6. Attendance Correction (Mandatory)

Workflow: Employee → Supervisor → Department Head → HR

- Correction request with reason and optional attachments
- Approval chain (configurable per tenant)
- Approval history with timestamps
- Audit trail (immutable)
- Notifications at each step
- Payroll impact preview (show how correction affects net salary before final approval)

---

# 7. Leave Management

### Leave Types (9+ Configurable Types)

- Annual leave
- Sick leave
- Maternity leave (120 days — Ethiopian law, gender-restricted)
- Paternity leave (gender-restricted)
- Emergency leave
- Study leave
- Unpaid leave
- Compensatory leave
- Custom leave types (tenant-defined)

### Features

- Configurable accrual (monthly, quarterly, annual)
- Carry-forward (with configurable max days)
- Holiday-aware day counting (skip public holidays and weekends, handle Pagumen correctly)
- Approval workflow (configurable chain: supervisor → dept head → HR)
- Team minimum staffing check
- Team leave calendar (monthly view, color-coded by type)
- Balance tracking (entitled, used, pending, remaining)
- Encashment (optional, v2.0)

---

# 8. Payroll (Ethiopian Rules Engine)

### Earnings

- Basic salary (prorated for mid-month hire/termination/Pagumen)
- Overtime (configurable rates: normal 1.25x, night 1.5x, holiday 2.0x, holiday-night 2.5x)
- Position allowance (fixed or percentage of basic)
- Transport allowance
- Housing allowance
- Hardship allowance
- Bonuses (one-time)

### Deductions

- Ethiopian income tax (7-bracket progressive, configurable when rates change)
- Pension — employee 7% + employer 11% (configurable)
- Loans (with balance tracking and automatic monthly deduction)
- Other deductions (configurable)

### Processing

- Rules engine (not hardcoded formulas — all rates configurable per tenant)
- Payroll run: draft → approved → (voided if error found)
- Calculation audit trail: every `PayrollEntry` stores full pipeline trace (immutable JSON)
- Idempotency protection: unique constraint on tenant + period prevents duplicate runs
- Voiding: approved runs can be voided, creating reversal entries
- Reprocessing: voided runs can be reprocessed with corrected data

### Outputs

- Payslip PDF (bilingual EN/AM, company header, full earnings/deductions breakdown)
- Bank transfer export (CSV, Ethiopian bank format)
- Tax report
- Pension report
- Department/cost center summary
- Accounting journal entries (configurable chart of accounts)

---

# 9. Employee Self-Service Portal

Employees can:

- View dashboard (today's attendance, leave balance, recent payslip, announcements)
- Check in / check out (mobile, QR, web)
- View attendance history (calendar + list views)
- Request attendance corrections
- Apply for leave (with balance display and calculated days)
- View leave balances and history
- View and download payslips
- Update personal details (some fields require approval)
- View and download own documents
- Browse organization directory (searchable, offline-cacheable)
- Receive and manage notifications

All self-service features mobile-optimized (primary use case).

---

# 10. Manager Portal

Managers can:

- View unified dashboard (team attendance today, pending approvals, overtime)
- Approve/reject from unified approval center (leave, corrections, profile updates)
- Batch approve/reject multiple items
- View team attendance (real-time today, historical calendar)
- View team leave calendar (who's off, minimum staffing indicator)
- Monitor team overtime (threshold warnings)
- Receive anomaly alerts (attendance irregularities, overdue approvals)
- Delegate approval authority during absence
- Export team reports

---

# 11. Executive Dashboard

Executives see:

- Organization health score (composite metric)
- Headcount (total, by status, department, branch)
- Attendance rate (today, weekly, monthly trend)
- Absenteeism rate (trend, department comparison)
- Payroll cost (monthly trend, department/cost center breakdown)
- Overtime cost (as % of total payroll)
- Turnover rate (monthly, quarterly)
- Workforce growth (hires vs exits trend)
- Leave utilization
- Department and branch comparison (radar chart, sortable table)
- Date range filtering with comparison to previous period
- All charts dark-mode compatible
- Drill-down from summary to detail

---

# 12. Reporting Engine

- Dynamic report builder (select data source → columns → filters → aggregation → preview)
- 4 data sources: employees, attendance, leave, payroll
- Saved report templates (reusable)
- Scheduled reports (daily/weekly/monthly email delivery)
- Export to PDF, Excel, CSV
- 8 pre-built report templates (attendance summary, payroll register, leave balance, etc.)
- Report permissions (HR for employee reports, finance for payroll reports)

---

# 13. Notification Center

### Channels

- In-app (always on, real-time via Reverb WebSocket)
- Email (branded, bilingual templates)
- SMS (adapter-based: LogSms for dev, EthioTelecom for prod, rate-limited)
- Push notifications (via PWA service worker)

### Events (13+ Notification Types)

- Leave requested / approved / rejected
- Attendance correction requested / approved
- Attendance anomaly detected
- Missing punch alert
- Payroll processed / payslip available
- Announcement published
- Approval reminder (48h overdue)
- Device offline
- Trial expiring (30 / 7 / 1 day warnings)

### Configuration

- User preferences (toggle channels per notification type)
- Customizable email templates (with template variables)
- SMS rate limiting (max 5/day per user)
- Announcement targeting (all, department, branch, role)

---

# 14. Mobile Experience (PWA v1.0)

### Employee App (PWA)

- Offline attendance (check in/out without connectivity)
- Leave request and balance viewing
- Payslip viewing and download
- Notification center
- Profile management
- QR attendance scanning
- Organization directory (offline-cacheable)
- Bottom tab navigation (Home, Attendance, Leave, Payslips, More)
- Install prompt, splash screen, offline fallback page

### Manager App (same PWA, role-based views)

- Approval center
- Team dashboards
- Team monitoring

### Admin App (same PWA, role-based views)

- Attendance monitoring
- Device status
- Alerts

Flutter native apps deferred to v2.0.

---

# 15. Integration Hub

- Biometric device adapters (Hikvision, ZKTeco, extensible)
- REST API (OpenAPI 3.0 documented, interactive Swagger UI)
- API keys with scoped permissions and rate limiting
- Webhooks (HMAC-SHA256 signed, exponential backoff retry, SSRF prevention)
- CSV import/export (universal framework, all entity types)
- Payroll exports (bank transfer, tax, pension)
- Accounting integration (configurable chart of accounts, journal entries)
- BioTime format parser (migration from legacy systems)
- Hikvision standalone software export parser

---

# 16. Offline-First Architecture

Every critical function gracefully degrades when connectivity is lost:

- Offline attendance capture (encrypted IndexedDB + HMAC)
- Offline employee directory lookup (cached)
- Offline approval drafts (queued, sent when online)
- Offline leave request drafts (queued)
- Background synchronization via service worker
- Conflict detection and resolution (formal state machine)
- Retry queue with exponential backoff
- Persistent offline indicator banner

---

# 17. Role & Permission System

### System Roles (Pre-Seeded)

- Platform Super Admin
- Tenant Admin
- HR Admin
- Finance Admin
- Department Head
- Supervisor
- Employee

### Custom Roles

- Tenant admins can create custom roles
- Granular permission assignment (per module + action)
- Permission categories: employees, attendance, leave, payroll, organization, reports, devices, settings, admin, notifications, audit

### Permission Resolution

- Permissions checked via `$user->hasPermission('module.action')`
- Never compare role strings directly
- Cached in Redis (15-min TTL, invalidated on change)

---

# 18. System Administration

- Tenant management (list, detail, status changes, impersonation, trial extension)
- Subscription management (upgrade/downgrade with proration, invoicing, overdue handling)
- Revenue dashboard (MRR, churn, conversion, trend)
- System health monitoring (service status, queue depth, failed jobs, resources)
- User and role management (per tenant)
- Device management (biometric devices, status, sync)
- All settings configurable from unified settings page (general, branding, calendar, attendance, leave, payroll, security, notifications, data)
- Audit log viewer (filterable, exportable, with before/after JSON diff)
- Full tenant data export (background job)
- Notification template customization
- Data retention policies (configurable per entity type)

---

# 19. Enterprise Quality Requirements

### Before Launch

- Mobile-first responsive UI (375px → 1440px+)
- Accessibility (WCAG 2.1 AA: keyboard navigation, screen reader, color contrast, focus visible)
- Comprehensive error handling (QueryBoundary pattern: loading/empty/error/success states)
- Retry and recovery mechanisms (queue failure recovery, offline retry)
- Background job processing (Horizon, per-queue workers)
- Optimistic UI where safe (per documented policy)
- Full audit logging (immutable, tenant-scoped)
- Performance monitoring (Lighthouse > 80 perf, > 90 a11y, Core Web Vitals)
- Security hardening (OWASP Top 10, dependency audit, security headers, SSRF prevention)
- Automated testing:
  - Backend: Pest (unit + feature + tenant isolation + performance)
  - Frontend: Vitest (component + contract via generated OpenAPI types)
  - E2E: Playwright (critical paths at 375px + 1280px)
- Demo tenant with realistic data (150+ employees, 6 months data)
- Backup and restore procedures
- Deployment scripts (deploy, rollback, backup, restore)
- Documentation (admin guide, user guide, deployment guide, API docs)

---

# 20. Features Deferred to v2.0

To keep the first release achievable without sacrificing quality:

- Recruitment / Applicant Tracking (ATS)
- Performance Management / OKRs
- Learning Management (LMS)
- Asset Management
- Visitor Management
- Contractor Management
- Fleet Management
- Leave encashment
- Flutter native mobile apps
- AI predictive analytics (attendance patterns, turnover prediction)
- Advanced workflow automation
- SSO integration (SAML, OAuth)
- Additional biometric vendors (Suprema, Anviz, FingerTec, Dahua)
- Custom domain support (beyond subdomains)
- Full ERP suite

---

## What Success Looks Like

ETHR v1.0 succeeds when:

1. A 500-employee Ethiopian organization can replace BioTime + Excel + manual payroll with ETHR in one week.
2. Attendance works reliably with spotty internet connectivity.
3. Payroll calculates correctly with full audit trail, and no employee disputes a payslip without resolution.
4. The product feels distinctly Ethiopian — not a generic SaaS template with Amharic bolted on.
5. IT administrators can deploy on an Ethiopian VPS without cloud vendor lock-in.
6. The free trial converts to paid subscriptions because the product is genuinely better than the alternatives.
