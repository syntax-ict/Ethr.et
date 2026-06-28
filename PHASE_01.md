# Phase 1 — Public Website & Tenant Onboarding

## Prerequisites
- Phase 0 complete (Docker, auth, tenancy, design system)

## Objective
Build the public marketing website and self-service tenant signup flow, including the new setup wizard with organization templates.

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
- [ ] Pricing page:
  - 3 plan tiers (Starter, Professional, Enterprise) in card layout
  - Feature comparison table
  - FAQ accordion
  - "Start Free Trial" and "Contact Sales" CTAs
- [ ] Features page:
  - Attendance platform deep-dive
  - HR management overview
  - Payroll overview
  - Offline-first capabilities
  - Localization highlights
- [ ] Contact page: name, email, phone, organization, message form
- [ ] FAQ page: accordion of common questions
- [ ] All pages responsive (mobile, tablet, desktop)
- [ ] All text via i18n keys (en + am)
- [ ] SEO meta tags, Open Graph tags

### Backend
- [ ] Contact form endpoint: `POST /api/v1/contact` (rate limited, email notification)
- [ ] Plan listing endpoint: `GET /api/v1/plans` (public, no auth)

### Tests
- [ ] Landing page renders all sections (Vitest)
- [ ] Pricing page shows plans (Vitest)
- [ ] Contact form submission (Pest)
- [ ] Mobile responsive layout (Vitest)

### Exit Criteria
- Marketing site is professional and complete
- All pages responsive and bilingual
- Contact form sends notification

---

## S07 — Self-Service Signup & Tenant Provisioning

### Backend
- [ ] Registration endpoint: `POST /api/v1/register`
  - Input: organization name, admin name, admin email, password, organization type, subdomain (auto-suggested)
  - Validates: unique email, unique subdomain, password strength
  - Creates: Tenant (status=trial, trial_ends_at = +6 months), User (role=tenant_admin), Subscription (plan=starter, status=trial)
  - Sends: verification email
  - Returns: tenant info + access tokens
- [ ] Email verification: `POST /api/v1/auth/verify-email` (token-based)
- [ ] Subdomain availability check: `GET /api/v1/register/check-subdomain?subdomain=acme`
- [ ] Tenant creation dispatches `TenantCreated` event:
  - Create MinIO bucket
  - Seed default settings (timezone, locale, calendar)
  - Create default shifts (if template selected)
- [ ] Trial enforcement: middleware checks `trial_ends_at`, returns 402 if expired

### Frontend
- [ ] Registration page:
  - Organization name input
  - Organization type selector (dropdown with icons)
  - Admin name, email, password inputs
  - Subdomain preview (`yourcompany.ethr.et`) with real-time availability check
  - Terms acceptance checkbox
  - "Create Free Account" button
- [ ] Email verification page (enter code or click link)
- [ ] Post-registration redirect to setup wizard (Phase 1, S08)
- [ ] Password strength indicator

### Tests
- [ ] Full registration flow (Pest): register -> verify email -> login -> access dashboard
- [ ] Duplicate email/subdomain rejection (Pest)
- [ ] Trial expiration enforcement (Pest)
- [ ] Subdomain availability check (Pest)
- [ ] Registration form validation (Vitest)
- [ ] Subdomain preview updates (Vitest)

### Exit Criteria
- New user can register, verify email, and access their tenant dashboard
- Subdomain `company.ethr.test` resolves to their tenant
- Trial is 6 months from signup

---

## S08 — Setup Wizard & Organization Templates

### Backend
- [ ] `organization_templates` table + seeder with templates:
  - Government Office, Private Bank, Hospital, Manufacturing, NGO, Hotel, University, General Company
  - Each template contains JSON: departments, positions, grades, shifts, leave types, payroll defaults
- [ ] Template listing: `GET /api/v1/templates` (public, cacheable)
- [ ] Template application: `POST /api/v1/onboarding/apply-template` (creates departments, positions, shifts, leave types from template)
- [ ] Extend existing onboarding endpoints:
  - `PUT /api/v1/onboarding/progress/org_profile` — save org details + template selection
  - `PUT /api/v1/onboarding/progress/org_structure` — review/edit departments, add branches
  - `PUT /api/v1/onboarding/progress/work_schedule` — configure shifts, working days, attendance rules
  - `PUT /api/v1/onboarding/progress/leave_policies` — configure leave types and accrual
  - `PUT /api/v1/onboarding/progress/payroll_config` — salary structure, tax, pension
  - `PUT /api/v1/onboarding/progress/employee_import` — CSV upload (existing)
  - `PUT /api/v1/onboarding/progress/review_launch` — mark complete, redirect to dashboard
- [ ] Onboarding progress: 7-step wizard (expanded from 4-step)
- [ ] `OnboardingStep` enum updated for 7 steps
- [ ] Tenant branding: `PUT /api/v1/settings/branding` (logo upload via FileService, color theme)

### Frontend
- [ ] `SetupWizard` component:
  - Progress bar with 7 steps
  - Back/Next navigation
  - Skip button (for experienced admins)
  - Progress saved on each step
- [ ] Step 1 — Organization Profile:
  - Organization name (pre-filled from registration)
  - Industry/type (pre-filled from registration)
  - Size range selector
  - Logo upload
  - Template selector: visual cards for each template with preview
- [ ] Step 2 — Organization Structure:
  - Department list (pre-populated from template)
  - Add/edit/remove departments
  - Branch management (add locations with name, city, address)
  - Drag-and-drop department reordering
- [ ] Step 3 — Work Schedule:
  - Shift definition form (name, start, end, break, grace period)
  - Pre-populated shifts from template
  - Working days selector (checkboxes for each day)
  - Attendance rules (late threshold, overtime policy)
- [ ] Step 4 — Leave Policies:
  - Leave type list (pre-populated from template)
  - Edit days, accrual, carry-forward per type
  - Add custom leave types
- [ ] Step 5 — Payroll Configuration:
  - Basic salary structure explanation
  - Allowance types (transport, housing, position — enable/disable)
  - Tax configuration (Ethiopian defaults pre-filled)
  - Pension rates (7% + 11% pre-filled)
- [ ] Step 6 — Employee Import:
  - Download CSV template
  - Upload CSV with field mapping
  - Preview table with validation errors
  - Commit import
  - Or skip (add employees later)
- [ ] Step 7 — Review & Launch:
  - Summary of all settings (read-only)
  - "Invite Team Members" section (email input list)
  - "Launch Dashboard" button
- [ ] All steps bilingual (en + am)

### Tests
- [ ] Template listing (Pest)
- [ ] Template application creates correct records (Pest)
- [ ] Full 7-step onboarding flow (Pest)
- [ ] Step progress persistence (Pest)
- [ ] Setup wizard navigation (Vitest)
- [ ] Template selector renders all templates (Vitest)
- [ ] CSV import step (Vitest)

### Exit Criteria
- New tenant sees 7-step wizard after registration
- Selecting a template pre-populates departments, shifts, leave types
- All steps editable, progress saved
- CSV import works with preview + validation
- Completing wizard launches dashboard
- Branding (logo + colors) applied across tenant

---

## Phase 1 Exit Criteria

- [ ] Marketing website is professional, responsive, bilingual
- [ ] Self-service signup creates tenant in < 30 seconds
- [ ] 8 organization templates available
- [ ] 7-step setup wizard works end-to-end
- [ ] Tenant branding applied (logo, colors)
- [ ] Email verification works
- [ ] Trial enforcement (6 months)
- [ ] All Pest + Vitest tests passing
