# ETHR — Product Requirements Document (v2.0)

## Document Control

| Field | Value |
|---|---|
| Product | ETHR — Ethiopian Workforce Operating System |
| Version | 1.0 |
| Target Release | Q4 2026 |
| Status | In Development |
| Classification | Confidential |

---

## 1. Product Overview

ETHR is an enterprise-grade, multi-tenant, offline-first Human Capital Management (HCM) SaaS platform designed for Ethiopian organizations. It replaces fragmented tools (Excel, BioTime, Hikvision standalone software, manual HR processes) with a unified platform covering attendance, HR, leave, payroll, and executive reporting.

### Target Market
- Ethiopian government offices (federal, regional, municipal)
- Commercial banks and financial institutions
- Hospitals and healthcare organizations
- Manufacturing companies
- NGOs and international organizations operating in Ethiopia
- Hotels and hospitality chains
- Universities and educational institutions
- Private companies (50-5000 employees)

### Deployment Model
- Multi-tenant SaaS (shared infrastructure, isolated data)
- Self-hosted option for government/security-sensitive organizations
- No cloud-vendor lock-in — runs on Ethiopian VPS providers

---

## 2. User Roles & Personas

### Platform Super Admin
- Manages all tenants, subscriptions, and platform health
- Can impersonate tenant admins (with guardrails)
- Monitors revenue, churn, and system metrics

### Tenant Admin
- Organization owner/CEO
- Full control over tenant settings, billing, and user management
- Creates custom roles and permissions

### HR Admin
- Manages employees, org structure, attendance settings
- Processes leave approvals
- Runs HR reports

### Finance Admin
- Configures payroll rules (tax, pension, allowances)
- Processes and approves payroll runs
- Generates bank transfers, tax reports, pension reports

### Department Head
- Manages team within department
- Approves leave and attendance corrections
- Views department analytics

### Supervisor
- Direct line manager for a group of employees
- Approves leave and corrections for direct reports
- Views team attendance

### Employee
- Self-service: attendance, leave, payslips, profile
- Primary device: mobile phone (PWA)
- May have limited tech literacy

---

## 3. Functional Requirements

### FR-01: Multi-Tenant Platform

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-01.1 | Each tenant has isolated data | P0 | Cross-tenant queries return empty. TenantIsolationTest passes in CI. |
| FR-01.2 | Tenants accessed via subdomain | P0 | `company.ethr.et` resolves to correct tenant. |
| FR-01.3 | Tenant lifecycle management | P0 | Status transitions: trial → active → suspended → cancelled. |
| FR-01.4 | Tenant branding | P1 | Logo and primary/secondary colors applied across UI. |
| FR-01.5 | Tenant settings configurable | P1 | All settings editable from unified settings page. |
| FR-01.6 | Per-tenant data export | P2 | Full data export as ZIP (database + files). |

### FR-02: Authentication & Security

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-02.1 | Email/password login | P0 | User logs in, receives 15-min access + 7-day refresh token. |
| FR-02.2 | OTP verification | P0 | 6-digit code via SMS/email, 5-min expiry, single-use. |
| FR-02.3 | TOTP MFA | P1 | Setup via QR code, required on login when enabled. |
| FR-02.4 | Trusted devices | P1 | Skip MFA for 30 days on trusted device. |
| FR-02.5 | Session management | P1 | List active sessions, revoke individual or all. |
| FR-02.6 | Login rate limiting | P0 | 5 attempts/min per IP. Lockout after 10 failures. |
| FR-02.7 | Permission-based authorization | P0 | All endpoints check `hasPermission()`. Custom roles supported. |
| FR-02.8 | Audit logging | P0 | All sensitive operations logged immutably. |

### FR-03: Organization Structure

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-03.1 | Branch CRUD with geofence | P0 | Branches store lat/lng/radius for attendance geofencing. |
| FR-03.2 | Department hierarchy | P0 | Parent-child departments up to 5 levels. Tree view renders. |
| FR-03.3 | Position with salary range | P1 | Min/max salary in ETB cents. |
| FR-03.4 | Org chart visualization | P2 | Interactive hierarchical diagram. |

### FR-04: Employee Management

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-04.1 | Employee CRUD | P0 | Create/read/update/soft-delete with all profile fields. |
| FR-04.2 | Full-text search | P0 | Search by name (EN+AM), code, email. < 100ms at 1000 records. |
| FR-04.3 | Lifecycle state machine | P0 | Valid transitions enforced. Immutable transition history. |
| FR-04.4 | Encrypted sensitive data | P0 | Bank account, TIN encrypted at rest (AES-256). |
| FR-04.5 | CSV import with preview | P1 | Upload, validate, preview errors per row, commit. Idempotent. |
| FR-04.6 | Bulk update | P1 | Update department/branch/status for selected employees. |
| FR-04.7 | Document management | P1 | Upload to MinIO with content verification. Expiry tracking. |
| FR-04.8 | Self-service profile | P1 | Employee edits own allowed fields. Sensitive changes need approval. |

