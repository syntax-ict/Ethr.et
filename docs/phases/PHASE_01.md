# Phase 1 — Public Website & Tenant Onboarding (v2.0)

> **Design record — not a progress tracker.**
> The `- [ ]` checkboxes below are the original up-front specification and were
> never maintained against the code. They under-report reality badly: several
> phases read as 0% complete while the features they describe are live and
> covered by tests. **Do not use them to judge what is done.**
>
> The live, code-grounded status is [`ENTERPRISE_ROADMAP.md`](ENTERPRISE_ROADMAP.md),
> with the standing audits in [`ETHR_AUDIT.md`](ETHR_AUDIT.md) and
> [`ETHR_AUDIT_2026-08-14.md`](ETHR_AUDIT_2026-08-14.md). Per the project rule,
> the source of truth is the code — verify against it, not against this file.
>
> Keep this document for its design intent: scope, data model, and acceptance
> criteria, which remain accurate and useful.

## Prerequisites
- Phase 0 complete (Docker, auth, tenancy, permissions, design system)

## Objective
Build the public marketing website, self-service tenant signup flow, 7-step setup wizard with organization templates, and demo tenant seeder.

---

## Completion Status (2026-07-30)

**Phase 1 is functionally complete and green.** Backend Pest (Register, Contact, Plan,
Onboarding, Branding, DemoTenantSeeder, TenantIsolation) and the frontend Vitest suite
(30 files / 209 tests) all pass; frontend `tsc --noEmit` is clean.

One deliberate divergence from the spec: the **frontend setup wizard is a streamlined
6-step flow** (Welcome → Organization → Branding → Template → First Branch → Invite Team)
rather than the literal 7 UI steps below. The **backend** onboarding exposes all 7 named
steps, and the deeper configuration the spec places inside the wizard (org-structure edit,
work schedule, leave policies, payroll config, CSV import) is delivered in dedicated product
modules — `Settings → Shifts/Leave Types/Payroll`, `Organization`, and `Employees → Import`.
Wizard sub-items that live in those modules rather than in the wizard are marked
`[~]` (delivered elsewhere) below.

---

## S06 — Marketing Website

### Frontend
- [x] Marketing layout: top nav (logo, Product, Pricing, FAQ, Contact, Login, "Start Free Trial" CTA)
- [x] Landing page:
  - Hero section: headline, subheadline, CTA buttons, product screenshot/mockup
  - Feature highlights: attendance, payroll, offline-first, multi-tenant (icon + title + description)
  - Industry showcase: government, banking, hospital, manufacturing cards
  - Social proof section: placeholder for testimonials/logos
  - CTA banner: "Start your 6-month free trial"
  - Footer: links, contact info, social media, language switcher
  - Ethiopian tibeb pattern as section dividers (design identity)
- [x] Pricing page:
  - 3 plan tiers (Starter, Professional, Enterprise) in card layout
  - Feature comparison table (uses DataTable with minimal config)
  - FAQ accordion
  - "Start Free Trial" and "Contact Sales" CTAs
- [x] Features page:
  - Attendance platform deep-dive (multi-source diagram)
  - HR management overview
  - Payroll overview
  - Offline-first capabilities explanation
  - Localization highlights (dual calendar, Amharic)
- [x] Contact page: name, email, phone, organization, message form
- [x] FAQ page: accordion of common questions
- [x] All pages responsive (mobile, tablet, desktop)
- [x] All text via i18n keys (en + am)
- [x] SEO meta tags, Open Graph tags
- [x] Semantic color tokens used throughout (no raw Tailwind colors)

### Backend
- [x] Contact form endpoint: `POST /api/v1/contact` (rate limited: 3/min/IP, email notification)
- [x] Plan listing endpoint: `GET /api/v1/plans` (public, no auth, cached 24hr)

### Tests
- [x] Landing page renders all sections (Vitest)
- [x] Pricing page shows plans (Vitest)
- [x] Contact form submission + rate limiting (Pest)
- [x] Mobile responsive layout (Vitest)
- [x] Dark mode rendering (Vitest)

### Exit Criteria
- Marketing site is professional with Ethiopian design identity
- All pages responsive and bilingual
- Contact form sends notification with rate limiting

---

