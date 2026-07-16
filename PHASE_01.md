# Phase 1 — Public Website & Tenant Onboarding (v2.0)

## Prerequisites
- Phase 0 complete (Docker, auth, tenancy, permissions, design system)

## Objective
Build the public marketing website, self-service tenant signup flow, 7-step setup wizard with organization templates, and demo tenant seeder.

---

## S06 — Marketing Website

### Frontend
- [ ] Marketing layout: top nav (logo, Product, Pricing, Demo, Blog, Login, "Start Free Trial" CTA)
- [ ] Landing page:
  - Hero section: headline, subheadline, CTA buttons, product screenshot/mockup
  - Feature highlights: attendance, payroll, offline-first, multi-tenant (icon + title + description)
  - Industry showcase: government, banking, hospital, manufacturing cards
  - Social proof section: placeholder for testimonials/logos
  - CTA banner: "Start your 6-month free trial"
  - Footer: links, contact info, social media, language switcher
  - Ethiopian tibeb pattern as section dividers (design identity)
- [ ] Pricing page:
  - 3 plan tiers (Starter, Professional, Enterprise) in card layout
  - Feature comparison table (uses DataTable with minimal config)
  - FAQ accordion
  - "Start Free Trial" and "Contact Sales" CTAs
- [ ] Features page:
  - Attendance platform deep-dive (multi-source diagram)
  - HR management overview
  - Payroll overview
  - Offline-first capabilities explanation
  - Localization highlights (dual calendar, Amharic)
- [ ] Contact page: name, email, phone, organization, message form
- [ ] FAQ page: accordion of common questions
- [ ] All pages responsive (mobile, tablet, desktop)
- [ ] All text via i18n keys (en + am)
- [ ] SEO meta tags, Open Graph tags
- [ ] Semantic color tokens used throughout (no raw Tailwind colors)

### Backend
- [ ] Contact form endpoint: `POST /api/v1/contact` (rate limited: 3/min/IP, email notification)
- [ ] Plan listing endpoint: `GET /api/v1/plans` (public, no auth, cached 24hr)

### Tests
- [ ] Landing page renders all sections (Vitest)
- [ ] Pricing page shows plans (Vitest)
- [ ] Contact form submission + rate limiting (Pest)
- [ ] Mobile responsive layout (Vitest)
- [ ] Dark mode rendering (Vitest)

### Exit Criteria
- Marketing site is professional with Ethiopian design identity
- All pages responsive and bilingual
- Contact form sends notification with rate limiting

---

## S07 — Self-Service Signup & Tenant Provisioning

### Backend
- [ ] Registration endpoint: `POST /api/v1/register`
  - Input: organization name, admin name, admin email, password, organization type, subdomain (auto-suggested)
  - Validates: unique email, unique subdomain, password strength (per tenant password policy defaults)
  - Creates: Tenant (status=trial, trial_ends_at = +6 months), User (role=tenant_admin via permission system), Subscription (plan=starter, status=trial)
  - Seeds: system roles and default permissions for new tenant
  - Sends: verification email
  - Returns: tenant info + access tokens
  - Adds `schema_version` to new tenant
- [ ] Email verification: `POST /api/v1/auth/verify-email` (token-based, 24hr expiry)
- [ ] Subdomain availability check: `GET /api/v1/register/check-subdomain?subdomain=acme` (real-time)
- [ ] `TenantCreated` event dispatches:
  - Create MinIO bucket (`tenant-{public_id}/`)
  - Seed default settings (timezone EAT, locale en, calendar gregorian)
  - Seed system roles with default permissions
  - Create default shift (8:30-17:30, Mon-Sat)
  - Initialize audit log
- [ ] Trial enforcement: middleware checks `trial_ends_at`, returns 402 if expired with upgrade CTA
- [ ] Trial expiry notifications: 30 days, 7 days, 1 day before expiry

### Frontend
- [ ] Registration page:
  - Organization name input
  - Organization type selector (dropdown with icons for each industry)
  - Admin name, email, password inputs
  - Password strength indicator (visual bar + requirements checklist)
  - Subdomain preview (`yourcompany.ethr.et`) with real-time availability check (debounced)
  - Terms acceptance checkbox
  - "Create Free Account" button (with loading state)
  - Error handling for all validation failures (RFC-7807 display)
- [ ] Email verification page (enter 6-digit code or click email link)
- [ ] Post-registration redirect to setup wizard (S08)
- [ ] All forms use Zod validation + React Hook Form
- [ ] Mobile-optimized registration flow

