# Phase 8 — Platform Administration (v2.0)

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
- All business modules complete (Phases 0-7)

## Objective
Build the super admin console with impersonation guardrails, subscription/billing management with proration, comprehensive system settings with custom role editor, and notification template management.

---

## S33 — Super Admin Console

### Backend
- [ ] Enhance super admin endpoints (all require `admin.*` permissions):
  - `GET /api/v1/admin/tenants` — full tenant list:
    - Fields: name, subdomain, type, status, plan, employee_count, subscription_status, created_at, last_active_at, trial_ends_at, schema_version
    - Search by name/subdomain
    - Filter by status (trial/active/suspended/cancelled), plan, type
    - Sort by any field
    - Paginated (max 100)
  - `GET /api/v1/admin/tenants/{id}` — tenant detail:
    - Full tenant info + branding
    - Subscription history (plan changes, status changes)
    - Invoice history (amount, status, dates)
    - Feature flags (with toggle controls)
    - Usage stats: employee_count, device_count, storage_used_mb, active_users_30d
    - Login activity (last 10 logins)
    - Audit log (recent 20 entries, tenant-scoped)
  - `PUT /api/v1/admin/tenants/{id}/status` — change status (activate, suspend, cancel)
    - Validates: can't suspend already cancelled; can't activate without valid subscription
    - Dispatches `TenantStatusChanged` event
    - Audit logged
  - `POST /api/v1/admin/tenants/{id}/extend-trial` — extend trial period
    - Input: additional_days
    - Updates trial_ends_at
    - Sends notification to tenant admin
    - Audit logged
  - `POST /api/v1/admin/tenants/{id}/backup` — trigger tenant data export
    - Background job: exports all tenant data to ZIP (database dump + MinIO files)
    - Notification when ready with download link
- [ ] Impersonation with guardrails:
  - `POST /api/v1/admin/tenants/{id}/impersonate`:
    - Requires MFA verification before starting
    - Creates audited impersonation session (30-min expiry, non-renewable)
    - Returns impersonation token with restricted abilities
    - Audit log entry: `{ action: 'impersonate_start', admin_id, tenant_id, timestamp }`
  - `POST /api/v1/admin/impersonate/exit`:
    - Ends impersonation, returns to super admin session
    - Audit log entry: `{ action: 'impersonate_end', ... }`
  - **Guardrails — blocked during impersonation:**
    - Password changes
    - MFA changes (enable/disable/setup)
    - API key creation or revocation
    - Subscription changes
    - Data deletion (employees, payroll, attendance)
    - Settings that affect billing
    - Webhook creation (SSRF risk)
  - All actions during impersonation logged with `impersonated_by` field in audit log
  - Impersonation session auto-expires after 30 minutes
- [ ] Platform revenue dashboard:
  - `GET /api/v1/admin/revenue`:
    - MRR (Monthly Recurring Revenue) in ETB cents
    - Total tenants (by status: trial, active, suspended, cancelled)
    - Revenue trend (12 months, area chart data)
    - Revenue by plan tier (pie chart data)
    - Churn rate: (cancelled this month / active start of month) × 100
    - Conversion rate: (trial→paid this month / expired trials this month) × 100
    - Average revenue per tenant (MRR / active tenants)
    - Top 10 tenants by employee count
    - Cached 15 min
- [ ] Platform health:
  - `GET /api/v1/admin/health`:
    - Service status: API (running), MariaDB (connected), Redis (connected), MinIO (connected), Reverb (connected), queue workers (running)
    - Queue depth per queue (attendance, payroll, notifications, etc.)
    - Failed jobs count (total + per queue)
    - Disk usage (total, available, % used)
    - Memory usage (total, available, % used)
    - Active WebSocket connections
    - Last backup timestamp
- [ ] Platform audit log: `GET /api/v1/admin/audit` — cross-tenant audit log for super admin actions
  - Filterable by admin user, action type, tenant, date range
  - Includes impersonation sessions

### Frontend
- [ ] Super admin dashboard:
  - Revenue `StatCard` row: MRR (ETB), total tenants, conversion rate %, churn rate %
  - Revenue trend area chart (12 months, `ChartWrapper`)
  - Tenant status breakdown donut chart (trial/active/suspended/cancelled)
  - Recent activity feed (Timeline: new signups, status changes, impersonation sessions)
  - System health indicators (green/amber/red dots per service)