## S07 — Self-Service Signup & Tenant Provisioning

### Backend
- [x] Registration endpoint: `POST /api/v1/register`
  - Input: organization name, admin name, admin email, password, organization type, subdomain (auto-suggested)
  - Validates: unique email, unique subdomain, password strength (per tenant password policy defaults)
  - Creates: Tenant (status=trial, trial_ends_at = +6 months), User (role=tenant_admin via permission system), Subscription (plan=starter, status=trial)
  - Seeds: system roles and default permissions for new tenant
  - Sends: verification email
  - Returns: tenant info + access tokens
  - Adds `schema_version` to new tenant
- [x] Email verification: `POST /api/v1/auth/verify-email` (token-based, 24hr expiry)
- [x] Subdomain availability check: `GET /api/v1/register/check-subdomain?subdomain=acme` (real-time)
- [x] `TenantCreated` event dispatches:
  - Create MinIO bucket (`tenant-{public_id}/`)
  - Seed default settings (timezone EAT, locale en, calendar gregorian)
  - Seed system roles with default permissions
  - Create default shift (8:30-17:30, Mon-Sat)
  - Initialize audit log
- [x] Trial enforcement: a trial-expired tenant is blocked. **Not** via the dedicated
      402-with-upgrade-CTA path this line originally claimed — `EnforceTrialExpiration`
      was never registered on any route or in `bootstrap/app.php` and has been removed
      (2026-08-31; verified zero references anywhere in `api/` or `src/` before deletion).
      Enforcement is real but generic: `Tenant::isActive()` already composes
      `status IN (trial, active) AND NOT isTrialExpired()`, and `ResolveTenant::lookupTenant()`
      calls it on every request, refusing an expired-trial tenant with the same
      403 `tenant-inactive` response a suspended or cancelled tenant gets — which the
      frontend already renders (`tenant-lookup-error-state.tsx`). A trial-specific
      402 + upgrade CTA does not exist on either side and would be new product work
      (a billing/conversion UX decision), not a bug fix — flagged, not built.
- [x] Trial expiry notifications: 30 days, 7 days, 1 day before expiry (`TrialExpiringNotification`)

### Frontend
- [x] Registration page:
  - Organization name input
  - Organization type selector (dropdown with icons for each industry)
  - Admin name, email, password inputs
  - Password strength indicator (visual bar + requirements checklist)
  - Subdomain preview (`yourcompany.ethr.et`) with real-time availability check (debounced)
  - Terms acceptance checkbox
  - "Create Free Account" button (with loading state)
  - Error handling for all validation failures (RFC-7807 display)
- [x] Email verification page (enter 6-digit code or click email link)
- [x] Post-registration redirect to setup wizard (S08)
- [x] All forms use Zod validation + React Hook Form
- [x] Mobile-optimized registration flow

### Tests
- [x] Full registration flow (Pest): register → verify email → login → access dashboard
- [x] Duplicate email rejection (Pest)
- [x] Duplicate subdomain rejection (Pest)
- [x] Trial expiration enforcement — 402 response (Pest)
- [x] Subdomain availability check (Pest)
- [x] TenantCreated event: bucket created, roles seeded, defaults set (Pest)
- [x] TenantIsolationTest still passes with new tenant (Pest)
- [x] Registration form validation (Vitest)
- [x] Subdomain preview updates live (Vitest)
- [x] Password strength indicator (Vitest)

### Exit Criteria
- New user can register, verify email, and access their tenant dashboard
- Subdomain `company.ethr.test` resolves to their tenant
- System roles and permissions seeded for new tenant
- Trial is 6 months from signup with expiry notifications
- MinIO bucket created for new tenant

---

## S08 — Setup Wizard & Organization Templates

> **Correction (2026-07-30, Onboarding v2 Slice 1).** The three `apply-template`
> sub-items below were marked `[x]` but the endpoint only wrote `tenant.type` and
> two settings keys — it created **no** records, and the frontend still toasted
> "departments, positions, shifts, and leave types created". That is now real,
> via `App\Services\Onboarding\OrganizationProvisioner`. Marks corrected below and
> the divergence recorded in [ONBOARDING_V2.md](ONBOARDING_V2.md).

