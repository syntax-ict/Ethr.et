# ETHR Onboarding v2 — Audit, Architecture Decision & Delivery Plan

Status: **Decision document. Not yet implemented.**
Date: 2026-07-30
Supersedes the onboarding sections of `PHASE_01.md` (S08) once approved.

---

## 1. Audit of what exists today

Verified against code, not documentation.

| Capability | Doc claim | Actual code | Verdict |
|---|---|---|---|
| `POST /onboarding/apply-template` creates departments, positions, grades, shifts, leave types, payroll defaults | `PHASE_01.md:141-148` `[x]` | `OnboardingController::applyTemplate()` writes `tenant.type` + two `settings` keys and echoes `template_data` back. **Creates zero records.** | **Doc is wrong** |
| Template application idempotent | `PHASE_01.md:148,188` `[x]` | Nothing is created, so nothing can duplicate. Test at `OnboardingTest.php:107` only asserts the echoed JSON shape. | **Doc is wrong** |
| `OnboardingStep` enum, 7 steps | `PHASE_01.md:158` `[x]` | No such enum in `app/Enums/`. Controller uses raw ints with an inline `1..7` guard. | **Doc is wrong** |
| Frontend template step | — | `setup-wizard.tsx:824` toasts *"departments, positions, shifts, and leave types created"*. Nothing was created. | **User-visible false claim** |
| 8 org templates | `[x]` | Real. `OrganizationTemplateSeeder` — but `template_data` only carries `departments`, `positions`, `shifts`, `leave_types`. No grades, payroll, holidays, approvals, number format. | Partial |
| Device adapters | `[x]` | Real and good: `DeviceAdapter` contract + Hikvision / ZKTeco / Suprema / Mock, `DeviceManager`, `PullDeviceEventsJob`, `DeviceSyncLog`, encrypted `connection_config`. | Solid base |
| Device **enrollment** read (employees on device) | — | Not in the contract. `DeviceAdapter` exposes `pullEvents()` only. | **Missing** |
| Employee ↔ device identity | — | Single `employees.badge_number` column. `PullDeviceEventsJob:82` matches `badge_number OR employee_code`. One badge per employee, tenant-wide. | **Blocks multi-device** |
| Historical attendance import from devices | — | `AttendanceInput` has **no timestamp field**; `AttendanceEngine::processCheckIn()` hardcodes `Carbon::now()`. Every pulled event is stamped at ingestion time. | **Hard blocker** |
| Employee import dedupe | — | `EmployeeImporter::commit()` dedupes on a synthetic `import_key` only. No matching on national ID / phone / email / name. Re-importing a renamed file creates full duplicates. | **Missing** |
| Login identifiers | — | `LoginRequest` requires `email`. Nothing else. | Email-only |
| SMS | `CLAUDE.md` stack table: "SMS: Interface-based, LogSms / EthioTelecom" | No SMS interface, driver, or config anywhere in `api/`. | **Missing entirely** |
| Invitations | `[x]` | Real — `UserProvisioningService`, email activation links. Email only, capped at 50. | Solid, email-only |

### Reusable assets (extend, do not rebuild)

`DeviceAdapter` + `DeviceManager` + `PullDeviceEventsJob` + `DeviceSyncLog` · `EmployeeImporter` + `AttendanceImportParser` + `AttendanceImporter` · `UserProvisioningService` · `AttendanceEngine` + `ShiftMatcher` · `OrganizationTemplate` + seeder · `OnboardingProgress` · `AuditLog::record()` · `BelongsToTenant` / `HasPublicId` · frontend `SetupWizard` shell, `StepHeader`, `StepFooter`, `QueryBoundary`.

---

## 2. Normalization of the request against the codebase

The brief specifies 11 wizard steps, 27 industries, 9 device vendors, 9 login methods, plus AD/LDAP and "AI". Taken literally that is a multi-quarter program and it conflicts with three standing constraints in `CLAUDE.md`: no paid API dependencies, task slices no larger than one feature, and never mixing planning with implementation above 20 files.

Decisions taken:

