# Product blueprint assessment — 2026-10-20

The owner supplied a product blueprint for ETHR.et ("Product blueprint for a
production-deployed Ethiopian workforce SaaS", updated 20 October 2026) and asked for every
item to be classified against what exists, with **existing docs and owner decisions taken
as the source of truth** wherever the two disagree. Upgrades and new features are allowed;
nothing is assumed to need new development.

Read-only assessment of `main` at `974b65a92`. Nothing was changed or deployed. Every
status is backed by code, tests or a recorded QA document; seven defects were then re-read
by hand and are marked **confirmed** below.

## How to read this

| Status | Meaning here |
|---|---|
| **Verified** | Implemented **and** pinned by an automated test or a recorded QA pass in `docs/audit/`. Not a claim that it was re-exercised on production today |
| **Partial** | Implemented but incomplete, unreliable, unproven on hardware, or with a known defect |
| **Missing** | Not implemented |
| **N/A** | Not required, or correctly not built |

Priority follows the blueprint: **P0** production safety and legal risk, **P1** essential
capability, **P2** important expansion, **P3** advanced or optional.

## Headline

| Status | Items | Share |
|---|---:|---:|
| Verified | 129 | 34% |
| Partial | 204 | 54% |
| Missing | 46 | 12% |
| N/A | 2 | 1% |
| **Total** | **381** | |

ETHR is a broad, genuinely working product with an unusually strong tenant-isolation
foundation. The weaknesses are not breadth but **depth in three places**: payroll
correctness at the edges, permission scoping *inside* a tenant, and approval workflow.
Almost all Missing items sit in sections the existing product vision already defers
(recruitment, performance, learning, expenses, assets).

| Section | V | P | M | N/A |
|---|---:|---:|---:|---:|
| A. Tenant management | 9 | 6 | 0 | 0 |
| P. Platform-owner console | 4 | 8 | 3 | 0 |
| B. Organization setup & policy | 6 | 7 | 2 | 0 |
| T. Template acceptance | 5 | 2 | 0 | 0 |
| E. Employee records | 3 | 9 | 1 | 0 |
| L. Lifecycle | 0 | 8 | 4 | 0 |
| I. Import/export | 4 | 3 | 0 | 0 |
| M. Data migration | 5 | 7 | 3 | 0 |
| AT. Attendance | 11 | 18 | 1 | 0 |
| IN. Integrations | 5 | 8 | 0 | 1 |
| S. Scheduling & shifts | 5 | 7 | 2 | 0 |
| LV. Leave | 3 | 11 | 1 | 0 |
| W. Approval workflow | 1 | 10 | 3 | 0 |
| PY. Payroll | 4 | 19 | 3 | 0 |
| PA. Payroll acceptance tests | 2 | 4 | 2 | 0 |
| X. Expense, travel, loans, assets | 1 | 2 | 8 | 0 |
| ESS. Employee self-service | 8 | 5 | 1 | 0 |
| R. Recruitment, performance, learning | 1 | 4 | 10 | 1 |
| N. Notifications | 7 | 5 | 0 | 0 |
| RP. Reports | 10 | 15 | 1 | 0 |
| LZ. Localization & accessibility | 8 | 10 | 0 | 0 |
| PR. Privacy & security | 8 | 11 | 1 | 0 |
| PH. Attendance photos | 7 | 1 | 0 | 0 |
| UX. Design system | 7 | 13 | 0 | 0 |
| OPS. Reliability & release | 5 | 11 | 0 | 0 |

---

## 1. Confirmed defects — P0, fix before anything else

Each was read in the code by hand after the review flagged it. None is a cross-tenant
leak; the tenant boundary held everywhere it was examined. Items 1–3 change what people
are paid; items 4–7 show data to people inside the same organisation who should not see it.

| # | Defect | Where | Why it matters |
|---|---|---|---|
| D1 | **Voided attendance still pays overtime.** A merged duplicate is set `voided` but keeps its `check_in`/`check_out`; the payroll overtime query has no status filter | `Services/Attendance/ConflictResolver.php:108`, `Services/Payroll/PayrollEngine.php:720` | A duplicate punch can be paid twice |
| D2 | **Bank file downloadable for an unapproved run.** Both export routes check only `view` | `PayrollController.php:296`, `:393` | Money can leave against a draft |
| D3 | **Leavers get no final pay.** The engine selects only hired/probation/confirmed and ignores `termination_date` | `PayrollEngine.php:170` | Contradicts `docs/payroll/ACCOUNTANT-SIGN-OFF.md` §1, which says a mid-period termination is prorated |
| D4 | **Supervisors see their team's salaries.** `salary_cents` is not `$hidden` and `EmployeeResource` returns it without `viewFinancial`; supervisors hold `employee.view` | `Resources/EmployeeResource.php:42`, `Models/Employee.php:86` | `employee.viewFinancial` exists for exactly this and is bypassed |
| D5 | **Personnel history readable for any employee.** Index/store check only the class permission, which supervisors hold, never `canAccessEmployee` | `PersonnelActionController.php:26,39` (and `RetirementCaseController`) | Before/after salary on promotions is exposed across the tenant |
| D6 | **Reports ignore data permissions.** `ReportController` checks `report.generate` only; `ReportEngine` queries the whole tenant | `ReportEngine.php:61-75` | HR Admin was deliberately denied `payroll.viewAll` (audit N95) yet can pull the payroll source |
| D7 | **Whole-organisation shift schedule visible to every employee.** `shift.viewAny` is in the everyone set and `schedule()` is unscoped | `Shift/ShiftController.php:272` | Data minimisation; low severity |

Found by review and highly likely, but not re-read by hand: payroll approval allows the
person who processed a run to approve it (`PayrollRunPolicy::approve`); an exit transition
does not deactivate the leaver's login (`EmployeeTransitioned` has no listener);
supervisors can open team `medical`/`id_copy` documents; scheduled reports email payroll
CSVs to any free-text address.

---

## 2. Where the blueprint and existing decisions disagree — decided

Existing docs win. Where the blueprint only *extends* an existing decision, that is
treated as an allowed upgrade, not a conflict.

| # | Topic | Blueprint | Existing source of truth | Decision |
|---|---|---|---|---|
| C1 | Approval chains | Configurable multilevel chains, delegation, escalation | `docs/PERMISSIONS.md` and `docs/INDUSTRY_TEMPLATES.md`: single-step. `docs/ENTERPRISE_ROADMAP.md` 10.1: "owner decision pending". `docs/PRD.md` FR-06.4 lists chains as P0 | **Single-step stays the current truth.** Payroll maker-checker is added now as P0 (it is a safety fix, not a workflow engine). The shared multilevel engine is the next P1 upgrade, built on the unused `leave_requests.current_approver_id` |
| C2 | Attendance photo retention | "Do not implement a blanket weekly deletion rule" | Owner decision 2026-10-17: 7-day default, tenant-configurable 1–90 days, 48h review, holds with reason, audited deletion (`docs/operations/ATTENDANCE-PHOTO-RETENTION.md`) | **Owner decision stands.** It already is what the blueprint asks for: configurable, documented, reviewable, with dispute holds. Not blanket |
| C3 | Income-tax amendment | "Proclamation No. 1395/2024" | `docs/payroll/STATUTORY-RULES-RESEARCH.md` reads the Gazette as 1395/2025, start date disputed (1, 7 or 8 July 2025; code uses 7 July) | **Keep 1395/2025.** Add Directive 1087/2025 (private pension), which the repo never cites, to the accountant's open questions |
| C4 | Recruitment, performance, learning | P2/P3 modules | `docs/PRODUCT_VISION.md` §20 defers ATS, performance/OKRs and learning to v2.0 | **No conflict** — the blueprint also says "after validating demand". Stays deferred |
| C5 | Account closure and erasure | Controlled offboarding | `docs/operations/TENANT-DELETION.md`: delete means reversible closure, never erasure | **Closure stands.** Customer self-service *export* is the missing half and is added (P1). Employee-level data-subject erasure is a separate feature needing its own owner decision |
| C6 | Organization activation | "Controlled activation" | Self-service sign-up with an immediate 6-month trial (`AuthService.php:51`); console can suspend | **Self-service stands.** Platform suspension is the control |
| C7 | Device integrations | "Supported biometric devices" | `docs/DEVICE_INTEGRATION.md`: unproven on hardware until field test O1. Marketing must not name device brands (PR #288/#293). Bridge is design-only (`docs/BRIDGE-DESIGN.md`) | **No device path is Verified until O1.** Internal docs may list adapters; the public site may not claim brands |
| C8 | Frontend | Shared components before page-by-page redesign | `docs/FRONTEND_REDESIGN_PLAN.md`: upgrade, not redesign; sign-in out of scope | **Agree.** UX items follow the plan's phases |
| C9 | Public-sector templates | Must not reuse private-sector rules | `ethiopia_public` deliberately not presented as civil-service compliant (`docs/INDUSTRY_TEMPLATES.md`) | **Agree.** Fix: the profile should also set `pension_scheme=public` |
| C10 | Privacy notice language | Amharic parity everywhere | Legal pages English-only until lawyer review; machine-translated legal text refused | **Existing stands** until the text is reviewed |
| C11 | Flexible schedules | Flexible schedules | Template notes define "flexible" as a wide grace period | **Existing stands**; core-hours flexi-time is a later upgrade |
| C12 | Bank / Telebirr | Do not claim integrations that are only file exports | Nothing claims them; marketing says "bank transfer file" | **Already compliant** |
| C13 | Backup retention | Backup and DR | `BACKUP_KEEP=2` chosen for the 5 GB host quota | **Existing stands**; an off-host copy is the upgrade |

Docs that are themselves stale and should be corrected by their owners:
`docs/deployment/CUTOVER-CHECKLIST.md` still reads `CUTOVER READY = NO` (2026-09-27)
although production has been live since 2026-10-10, and several host gates have since been
answered in practice. `docs/ENTERPRISE_ROADMAP.md` 10.5 lists statutory report templates as
Missing although `docs/payroll/STATUTORY-SCHEDULES.md` delivered them.
`docs/audit/TENANT-ISOLATION-AUDIT.md` §2 predates the 2026-10-06 tenancy decision.

---

## 3. The register

Evidence names the main file or test; `api/` and `src/src/` prefixes are dropped.

### A. Tenant management — P0 isolation, P1 rest

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| A1 | Tenant isolation everywhere | **Verified** | Fail-closed `BelongsToTenant`; `TenantIsolationTest`, `TenantScopeBypassInventoryTest` (168/65), `QueuedJobTenantPredicateTest`, `TenantStorageIsolationTest`; BASELINE §11c–§11j. 44 bypasses are safe by gate, not by query |
| A2 | Registration, provisioning, activation | Partial | `OrganizationProvisionerTest`, `SignupFlowTest`. No vetting step (C6) |
| A3 | Unique slug, custom domains | **Verified** | `PathAndCustomDomainTenancyTest`, `CustomDomainRecheckTest`. Each custom domain still needs a Plesk alias |
| A4 | Profile, logo, brand, address, contact | **Verified** | `BrandingTest`, `TenantLogoTest`, `TenantLandingTest` |
| A5 | Branches, sites, departments | **Verified** | `OrganizationStructureTest`, `OrgScopeFailClosedTest` |
| A6 | Customer admin vs platform owner | **Verified** | `PlatformPermissionDelegationTest`, `PlatformAdminContextTest` |
| A7 | Modules and plan entitlements | Partial | `PlanFeatureGateTest`. No per-org override; `FeatureFlag` model unread |
| A8 | Tenant policies and approval rules | Partial | Policies configurable; approval rules are not (C1) |
| A9 | Date, calendar, language, financial settings | **Verified** | `CalendarPreferenceTest`, `TenantSettingsValidationTest` |
| A10 | Suspension, reactivation, closure | **Verified** | `TenantDeletionTest`, `OverdueInvoiceEscalationTest`. Org not notified |
| A11 | Usage, storage, employee limits | Partial | `PlanLimitTest`. No storage measurement; trials uncapped |
| A12 | Customer data export, offboarding | Partial | Operator-only JSON backup, no files, no download (C5) |
| A13 | Auditable support access | **Verified** | `ImpersonationTest`, `docs/operations/IMPERSONATION.md`. No reason/ticket field |
| A14 | No cross-tenant search, cache, download, notification | **Verified** | `GlobalSearchTest`, `DashboardCachingTest`, `QueuedListenerTenantScopeTest` |
| A15 | Tenant audit history and report permissions | Partial | Audit log immutable (`AuditLogImmutabilityTest`); report permissions broken inside the tenant (D6) |
| — | **Acceptance:** cannot reach another org by URL, id, filter, cache or export | Partial | Covered piecemeal by the suites above; no single suite sending foreign ids to every `{id}` route or checking exported files |

### P. Platform-owner console

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| P1 | Create and manage tenants | Partial | Manage yes; operators cannot create a tenant |
| P2 | Activate/suspend/reactivate/close | **Verified** | `PlatformOperatorControlsTest`, `admin-tenant-delete.test.tsx` |
| P3 | Plans, renewals, entitlements | Partial | `AdminPlanCatalogTest`. Cannot assign a plan to a tenant |
| P4 | Employee, storage, usage limits | Partial | No storage limit |
| P5 | Provisioning health, setup progress | Partial | `ReadinessScorer` tenant-side only |
| P6 | Support requests, incident tracking | Missing | — |
| P7 | Error and integration monitoring | Partial | `/admin/health`, failed jobs; Sentry DSN not set in the hosting template |
| P8 | Authorized support access with audit | **Verified** | As A13 |
| P9 | Release and app health | Partial | `release.json`, `scripts/release-status.sh`; health reports hard-coded `1.0.0` |
| P10 | Backup status, restore verification | Partial | `BackupRestoreRehearsalTest` in CI; host restore M6 not performed |
| P11 | Feature flags, controlled rollout | Missing | `FeatureFlag` model has no reader |
| P12 | Customer export and offboarding | Partial | As A12 |
| P13 | Availability and incident communication | Missing | No status page or banner |
| P14 | Operators separate from customer admins | **Verified** | One shared super-admin role; no narrower support/billing roles |
| P15 | Platform reports without employee-level access | **Verified** | `PlatformAdminTest` |

### B. Organization setup and policy — P1

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| B1 | Guided onboarding | **Verified** | `OnboardingTest`, `onboarding-v2.test.tsx`, QA-PRODUCTION-2026-10-11 |
| B2 | Organization type, policy profile | **Verified** | `PolicyProfileCatalog`; `ethiopia_public` does not set the public pension scheme |
| B3 | Departments, subdepartments, branches, positions | **Verified** | `OrganizationStructureTest` |
| B4 | Org chart, reporting lines | **Verified** | `org-chart.test.tsx`, `reporting-chart.test.tsx`. No department head |
| B5 | Job descriptions, job and salary grades | Partial | `GradeSalaryStepTest`; description is free text, positions not linked to grades |
| B6 | Headcount / establishment | Missing | Only plan seat caps |
| B7 | Working days, weekends, shifts, holidays | **Verified** | `ShiftEngineTest`, `HolidayEngineTest`, `MovableHolidayTest` |
| B8 | Ethiopian and Gregorian calendar | **Verified** | `CalendarPreferenceTest`, `EthiopianCalendarTest` |
| B9 | Payroll, pension, tax configuration | Partial | Dated rules, tested; accountant sign-off O2 outstanding |
| B10 | Leave entitlements, carryover | Partial | No seniority accrual (Proclamation 1156/2019 Art. 77) |
| B11 | Approval chains | Missing | C1 |
| B12 | Effective-dated policy changes | Partial | Tax and pension only |
| B13 | Setup validation before processing | Partial | Readiness is advisory; payroll never checks it |
| B14 | Preview of policy-change impact | Partial | Shift usage preview only |
| B15 | Editable industry templates | Partial | 35 industries × 10 workforce × 3 policy profiles in code; no admin template editor (by design, `docs/ONBOARDING_V2.md` D3) |

The blueprint's design rule — configurable profiles, not per-customer applications — **is
followed**: no tenant-specific code paths exist.

### T. Industry templates

| Organization type | Coverage | Missing piece |
|---|---|---|
| Private SMEs | Covered | — |
| Manufacturing | Covered | — |
| Retail, hotels, hospitality | Covered | Shift swaps (S6) |
| Federal government | Partial | Real civil-service scale, establishment, public pension on profile |
| Regional and woreda | Partial | Same as federal |
| NGOs and INGOs | Partial | Project/donor cost allocation, grant reports |
| Schools and universities | Partial | Academic calendar |
| Hospitals | Partial | On-call (excluded by design), credential-expiry alerts |
| Security companies | Partial | Guard-to-site deployment, coverage alerts |
| Construction, agriculture | Partial | Project cost allocation |
| Financial institutions | Partial | Segregation of duties (payroll maker-checker) |
| Large companies and groups | Missing | Legal entities, pay groups, consolidated reporting |

| ID | Template acceptance | Status | Evidence / gap |
|---|---|---|---|
| T-a | Selectable at onboarding | **Verified** | `IndustryConfigurationTest` |
| T-b | Documented assumptions | **Verified** | `docs/INDUSTRY_TEMPLATES.md`, bilingual review notes |
| T-c | Customizable within authority | **Verified** | `OrganizationTemplateLibraryTest` |
| T-d | Never silently overrides data | **Verified** | "creates nothing the second time", "never overwrites an attendance setting" |
| T-e | Effective-dated and audited | Partial | Audited; only tax and pension dated |
| T-f | Org-specific reports configurable | Partial | Report builder exists; templates propose none |
| T-g | Updatable without corrupting history | **Verified** | `catalog_version`, immutable `calculation_log` |

### E. Employee records — P1

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| E1 | Unique number, duplicate detection | Partial | `EmployeeNumberingAndImportTest`; manual add never checks for the same person |
| E2 | English and Amharic names | **Verified** | `name_am`; `e2e/amharic-layout.spec.ts` |
| E3 | Contacts and emergency contacts | **Verified** | `NestedResourceScopingTest`, `ProfileSelfServiceTest` |
| E4 | Employment type, status, dates | Partial | Employment type lives only on contracts |
| E5 | Branch, department, position, manager, cost center | **Verified** | `EmployeeRelationScopedValidationTest` |
| E6 | Contracts and renewal reminders | Partial | `EmployeeContractTest`; expiring list never shown, no reminder |
| E7 | Salary and assignment history | Partial | Direct `PUT` changes salary with no history row; no future-dating |
| E8 | Qualifications, licences, certificates | Partial | No certification register |
| E9 | Secure documents | Partial | Private disk, 15-min links; supervisors can open `medical`/`id_copy` |
| E10 | Dependents and beneficiaries | Missing | — |
| E11 | Employee photo | Partial | Consent-gated self-upload only |
| E12 | Controlled sensitive-field editing | Partial | Self-service gated; HR edit path and resource bypass `*Financial` (D4) |
| E13 | Promotions, transfers, status history | Partial | Direct and bulk edits leave only an audit entry |

### L. Lifecycle

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| L1 | Candidate handoff to onboarding | Missing | Deferred with ATS (C4) |
| L2 | New-hire onboarding checklist | Missing | `OnboardingProgress` is org setup, not new hires |
| L3 | Document collection and verification | Partial | No verified status, no required-documents list |
| L4 | Probation tracking | Partial | Overdue flag only; probationers cannot resign |
| L5 | Contract renewal reminders | Partial | As E6 |
| L6 | Promotion, transfer, acting | Partial | Only promotion tested; acting not reverted |
| L7 | Salary, department, manager change | Partial | Manager change has no history |
| L8 | Suspension and reinstatement | Partial | Untested directly |
| L9 | Resignation, retirement, termination | Partial | `RetirementCaseTest`; login not deactivated on exit |
| L10 | Exit clearance, property return | Missing | — |
| L11 | Final pay, loan reconciliation | Missing | D3 |
| L12 | Archived records, retention | Partial | Soft delete only |
| — | **Acceptance:** create, update, transfer, offboard via authorized workflows | Partial | Create/update/transfer yes; offboarding incomplete |

### I. Import and export

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| I1 | Import with template | **Verified** | `EmployeeImportParityTest`; CSV only |
| I2 | Column mapping, validation | Partial | Alias matching, no interactive mapping |
| I3 | Duplicates, preview of changes | Partial | Cannot update existing records |
| I4 | Row-by-row errors | **Verified** | `EmployeeNumberingAndImportTest` |
| I5 | Confirmation before change | **Verified** | Preview → commit with idempotent key |
| I6 | Audit, correction, recovery | Partial | Audited; no undo |
| I7 | Permission-controlled export | **Verified** | `CsvExportSafetyTest`; omits salary, TIN, national ID |

### M. Data migration

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| M1 | Downloadable templates | Partial | Employees and attendance only |
| M2 | Employees, departments, positions, managers | Partial | Structure and managers not importable |
| M3 | Historical attendance | **Verified** | `AttendanceHistoryImportTest` |
| M4 | Leave balances | Missing | — |
| M5 | Salary structures, loan balances | Partial | Per-employee salary only |
| M6 | Required fields, duplicates | **Verified** | `MigrationWorkspaceTest` |
| M7 | Preview before commit | **Verified** | `migration-step.test.tsx` |
| M8 | Row-level errors | **Verified** | As I4 |
| M9 | Reconcile headcount and payroll totals | Missing | — |
| M10 | Permission-controlled history | Partial | Normal permissions only |
| M11 | Customer sign-off before cutover | Partial | Go-live audited with readiness score; no sign-off |
| M12 | Rollback / recovery | Missing | `docs/ROLLBACK_RUNBOOK.md` covers deployments only |
| M13 | Guided setup and training | Partial | No training material |
| M14 | Sample data / demo | Partial | `DemoTenantSeeder`; no per-customer sandbox |
| M15 | Onboarding progress | **Verified** | `ReadinessScorerTest` |

### AT. Attendance — P1

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| AT1 | Device ingestion | Partial | Adapters tested; unproven on hardware (O1, C7) |
| AT2 | Secure registration | **Verified** | `DeviceManagementTest`, `DeviceHostPolicyTest` |
| AT3 | Device-to-employee mapping | Partial | Unmatched badges logged and **dropped** (`PullDeviceEventsJob.php:339`) |
| AT4 | Duplicate punches | **Verified** | `AttendanceConflictTest`; QA-ATTENDANCE-PRODUCTION-2026-10-12 |
| AT5 | Check-in/out, late, absent, early | Partial | `ABSENT` never written; derived absence ignores holidays and leave |
| AT6 | Missing punches | **Verified** | `MissingPunchNotificationTest` |
| AT7 | Shift-aware calculation | **Verified** | `ShiftRotationMatchingTest` |
| AT8 | Overnight shifts | **Verified** | `NightShiftAttendanceTest` |
| AT9 | Calendar and holidays | Partial | Days never marked holiday |
| AT10 | Overtime rules | Partial | No eligibility or pre-approval; `ot_daily_cap_minutes` unused |
| AT11 | Grace and rounding | Partial | No rounding |
| AT12 | Daily/weekly/monthly summaries | Partial | No stored timesheet; voided rows counted |
| AT13 | Correct reporting period | Partial | `in_open_payroll_period` hard-coded `true` |
| AT14 | Correction requests | **Verified** | `IntelligenceCorrectionTest` |
| AT15 | Supervisor and HR review | Partial | Single step (C1) |
| AT16 | Reason and evidence | Partial | No attachment |
| AT17 | Original punch retained | **Verified** | `IntelligenceCorrectionTest:717` |
| AT18 | Approval history, editor | **Verified** | Audited |
| AT19 | Conflicting corrections | Missing | — |
| AT20 | Late sync, outage reconciliation | Partial | Offline sync tested; unmatched punches lost |
| AT21 | Raw vs calculated vs adjusted | Partial | No separate raw-punch store |
| AT22 | Approved attendance to payroll, traceable | Partial | Overtime only; no record references; D1 |
| AT23 | Device health, last sync | **Verified** | `device-detail-page.test.tsx` |
| AT24 | Sync failure alerts | **Verified** | `JobFailureHandlingTest` |
| AT25 | Supported models and firmware | Partial | Awaits O1 |
| AT26 | Duplicate device assignment | **Verified** | `DeviceSerialUniquenessTest` |
| AT27 | Multi-site mapping | Partial | No cross-branch punching policy |
| AT28 | Manual import fallback | Partial | Bypasses the engine; re-upload duplicates |
| AT29 | Retries and recovery | Partial | Unproven on hardware |
| AT30 | Device data retention | Partial | Photos covered; sync logs and identities not |
| — | **Acceptance:** an approved change never silently erases the punch or changes approved payroll | Partial | Originals kept (tested); **no lock** between approved payroll and later attendance edits |

### IN. Integrations

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| IN1 | Device integrations documented | **Verified** | `docs/DEVICE_INTEGRATION.md`, `docs/field-test/` |
| IN2 | Capabilities and limits listed | **Verified** | Same docs state the limits |
| IN3 | Maintainable adapter layer | **Verified** | `DeviceAdapter`, `DeviceManager` |
| IN4 | Validated employee import/export | **Verified** | As I1, I7 |
| IN5 | Payroll export formats | Partial | Generic CSV; bank layouts are owner action O10 |
| IN6 | Notification providers documented | Partial | Ethio Telecom SMS sender untested |
| IN7 | Controlled APIs and credentials | **Verified** | `ApiKeyAuthenticationTest` |
| IN8 | Integration permissions and scope | Partial | No branch/data scope on keys |
| IN9 | Sync timestamps, retries, reconciliation | Partial | No reconciliation of dropped punches |
| IN10 | Duplicate-event protection | Partial | CSV re-import duplicates |
| IN11 | Integration health | Partial | Unmatched badges invisible |
| IN12 | API versioning policy | Partial | `/api/v1` + contract gate; no written deprecation policy |
| IN13 | Customer and support docs | Partial | No customer device-setup guide |
| IN14 | Bank / Telebirr integrations | N/A | Not built and not claimed (C12) |

### S. Scheduling and shifts

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| S1 | Standard and flexible | Partial | Flexible = grace (C11) |
| S2 | Rotations | **Verified** | `ShiftRotationTest` |
| S3 | Night shifts | **Verified** | `NightShiftAttendanceTest` |
| S4 | Rest days, holidays | **Verified** | `HolidayEngineTest` |
| S5 | Department and employee assignment | **Verified** | `ShiftAssignmentManagementTest`; no overlap check |
| S6 | Shift swaps | Missing | — |
| S7 | Absence and lateness rules | Partial | Absence never recorded |
| S8 | Overtime requests and caps | Partial | Paid automatically; cap stored but unused |
| S9 | Break and rest rules | Partial | No rest-between-shifts or 48-hour check |
| S10 | Part-time, temporary, contract | Partial | Contract type not linked to schedules |
| S11 | Multi-location rosters | Partial | Roster skips rotations |
| S12 | Staffing conflict alerts | Missing | — |
| S13 | Published rosters | Partial | Today only; D7 |
| S14 | Historical schedules | **Verified** | Started assignments cannot be deleted |

### LV. Leave

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| LV1 | Types and eligibility | Partial | `requires_attachment`, `requires_approval` stored, never enforced |
| LV2 | Annual entitlement | Partial | 16 days; no +1 per 2 years of service |
| LV3 | Sick leave and documents | Partial | No upload; Art. 85 graded pay not modelled |
| LV4 | Maternity, paternity | **Verified** | `LeaveManagementTest` |
| LV5 | Special, unpaid, custom | **Verified** | `PayrollUnpaidLeaveTest` |
| LV6 | Accrual, carryover, expiry, proration | Partial | Terminated staff keep accruing; no expiry or proration |
| LV7 | Amend and cancel | Partial | Pending-only cancel; whole days only |
| LV8 | Multilevel approval, delegation | Missing | C1 |
| LV9 | Department calendars, conflicts | Partial | Team calendar only |
| LV10 | Balance with transaction history | Partial | Balances changed in place, no ledger |
| LV11 | Holidays and non-working days | Partial | Uses org week, not the employee's shift |
| LV12 | Impact on payroll and attendance | Partial | Unpaid deduction only |
| LV13 | Notifications | **Verified** | `NotificationDispatchTest`; nobody told when no supervisor |
| LV14 | Leave reports | Partial | Balances only |
| LV15 | Profiles for different rules | Partial | One profile per org (C9) |

### W. Approval workflow

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| W1 | Configurable chains | Missing | C1 |
| W2 | Single and multilevel | Partial | Single only |
| W3 | Department and amount routing | Partial | Implicit via permission and scope |
| W4 | Prepare / review / approve separation | Partial | Payroll lacks maker-checker |
| W5 | Delegation | Missing | — |
| W6 | Escalation | Partial | 48h reminder, never escalates |
| W7 | Return for correction | Missing | — |
| W8 | Required rejection reason | Partial | Profile reviews optional; batch reject uses a literal |
| W9 | Timestamps and history | Partial | Rejections lack a decision time |
| W10 | Reassignment on role change | Partial | Works by design, untested |
| W11 | No self-approval | Partial | Leave, corrections, profile tested; payroll not |
| W12 | New and overdue notifications | Partial | Supervisor only |
| W13 | One approval inbox | **Verified** | `BatchApprovalParityTest`; payroll not in it |
| W14 | Audit of workflow and policy changes | Partial | Audited; largely untested |

There is no shared engine: one query layer feeds the inbox
(`Services/Approval/DecidableApprovals.php`) and each module decides in its own service.

### PY. Payroll — P0 correctness, P1 rest

Accountant sign-off (`docs/payroll/ACCOUNTANT-SIGN-OFF.md`) is **prepared, not signed**.
No statutory compliance may be claimed until O2 and O3 are complete.

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| PY1 | Period and cutoff | Partial | Monthly only; no cutoff or lock |
| PY2 | Salary, grade, effective dates | Partial | Personnel actions apply immediately |
| PY3 | Earnings | Partial | No per-employee bonuses or one-off earnings |
| PY4 | Deductions, loans | Partial | No advances or custom deductions |
| PY5 | Unpaid leave, adjustments | Partial | No manual adjustments |
| PY6 | Proration | Partial | Joiners yes; leavers no (D3); mid-period raise not split |
| PY7 | Arrears, retro | Missing | Void and reprocess only |
| PY8 | One-time and recurring | Partial | Recurring only |
| PY9 | Pay groups and calendars | Missing | — |
| PY10 | Pre-calculation validation | Partial | Checks run after calculation |
| PY11 | Draft and exception queue | Partial | No exception queue |
| PY12 | Payslip preview | **Verified** | `PayrollProcessingTest`; PDF not watermarked DRAFT |
| PY13 | Income-tax rules | Partial | `TaxCalculatorTest`; unsigned, date disputed (C3) |
| PY14 | Pension by scheme | Partial | Per org, not per employee; Directive 1087/2025 absent |
| PY15 | Other statutory obligations | Partial | — |
| PY16 | Effective-dated rule history | Partial | Org tax ladder hard-deleted on replace |
| PY17 | Exemptions | Partial | `NationalityStatusTest`; no allowance caps |
| PY18 | Calculation explanations | **Verified** | `payroll-calculation-log.test.tsx` |
| PY19 | Gross-to-net reconciliation | **Verified** | `PayrollGoldenPayslipsTest` |
| PY20 | Review and final approval | Partial | One person can process and approve |
| PY21 | Lock after finalization | Partial | Only `calculation_log` guarded |
| PY22 | Adjustments, reversals, reruns | Partial | `PayrollBalanceMovementTest`; no adjustment lines |
| PY23 | Payslips, secure access | **Verified** | `PayslipReleaseTest` |
| PY24 | Summaries, tax and pension exports | Partial | `StatutorySchedulesTest`; layouts unconfirmed (O3) |
| PY25 | Payment file only when valid | Partial | D2 |
| PY26 | Payment reconciliation | Missing | — |

| ID | Payroll acceptance test | Status | Evidence / gap |
|---|---|---|---|
| PA1 | Reproducible from inputs and rule version | Partial | Rule recorded; old org ladder deleted |
| PA2 | Post-approval changes detected | Partial | Loan and cost-sharing only |
| PA3 | No silent alteration of finalized payroll | Partial | No edit API; amount columns unguarded |
| PA4 | No exposure of others' salaries | **Verified** | `PayslipReleaseTest` (but see D4 outside payslips) |
| PA5 | Independently reviewed test cases | Missing | O2 |
| PA6 | Rule updates validated first | Missing | — |
| PA7 | Totals reconcile before export | Partial | D2 |
| PA8 | No double counting on rerun | **Verified** | `PayrollBalanceMovementTest`; D1 is a separate path |

### X. Expense, travel, loans, assets — P2

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| X1–X5 | Expenses, travel, per diem, advances, receipts | Missing | — |
| X6 | Loans and repayment schedules | Partial | No stored schedule, no advances |
| X7 | Salary-deduction links | **Verified** | `PayrollCostSharingTest` |
| X8–X9 | Assets and history | Missing | Deferred to v2.0 (`docs/PRODUCT_VISION.md` §20) |
| X10 | Cost-center allocation | Partial | Journal not split by cost center |
| X11 | Expense and travel reports | Missing | — |

### ESS. Employee self-service

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| ESS1 | Dashboard and profile | **Verified** | `EmployeePortalTest` |
| ESS2 | Attendance and corrections | **Verified** | `AttendanceCorrectionScopeTest` |
| ESS3 | Leave balance and requests | **Verified** | `e2e/leave.spec.ts` |
| ESS4 | Leave status | **Verified** | `NotificationDispatchTest` |
| ESS5 | Shift schedule, holidays | Partial | Today only |
| ESS6 | Payslips, compensation | Partial | No compensation summary |
| ESS7 | Certificate requests | Missing | — |
| ESS8 | Secure document downloads | Partial | Employees cannot see their own documents |
| ESS9 | Notifications, tasks | **Verified** | `NotificationSystemTest` |
| ESS10 | Personal-info update requests | **Verified** | `ProfileUpdateSelfReviewTest` |
| ESS11 | Language preference | Partial | Emails ignore it (N11) |
| ESS12 | Mobile experience | **Verified** | `pwa-assets.test.ts`, `e2e/responsive.spec.ts` |
| ESS13 | Session and account security | **Verified** | `SessionManagementTest`, `TrustedDeviceTest` |
| ESS14 | Privacy information | Partial | No self-service data access |

### R. Recruitment, performance, learning — P2/P3, deferred (C4)

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| R1 | Requisitions | Missing | — |
| R2 | Vacancies | **Verified** | `VacancyTest` |
| R3–R6 | Applicants, interviews, offers, conversion | Missing | — |
| R7 | Applicant data access | N/A | No applicant data stored |
| R8 | Probation assessments | Partial | Status only |
| R9–R10 | Review cycles, objectives | Missing | — |
| R11 | Feedback | Partial | Engagement voice module, not performance |
| R12 | Training | Missing | — |
| R13 | Certification tracking | Partial | Document expiry only |
| R14 | Promotion, succession | Partial | Promotion only |
| R15 | Skills inventory | Missing | — |
| R16 | Confidential assessments | Missing | — |

### N. Notifications

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| N1 | Notification center | **Verified** | `notification-bell.test.tsx` |
| N2 | Pending approval alerts | **Verified** | `ApprovalReminderTest` |
| N3 | Contract and document expiry | Partial | No scheduled reminder |
| N4 | Leave and attendance alerts | **Verified** | `MissingPunchNotificationTest` |
| N5 | Payroll status | **Verified** | `PayslipNotificationDeliveryTest` |
| N6 | Device failure alerts | **Verified** | `QueuedListenerTenantScopeTest` |
| N7 | Preferences | **Verified** | `NotificationPreferenceEnforcementTest` |
| N8 | Read state and deep links | Partial | Clicking only marks read |
| N9 | Email and SMS | Partial | No notification sends SMS |
| N10 | Retry and failure handling | **Verified** | `MailResilienceTest` |
| N11 | Amharic and English templates | Partial | `User` lacks `HasLocalePreference`; queued mail renders English |
| N12 | No sensitive data in previews | Partial | Tenant template can add `{net_amount}`; scheduled reports email salary CSVs anywhere |

### RP. Reports — P1

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| RP1 | Active and inactive lists | **Verified** | `ReportBuilderTest` |
| RP2 | Headcount breakdowns | Partial | Not by role or type |
| RP3 | Turnover | Partial | **Miscounts**: exits dated by `updated_at` (`ExecutiveDashboardService.php:281`) |
| RP4 | Contract and certification expiry | Partial | No certification register |
| RP5 | Daily and monthly attendance | **Verified** | `TeamAttendanceSummaryTest` |
| RP6 | Late, absence, overtime, missed | Partial | Scattered, no absence count |
| RP7 | Correction and exception reports | **Verified** | `AttendanceIntegrityTest` |
| RP8 | Leave utilization and balances | **Verified** | — |
| RP9 | Pending and overdue approvals | Partial | No ageing |
| RP10 | Payroll registers | **Verified** | `PayrollGoldenPayslipsTest` |
| RP11 | Gross-to-net reconciliation | **Verified** | Same |
| RP12 | Loan and advance balances | **Verified** | `PayrollBalanceMovementTest` |
| RP13 | Pension and tax reports | **Verified** | `StatutorySchedulesTest`; layout unconfirmed |
| RP14 | Workforce cost by dimension | Partial | No project dimension |
| RP15 | Personnel changes | Partial | Per employee only |
| RP16 | User activity and audit | **Verified** | `audit-log-export.test.tsx` |
| RP17 | Filters by permitted scope | Partial | D6 |
| RP18 | Metric definitions | Missing | — |
| RP19 | Ethiopian/Gregorian dates | Partial | Exports Gregorian only |
| RP20 | CSV/XLSX encoding | Partial | No UTF-8 BOM, so Amharic garbles in Excel |
| RP21 | Print and PDF | **Verified** | — |
| RP22 | Access matches data permissions | Partial | D6 |
| RP23 | Period and generation timestamps | Partial | Period not stated |
| RP24 | Dashboard, table, export agree | Partial | Dashboard headcount counts departed staff |
| RP25 | Incomplete-data warnings | Partial | Truncation not carried into exports |
| RP26 | No fabricated analytics | Partial | `headcountTrend` rebuilt from current status; `pending_approvals => 0` hard-coded |

### LZ. Localization and accessibility — P1

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| LZ1 | Complete English | **Verified** | `hardcoded-english.test.tsx` |
| LZ2 | Amharic coverage | **Verified** | `scripts/i18n-check.js`, `LangParityTest`; legal pages excepted (C10) |
| LZ3 | Ethiopic fonts | **Verified** | Noto Sans Ethiopic, PDF fonts |
| LZ4 | Long translations | Partial | E2E advisory; tab strip overflows at 375px |
| LZ5 | Keyboard and focus | Partial | axe advisory only |
| LZ6 | Consistent calendar display | Partial | `useDateFormatters` is Gregorian `en-GB` in 41 consumers |
| LZ7 | Storage vs display dates | **Verified** | `date-utils.test.ts` |
| LZ8 | Unambiguous periods | Partial | Raw ISO dates on leave |
| LZ9 | ETB | **Verified** | `currency-display.test.tsx` |
| LZ10 | Phone numbers | **Verified** | `PhoneWritePathCanonicalizationTest` |
| LZ11 | Time zones | **Verified** | `AttendanceTenantTimezoneTest` |
| LZ12 | Accessible components | Partial | Unlinked labels on organization page |
| LZ13 | Contrast, reduced motion | **Verified** | `design-token-contrast.test.ts` |
| LZ14 | Mobile layouts | Partial | 16 tables unusable on phones |
| LZ15 | Low bandwidth | Partial | ~472 KB first load; Lighthouse gate not in CI |
| LZ16 | No English-only errors or confirms | Partial | 13 native `confirm()`, 6 in English; literal `"Rejected"` |
| LZ17 | No concatenated fragments | Partial | 27 prefix/suffix keys |
| LZ18 | Localized exports | Partial | English CSV headers |

### PR. Privacy and security

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| PR1 | Privacy notice | Partial | Awaiting legal review |
| PR2 | Lawful basis per category | Partial | Photos only |
| PR3 | Minimization | Partial | No data inventory |
| PR4 | Biometric safeguards | **Verified** | `PhotoConsentTest` |
| PR5 | Identity documents | **Verified** | Encrypted TIN, national ID, account numbers |
| PR6 | Least privilege | **Verified** | `ControllerAuthorizationInventoryTest` — route-level; D4–D7 are inside routes |
| PR7 | Strong authentication | **Verified** | `MfaTest`, `SessionIdleTimeoutTest` |
| PR8 | Step-up for privileged actions | Partial | Platform only |
| PR9 | Backend isolation | **Verified** | As A1 |
| PR10 | Caches, attachments, reports | **Verified** | `TenantStorageIsolationTest` |
| PR11 | Sensitive access audit | Partial | Document downloads, exports, report runs not audited |
| PR12 | Retention and deletion | Partial | No schedule for leavers' records |
| PR13 | Secure files | **Verified** | `VerifyUploadedFiles`, signed links |
| PR14 | Encryption | Partial | Backups unencrypted; SMTP pinned to an expired certificate |
| PR15 | Backup, restore, DR | Partial | Host restore M6 not performed |
| PR16 | Incident response | Partial | `docs/operations/DATA-BREACH-RESPONSE.md`; ECA registration pending |
| PR17 | Data-subject requests | Missing | C5 |
| PR18 | Third-party transfers | Partial | No sub-processor list |
| PR19 | Common web vulnerabilities | **Verified** | CSP live (M1), CSRF and rate-limit tests |
| PR20 | Security review on access changes | Partial | Inventory tests; ruleset requires 0 approvals |

### PH. Attendance photos

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| PH1 | Documented retention | **Verified** | `docs/operations/ATTENDANCE-PHOTO-RETENTION.md` (C2) |
| PH2 | Restricted access | **Verified** | `AttendancePhotoRetentionTest` |
| PH3 | Linked to the event | **Verified** | Keyed to record and direction |
| PH4 | Auditable holds | **Verified** | `photo_held` / `photo_hold_released` |
| PH5 | Notice before deletion | **Verified** | 48h review |
| PH6 | Deletion recorded | **Verified** | `photos_deleted` audit |
| PH7 | Backup retention defined | Partial | Window not stated (about 3 days at `BACKUP_KEEP=2`) |
| PH8 | Minimization | **Verified** | `selfie-compression.test.ts` |

### UX. Design system

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| UX1 | Visual identity | **Verified** | `docs/DESIGN_SYSTEM.md` |
| UX2 | Tokens | Partial | Phase 2 not started |
| UX3 | Role dashboards | **Verified** | `ManagerPortalTest` |
| UX4 | Navigation | **Verified** | `nav-permissions.test.tsx` |
| UX5 | Shared page components | Partial | `PageHeader` dead props, 3 pagers |
| UX6 | Predictable forms | Partial | Four save behaviours in settings |
| UX7 | Pagination, search, empty | Partial | 7 hand-rolled pagers |
| UX8 | Loading and error states | Partial | 39 files without error branch |
| UX9 | Destructive confirmation | Partial | ~20 unconfirmed |
| UX10 | Unsaved-change warnings | Partial | One page |
| UX11 | Mobile nav and tables | Partial | 16 tables |
| UX12 | Keyboard | Partial | axe advisory |
| UX13 | Search and shortcuts | **Verified** | `command-palette.test.tsx` |
| UX14 | Information density | Partial | No standard |
| UX15 | No needless animation | **Verified** | — |
| UX16 | Consistent status colours | Partial | Two central maps disagree |
| UX17 | Language parity | **Verified** | As LZ2 |
| UX18 | Tenant branding | **Verified** | `tenant-branding.test.tsx` |
| UX19 | Reusable code | Partial | `organization/page.tsx` 1,549 lines |
| UX20 | Regression strategy | Partial | No visual regression; E2E advisory |

### OPS. Reliability and release

| ID | Item | Status | Evidence / gap |
|---|---|---|---|
| OPS1 | Health checks | **Verified** | `/health`, `QueueHealthTest` |
| OPS2 | Error monitoring | Partial | Sentry likely not active in production |
| OPS3 | Failed requests | Partial | Silent empty screens (UX8) |
| OPS4 | Duplicate-submission protection | **Verified** | `PayrollProcessingTest:171`, `IdempotencyKeyHeaderTest` |
| OPS5 | Tested restore | Partial | M6 not performed on the host |
| OPS6 | Controlled migrations and release | **Verified** | `release.yml`, queue-drain precondition |
| OPS7 | Rollback | Partial | Never rehearsed |
| OPS8 | Hosting compatibility | Partial | `verify.sh` 50/50; host gates open |
| OPS9 | Storage monitoring | Partial | 5 GB quota never measured |
| OPS10 | Upload limits | **Verified** | `UploadLimit`; host locks 2 MB |
| OPS11 | Performance budgets | Partial | Opt-in, not in CI |
| OPS12 | Slow request monitoring | Partial | — |
| OPS13 | Dependency patching | **Verified** | Dependabot, weekly `security.yml` |
| OPS14 | Incident communication | Partial | No customer channel |
| OPS15 | Post-release smoke tests | Partial | Manual |
| OPS16 | No untested migrations on live data | Partial | CI runs MariaDB 10.11; production is MySQL 8.0.32 strict, and that gap already broke one live `migrate` |

---

## 4. Readiness to serve another organization — end-to-end scenarios

| Scenario | Verdict | Blocking items |
|---|---|---|
| Tenant isolation | **Pass** | Single acceptance suite still worth adding |
| Employee lifecycle | Partial | Offboarding: login, clearance, final pay (D3) |
| Attendance integration | Partial | Hardware unproven (O1); unmatched punches dropped |
| Attendance correction | Partial | Traceable, but no payroll-period lock |
| Leave | Partial | Seniority accrual, ledger |
| Payroll | **Not ready to claim** | D1–D3, maker-checker, accountant sign-off O2 |
| Permissions | Partial | D4–D7 |
| Localization | Partial | Calendar-consistent dates (LZ6) |
| Reports | Partial | D6, turnover, CSV encoding |
| Privacy | Partial | Data-subject requests, access auditing |
| Mobile | Partial | Responsive tables |
| Failure handling | Partial | Error states (UX8) |
| Recovery | **Not proven** | Host restore M6 |
| Release | Partial | Smoke tests manual; MySQL 8 gate missing |

---

## 5. Decided order of work

### Now — P0, code (small, independent, safe to ship one at a time)

1. **Payroll correctness:** exclude voided and flagged attendance from overtime and
   summaries (D1); gate bank export on an approved run and reconcile its total (D2); pay
   leavers to `termination_date` and settle loans on exit (D3); refuse approval by the
   person who processed the run.
2. **Permissions inside the tenant:** `salary_cents` behind `employee.viewFinancial`, and
   `employee.updateFinancial` on the HR edit path (D4); per-employee scope on personnel
   actions and retirement cases (D5); `ReportEngine` applying org scope and payroll and
   financial permissions, and scheduled-report recipients limited to permitted users
   (D6); `/shifts/schedule` scoped (D7); supervisor access to `medical`/`id_copy`
   documents.
3. **Offboarding safety:** deactivate login and revoke tokens on an exit transition.
4. **Attendance–payroll lock:** corrections dated inside an approved run are refused or
   flagged on the run; replace the hard-coded `in_open_payroll_period`.
5. **MySQL 8 strict CI job** next to the MariaDB one.

### Now — P0, owner actions (no code can close these)

- **O2** accountant sign-off, with C3 added: the 1395/2025 start date and Directive 1087/2025.
- **O3** agency layouts for tax and pension schedules.
- **M6** restore rehearsal on the host, plus confirming nightly backups exist on production.
- **O1** device field test.
- Set the Sentry DSN on production.
- Refresh `docs/deployment/CUTOVER-CHECKLIST.md` to match the live system.
- ECA registration as a processor.

### Next — P1

Shared approval engine with delegation and escalation (C1); leave seniority accrual,
proration, expiry and a balance ledger; unmatched-punch queue; contract, probation and
document expiry reminders; employees' own documents and certificate requests;
notification deep links and recipient language; dates through `useCalendar` everywhere;
`useConfirm`/`ReasonDialog` replacing native confirms; CSV UTF-8 BOM and Ethiopian-date
column; turnover and headcount history fixed, with written metric definitions; customer
self-service data export; leave-balance and loan-balance migration imports with a
reconciliation report.

### Later — P2

Shift swaps and staffing alerts; overtime pre-approval and caps; establishment
management; legal entities and pay groups; project and donor cost allocation; academic
calendar; expense and travel; asset register and exit clearance; feature flags; status
banner and support tickets; storage metering.

### After validating demand — P3

Recruitment and applicant tracking, performance management, learning, succession and
skills (deferred to v2.0 by `docs/PRODUCT_VISION.md` §20).

---

## What this assessment does not claim

- **Verified** means a test or recorded QA exists, not that the feature was re-exercised
  on production today.
- No statutory figure is asserted correct. That is O2's job.
- D1–D7 were confirmed by reading code, not by reproducing them on a running system;
  each fix should land with the test that would have caught it.
