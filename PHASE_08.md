# Phase 8 — Platform Administration

## Prerequisites
- All business modules complete (Phases 0-7)

## Objective
Build the super admin console for platform operations, subscription/billing management, and comprehensive system settings.

---

## S32 — Super Admin Console

### Backend
- [ ] Enhance existing super admin endpoints (from S5):
  - `GET /api/v1/admin/tenants` — full tenant list with:
    - Tenant name, subdomain, type, status, plan, employee count, subscription status
    - Created at, last active, trial_ends_at
    - Search by name/subdomain
    - Filter by status, plan, type
    - Sort by any field
  - `GET /api/v1/admin/tenants/{id}` — tenant detail:
    - Full tenant info
    - Subscription history
    - Invoice history
    - Feature flags
    - Usage stats (employees, devices, storage)
    - Login activity
    - Audit log (recent)
  - `PUT /api/v1/admin/tenants/{id}/status` — change tenant status (activate, suspend, cancel)
  - `POST /api/v1/admin/tenants/{id}/impersonate` — impersonate tenant admin (creates audited session)
  - `POST /api/v1/admin/tenants/{id}/extend-trial` — extend trial period
- [ ] Platform revenue dashboard:
  - `GET /api/v1/admin/revenue` — enhanced:
    - MRR (Monthly Recurring Revenue) in ETB
    - Total tenants, active, trial, suspended, cancelled
    - Revenue trend (12 months)
    - Revenue by plan tier
    - Churn rate
    - Conversion rate (trial -> paid)
    - Average revenue per tenant
- [ ] Platform health:
  - `GET /api/v1/admin/health` — system health:
    - Service status (API, database, Redis, MinIO, Reverb, queue)
    - Queue depth per queue
    - Failed jobs count
    - Disk usage
    - Memory usage
    - Active WebSocket connections
- [ ] Platform audit log: `GET /api/v1/admin/audit` — cross-tenant audit log (super admin actions)
- [ ] Tenant backup: `POST /api/v1/admin/tenants/{id}/backup` — trigger tenant data export
- [ ] All super admin endpoints require `super_admin` role + active status

### Frontend
- [ ] Super admin dashboard:
  - Revenue summary cards (MRR, total tenants, conversion rate, churn)
  - Revenue trend chart (12 months)
  - Tenant status breakdown (pie: trial, active, suspended, cancelled)
  - Recent activity feed (new signups, status changes)
  - System health indicators
- [ ] Tenant management page:
  - DataTable: name, subdomain, type, status, plan, employees, created, last active
  - Search + filter + sort
  - Click -> tenant detail page
  - Quick actions: suspend, activate, extend trial
- [ ] Tenant detail page:
  - Profile card (name, subdomain, type, created, logo)
  - Subscription info (plan, status, next billing)
  - Usage stats (employees, devices, storage)
  - Invoice history
  - Feature flags toggles
  - Audit log (tenant-scoped)
  - Action buttons: impersonate, suspend, extend trial, backup
- [ ] System health page:
  - Service status grid (green/red indicators)
  - Queue depth bars
  - Failed jobs list with retry button
  - Resource usage gauges
- [ ] Impersonation banner: when impersonating, show persistent warning bar with "Exit Impersonation" button

### Tests
- [ ] Tenant management CRUD (Pest)
- [ ] Revenue calculations (Pest)
- [ ] Impersonation creates audit trail (Pest)
- [ ] Non-super-admin denied (Pest)
- [ ] Health endpoint (Pest)
- [ ] Super admin dashboard (Vitest)
- [ ] Tenant management table (Vitest)

### Exit Criteria
- Super admin has full visibility into all tenants
- Revenue metrics calculated correctly
- Impersonation works with full audit trail
- System health monitoring functional
- Tenant lifecycle management (activate, suspend, cancel)

---

## S33 — Subscription & Billing Management

### Backend
- [ ] Enhance billing service (from S3):
  - Plan upgrade/downgrade: `POST /api/v1/billing/change-plan`
    - Proration calculated (upgrade: charge difference immediately; downgrade: credit next invoice)
    - Feature access updated immediately on upgrade
  - Seat-based billing: price adjusts based on employee count
  - Invoice generation: `GenerateInvoiceJob` runs monthly
    - Auto-generate draft invoices
    - Line items: plan fee, seat charges, prorations, credits
    - Bilingual (EN/AM)
  - Payment methods:
    - Manual bank transfer (existing)
    - Payment instruction display (bank name, account, reference)
    - Admin marks invoice as paid (`POST /api/v1/billing/invoices/{id}/mark-paid`)
  - Overdue handling:
    - 7 days after due: send reminder notification
    - 30 days after due: mark subscription as `past_due`
    - 60 days after due: suspend tenant
  - Receipt generation (PDF): `GET /api/v1/billing/invoices/{id}/receipt`
- [ ] Tenant billing dashboard:
  - `GET /api/v1/billing/dashboard` — current plan, next billing, upcoming invoice, payment history