**D1 — 11 brief steps collapse to 7 canonical steps.** A 15-minute setup cannot have 11 screens. Welcome+Company merge; AI Config+Review merge into one editable preview; Devices folds into Migration (discovery and connection are the same act); Validation+Go-Live merge into one readiness screen. Formalize as an `OnboardingStep` enum — the artifact `PHASE_01.md` already claims exists.

| # | Canonical step | Absorbs brief steps |
|---|---|---|
| 1 | Welcome & Company | 1, 2 |
| 2 | Industry | 3 |
| 3 | Smart Configuration (preview + edit) | 4, 5 |
| 4 | Workforce Migration | 6 |
| 5 | Access & Identity | 7 |
| 6 | Invite Team | 8 |
| 7 | Readiness & Go Live | 9, 10, 11 |

**D2 — "AI" is a deterministic rules engine, not an LLM.** The brief's "AI Smart Configuration" and "AI validation" need to be reproducible, auditable, offline-capable, and free — all four rule out a model call. Implement `IndustryProfileResolver`: template JSON + tenant signals (size, type, region) → a scored configuration plan. Confidence scores come from provenance (`explicit` 1.0 / `industry_default` 0.8 / `heuristic` 0.6 / `global_fallback` 0.4), which is defensible and testable. Same for the readiness score — a weighted rubric over real counts. Name it "Smart Setup" in the UI, not "AI".

**D3 — 27 industries become 8 verified templates × industry aliases.** Shipping 27 hand-authored configurations is 27 chances to be wrong about Ethiopian labour practice. Keep the 8 real templates, add an `industry_aliases` map (`ministry`, `woreda_administration`, `tvet` → `government`; `microfinance`, `insurance` → `bank`; …) so the picker shows all 27 and each resolves to a maintained base plus a thin override patch. One place to fix a bug.

**D4 — Identity resolution gets its own table.** `employees.badge_number` cannot express "this person is user 44 on the ZKTeco at HQ and user 1102 on the Hikvision at Bole". Introduce `employee_external_identities` (tenant_id, employee_id, source_type, source_ref, identifier_type, identifier_value, confidence, verified_at) with a unique index on (tenant_id, source_type, source_ref, identifier_type, identifier_value). ETHR becomes master identity; `badge_number` stays as a legacy read path during migration.

**D5 — Attendance history migration is blocked until `AttendanceEngine` accepts an event timestamp.** This is a prerequisite, not a nice-to-have. Add `occurredAt` to `AttendanceInput`, default `now()`, and thread it through `processCheckIn` / `processCheckOut`. It also fixes a live correctness bug: any device sync with a backlog currently records every event at ingestion time.

**D6 — Login identifiers, not login methods.** Do not build 9 auth paths. Generalize `LoginRequest` from `email` to `identifier`, resolved against a tenant-configured ordered list (`email`, `phone`, `employee_code`, `username`). SSO already has `SsoSetting` + `SsoController` — leave it. OTP/passwordless is deferred: it depends on an SMS layer that does not exist.

**D7 — AD/LDAP, WhatsApp, and Anviz/Ronald Jack/Matrix/FingerTec/ESSL adapters are deferred.** They need a real device or directory to test against; writing untested vendor adapters is a liability, not a feature. Ship a documented `GenericHttpAdapter` + CSV path that covers them today, and add real adapters when a customer engagement supplies hardware.

**D8 — Full-history import is opt-in and capped.** "Import Entire History" against a 500-employee ZKTeco is millions of rows through a queue. Offer 30 / 90 / custom-from-date / full, run it as a batched background job with progress, and default the selector to 90 days.

---

## 3. Delivery plan

Each slice compiles, tests, and ships independently, per `CLAUDE.md`. Ordered by dependency and by the audit's severity — the false template claim first, because it is shipping a user-visible untruth today.

**Slice 0 — Truth repair (small, do first). ✅ DONE.**
Corrected the `[x]` marks in `PHASE_01.md` and the `setup-wizard.tsx` template toast so the product stops claiming work it did not do. No new features.