- [ ] Tenant management page:
  - DataTable: name, subdomain, type, status, plan, employees, created, last active
  - Search + filter + sort
  - Click → tenant detail page
  - Quick actions column: suspend, activate, extend trial (dropdown)
- [ ] Tenant detail page:
  - Profile card: name, subdomain, type, created, logo, status `StatusBadge`
  - Usage stats `StatCard` row: employees, devices, storage, active users
  - Subscription card: plan name, status, next billing, started date
  - Invoice history DataTable (date, amount, status, download receipt)
  - Feature flags: toggle switches per feature
  - Audit log DataTable (tenant-scoped, recent 50)
  - Action buttons: Impersonate, Suspend, Extend Trial, Backup
  - Impersonate button → `ConfirmDialog` with MFA input
- [ ] System health page:
  - Service status grid: cards per service (name, status indicator, last check)
  - Queue depth bar chart per queue
  - Failed jobs DataTable with retry button per job
  - Resource usage gauge charts (disk, memory)
- [ ] **Impersonation banner**: when impersonating, show persistent warning bar at page top:
  - Red/amber background: "⚠ You are impersonating {tenant_name} as Super Admin"
  - "Exit Impersonation" button
  - Timer showing remaining session time (30 min countdown)
  - Banner cannot be dismissed

### Tests
- [ ] Tenant management — list, detail, status change (Pest)
- [ ] Revenue calculations — MRR, churn, conversion (Pest)
- [ ] Impersonation — MFA required (Pest)
- [ ] Impersonation — blocked actions list (test each) (Pest)
- [ ] Impersonation — all actions logged with impersonated_by (Pest)
- [ ] Impersonation — session expires after 30 min (Pest)
- [ ] Non-super-admin denied all admin endpoints (Pest)
- [ ] Health endpoint — all services checked (Pest)
- [ ] Trial extension — trial_ends_at updated (Pest)
- [ ] Platform audit log — cross-tenant (Pest)
- [ ] Super admin dashboard renders (Vitest)
- [ ] Tenant management DataTable (Vitest)
- [ ] Impersonation banner renders during impersonation (Vitest)
- [ ] Health page — service status grid (Vitest)

### Exit Criteria
- Super admin has full visibility into all tenants
- Revenue metrics calculated correctly
- Impersonation works with MFA, guardrails, audit trail, and 30-min expiry
- System health monitoring functional
- Tenant lifecycle management (activate, suspend, cancel, extend trial)
- Impersonation banner visible and undismissable

---

## S34 — Subscription & Billing Management

### Backend
- [ ] Plan upgrade/downgrade: `POST /api/v1/billing/change-plan`
  - Proration:
    - Upgrade: charge prorated difference immediately (remaining days × daily rate difference)
    - Downgrade: credit to next invoice (remaining days × daily rate difference)
  - Feature access updated immediately on upgrade
  - On downgrade: validate current usage doesn't exceed new plan limits (employee count, device count)
  - Audit logged
  - Dispatches `SubscriptionChanged` event → invalidates permission cache
- [ ] Seat-based billing: plan price adjusts based on employee count
  - Plan defines: base_price_cents, included_seats, additional_seat_price_cents
  - Monthly cost = base_price + max(0, employee_count - included_seats) × additional_seat_price
- [ ] Invoice generation: `GenerateInvoiceJob` runs monthly (1st of month)
  - Auto-generate draft invoices for all active tenants
  - Line items: plan fee, seat charges (if over included), prorations (credits/charges), adjustments
  - Bilingual invoice (EN/AM columns)
  - Invoice PDF generated and stored in MinIO
  - Notification sent to tenant admin
- [ ] Payment methods:
  - Manual bank transfer (primary for Ethiopian market)
  - Payment instruction display: bank name, account number, reference code (tenant subdomain + invoice number)
  - Admin marks invoice as paid: `POST /api/v1/billing/invoices/{id}/mark-paid` with payment reference
- [ ] Overdue handling (automated):
  - 7 days after due: send reminder notification
  - 14 days: second reminder with urgency
  - 30 days: mark subscription as `past_due`, warning banner in tenant dashboard
  - 60 days: suspend tenant (read-only access — can view but not create/edit)
  - On payment: automatically reactivate if suspended for non-payment
- [ ] Receipt PDF: `GET /api/v1/billing/invoices/{id}/receipt`
  - Generated on payment confirmation
  - Bilingual, company-branded