- [ ] Super admin billing:
  - `GET /api/v1/admin/billing/invoices` — all invoices across tenants
  - `PUT /api/v1/admin/billing/invoices/{id}/mark-paid` — mark as paid (admin side)
  - `POST /api/v1/admin/plans` — CRUD subscription plans
  - `PUT /api/v1/admin/plans/{id}` — edit plan pricing/features

### Frontend
- [ ] Tenant billing page:
  - Current plan card (name, price, features, employee limit)
  - "Upgrade" / "Change Plan" button -> plan comparison modal
  - Next invoice preview
  - Invoice history table (date, amount, status, download)
  - Payment instructions for manual transfer
  - Receipt download button (for paid invoices)
- [ ] Plan selection modal:
  - Side-by-side plan comparison (features, pricing, limits)
  - Proration preview (cost/credit for remainder of period)
  - Confirm upgrade/downgrade
- [ ] Super admin plan management:
  - Plan list with pricing
  - Edit plan dialog (price, limits, features)
  - Create new plan

### Tests
- [ ] Plan upgrade with proration (Pest)
- [ ] Plan downgrade with credit (Pest)
- [ ] Monthly invoice generation (Pest)
- [ ] Overdue handling (reminder, past_due, suspend) (Pest)
- [ ] Receipt PDF generation (Pest)
- [ ] Billing dashboard (Vitest)
- [ ] Plan comparison modal (Vitest)

### Exit Criteria
- Plan upgrade/downgrade with proration
- Monthly invoice auto-generation
- Overdue handling with escalation
- Receipt PDF generation
- Tenant billing dashboard complete
- Super admin can manage plans and invoices

---

## S34 — System Settings & Configuration

### Backend
- [ ] Tenant settings consolidated endpoint: `GET/PUT /api/v1/settings`
  - Organization: name, logo, address, phone, email, timezone, default locale
  - Calendar: ethiopian_calendar enabled/disabled, fiscal year start
  - Attendance: default grace period, OT daily cap, OT weekly cap, confidence threshold
  - Leave: working days (which days of week), default approval chain
  - Payroll: pay period (monthly), payroll run day, auto-calculate OT
  - Notifications: email sender name, default language
  - Security: password policy, session timeout, MFA requirement (optional/required/disabled)
  - Branding: primary color, secondary color, logo (via FileService)
- [ ] Holiday management: already built in Phase 4 S20
- [ ] Shift management: already built in Phase 3 S13
- [ ] Leave type management: already built in Phase 4 S19
- [ ] Payroll rules management: already built in Phase 4 S21
- [ ] Notification template management:
  - `GET /api/v1/settings/notification-templates` — list all templates
  - `PUT /api/v1/settings/notification-templates/{type}` — customize template (subject, body)
  - Default templates for each notification type (EN + AM)
  - Template variables: `{employee_name}`, `{leave_type}`, `{date}`, `{company_name}`, etc.
- [ ] Data management:
  - `POST /api/v1/settings/export` — full tenant data export (background job, download when ready)
  - `GET /api/v1/settings/export/status` — export status
  - Data retention settings (how long to keep audit logs, attendance records)
- [ ] Audit log viewer:
  - `GET /api/v1/audit-logs` — tenant-scoped, paginated
  - Filterable by action, user, date range, entity type
  - Export to CSV

### Frontend
- [ ] Settings page (tabbed layout):
  - **General**: org name, address, phone, email, timezone picker, locale picker
  - **Branding**: logo upload, color pickers (primary, secondary), preview
  - **Calendar**: Ethiopian calendar toggle, fiscal year start month
  - **Attendance**: grace period, OT caps, confidence threshold slider
  - **Leave**: working days checkboxes, approval chain configuration
  - **Payroll**: pay period, run day, auto OT toggle
  - **Security**: password policy config, session timeout, MFA policy
  - **Notifications**: template list with edit capability, test send
  - **Data**: export button, retention settings
- [ ] Audit log viewer page:
  - Searchable data table
  - Filters: action type, user, date range, entity
  - Detail view: before/after comparison (JSON diff)
  - Export button

### Tests
- [ ] Settings read/write (Pest)
- [ ] Notification template customization (Pest)
- [ ] Data export job (Pest)
- [ ] Audit log filtering (Pest)
- [ ] Settings form rendering (Vitest)
- [ ] Audit log table (Vitest)

### Exit Criteria
- All tenant settings configurable from one page
- Notification templates customizable
- Full data export available
- Audit log viewable and exportable
- All settings pages responsive and bilingual

---

## Phase 8 Exit Criteria

- [ ] Super admin console complete (tenants, revenue, health, impersonation)
- [ ] Subscription management with upgrade/downgrade/proration
- [ ] Invoice auto-generation and overdue handling
- [ ] All system settings configurable
- [ ] Notification templates customizable
- [ ] Audit log viewable and exportable
- [ ] Data export functional
- [ ] All Pest + Vitest tests passing