### FR-05: Attendance Platform

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-05.1 | 9 attendance sources | P0 | Biometric, mobile, QR, kiosk, web, manual, CSV, API, offline. |
| FR-05.2 | Confidence scoring | P0 | Score 50-100 based on source + GPS + geofence + selfie. |
| FR-05.3 | Conflict resolution | P0 | Formal state machine with deterministic outcomes for all scenarios. |
| FR-05.4 | Shift matching | P0 | Employee → department → branch → tenant cascade. |
| FR-05.5 | Late/early/OT detection | P0 | Compared against matched shift. |
| FR-05.6 | Offline attendance | P0 | IndexedDB + HMAC + background sync + conflict resolution. |
| FR-05.7 | Hikvision integration | P0 | ISAPI pull + push webhook. Device status monitoring. |
| FR-05.8 | ZKTeco integration | P0 | Push/pull SDK protocol. |
| FR-05.9 | Correction workflow | P0 | 4-step approval: employee → supervisor → dept → HR. |
| FR-05.10 | Kiosk mode | P1 | Token-auth sessions, PIN entry, employee code. |
| FR-05.11 | QR attendance | P1 | Time-limited encrypted QR with auto-refresh. |
| FR-05.12 | Missing punch alerts | P1 | Daily job scans for incomplete records. Notifies employee + supervisor. |
| FR-05.13 | Per-tenant method control | P1 | Enable/disable each attendance source per tenant. |
| FR-05.14 | Device dashboard | P1 | Real-time status, sync logs, health monitoring. |
| FR-05.15 | Payroll impact preview | P2 | Show how correction affects payroll before final approval. |

### FR-06: Leave Management

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-06.1 | 9+ leave types | P0 | Annual, sick, maternity (120 days), paternity, emergency, study, unpaid, compensatory, custom. |
| FR-06.2 | Balance tracking | P0 | Entitled + carried - used - pending = remaining. Half-day precision. |
| FR-06.3 | Holiday-aware counting | P0 | Skip holidays and non-working days. Handle Pagumen. |
| FR-06.4 | Approval workflow | P0 | Configurable chain. Notifications at each step. |
| FR-06.5 | Team leave calendar | P1 | Monthly view, color-coded, staffing indicator. |
| FR-06.6 | Accrual and carry-forward | P1 | Monthly/quarterly/annual accrual. Year-end carry-forward with max. |
| FR-06.7 | Gender-restricted types | P1 | Maternity = female only. Paternity = male only. |

### FR-07: Holiday Engine

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-07.1 | Auto-detect Ethiopian holidays | P0 | 13 holidays computed correctly for any year. |
| FR-07.2 | Custom holidays | P1 | Add regional/branch-specific holidays. |
| FR-07.3 | Recurring holidays | P1 | Auto-create for new year. |
| FR-07.4 | Integration with leave/attendance/payroll | P0 | Holidays skipped in leave counting, flagged in attendance, OT rates applied. |

### FR-08: Payroll

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-08.1 | Ethiopian tax calculation | P0 | 7-bracket progressive tax. Correct to the cent. Configurable. |
| FR-08.2 | Pension calculation | P0 | Employee 7% + employer 11%. Configurable. |
| FR-08.3 | Overtime calculation | P0 | 4 rates: normal 1.25x, night 1.5x, holiday 2.0x, holiday-night 2.5x. |
| FR-08.4 | Payroll processing pipeline | P0 | 14-step pipeline. All integer cents. No floating-point. |
| FR-08.5 | Calculation audit trail | P0 | Full pipeline trace stored as immutable JSON per entry. |
| FR-08.6 | Payslip PDF | P0 | Bilingual (EN+AM). Company branding. Earnings/deductions breakdown. |
| FR-08.7 | Bank export | P0 | CSV in Ethiopian bank format. Decrypts account numbers. |
| FR-08.8 | Payroll idempotency | P0 | Unique constraint on tenant + period. Duplicate runs rejected. |
| FR-08.9 | Payroll voiding | P1 | Void approved run. Reversal entries. Watermarked payslips. |
| FR-08.10 | Pagumen proration | P1 | Full month or daily rate strategy per tenant. |
| FR-08.11 | Loan management | P1 | Create loan, automatic monthly deduction, balance tracking. |
| FR-08.12 | Allowances | P1 | Fixed or % of basic. Per position/grade. Taxable flag. |
| FR-08.13 | Tax and pension reports | P1 | Exportable reports for government filing. |