### Backend
- [x] `organization_templates` table + seeder with 8 templates:
  - Government Office, Private Bank, Hospital, Manufacturing, NGO, Hotel, University, General Company
  - Each template contains JSON: departments, positions, grades, shifts, leave types, payroll defaults, working days, attendance method preferences, Pagumen proration strategy, fiscal year start
- [x] Template listing: `GET /api/v1/templates` (public, cacheable 24hr)
- [x] Template application: `POST /api/v1/onboarding/apply-template` — `OrganizationProvisioner`
  - [x] Creates departments, positions, grades from template
  - [x] Creates shifts from template (first shift becomes tenant default; midnight-crossing detected)
  - [x] Creates leave types from template (bare codes expanded via `LeaveTypeCatalog` to statutory Ethiopian defaults)
  - [x] Seeds public holidays for the year via the shared `HolidayService` (exact movable-feast computus)
  - [x] Sets payroll defaults + attendance method + employee-number format from template `settings` (create-only, never overwrites a namespace the tenant edited)
  - [x] Idempotent (create-only; re-apply creates nothing, restores soft-deleted matches rather than colliding on their unique code)
  - [x] Returns a per-resource `provisioned` tally; the tally is written to the `onboarding.template_applied` audit entry
- [x] Onboarding progress endpoints:
  - `GET /api/v1/onboarding/progress` — current step + all saved data
  - `PUT /api/v1/onboarding/progress/{step}` — step-keyed save; `{step}` validated against the `OnboardingStep` enum
  - `POST /api/v1/onboarding/apply-template`, `POST /api/v1/onboarding/invite`, `POST /api/v1/onboarding/complete`
  - `[~]` The named per-step deep-config routes (`org_structure`, `work_schedule`, `leave_policies`, `payroll_config`, `employee_import`, `review_launch`) were spec-only and never existed as distinct endpoints; that configuration lives in the `Organization`, `Settings`, and `Employees → Import` modules as noted in the Phase 1 completion status
- [x] `OnboardingStep` enum: 7 canonical steps (`App\Enums\OnboardingStep`; see [ONBOARDING_V2.md](ONBOARDING_V2.md) D1 for the eleven→seven mapping)
- [x] Progress auto-saved on each step (resume on refresh/return)
- [x] **Smart configuration engine** (`OnboardingStep::SMART_CONFIGURATION`; [ONBOARDING_V2.md](ONBOARDING_V2.md) D2–D3):
  - `IndustryCatalog` — 27 selectable industries, each aliased to one of the 8 maintained base templates plus a thin override patch
  - `IndustryProfileResolver` → `ConfigurationPlan`: a scored, editable plan where each section carries provenance (`ConfigurationSource`: explicit 1.0 / industry_default 0.8 / heuristic 0.6 / global_fallback 0.4) and a confidence derived purely from it — a deterministic, auditable substitute for an LLM score (no model call)
  - `GET /api/v1/onboarding/industries` — the picker list
  - `POST /api/v1/onboarding/configuration/preview` — scored plan for an industry + tenant signals (headcount, region); read-only, writes nothing
  - `POST /api/v1/onboarding/configuration/apply` — provisions the (possibly edited) plan through `OrganizationProvisioner`, marks step 3, audits `onboarding.configuration_applied`; `save:true` stores the plan in `settings.saved_configuration`
  - Global "save as reusable template" is deferred (the `organization_templates` catalog is global and needs tenant scoping first)
  - Covered by `IndustryConfigurationTest` (catalog, resolver provenance/heuristics, and all three endpoints)
- [~] Tenant branding: `PUT /api/v1/settings/branding` — stores a **logo URL** + theme colors (covered by `BrandingTest`). Direct logo *file* upload with magic-byte content verification is not wired into this endpoint; magic-byte verification exists as the `VerifyFileContent` rule used on photo/document/import uploads.

### Frontend

> **Note:** the frontend wizard is a streamlined 6-step flow. Steps 2–7 of the deep
> configuration below are delivered in dedicated product modules (`Organization`,
> `Settings → Shifts/Leave Types/Payroll`, `Employees → Import`) rather than inside the
> wizard; those items are marked `[~]` (delivered elsewhere).