**Slice 1 — `OrganizationProvisioner` service. ✅ DONE.**
`applyTemplate` is real: it creates branches, departments, positions, grades, shifts, leave types (via `LeaveTypeCatalog`), holidays (via the existing `HolidayService::autoDetect`, which computes movable feasts exactly — no fixed-list guessing), and merges tenant settings. Create-only and soft-delete aware (a trashed natural-key match is restored, not re-inserted), which is what makes re-application idempotent. Added `OnboardingStep` enum; extended the seeder `template_data` with grades and settings. Covered by `OrganizationProvisionerTest` + the rewritten `OnboardingTest` apply/re-apply/audit cases.

**Slice 2 — `IndustryProfileResolver` + 27-industry alias map. ✅ DONE.**
`IndustryCatalog` maps all 27 industries onto the 8 maintained bases plus thin override patches. `IndustryProfileResolver` produces a scored, explainable `ConfigurationPlan`: each section carries provenance (`ConfigurationSource`: explicit 1.0 / industry_default 0.8 / heuristic 0.6 / global_fallback 0.4) and a confidence derived purely from it — the deterministic stand-in for an LLM score. Endpoints: `GET /onboarding/industries`, `POST /onboarding/configuration/preview` (read-only, writes nothing), `POST /onboarding/configuration/apply` (hands the edited plan to Slice 1's provisioner, marks step 3, audits). Save is within-tenant (`settings.saved_configuration`); publishing a plan as a globally reusable `OrganizationTemplate` is deferred — that table is a global catalog and needs tenant scoping first. Covered by `IndustryConfigurationTest` (15 cases). **Frontend consumption (27-industry picker + editable scored review) is a dedicated frontend slice; the backend contract is ready.**

**Slice 3 — `AttendanceInput::$occurredAt` (prerequisite fix). ✅ DONE.**
`occurredAt` threads through `AttendanceInput` → `AttendanceEngine` (both check-in and check-out key off the punch moment, not `now()`). Wired into all four delayed-ingestion paths: `PullDeviceEventsJob`, the Hikvision and ZKTeco webhooks, and offline sync (which validated a `timestamp` it had been silently discarding). Fixes the live backlog bug. `AttendanceEventTimeTest`.

**Slice 4 — `employee_external_identities` + `IdentityResolver`. ✅ DONE.**
New tenant-scoped table maps each external identifier (device user id, card, …) to one master employee, unique on (source_ref, identifier) so the same id on two devices stays distinct. `IdentityResolver` does deterministic weighted matching (employee_code 0.9, badge 0.85, email 0.85, phone 0.7, name ≤0.2) → `matched` / `probable` / `ambiguous` / `new`; a single strong identifier clears MATCH, name-only never does. `PullDeviceEventsJob` and `EmployeeImporter` are rewired onto it, so an ambiguous/unknown badge is left for review and a re-imported person is skipped, not duplicated. National-ID matching is noted as a follow-up (no such column exists yet). `IdentityResolutionTest`.

**Slice 5 — Device enrollment discovery. ✅ DONE.**
`pullEnrollments(Device)` added to `DeviceAdapter` and implemented on Hikvision, ZKTeco, Suprema, and Mock; new `GenericHttpAdapter` (registered as `generic`) covers untestable vendors via configurable JSON mapping. `GET /devices/{device}/enrollments` reads the device roster and shows each person's suggested employee match (read-only preview). `DeviceEnrollmentTest`.

**Slice 6 — Migration workspace (backend). ✅ DONE (backend).**
`migration_batches` + `migration_staging_rows` (transient staging, hard-deleted after 7 days by `CleanupExpiredDataJob`). `WorkforceMigrationService` stages people from a device or spreadsheet, resolves each, and assigns a suggested action; commit honours the chosen action (merge / create / skip / defer) — nothing is created until commit, and a known person is never duplicated. Endpoints under `/onboarding/migration/*`. `MigrationWorkspaceTest`. **Frontend review UI deferred (contract ready).**

**Slice 7 — Access & identity step. ✅ DONE.**
Login generalized from email-only to a tenant-configured identifier list via `AuthIdentifierResolver` (email / phone / employee_code / **username**). Tenant-scoped, denies ambiguous identifiers, generic error under the field the client sent (backward compatible — `email` still works). `/onboarding/access` reads/sets the policy and the access-step UI offers all four. `LoginIdentifierTest`; full Auth suite green. **A focused security review of the auth-path change is recommended before release.**

**Slice 8b — Attendance-history import. ✅ DONE (backend).**
`PullDeviceEventsJob` gained a `sinceOverride` for one-off backfills, and
`POST /devices/{device}/import-history` triggers it with a window (last 30 / 90 /
from-date / full). Backfilled punches are dated at event time and the incremental
cursor is preserved. `AttendanceHistoryImportTest`. Adapter-side pagination for
very large `full` imports is the remaining follow-up (decision D8).

**Slice 8 — Readiness & Go Live. ✅ DONE (backend).**
`ReadinessScorer` — a deterministic weighted rubric over real counts across configuration / data / security, each failing check carrying a remediation deep link. `GET /onboarding/readiness` and `POST /onboarding/go-live` (marks step 7, completes onboarding). `ReadinessScorerTest`. **Launch screen UI deferred (contract ready).**

**Slice 9 — Documentation. ✅ DONE.**
See `INDUSTRY_TEMPLATES.md`, `DEVICE_INTEGRATION.md`, `IDENTITY_RESOLUTION.md`, `MIGRATION.md`, `FRONTEND.md`; `ARCHITECTURE.md` and `DATABASE.md` updated.

---

## 4. Frontend status — ✅ DELIVERED

The interactive frontend for the onboarding-v2 steps is implemented in
`src/src/features/onboarding/v2/` and surfaced at `/setup/guided`:

- **Smart configuration** (`industry-config-step.tsx`) — 27-industry picker grouped
  by sector, size/region signals, a scored review with per-section confidence
  badges, per-item removal, and apply.
- **Workforce migration** (`migration-step.tsx`) — stage from a device or a pasted
  list, a review queue with per-row match verdict + action select, and commit.
- **Access & identity** (`access-step.tsx`) — choose login identifiers.
- **Readiness & go live** (`readiness-step.tsx`) — scored report, category checks,
  gap deep links, launch.
- Composed by `guided-onboarding.tsx` (4-step stepper).

Hooks in `v2/api.ts`, types in `v2/types.ts`. Bilingual (en + am `setup2.*`
keys), semantic tokens, `QueryBoundary` on every fetch. Type-clean (`tsc`) and
covered by `test/onboarding-v2.test.tsx`. The existing 6-step `SetupWizard` still
works; its template step reports the real provisioned count (Slice 0/1).

Contract reference: `FRONTEND.md`. Live in-browser verification against a running
backend + authenticated tenant is the one remaining manual check (the dev server
was held by another session during implementation).

### Superseded historical slices (below) — original planning notes retained for provenance.

**Slice 5 — Device enrollment discovery.**
Add `pullEnrollments(Device): array` to `DeviceAdapter`; implement on Hikvision, ZKTeco, Suprema, Mock; add `GenericHttpAdapter`. Feeds the resolver.

**Slice 6 — Migration workspace (backend + frontend).**
Staging tables, source connectors (CSV/Excel/device/manual), conflict review queue with merge / skip / create / defer, batched history import with progress. 7-day staging purge per the soft-delete policy.

**Slice 7 — Access & identity step.**
`identifier`-based login, tenant identifier policy, per-role identifier defaults. Security review required — this touches the auth path.

**Slice 8 — Readiness & Go Live.**
`ReadinessScorer` rubric (configuration / security / data completeness), gap list with deep links, launch screen.

**Slice 9 — Documentation.**
`CLAUDE.md`, `ARCHITECTURE.md`, `PRODUCT_VISION.md`, `PHASE_01.md`, `DATABASE.md`, plus new `ONBOARDING.md`, `INDUSTRY_TEMPLATES.md`, `DEVICE_INTEGRATION.md`, `IDENTITY_RESOLUTION.md`, `MIGRATION.md`. Create `FRONTEND.md` — the brief references it and it does not exist in this repo.

### Out of scope (explicit)

AD/LDAP, WhatsApp invitations, OTP/passwordless login (needs an SMS layer that does not exist), Anviz / Ronald Jack / Matrix / FingerTec / ESSL native adapters, and any LLM-backed configuration. Each is listed with its blocker above.