### Frontend
- [ ] Tenant billing page:
  - Current plan card: plan name, price (ETB), features list, employee limit, current employee count
  - "Change Plan" button → plan comparison modal
  - Next invoice preview card: amount, due date, line items
  - Invoice history DataTable: date, amount (CurrencyDisplay), status (StatusBadge), actions (download receipt, download invoice)
  - Payment instructions card: bank details, reference code, "Copy Reference" button
  - Overdue warning banner (if past_due — amber, showing days overdue)
- [ ] Plan comparison modal:
  - Side-by-side plan cards (current highlighted): features, pricing, limits
  - Proration preview: "Upgrading today will cost X ETB for the remaining Y days"
  - or "Downgrading will credit X ETB to your next invoice"
  - Usage check: "Your current usage (Z employees) exceeds the new plan's limit" warning
  - Confirm button
- [ ] Super admin plan management:
  - Plans DataTable: name, base price, included seats, seat price, status
  - Edit plan dialog: price, limits, features (JSON), active toggle
  - Create new plan
- [ ] Super admin invoice management:
  - All invoices DataTable (across tenants): tenant, amount, status, due date, actions
  - "Mark as Paid" button → dialog with payment reference input

### Tests
- [ ] Plan upgrade with proration — correct prorated charge (Pest)
- [ ] Plan downgrade with credit — correct credit applied (Pest)
- [ ] Downgrade blocked if usage exceeds new plan limits (Pest)
- [ ] Seat-based billing — correct calculation (Pest)
- [ ] Monthly invoice generation — all active tenants (Pest)
- [ ] Invoice line items — correct amounts (Pest)
- [ ] Overdue handling — 7-day reminder (Pest)
- [ ] Overdue handling — 30-day past_due status (Pest)
- [ ] Overdue handling — 60-day suspension (Pest)
- [ ] Payment reactivation — suspended tenant reactivated (Pest)
- [ ] Receipt PDF generation (Pest)
- [ ] Billing dashboard renders (Vitest)
- [ ] Plan comparison modal — proration preview (Vitest)
- [ ] Overdue warning banner (Vitest)

### Exit Criteria
- Plan upgrade/downgrade with correct proration
- Seat-based billing calculates correctly
- Monthly invoice auto-generation
- Overdue handling with escalation (reminder → past_due → suspend)
- Receipt PDF generation
- Payment via bank transfer with reference tracking
- Super admin can manage plans and mark invoices paid

---

## S35 — System Settings & Configuration

### Backend
- [ ] Tenant settings consolidated endpoint: `GET/PUT /api/v1/settings` (requires `settings.view`/`settings.edit`)
  - **General:** name, address (AddressForm fields), phone, email, timezone (default EAT), default locale
  - **Calendar:** ethiopian_calendar enabled/disabled, fiscal_year_start_month, pagumen_proration_strategy
  - **Attendance:** default_grace_period_minutes, ot_daily_cap_minutes, ot_weekly_cap_minutes, confidence_threshold, enabled methods (per AttendanceSetting)
  - **Leave:** working_days (array of day numbers), approval_chain (array of role slugs), min_staffing_percentage
  - **Payroll:** pay_period (monthly), payroll_run_day (day of month), auto_calculate_ot (boolean)
  - **Notifications:** email_sender_name, default_notification_language
  - **Security:** password_min_length, password_require_uppercase, password_require_number, session_timeout_minutes, mfa_policy (optional/required/disabled)
  - **Branding:** primary_color, secondary_color, logo (via FileService)
- [ ] Custom role management:
  - `GET /api/v1/settings/roles` — list all roles for tenant (system + custom)
  - `POST /api/v1/settings/roles` — create custom role (name, slug, permissions array)
  - `PUT /api/v1/settings/roles/{id}` — update role permissions
  - `DELETE /api/v1/settings/roles/{id}` — delete (only custom roles, not system roles)
  - `GET /api/v1/settings/permissions` — list all available permissions (grouped by module)
  - System roles: permissions viewable but not editable
  - Custom roles: full permission editor
  - Permission: `settings.edit`
- [ ] Notification template management:
  - `GET /api/v1/settings/notification-templates` — list all templates with default content
  - `PUT /api/v1/settings/notification-templates/{type}` — customize (subject, body, locale)
  - Default templates for each notification type (EN + AM)
  - Template variables: `{employee_name}`, `{leave_type}`, `{date}`, `{company_name}`, `{amount}`, `{days}`, `{approver_name}`
  - Preview: render template with sample data
  - Reset to default button