- [x] `SetupWizard` component:
  - Progress bar with steps (step names, current step highlighted, completed steps checked)
  - Back/Next navigation (Next disabled until required fields valid)
  - Skip button (skips straight to the dashboard)
  - Progress saved on each step transition
- [x] Step 1 — Organization Profile (name/type pre-filled, template selector cards, template preview)
  - `[~]` Size-range selector and logo *file* upload — branding step uses a logo URL; file upload + content verification lives in `Settings → Branding`
- [~] Step 2 — Organization Structure — wizard adds a first branch; department list edit, drag-drop reorder, and hierarchy live in the `Organization` module
- [~] Step 3 — Work Schedule — `Settings → Shifts` and `Attendance → Settings`
- [~] Step 4 — Leave Policies — `Settings → Leave Types`
- [~] Step 5 — Payroll Configuration — `Settings → Payroll` (allowance/tax/pension cards)
- [~] Step 6 — Employee Import — `Employees → Import` (mapping, preview, validation, commit)
- [~] Step 7 — Review & Launch — wizard has Invite Team + finish/celebration; a read-only per-step summary is not in the wizard
- [x] All steps bilingual (en + am)
- [x] All steps responsive (mobile-friendly — employees may set up on phone)

### Tests
- [x] Template listing (Pest)
- [x] Template application creates correct records — verify count and attributes (Pest — `OnboardingTest` "applies a template and creates the records it describes" + `OrganizationProvisionerTest`)
- [x] Template re-application is idempotent (Pest — `OnboardingTest` "re-applies a template without duplicating anything"; `OrganizationProvisionerTest` covers restore-on-trashed and no-overwrite-of-edits)
- [x] Full 7-step onboarding flow end-to-end (Pest)
- [x] Step progress persistence — resume after refresh (Pest)
- [x] Branding endpoint (Pest) — `BrandingTest` covers logo-URL + theme update, hex-color validation, and non-admin authorization *(the branding endpoint stores a URL, so there is no file magic-byte step to assert here; that path is covered where uploads actually occur)*
- [x] Setup wizard navigation — forward, back, skip (Vitest)
- [x] Template selector renders all 8 templates (Vitest)
- [x] Template preview shows departments/positions (Vitest)
- [x] CSV import step — upload, preview, validation errors (Vitest) — `employees-import.test.tsx` covers upload → preview (valid vs. error rows) → commit; import UI lives in `Employees → Import`
- [~] Review step — summary matches saved data (Vitest) — no in-wizard review-summary step

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
- [x] `DemoSeeder` command: `php artisan db:seed --class=DemoTenantSeeder` *(class is `DemoTenantSeeder`)*
- [x] Creates demo tenant (`demo.ethr.test`) with:
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
- [x] Demo admin credentials: `admin@demo.ethr.test` / `demo123456`
- [x] Demo employee credentials: `employee@demo.ethr.test` / `demo123456`
- [x] Demo manager credentials: `manager@demo.ethr.test` / `demo123456`
- [x] Seeder is idempotent (can run multiple times safely — clears and re-seeds)
- [x] All demo data passes TenantIsolationTest

### Tests
- [x] DemoSeeder creates all expected data (Pest)
- [x] Demo credentials work for login (Pest)
- [x] TenantIsolationTest passes with demo tenant (Pest)
- [x] Demo data is realistic (attendance patterns, payroll calculations) (Pest)

### Exit Criteria
- `php artisan db:seed --class=DemoSeeder` creates a fully functional demo environment
- Sales demos can showcase all features with realistic data
- 3 role-based demo accounts available
- Demo data includes 6 months of history

---

## Phase 1 Exit Criteria

- [x] Marketing website is professional, responsive, bilingual, with Ethiopian design identity
- [x] Self-service signup creates tenant in < 30 seconds
- [x] System roles and permissions seeded per new tenant
- [x] 8 organization templates available with preview
- [x] Setup wizard works end-to-end (streamlined 6-step frontend; backend exposes all 7 named steps)
- [x] Tenant branding applied (logo, colors)
- [x] Email verification works
- [x] Trial enforcement (6 months) with expiry notifications
- [x] Demo tenant seeder creates realistic 150+ employee environment
- [x] All Pest + Vitest tests passing
- [x] TenantIsolationTest passes