### FR-09: Employee Self-Service Portal

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-09.1 | Employee dashboard | P0 | Attendance today, leave balance, payslip summary, announcements. |
| FR-09.2 | Mobile-first design | P0 | Bottom tab bar, 48px touch targets, bottom sheets, pull-to-refresh. |
| FR-09.3 | Organization directory | P1 | Searchable, offline-cacheable. No sensitive data. |
| FR-09.4 | Announcements | P1 | Priority-ordered, targeted, bilingual. |

### FR-10: Manager Portal

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-10.1 | Unified approval center | P0 | Aggregates leave, corrections, profile updates. Batch approve. |
| FR-10.2 | Team attendance monitoring | P0 | Real-time today, historical calendar. |
| FR-10.3 | Team leave calendar | P1 | Monthly grid, staffing indicators. |
| FR-10.4 | Overtime monitoring | P1 | Threshold warnings. |
| FR-10.5 | Approval delegation | P2 | Delegate to another user for date range. |

### FR-11: Notifications

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-11.1 | In-app notifications | P0 | 15+ types. Bell icon with unread count. Mark as read. |
| FR-11.2 | Real-time via WebSocket | P0 | Reverb broadcast. Live badge update. Reconnect with backoff. |
| FR-11.3 | Email notifications | P0 | Branded, bilingual templates. Customizable per tenant. |
| FR-11.4 | User preferences | P1 | Toggle per type per channel. In-app always on. |
| FR-11.5 | SMS for critical | P2 | OTP, payroll ready. Rate limited 5/day. |

### FR-12: Executive Dashboard & Reporting

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-12.1 | KPI dashboard | P1 | Headcount, attendance %, payroll cost, overtime %, turnover. < 1.5s load. |
| FR-12.2 | Department/branch comparison | P1 | Sortable table, drill-down to detail. |
| FR-12.3 | Dynamic report builder | P1 | 4 data sources. Filters, grouping, aggregation. |
| FR-12.4 | Export to PDF/Excel/CSV | P1 | From report builder and list pages. |
| FR-12.5 | Scheduled reports | P2 | Recurring email delivery. |
| FR-12.6 | Pre-built reports | P1 | 8 templates: attendance, payroll, leave, directory, OT, tax, pension, hires/exits. |

### FR-13: Integration

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-13.1 | OpenAPI documentation | P1 | Full spec. Interactive Swagger UI. |
| FR-13.2 | API keys | P1 | Scoped permissions. Rate limiting. Expiry. |
| FR-13.3 | Webhooks | P1 | HMAC-SHA256 signed. SSRF prevention. Retry with backoff. |
| FR-13.4 | TypeScript contract testing | P1 | Generated types from spec. CI enforcement. |
| FR-13.5 | Universal import/export | P1 | CSV for employees, attendance, holidays, departments. |
| FR-13.6 | Accounting integration | P2 | Payroll journal entries. Configurable chart of accounts. |
| FR-13.7 | Legacy format parsers | P2 | BioTime, Hikvision standalone exports. |

### FR-14: Platform Administration

| ID | Requirement | Priority | Acceptance Criteria |
|---|---|---|---|
| FR-14.1 | Super admin console | P0 | Tenant management, revenue metrics, system health. |
| FR-14.2 | Impersonation with guardrails | P1 | MFA required. 30-min expiry. Blocked actions. Audit trail. Persistent banner. |
| FR-14.3 | Subscription/billing | P1 | Upgrade/downgrade with proration. Invoice generation. Overdue handling. |
| FR-14.4 | Custom role editor | P1 | Create custom roles. Granular permission checkboxes. |

---

## 4. Non-Functional Requirements

### NFR-01: Performance

| Metric | Target | Measurement |
|---|---|---|
| API response (p95) | < 200ms | Automated Pest benchmarks |
| Dashboard page load | < 1.5s | Lighthouse |
| Employee list (1000 rows) | < 500ms | Pest benchmark |
| Employee full-text search | < 100ms | Pest benchmark |
| Payroll processing (500 employees) | < 30s | Pest benchmark |
| Attendance sync (batch 100) | < 5s | Pest benchmark |
| LCP | < 2.5s | Lighthouse |
| FID | < 100ms | Lighthouse |
| CLS | < 0.1 | Lighthouse |
| Lighthouse Performance | > 80 | CI |
| Lighthouse Accessibility | > 90 | CI |
| Frontend bundle (gzipped) | < 150KB | CI |

### NFR-02: Security

| Requirement | Standard |
|---|---|
| OWASP Top 10 compliance | All 10 categories audited with test evidence |
| Tenant isolation | CI-enforced TenantIsolationTest |
| Encryption at rest | AES-256 for bank details, TIN, TOTP secrets |
| Transport encryption | TLS 1.2+ enforced |
| Password storage | bcrypt |
| Session management | httpOnly, Secure, SameSite cookies |
| Rate limiting | Per-endpoint policy (see CLAUDE.md) |
| Content Security Policy | Strict CSP headers |
| File upload verification | Magic byte validation + EXIF stripping |
| Webhook security | HMAC-SHA256 + SSRF prevention |