### Tests
- [ ] Full registration flow (Pest): register → verify email → login → access dashboard
- [ ] Duplicate email rejection (Pest)
- [ ] Duplicate subdomain rejection (Pest)
- [ ] Trial expiration enforcement — 402 response (Pest)
- [ ] Subdomain availability check (Pest)
- [ ] TenantCreated event: bucket created, roles seeded, defaults set (Pest)
- [ ] TenantIsolationTest still passes with new tenant (Pest)
- [ ] Registration form validation (Vitest)
- [ ] Subdomain preview updates live (Vitest)
- [ ] Password strength indicator (Vitest)

### Exit Criteria
- New user can register, verify email, and access their tenant dashboard
- Subdomain `company.ethr.test` resolves to their tenant
- System roles and permissions seeded for new tenant
- Trial is 6 months from signup with expiry notifications
- MinIO bucket created for new tenant

---

## S08 — Setup Wizard & Organization Templates

### Backend
- [ ] `organization_templates` table + seeder with 8 templates:
  - Government Office, Private Bank, Hospital, Manufacturing, NGO, Hotel, University, General Company
  - Each template contains JSON: departments, positions, grades, shifts, leave types, payroll defaults, working days, attendance method preferences, Pagumen proration strategy, fiscal year start
- [ ] Template listing: `GET /api/v1/templates` (public, cacheable 24hr)
- [ ] Template application: `POST /api/v1/onboarding/apply-template`
  - Creates departments, positions, grades from template
  - Creates shifts from template
  - Creates leave types from template
  - Sets payroll defaults from template
  - Sets attendance method preferences
  - Sets calendar and fiscal year settings
  - Idempotent (can re-apply without duplicating)
- [ ] Onboarding progress endpoints (7-step):
  - `GET /api/v1/onboarding/progress` — current step + all saved data
  - `PUT /api/v1/onboarding/progress/org_profile` — org details + template selection
  - `PUT /api/v1/onboarding/progress/org_structure` — review/edit departments, add branches
  - `PUT /api/v1/onboarding/progress/work_schedule` — configure shifts, working days, attendance rules
  - `PUT /api/v1/onboarding/progress/leave_policies` — configure leave types and accrual
  - `PUT /api/v1/onboarding/progress/payroll_config` — salary structure, tax, pension, Pagumen strategy
  - `PUT /api/v1/onboarding/progress/employee_import` — CSV upload (existing)
  - `PUT /api/v1/onboarding/progress/review_launch` — mark complete, redirect to dashboard
- [ ] `OnboardingStep` enum: 7 steps
- [ ] Progress auto-saved on each step (resume on refresh/return)
- [ ] Tenant branding: `PUT /api/v1/settings/branding` (logo upload via FileService with content verification, color theme)

### Frontend
- [ ] `SetupWizard` component:
  - Progress bar with 7 steps (step names, current step highlighted, completed steps checked)
  - Back/Next navigation (Next disabled until required fields valid)
  - Skip button (for experienced admins — skips to review)
  - Progress saved on each step transition
  - Unsaved changes warning if navigating away
- [ ] Step 1 — Organization Profile:
  - Organization name (pre-filled from registration)
  - Industry/type (pre-filled from registration)
  - Size range selector (1-50, 51-200, 201-500, 500+)
  - Logo upload (FileUpload component, content verification)
  - Template selector: visual cards for each template with icon, description, and "what you get" preview
  - Template preview: show departments, positions, shifts that will be created
- [ ] Step 2 — Organization Structure:
  - Department list (pre-populated from template, editable)
  - Add/edit/remove departments (inline editing)
  - Branch management (add locations: name, city, address, geofence coordinates optional)
  - Drag-and-drop department reordering
  - Department hierarchy (parent-child selector)
- [ ] Step 3 — Work Schedule:
  - Shift definition form (name, start time, end time, break duration, grace period)
  - Pre-populated shifts from template
  - Working days selector (checkboxes for each day, default Mon-Sat for Ethiopia)
  - Attendance method configuration (which sources to enable)
  - Late threshold, overtime policy
- [ ] Step 4 — Leave Policies:
  - Leave type list (pre-populated from template)
  - Edit days, accrual method, carry-forward max per type
  - Add custom leave types
  - Gender-restricted leave type indicators (maternity/paternity)
- [ ] Step 5 — Payroll Configuration:
  - Basic salary structure explanation
  - Allowance types (transport, housing, position — enable/disable, set amounts)
  - Tax configuration (Ethiopian defaults pre-filled, editable)
  - Pension rates (7% + 11% pre-filled, editable)
  - Pagumen proration strategy selector (full_month / daily_rate)
  - Fiscal year start month selector