- [ ] Data management:
  - `POST /api/v1/settings/export` — full tenant data export (background job)
  - `GET /api/v1/settings/export/status` — export status and download link
  - Data retention settings: `audit_log_retention_days` (default 365), `notification_retention_days` (default 90)
  - Cleanup job: purges old audit logs and notifications per retention settings
- [ ] Audit log viewer:
  - `GET /api/v1/audit-logs` — tenant-scoped, paginated
  - Filterable by: action type, user, date range, entity type, entity ID
  - `GET /api/v1/audit-logs/export` — CSV export of filtered results
  - Each entry: timestamp, user, action, entity_type, entity_id, before (JSON), after (JSON), ip_address, impersonated_by

### Frontend
- [ ] Settings page (tabbed layout):
  - **General tab:** org name, address (`AddressForm`), phone (`PhoneInput`), email, timezone picker, locale picker
  - **Branding tab:** logo upload (`FileUpload`), color pickers (primary, secondary), live preview card
  - **Calendar tab:** Ethiopian calendar toggle, fiscal year start month dropdown, Pagumen strategy selector
  - **Attendance tab:** grace period (minutes input), OT caps (daily/weekly minutes), confidence threshold slider (50-100), method toggles
  - **Leave tab:** working days checkboxes (Sun-Sat), approval chain builder (drag roles to reorder), min staffing % input
  - **Payroll tab:** pay period (monthly, read-only for v1.0), run day (1-28), auto OT toggle
  - **Security tab:** password policy config (min length, complexity), session timeout (minutes), MFA policy (radio: optional/required/disabled)
  - **Notifications tab:** sender name input, default language, template list with edit buttons, test send button
  - **Roles & Permissions tab:** (new)
    - Roles DataTable: name, type (system/custom badge), user count, actions
    - Create custom role button → dialog with name input
    - Role detail: permission grid (modules as rows, actions as columns — checkboxes)
    - System role indicator: "This is a system role. Permissions cannot be modified."
    - Assign users: user list with role selector
  - **Data tab:** export button, retention settings (days inputs), "Clean up old data" button with confirmation
- [ ] Audit log viewer page:
  - DataTable: timestamp, user, action, entity type, summary
  - Filters: action type dropdown, user search, date range, entity type
  - Click row → detail drawer:
    - Before/after JSON diff view (highlighted changes)
    - Impersonation indicator (if impersonated_by is set)
    - IP address and device info
  - Export button (CSV of current filtered view)

### Tests
- [ ] Settings read/write — all categories (Pest)
- [ ] Custom role creation with permissions (Pest)
- [ ] Custom role deletion — system roles cannot be deleted (Pest)
- [ ] Permission check after role change — cache invalidated (Pest)
- [ ] Notification template customization — used in actual notifications (Pest)
- [ ] Notification template preview with sample data (Pest)
- [ ] Data export job — generates complete ZIP (Pest)
- [ ] Audit log filtering — all filter combinations (Pest)
- [ ] Audit log export — CSV correct (Pest)
- [ ] Data retention cleanup job (Pest)
- [ ] Settings form rendering — all tabs (Vitest)
- [ ] Role permission grid — checkbox interactions (Vitest)
- [ ] Audit log diff view — highlights changes (Vitest)

### Exit Criteria
- All tenant settings configurable from unified settings page
- Custom roles creatable with granular permissions
- Notification templates customizable with preview
- Full data export available
- Audit log viewable with before/after diff and exportable
- Data retention policies configurable and enforced
- All settings pages responsive and bilingual

---

## Phase 8 Exit Criteria

- [ ] Super admin console complete (tenants, revenue, health, impersonation with guardrails)
- [ ] Impersonation: MFA required, 30-min expiry, restricted actions, audit trail, persistent banner
- [ ] Subscription management with upgrade/downgrade/proration/seat-based
- [ ] Invoice auto-generation and overdue handling (reminder → past_due → suspend)
- [ ] Custom roles with granular permission editor
- [ ] All system settings configurable
- [ ] Notification templates customizable with preview
- [ ] Audit log viewable with diff view and exportable
- [ ] Data export and retention policies
- [ ] All Pest + Vitest tests passing
- [ ] TenantIsolationTest passes