### NFR-03: Accessibility

| Requirement | Standard |
|---|---|
| WCAG compliance | 2.1 AA |
| Keyboard navigation | All interactive elements focusable, logical tab order |
| Screen reader support | ARIA labels, live regions, semantic HTML |
| Color contrast | 4.5:1 normal text, 3:1 large text |
| Touch targets | ≥ 48px on mobile |

### NFR-04: Localization

| Requirement | Detail |
|---|---|
| Languages shipped | English (complete), Amharic (complete) |
| Language architecture | Supports adding om, ti, so, sid |
| Ethiopian calendar | Gregorian ↔ Ethiopian conversion, including Pagumen |
| Holidays | 13 Ethiopian holidays auto-detected |
| Currency | ETB in integer cents, formatted as X,XXX.XX ETB |
| Timezone | UTC+3 (EAT), no DST |
| Amharic typography | Noto Sans Ethiopic, line-height 1.6-1.8 |

### NFR-05: Reliability

| Requirement | Target |
|---|---|
| Data durability | No data loss under any failure scenario |
| Offline capability | Attendance works offline with eventual consistency |
| Queue failure recovery | Failed jobs preserved, admin alerted, manual retry |
| Backup frequency | Daily automated database + file backup |
| Idempotency | All write endpoints support idempotency keys |

### NFR-06: Scalability

| Metric | Target for v1.0 |
|---|---|
| Tenants | 100+ concurrent |
| Employees per tenant | 5,000 |
| Concurrent users per tenant | 500 |
| Attendance records/day per tenant | 10,000 |
| Payroll entries per run | 5,000 |

### NFR-07: Compatibility

| Requirement | Detail |
|---|---|
| Browsers | Chrome 90+, Firefox 90+, Safari 15+, Edge 90+ |
| Mobile | iOS 15+ (Safari), Android 10+ (Chrome) |
| PWA | Installable, offline attendance, background sync |
| Server OS | Ubuntu 22.04+ |
| Container | Docker Engine 24+, Docker Compose v2 |
| Database | MariaDB 10.11+ |
| Runtime | PHP 8.2+, Node.js 20 LTS |

---

## 5. Data Requirements

### Data Retention

| Data Type | Retention |
|---|---|
| Audit logs | Configurable per tenant (default 365 days) |
| Attendance records | Permanent (never deleted) |
| Payroll entries | Permanent (never deleted) |
| Employee records | Soft-deleted (never hard-deleted) |
| Notifications | Configurable (default 90 days) |
| Webhook deliveries | 30 days |
| API key logs | 30 days |
| File exports | 30 days |
| Import staging | 7 days |

### Data Classification

| Level | Examples | Protection |
|---|---|---|
| Public | Plans, templates, marketing content | None required |
| Internal | Employee names, departments, attendance | Tenant isolation |
| Confidential | Bank details, TIN, TOTP secrets | AES-256 encryption + tenant isolation |
| Restricted | Passwords, OTP codes | Hash-only storage (bcrypt/Argon2id) |

---

## 6. Deployment Requirements

| Requirement | Detail |
|---|---|
| Self-hosted | Must run on Ethiopian VPS without cloud vendor dependency |
| Docker-based | Single `docker compose up` to start all services |
| SSL/TLS | Let's Encrypt auto-renewal via certbot |
| Backup | Automated daily backup script (database + files) |
| Monitoring | Health endpoint, Horizon dashboard, slow query log |
| Zero-downtime deploy | Deploy script with migration + cache clear + restart |

---

## 7. Testing Requirements

| Test Type | Tool | Scope | Threshold |
|---|---|---|---|
| Unit/Feature (backend) | Pest | All controllers, services, models | 100% endpoint coverage |
| Component (frontend) | Vitest | All shared components, feature components | All QueryBoundary states |
| Contract | openapi-typescript | API response types match frontend types | CI enforcement |
| E2E | Playwright | 7 critical path flows | All passing |
| Accessibility | aXe + Playwright | 10 key pages | Zero errors |
| Performance | Pest benchmarks | 7 key endpoints | All targets met |
| Security | TenantIsolationTest + OWASP | All endpoints | Zero cross-tenant leaks |
| Responsive | Playwright screenshots | 10 key pages × 6 breakpoints | Manual review |

---

## 8. Success Metrics

| Metric | Target (6 months post-launch) |
|---|---|
| Registered tenants | 50+ |
| Trial → paid conversion | > 15% |
| Monthly active employees | 5,000+ |
| Attendance records processed/day | 10,000+ |
| Payroll runs/month | 30+ |
| Customer support tickets | < 5/tenant/month |
| System uptime | > 99.5% |
| NPS score | > 40 |