- [ ] Step 6 — Employee Import:
  - Download CSV template button
  - Upload CSV with drag-and-drop (FileUpload component)
  - Field mapping UI (auto-detected columns + manual override)
  - Preview table with validation (green rows valid, red rows with error messages)
  - Commit import with progress indicator
  - Or skip ("Add employees later" — no penalty)
- [ ] Step 7 — Review & Launch:
  - Summary of all settings (read-only cards per step)
  - Edit button per section (jumps back to that step)
  - "Invite Team Members" section (email input list for initial HR staff)
  - "Launch Dashboard" button
  - Celebration animation on launch
- [ ] All steps bilingual (en + am)
- [ ] All steps responsive (mobile-friendly — employees may set up on phone)

### Tests
- [ ] Template listing (Pest)
- [ ] Template application creates correct records — verify count and attributes (Pest)
- [ ] Template re-application is idempotent (Pest)
- [ ] Full 7-step onboarding flow end-to-end (Pest)
- [ ] Step progress persistence — resume after refresh (Pest)
- [ ] Branding upload with content verification (Pest)
- [ ] Setup wizard navigation — forward, back, skip (Vitest)
- [ ] Template selector renders all 8 templates (Vitest)
- [ ] Template preview shows departments/positions (Vitest)
- [ ] CSV import step — upload, preview, validation errors (Vitest)
- [ ] Review step — summary matches saved data (Vitest)

### Exit Criteria
- New tenant sees 7-step wizard after registration
- 8 organization templates available with previews
- Selecting a template pre-populates departments, shifts, leave types, payroll config
- All steps editable, progress auto-saved
- CSV import works with preview + validation
- Completing wizard launches dashboard with all settings applied
- Branding (logo + colors) applied across tenant

---

## S09 — Demo Tenant Seeder (NEW)

### Backend
- [ ] `DemoSeeder` command: `php artisan db:seed --class=DemoSeeder`
- [ ] Creates demo tenant (`demo.ethr.test`) with:
  - 150+ employees across 5 departments and 3 branches
  - Realistic Ethiopian names (Amharic + English)
  - Varied employee statuses (active, probation, suspended, departed)
  - Employee photos (placeholder avatars)
  - Department hierarchy (2 levels deep)
  - 3 shifts (morning, afternoon, night)
  - 6 months of attendance data:
    - Realistic patterns (most employees on time, some consistently late)
    - Various sources (biometric, mobile, QR, manual)
    - Some missing punches
    - Some corrections (pending, approved, rejected)
  - 3 months of payroll data:
    - Full payroll runs (approved)
    - Varied salaries, allowances, loans
    - Correct tax and pension calculations
  - Leave data:
    - Accrued balances
    - Pending, approved, and rejected leave requests
    - Various leave types used
  - Active and inactive biometric devices
  - Announcements (3 published, 1 draft)
  - Notifications (mix of read and unread)
- [ ] Demo admin credentials: `admin@demo.ethr.test` / `demo123456`
- [ ] Demo employee credentials: `employee@demo.ethr.test` / `demo123456`
- [ ] Demo manager credentials: `manager@demo.ethr.test` / `demo123456`
- [ ] Seeder is idempotent (can run multiple times safely — clears and re-seeds)
- [ ] All demo data passes TenantIsolationTest

### Tests
- [ ] DemoSeeder creates all expected data (Pest)
- [ ] Demo credentials work for login (Pest)
- [ ] TenantIsolationTest passes with demo tenant (Pest)
- [ ] Demo data is realistic (attendance patterns, payroll calculations) (Pest)

### Exit Criteria
- `php artisan db:seed --class=DemoSeeder` creates a fully functional demo environment
- Sales demos can showcase all features with realistic data
- 3 role-based demo accounts available
- Demo data includes 6 months of history

---

## Phase 1 Exit Criteria

- [ ] Marketing website is professional, responsive, bilingual, with Ethiopian design identity
- [ ] Self-service signup creates tenant in < 30 seconds
- [ ] System roles and permissions seeded per new tenant
- [ ] 8 organization templates available with preview
- [ ] 7-step setup wizard works end-to-end
- [ ] Tenant branding applied (logo, colors)
- [ ] Email verification works
- [ ] Trial enforcement (6 months) with expiry notifications
- [ ] Demo tenant seeder creates realistic 150+ employee environment
- [ ] All Pest + Vitest tests passing
- [ ] TenantIsolationTest passes
