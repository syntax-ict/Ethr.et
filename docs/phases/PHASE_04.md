# Phase 4 — Leave Management & Payroll (v2.0)

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
- Phase 2 complete (employees, org structure)
- Phase 3 complete (attendance, shifts, overtime) — all gaps closed

## Objective
Full leave management with Ethiopian calendar awareness, and payroll processing with Ethiopian tax/pension rules engine, calculation audit trail, and voiding/reprocessing.

**SPLIT RECOMMENDATION:** This phase is delivered in two sub-phases to reduce risk and deliver value earlier:
- **Phase 4A:** Leave Management + Holiday Engine (S20 + S21) — delivers value independently
- **Phase 4B:** Payroll Rules Engine + Processing (S22 + S23) — builds on attendance + leave data

---

## Progress / Gap Closure Log

Phase 4 backend was already substantially implemented (leave, holidays, tax/pension/overtime
calculators, loans, payroll processing, payslip PDF, bank/tax/pension exports). Audit findings
and closures are tracked here.

### 2026-07-28 — S22 Allowance engine (GAP-4B-1) ✅

**Gap:** `PayrollEngine::processEmployee()` hard-coded allowances to empty (`$allowances = []`),
and taxable income was set to full gross — the S22 `AllowanceService` and the S23 step-8
"taxable income = gross − non-taxable allowances" rule were unimplemented.

**Closed:**
- `PayrollRule` model + factory over the (previously model-less) `payroll_rules` table
  (`BelongsToTenant`, `HasPublicId`, `HasAuditLog`). Allowance rules: `type` `fixed` (formula
  `{amount_cents}`) or `percentage` (formula `{percent}` of basic), `is_taxable`, `is_active`,
  `sort_order`.
- `App\Services\Payroll\AllowanceService` — resolves a tenant's active allowance rules into
  per-employee amounts in integer cents (percentage computed on prorated basic), returning the
  taxable / non-taxable split. Tenant-scoped.
- `PayrollEngine` wired to `AllowanceService`: allowances now flow into gross; **taxable income =
  gross − non-taxable allowances** (S23 step 8); allowance breakdown recorded in an `allowances`
  step of the immutable `calculation_log` and in `payroll_entries.allowances`. Payslip PDF renders
  each allowance by name. Backwards compatible — no rules ⇒ identical output to before.
- Tests: `tests/Feature/PayrollAllowanceTest.php` (8 tests) — fixed & percentage allowances,
  taxable vs non-taxable tax effect, integer-cents rounding, inactive rules ignored, tenant
  isolation, calculation_log breakdown.
- Gates: full Pest suite green (841), PHPStan level 6 clean, Pint clean.

**Still open in S22/S23 (not yet closed):** allowance/loan config REST API + payroll config
frontend (making allowance rules, OT rates, and tax brackets tenant-manageable).

### 2026-07-28 — S22/S23 Overtime by rate type (GAP-4B-2) ✅

**Gap:** `PayrollEngine` collapsed all overtime into a single `normal` (1.25x) bucket with
hard-coded 22 days / 8 hours, so night (1.5x), holiday (2.0x) and holiday-night (2.5x) overtime —
which the `OvertimeCalculator` already supports — was underpaid.

**Closed:**
- `AttendanceRecord::overtimeWindow()` — extracts the past-shift-end OT interval as the single
  source of truth; `overtimeMinutes()` now derives from it (behaviour unchanged).
- `App\Services\Payroll\OvertimeClassifier` — splits a record's OT minutes into
  normal / night / holiday / holiday_night by overlapping the OT window with the 22:00–06:00 night
  window (handles OT crossing midnight) and the holiday flag.
- `HolidayService::getHolidayDates()` — pre-fetches a period's holiday dates once (branch-aware)
  so classification is an in-memory lookup, not a per-record query.
- `PayrollEngine` now accumulates OT minutes per type and calls `OvertimeCalculator` once per type,
  recording a `by_type` breakdown in the `overtime` `calculation_log` step. Working days (22) and
  hours/day (8) are now named constants. Backwards compatible: daytime, non-holiday OT is identical
  to before.
- Also corrected a latent typing issue: larastan treated `AttendanceRecord::$check_in/$check_out`
  as `string`; typing them as `Carbon` removed **12 obsolete PHPStan baseline suppressions** across
  AttendanceRecord, AttendanceIntelligence, ConflictResolver, and DeviceController.
- Tests: `tests/Unit/OvertimeClassifierTest.php` (6) + `tests/Feature/PayrollOvertimeTest.php` (3).
- Gates: full Pest suite green, PHPStan level 6 clean, Pint clean.

### 2026-07-28 — S23 Unpaid-leave proration (GAP-4B-3) ✅

**Gap:** the engine ignored approved leave (spec S23 step 2), so an employee on **unpaid leave was
paid in full**.

**Closed:**
- `PayrollEngine` now gathers approved leave of unpaid types (`LeaveType.is_paid = false`)
  overlapping the pay period and reduces earned basic by the contractual daily rate
  (full monthly basic / 22 working days) per unpaid working day, capped at the prorated basic and
  recorded as an `unpaid_leave` `calculation_log` step. Paid leave has no effect.
- Day counting reuses `LeaveDayCalculator` (weekend/holiday-aware); a request fully inside the
  period uses its stored half-day-aware `days`, a boundary-spanning request is clipped and
  recounted. Runs before OT/allowance/tax/pension so all downstream amounts reflect actual pay.
- Tests: `tests/Feature/PayrollUnpaidLeaveTest.php` (6) — unpaid reduces pay, paid does not,
  pending ignored, boundary clipping, half-day, and the no-leave baseline.
- Gates: full Pest suite green, PHPStan level 6 clean, Pint clean.

**Latent bug spotted (out of scope):** `TeamMonitoringController` reads `$leaveType->color`, but
`leave_types` has no `color` column — currently hidden by the loose `leaveType` relation typing.

### 2026-07-28 — S22/S23 Payroll config API + frontend (GAP-4B-4) ✅

**Gap:** allowance rules, tax brackets and OT rates existed in the engine but were not
tenant-manageable — no REST API and no UI. This was the last item left open by GAP-4B-1.

**Closed — backend:**
- New permissions `payroll.viewConfig` (hr_admin / finance_admin) and `payroll.manageConfig`
  (tenant_admin); `PermissionSeeder` now seeds 67 permissions.
- `PayrollRuleController` — `apiResource` CRUD at `/payroll/rules` over `category = allowance`
  rules. `Store/UpdatePayrollRuleRequest` validate the `formula` shape against `type`
  (`fixed` ⇒ `amount_cents`, `percentage` ⇒ `percent` ≤ 100) and reject the other key outright.
  The `overtime` rule row is excluded from the allowance listing.
- `TaxBracketController` — `GET /payroll/tax-brackets` (falls back to the platform ladder so the
  UI shows what is actually applied) and `PUT` to replace the whole ladder.
  `ReplaceTaxBracketsRequest` enforces ladder integrity: starts at 0, contiguous
  (`next.min == prev.max + 1`), only the final band open-ended. Whole-ladder replacement means the
  ladder can never be left with a gap or overlap between two requests.
- `OvertimeRateController` — `GET`/`PUT /payroll/overtime-rates`, stored as a single
  `category = overtime` payroll rule. `UpdateOvertimeRatesRequest` floors each multiplier at its
  Labour Proclamation minimum (1.25 / 1.5 / 2.0 / 2.5). Response reports `rates`, `defaults` and
  `is_customized`.
- `OvertimeCalculator` is now tenant-aware (`ratesFor()`, per-tenant memo, per-key fallback to
  `DEFAULT_RATES`); `PayrollEngine` passes the tenant and records the applied `rate` per OT type in
  the `overtime` `calculation_log` step.
- `LoanController::update` / `::cancel` — adjust the instalment, or cancel so the loan stops being
  deducted. Both refuse a non-active loan with RFC-7807 `422`; the row is never hard-deleted.
- Tests: `tests/Feature/PayrollConfigTest.php` (26).
- Gates: full Pest suite green (882), PHPStan level 6 clean (one obsolete baseline entry removed),
  Pint clean.

**Bugs found and fixed while closing this gap:**
1. **`PayrollEngine` never passed the tenant to `TaxCalculator`** — `calculate()` took `?int
   $tenantId` but was called without it, so the `tax_brackets` table was **entirely inert** and
   every tenant was taxed on the hard-coded defaults. Fixed; covered by a test that asserts a
   replaced ladder changes withheld tax.
2. **`TaxCalculator` merged tenant and platform brackets** (`tenant_id = X OR tenant_id IS NULL`),
   which would have produced an overlapping ladder the moment a tenant defined its own. Resolution
   is now strictly tenant → platform → hard-coded defaults, with a per-tenant memo so a run
   resolves the ladder once instead of once per employee.
3. **`TaxBracketSeeder` disagreed with `TaxCalculator::$defaultBrackets`** — it seeded a different
   6-band ladder (missing the 35% band) which, being in the database, silently overrode the correct
   defaults. Re-aligned to the canonical Proclamation 979/2016 bands, with stale rows from the
   superseded ladder deleted on re-seed.

**Closed — frontend:**
- `/settings/payroll` (tenant-admin gated), wired into the sidebar and `route-meta`.
- `AllowanceRulesCard` — CRUD table + dialog, fixed (ETB) or percentage-of-basic, taxable and
  active switches. `TaxBracketsCard` — editable ladder where each band's lower bound is *derived*
  from the previous band's upper bound, so a non-contiguous ladder cannot be submitted.
  `OvertimeRatesCard` — four multipliers with statutory minimums, "using defaults" badge, and
  reset-to-defaults.
- All three use `QueryBoundary` and semantic tokens; 54 i18n keys added to `en` + `am` (including
  the `route.settings.payroll.{label,desc}` pair the breadcrumb resolves via `routeI18nKey()` —
  without them the breadcrumb falls back to the hard-coded English in `route-meta.ts`).
- `useUpdateLoan` / `useCancelLoan` hooks added for the new loan endpoints.
- Added a `ResizeObserver` polyfill to `src/test/setup.ts` — jsdom has none and Radix primitives
  throw without it.
- Tests: `src/test/payroll-config.test.tsx` (14) covering loading/empty/error/success, cents
  conversion on submit, and ladder contiguity. Vitest 191 green, `tsc --noEmit` clean, Prettier clean.

**Browser verified** (`demo` tenant, `admin@demo.ethr.et`, tenant_admin):
- End-to-end write path for all three cards: allowance created (persisted as
  `{"amount_cents":100000}` with a `payroll_rule.created` audit row), overtime rates saved
  (`{"normal":2,"night":2.5,...}`, "Using defaults" badge correctly cleared), tax ladder replaced
  and re-resolved by `TaxCalculator` (500,000 cents ⇒ 69,750 tax).
- Sub-minimum overtime rate is blocked client-side by the `min` attribute before it reaches the
  API (server-side rejection is covered by Pest).
- Responsive: no horizontal page overflow at 1280 / 768 / 375; both wide tables scroll inside their
  own `overflow-x-auto` containers at 375px and the OT grid collapses to one column.
- Dark + light verified against the spec tokens (`#0F172A` / `#F1F5F9` / `#1E293B` dark,
  `#FFFFFF` / `#0F172A` light). The app keys dark mode off the `.dark` class, not `data-theme`.
- Amharic: all strings translated, no raw keys leaked. Console clean (no errors, no warnings).
- Demo rows created during the sweep were removed afterwards; the dev DB was re-seeded with
  `PermissionSeeder` (63 ⇒ 67 permissions) and the corrected `TaxBracketSeeder`, which visibly
  replaced the stale 6-band ladder with the canonical 7-band one.

### 2026-07-28 — S20 Leave accrual & carry-forward were never scheduled (GAP-4A-1) ✅

**Gap:** `LeaveBalanceService::accrueMonthly()` and `carryForward()` were implemented and tested,
but **nothing ever called them** outside the test suite — no job, no command, no scheduler entry.
Monthly-accrual leave types would therefore have stayed at zero entitlement forever in production,
and unused leave would never have rolled into the next year.

**Closed:**
- `AccrueLeaveBalancesJob` + `CarryForwardLeaveBalancesJob`, dispatched per active tenant following
  the `ScanMissingPunchesJob` pattern (resolve the tenant, set `CurrentTenant`, then work), so one
  tenant's failure cannot stall the others.
- Scheduled in `routes/console.php`: accrual `monthlyOn(1, '00:30')`, carry-forward
  `yearlyOn(1, 1, '01:00')`.
- Both jobs are replay-safe: `accrueMonthly()` targets a cumulative entitlement rather than adding
  an increment, and carry-forward *assigns* `carried_days` rather than incrementing it — so a retry
  or a missed month self-corrects.
- Tests: `tests/Feature/LeaveBalanceJobsTest.php` (9), which clear the ambient tenant first to
  prove the jobs stand alone rather than inheriting it from the test harness.

**Bugs found and fixed while closing this gap:**
1. **`carryForward()` never filtered `LeaveType` by tenant** — it took `$tenantId` but leaned on the
   ambient global scope for leave types while scoping balances explicitly. From a queued job with
   no resolved tenant the global scope resolves to `0 = 1`, so it would have silently carried
   nothing; with a *different* tenant resolved it would have crossed tenants. Both it and
   `accrueMonthly()` now scope explicitly via `withoutGlobalScope('tenant')` + `where('tenant_id')`,
   matching `PayrollEngine`.
2. **`carryForward()` crashed on a deleted employee** — `Employee::find()` returning null was passed
   straight into `getOrCreateBalance(Employee $employee, …)`. Now skipped.

**Timing note:** the spec says carry-forward runs at *fiscal year-end*, which is tenant-configurable
(`fiscal_year_start_month`, Hamle vs Meskerem). But `LeaveBalance.year` is the **Gregorian** year —
`accrueMonthly()` keys off `now()->year` and accrues on a Jan–Dec curve — so carry-over runs on
1 January to stay in step with accrual. Re-keying balances to the Ethiopian fiscal year would be a
much larger change (balance keys + accrual maths); flagging rather than assuming.

### 2026-07-28 — S21 Only 8 of the 13 public holidays were detected (GAP-4A-2) ✅

**Gap:** `HolidayService::getEthiopianHolidays()` returned 8 fixed-date holidays. All five **movable
feasts** were missing, so leave-day counting, attendance holiday classification and holiday overtime
were all wrong on those dates.

**Closed:**
- `EthiopianCalendar::orthodoxEaster()` / `orthodoxGoodFriday()` — Alexandrian (Julian) computus via
  a new `julianToJdn()`, reusing the existing exact JDN machinery. Verified against the published
  dates for 2021–2027 and asserted to land on Sunday/Friday across 2020–2040.
- `EthiopianCalendar::hijriToGregorian()` / `hijriYearsOverlapping()` — tabular Islamic calendar
  (Kuwaiti algorithm). `hijriYearsOverlapping()` returns a *list* because a Hijri year is ~11 days
  shorter, so a feast can fall twice in one Gregorian year.
- Added Siklet, Fasika, Eid al-Fitr, Eid al-Adha (Arafa) and Mawlid ⇒ **13 holidays**, bilingual.
- Migration `add_is_estimated_to_holidays`: the three Islamic feasts are flagged `is_estimated`
  because Ethiopia observes them by local moon sighting, which can move the date by a day — HR is
  expected to confirm and adjust. Orthodox feasts are *calculated*, so they are not flagged.
  Surfaced on `HolidayResource` and editable via the holiday FormRequests.
- Tests: `tests/Feature/MovableHolidayTest.php` (10), including a ±1-day check against observed
  Islamic dates and a no-gap sweep of Hijri year resolution over 2020–2060.
- Typed the `Holiday` model with `@property` docblocks, which removed **13 obsolete PHPStan baseline
  entries** across `HolidayResource` and `EmployeeDashboardService`.

**Contract clarified (not a bug):** `autoDetect($year)` seeds the *Ethiopian* year beginning in
September of `$year`, so Genna, Timkat and Adwa always land in `$year + 1`. Seeding consecutive years
gives each calendar year exactly 13 — now asserted by a test, since a first attempt at the obvious
"all dates inside the requested year" assertion was simply the wrong contract.

**Third bug, surfaced by browser-testing this change:** `autoDetect()` de-duplicated on
**name + date**, so a tenant that had already recorded "Ethiopian Christmas" gained a second row when
auto-detect proposed "Ethiopian Christmas (Genna)" for the same day. Going from 8 to 13 candidates
made the collision far more likely. It now matches on the date of a tenant-wide holiday, leaving
branch-specific rows untouched (they coexist with national holidays by design).

**Gates:** full Pest suite green (903), PHPStan level 6 clean, Pint clean.

**Browser verified:** ran Auto-Detect on the `demo` tenant — 13 holidays including all five movable
feasts, `is_estimated` set on exactly the three Islamic ones, zero duplicate dates against the
tenant's pre-existing entries, console clean. Demo data restored afterwards.

**Remaining in Phase 4:** the S20/S21/S22/S23 checklists below are the original spec and are not
maintained as live state — see this log for what is actually closed.

### 2026-07-28 — S20 Team leave calendar 500'd on a missing column (GAP-4A-3) ✅

**Gap:** the latent bug flagged (out of scope) while closing GAP-4B-3. `TeamMonitoringController::leaveCalendar`
eager-loaded `leaveType:id,name,color` and read `$leaveRequest->leaveType?->color`, but `leave_types`
has **no `color` column** — so `GET /api/v1/team/leave/calendar` threw *Unknown column 'color'* and
returned 500 for **every** manager. It was hidden from PHPStan by the loosely-typed `leaveType`
relation (access resolved to `Model`) and had **no test**, so the suite stayed green.

**Closed:**
- `LeaveType::calendarColor()` — a stable, visually distinct colour derived deterministically from the
  immutable `code` (fixed 8-colour palette). No dead schema column: a stored colour would be perpetually
  null since nothing sets, seeds or configures one, and the design system discourages arbitrary stored
  hex. The API contract (per-cell `color`) is preserved; custom tenant leave types get a distinct colour
  for free.
- Controller now loads `leaveType:id,name,code` and calls `->calendarColor()`.
- Typed `LeaveRequest::leaveType()` as `BelongsTo<LeaveType, $this>` (the fix for the root cause that hid
  the bug). This also let PHPStan resolve `leaveType->name` in `LeaveApproved/Rejected/RequestedNotification`
  and `ApprovalController`, retiring **6 obsolete baseline entries** (only the deterministic, change-caused
  ones — the environment-sensitive per-model trait entries were left untouched).
- Tests: `tests/Feature/TeamLeaveCalendarTest.php` (3) — the missing regression: endpoint returns 200 with
  colour-coded blocks, colour determinism, and employee-forbidden authz.

**Gates:** full Pest suite green (906), PHPStan level 6 clean, Pint clean.

---

## PHASE 4A — Leave Management & Holidays

---

## S20 — Leave Management

### Backend
- [ ] `leave_types`, `leave_balances`, `leave_requests` tables migration (see DATABASE.md)
- [ ] Models with `BelongsToTenant`, `HasPublicId`, `SoftDeletes`
- [ ] `LeaveTypeController`: CRUD for leave types
  - Default types seeded from organization template (S08)
  - Gender-restricted types: maternity (female only, 120 days — Ethiopian law), paternity (male only)
  - Configurable per type: days_entitled, accrual_method (monthly/quarterly/annual/none), carry_forward_max, min_notice_days, max_consecutive_days, requires_attachment, requires_medical_certificate
  - 9 default types: annual, sick, maternity, paternity, emergency, study, unpaid, compensatory, bereavement
  - Permission: `leave.manage_types`
- [ ] `LeaveBalanceService`:
  - `calculateBalance(Employee, LeaveType, year)` — entitled + carried - used - pending = remaining
  - `accrueMonthly()` — scheduled job: add monthly accrual to balances (runs 1st of each month)
  - `carryForward(year)` — scheduled job: at fiscal year-end, carry forward up to max_carry_days, zero out excess
  - `adjustBalance(Employee, LeaveType, adjustment, reason)` — manual adjustment by HR with audit log
  - All amounts stored as half-day precision (0.5 increments) to support half-day leave
  - Permission: `leave.manage_balances`
- [ ] `LeaveRequestController`:
  - `POST /api/v1/leave/request` — submit leave request (requires `leave.request`)
    - Validate: sufficient balance, min notice period, max consecutive days, no overlap with existing approved leave
    - Holiday-aware calculation: skip public holidays and non-working days when counting leave days
    - Pagumen handling: leave spanning Pagumen counts Pagumen working days correctly
    - Set `pending_days` on balance (reserved until approved/rejected)
    - Idempotency-Key required
  - `GET /api/v1/leave/my` — employee's own requests (paginated, filterable by status/type/year)
  - `GET /api/v1/leave/team` — manager's team requests (requires `team.view`)
  - `GET /api/v1/leave/balance` — current user's balances (all types with breakdown)
  - `GET /api/v1/leave/balance/{employee_id}` — specific employee (requires `leave.manage_balances`)
  - `PUT /api/v1/leave/{id}/approve` — approve (advances approval chain, requires `leave.approve`)
  - `PUT /api/v1/leave/{id}/reject` — reject with required reason (requires `leave.approve`)
  - `PUT /api/v1/leave/{id}/cancel` — employee cancels own pending request
- [ ] Approval chain: configurable per tenant (default: employee → supervisor → dept head → HR)
  - Each step advances to next approver
  - Final approval: updates balance (decrement remaining, clear pending_days)
  - Any rejection: clears pending_days, notifies employee
- [ ] `LeaveApproved` event → update balance, notify employee, update team calendar cache
- [ ] `LeaveRejected` event → clear pending_days, notify employee
- [ ] Team minimum staffing check: warn approver if < 70% staffing for requested dates (configurable threshold)
- [ ] Notification dispatch at each approval step
- [ ] Audit logging for all leave operations

### Frontend
- [ ] Leave request form (drawer form — keeps calendar visible):
  - Leave type selector (only types available to this employee, with current balance shown per type)
  - Date range picker (`DualCalendarPicker`, highlight holidays/weekends as grayed-out)
  - Calculated working days (excluding holidays/weekends, updated live as dates change)
  - Pagumen indicator (if leave spans Pagumen, show note)
  - Balance remaining after this request (calculated live)
  - Reason text field
  - Attachment upload (`FileUpload`, if type requires)
  - Submit button with loading state
  - Uses Zod validation
- [ ] My Leaves page:
  - Balance cards per type (`StatCard`: entitled, used, pending, remaining — color-coded)
  - Request history DataTable (type, dates, days, status `StatusBadge`, actions)
  - Cancel button on pending requests (with `ConfirmDialog`)
  - Filter by type, status, year
  - QueryBoundary for all states
- [ ] Team leave calendar (manager view):
  - Monthly calendar grid: employees as rows, days as columns
  - Leave blocks color-coded by leave type
  - Holiday columns highlighted
  - Staffing indicator per day (green = sufficient, amber = low, red = below minimum)
  - List view toggle: pending requests with approve/reject inline buttons
  - Filter by department
- [ ] Leave approval page:
  - Pending requests list (DataTable with employee photo, name, type, dates, days, reason)
  - Request detail drawer: employee info, type, dates, calculated days, reason, attachment (if any)
  - Team calendar context panel (who else is off on those dates)
  - Approve / Reject buttons
  - Rejection requires reason (text input)
  - `ApprovalChain` component showing workflow progress
- [ ] HR leave dashboard:
  - Leave usage summary by type (bar chart via `ChartWrapper`)
  - Leave usage by department (comparison table)
  - Upcoming leaves this week/month
  - Balance alerts: employees with zero balance (sorted by urgency)

### Tests
- [ ] Leave request with balance check — sufficient and insufficient (Pest)
- [ ] Holiday-aware day calculation — skip holidays and weekends (Pest)
- [ ] Pagumen leave calculation — leave spanning 13th month (Pest)
- [ ] Approval chain full flow: submit → approve → approve → balance updated (Pest)
- [ ] Rejection clears pending_days (Pest)
- [ ] Cancellation by employee (Pest)
- [ ] Maternity restricted to female employees (Pest)
- [ ] Paternity restricted to male employees (Pest)
- [ ] Min notice enforcement (Pest)
- [ ] Max consecutive days enforcement (Pest)
- [ ] Overlap detection — rejected if overlapping approved leave (Pest)
- [ ] Carry-forward at fiscal year-end (Pest)
- [ ] Monthly accrual job (Pest)
- [ ] Manual balance adjustment with audit log (Pest)
- [ ] Team minimum staffing warning (Pest)
- [ ] Tenant isolation (Pest)
- [ ] Idempotency on leave request (Pest)
- [ ] Permission checks: employee can request, supervisor can approve (Pest)
- [ ] Leave form with balance display (Vitest)
- [ ] Calculated days updates live (Vitest)
- [ ] Team calendar renders correctly (Vitest)
- [ ] Approval UI with ApprovalChain component (Vitest)
- [ ] QueryBoundary states on all leave pages (Vitest)

### Exit Criteria
- All 9+ leave types configurable with accrual and carry-forward
- Balances calculate correctly including pending reservations
- Holiday-aware day counting with Pagumen support
- Approval workflow with configurable chain and notifications
- Team calendar shows leave schedule with staffing indicators
- Half-day leave supported (0.5 increments)

---

## S21 — Holiday Engine & Ethiopian Calendar Integration

### Backend
- [ ] `holidays` table migration (with `BelongsToTenant`, `SoftDeletes`)
- [ ] `HolidayService`:
  - `getHolidays(tenantId, year)` — all holidays for the year (cached 24hr: `tenant:{id}:holidays:{year}`)
  - `isHoliday(tenantId, date)` — check if date is a holiday (reads from cache)
  - `isWorkingDay(tenantId, date)` — checks holiday + working days config
  - `getEthiopianHolidays(ethiopianYear)` — auto-detect Ethiopian public holidays:
    - Ethiopian New Year / Enkutatash (Meskerem 1)
    - Timkat / Epiphany (Ter 11)
    - Adwa Victory Day (Yekatit 23)
    - Good Friday (computed from Easter tables)
    - Easter / Fasika (computed)
    - Ethiopian Patriots Day (Miazia 27)
    - International Labour Day (Miazia 23 / May 1)
    - Downfall of the Derg (Ginbot 20)
    - Eid al-Fitr (computed from Islamic calendar)
    - Eid al-Adha (computed from Islamic calendar)
    - Mawlid (computed from Islamic calendar)
    - Meskel / Finding of the True Cross (Meskerem 17)
    - Genna / Christmas (Tahsas 29)
  - Auto-seed holidays when tenant enables Ethiopian calendar
- [ ] `HolidayController`:
  - `GET /api/v1/holidays` — list for current year (with Ethiopian calendar dates if enabled)
  - `POST /api/v1/holidays` — add custom/regional holiday
  - `PUT /api/v1/holidays/{id}` — edit
  - `DELETE /api/v1/holidays/{id}` — soft delete
  - `POST /api/v1/holidays/auto-detect` — re-run auto-detection for specified year
- [ ] Branch-specific holidays (some holidays apply only to certain branches — e.g., regional holidays)
- [ ] Recurring holidays flag (same date every year, auto-created)
- [ ] Integration points (verified working):
  - Leave: `HolidayService::isWorkingDay()` used in leave day counting
  - Attendance: holidays flagged in attendance records, holiday shifts
  - Payroll: holiday overtime rates (2.0x normal, 2.5x night)
- [ ] Cache invalidation on holiday CRUD (`tenant:{id}:holidays:{year}`)
- [ ] Permission: `settings.edit` for holiday management

### Frontend
- [ ] Holiday management page:
  - Annual calendar view with holidays highlighted (color-coded by type)
  - Holiday list DataTable (name, Gregorian date, Ethiopian date, type badge: national/religious/regional, branch scope, recurring badge)
  - Add holiday dialog (name in EN + AM, date via `DualCalendarPicker`, type selector, branch scope multi-select, recurring checkbox)
  - Auto-detect button: "Generate Ethiopian Holidays for [year selector]"
  - Edit/delete actions (soft delete)
  - QueryBoundary for all states
- [ ] Holiday display in other modules:
  - Leave request date picker: holidays grayed out, not counted, tooltip showing holiday name
  - Attendance calendar: holidays marked with indicator
  - Team calendar: holiday columns highlighted

### Tests
- [ ] Ethiopian holiday auto-detection for known year — verify all 13 holidays (Pest)
- [ ] Holiday-aware leave calculation — holidays skipped correctly (Pest)
- [ ] Branch-specific holidays — only apply to assigned branches (Pest)
- [ ] Recurring holidays — auto-created for new year (Pest)
- [ ] Holiday CRUD + soft delete (Pest)
- [ ] Cache invalidation on holiday change (Pest)
- [ ] Islamic holiday computation (approximate — noted as approximate in UI) (Pest)
- [ ] Holiday calendar view renders correctly (Vitest)
- [ ] Auto-detect button populates holidays (Vitest)

### Exit Criteria
- 13 Ethiopian holidays auto-detected correctly
- Custom/regional/branch-specific holidays manageable
- Recurring holidays auto-generated
- Holidays integrated with leave day counting, attendance, and payroll OT rates
- Dual calendar display (Gregorian + Ethiopian dates shown)
- Holiday cache with proper invalidation

---

## PHASE 4B — Payroll Engine & Processing

---

## S22 — Payroll Rules Engine

### Backend
- [ ] `payroll_rules`, `tax_brackets`, `employee_loans` tables migration
- [ ] `PayrollRule` model: category (tax, pension, overtime, allowance, deduction), formula (JSON), is_active
- [ ] `TaxCalculator` service:
  - Ethiopian income tax brackets (stored in `tax_brackets` table, configurable):
    - 0 – 600: 0%
    - 601 – 1,650: 10% − 60
    - 1,651 – 3,200: 15% − 142.50
    - 3,201 – 5,250: 20% − 302.50
    - 5,251 – 7,800: 25% − 565
    - 7,801 – 10,900: 30% − 955
    - 10,901+: 35% − 1,500
  - Method: `calculate(grossTaxableCents): taxCents`
  - All arithmetic in integer cents — no floating point at any step
  - Edge case test: salary exactly at bracket boundary
- [ ] `PensionCalculator` service:
  - Employee contribution: 7% of basic salary (configurable)
  - Employer contribution: 11% of basic salary (configurable)
  - Method: `calculate(basicSalaryCents): { employee_cents, employer_cents }`
  - Integer arithmetic only
- [ ] `OvertimeCalculator` service:
  - Normal overtime: 1.25x hourly rate
  - Night overtime: 1.5x
  - Holiday overtime: 2.0x
  - Holiday night overtime: 2.5x
  - All rates configurable per tenant
  - Input: overtime_minutes, shift_type, is_holiday → amount_cents
  - Hourly rate derived from: (monthly_basic_cents / working_days_in_month / 8)
  - Integer arithmetic only — document rounding strategy (round half up)
- [ ] `AllowanceService`: manage allowance types per tenant
  - Types: position, transport, housing, hardship, other (custom)
  - Fixed amount (cents) or percentage of basic salary
  - Configurable per position/grade
  - Taxable vs non-taxable flag per allowance type
- [ ] `LoanService`:
  - `POST /api/v1/payroll/loans` — create loan (employee_id, amount_cents, monthly_deduction_cents, reason, start_date)
  - Automatic deduction in payroll until fully repaid
  - Loan tracking: remaining_balance_cents, total_paid_cents, installments_remaining
  - `GET /api/v1/payroll/loans` — list loans (filterable by employee, status)
  - `GET /api/v1/payroll/loans/{id}` — loan detail with payment history
  - `PUT /api/v1/payroll/loans/{id}/pause` — pause deductions
  - `PUT /api/v1/payroll/loans/{id}/resume` — resume deductions
  - Permission: `payroll.configure`
- [ ] Default tax brackets seeded from Ethiopian law
- [ ] Default payroll rules seeded from organization template

### Frontend
- [ ] Payroll configuration page (settings tab):
  - Tax brackets table (editable rows: min, max, rate, deduction — `CurrencyInput`)
  - "Reset to Ethiopian defaults" button
  - Pension rates (employee %, employer % — number inputs)
  - Overtime rates (normal, night, holiday, holiday night — multiplier inputs)
  - Allowance types DataTable + CRUD dialog (name, type: fixed/percentage, amount/rate, taxable toggle)
  - Pagumen proration strategy selector
  - Fiscal year start month
  - All values display in ETB format
- [ ] Loan management page:
  - Employee loans DataTable (employee, amount, monthly deduction, remaining, status)
  - Create loan dialog (employee search, amount `CurrencyInput`, monthly deduction, reason, start date `DualCalendarPicker`)
  - Loan detail: payment history timeline, remaining balance, pause/resume actions

### Tests
- [ ] Tax calculation for each bracket — verify exact cents (Pest)
- [ ] Tax calculation at bracket boundary (exactly 600, 1650, etc.) (Pest)
- [ ] Tax calculation with 0 income (Pest)
- [ ] Pension calculation — 7% + 11% exact cents (Pest)
- [ ] Pension with 0 basic salary (unpaid leave month) (Pest)
- [ ] Overtime calculation for all 4 types — verify exact cents (Pest)
- [ ] Overtime hourly rate derivation (Pest)
- [ ] Allowance calculation — fixed amount (Pest)
- [ ] Allowance calculation — percentage of basic (Pest)
- [ ] Loan creation + deduction tracking (Pest)
- [ ] Loan fully repaid — deduction stops (Pest)
- [ ] Loan pause/resume (Pest)
- [ ] All calculations use integer arithmetic — no floats (Pest: assert no float operations)
- [ ] Tax config form renders correctly (Vitest)
- [ ] CurrencyInput in payroll forms (Vitest)

### Exit Criteria
- Ethiopian tax brackets calculate correctly to the cent
- Pension (7% + 11%) calculates correctly
- Overtime rates configurable and correct for all 4 types
- All arithmetic is integer cents — zero floating-point operations
- Allowances configurable per position/grade with taxable flag
- Loans track balance and monthly deductions with pause/resume
- All rates configurable by tenant admin

---

## S23 — Payroll Processing & Payslips

### Backend
- [ ] `payroll_runs`, `payroll_entries` tables migration
  - `payroll_runs`: tenant_id, period_start, period_end, period_key (unique: tenant_id + year + month), status (draft/approved/voided), processed_by, approved_by, voided_by, voided_at, void_reason, totals (JSON)
  - `payroll_entries`: payroll_run_id, employee_id, all amount fields as BIGINT cents, `calculation_log` JSON (mandatory)
- [ ] `PayrollEngine` service:
  - `process(tenantId, periodStart, periodEnd): PayrollRun`
  - Idempotency: unique constraint on `period_key` — reject duplicate runs for same period
  - Pipeline for each active employee:
    1. Gather confirmed attendance for period (worked days, OT hours by type)
    2. Gather approved leave for period
    3. Calculate basic salary (prorate if mid-month hire/termination)
    4. Handle Pagumen proration (per tenant strategy: full_month or daily_rate)
    5. Calculate overtime amount (OvertimeCalculator, by type)
    6. Calculate allowances (AllowanceService, taxable vs non-taxable)
    7. Sum gross salary (basic + OT + taxable allowances + non-taxable allowances)
    8. Calculate taxable income (gross - non-taxable allowances)
    9. Calculate income tax (TaxCalculator on taxable income)
    10. Calculate pension (PensionCalculator on basic salary)
    11. Deduct loans (LoanService — monthly installment, update remaining)
    12. Deduct other deductions
    13. Calculate net salary (gross - tax - pension_employee - loans - other_deductions)
    14. Create PayrollEntry with full `calculation_log` JSON (see ARCHITECTURE.md)
  - All amounts in integer cents — assert no float operations
  - Status: `draft` on creation
  - Dispatch `PayrollProcessed` event on completion
- [ ] `PayrollController`:
  - `POST /api/v1/payroll/process` — run payroll (requires `payroll.process`, Idempotency-Key required)
    - Rate limited: 2/hour per tenant
    - Background job for > 50 employees
    - Progress tracking in Redis
  - `GET /api/v1/payroll/runs` — list runs (status, period, totals)
  - `GET /api/v1/payroll/runs/{id}` — run detail with paginated entries
  - `GET /api/v1/payroll/runs/{id}/summary` — summary by department, cost center
  - `PUT /api/v1/payroll/runs/{id}/approve` — approve run (requires `payroll.approve`)
    - Locks calculation_logs (immutable after approval)
    - Generates payslip PDFs
    - Dispatches `PayrollApproved` event → notifications to all employees
  - `POST /api/v1/payroll/runs/{id}/void` — void approved run (requires tenant_admin)
    - Creates reversal entries
    - Marks run as `voided` with reason
    - Audit logged
  - `POST /api/v1/payroll/runs/{id}/reprocess` — reprocess voided run
    - Creates new run for same period, linked via `voided_run_id`
    - Full pipeline re-execution
  - `GET /api/v1/payroll/payslips/my` — employee's own payslips (requires `payslips.own`)
  - `GET /api/v1/payroll/payslips/{employee_id}` — specific employee (requires `payroll.view`)
- [ ] Payslip PDF generation (DomPDF):
  - Bilingual layout (EN + AM columns)
  - Company header (logo from branding, name, address)
  - Employee details (name, code, department, position, bank info)
  - Earnings breakdown: basic, OT (by type), allowances (by type)
  - Deductions breakdown: income tax, pension (employee), loans, other
  - Employer contributions: pension (employer) — shown but not deducted from employee
  - Net salary (large, prominent)
  - Period dates (Gregorian + Ethiopian if enabled)
  - Generated timestamp
  - Voided payslips: "VOIDED" watermark
  - Saved to MinIO: `tenant-{id}/payroll/{year}/{month}/{employee_public_id}.pdf`
- [ ] Bank export: `GET /api/v1/payroll/runs/{id}/export/bank`
  - CSV format: employee_name, bank_name, bank_branch, account_number (decrypted), net_amount
  - Ethiopian bank format support
  - Requires `payroll.export` permission
  - Decrypts bank account numbers only for this export
- [ ] Tax report: `GET /api/v1/payroll/runs/{id}/export/tax`
  - Employee name, TIN (decrypted), gross taxable, tax amount
- [ ] Pension report: `GET /api/v1/payroll/runs/{id}/export/pension`
  - Employee name, basic salary, employee contribution, employer contribution
- [ ] `PayrollPolicy`: `payroll.process` + `payroll.approve` for processing; `payslips.own` for employees
- [ ] Audit logging for all payroll operations

### Frontend
- [ ] Payroll dashboard page:
  - Run history DataTable (period, status `StatusBadge`, employee count, total net `CurrencyDisplay`, actions)
  - "Run Payroll" button → drawer:
    - Period selector (month/year via `DualCalendarPicker`)
    - Pagumen indicator (if Pagumen month selected)
    - "Process" button with confirmation
    - Progress bar (polls status for large runs)
  - Run detail on click
- [ ] Payroll run detail page:
  - Summary `StatCard` row: total gross, total tax, total pension, total deductions, total net
  - Department breakdown bar chart (`ChartWrapper`)
  - Employee entries DataTable: name, basic, OT, allowances, gross, tax, pension, loans, deductions, net
  - All amounts via `CurrencyDisplay`
  - Search + filter + sort
  - Expand row to see full calculation breakdown (from calculation_log)
  - Approve button (if draft, user has `payroll.approve`)
  - Void button (if approved, user is tenant_admin) — opens `ConfirmDialog` with reason input
  - Export dropdown: bank, tax, pension, full report
- [ ] Payslip viewer (employee self-service):
  - List of payslips by month (DataTable: period, net amount, download)
  - Click to view: styled payslip layout matching PDF
  - Download PDF button
  - Calculation breakdown accordion (from calculation_log — each step expandable)
- [ ] Salary breakdown component: stacked bar or breakdown list (earnings vs deductions) per employee

### Tests
- [ ] Payroll pipeline end-to-end (Pest):
  - Employee with: 20 worked days, 8 OT hours (normal), transport allowance, active loan
  - Verify each calculation step against expected cents
  - Verify net = gross - tax - pension - loan - deductions
  - Verify calculation_log contains all 14 steps with correct values
- [ ] Payroll with zero overtime (Pest)
- [ ] Payroll with all 4 OT types (Pest)
- [ ] Proration for mid-month hire (Pest)
- [ ] Proration for Pagumen — full_month strategy (Pest)
- [ ] Proration for Pagumen — daily_rate strategy (Pest)
- [ ] Payroll idempotency — duplicate period_key rejected (Pest)
- [ ] Payroll approval locks calculation_log (Pest)
- [ ] Payroll voiding creates reversal entries (Pest)
- [ ] Payroll reprocessing after void (Pest)
- [ ] Payslip PDF generation — contains correct data (Pest)
- [ ] Voided payslip shows watermark (Pest)
- [ ] Bank export — correct format, decrypted account numbers (Pest)
- [ ] Bank export — non-finance user denied (Pest)
- [ ] Tax report — correct TIN and amounts (Pest)
- [ ] Employee can only see own payslips (Pest)
- [ ] Tenant isolation (Pest)
- [ ] All amounts are integer cents — no float in any calculation (Pest)
- [ ] Tax bracket boundary: salary exactly at 10,900 ETB (Pest)
- [ ] Payroll dashboard renders (Vitest)
- [ ] Payslip viewer — calculation breakdown accordion (Vitest)
- [ ] CurrencyDisplay formatting in all payroll views (Vitest)
- [ ] Salary breakdown component (Vitest)

### Exit Criteria
- Payroll processes end-to-end from attendance through net salary
- Ethiopian tax brackets applied correctly to the cent
- Pension calculated correctly (7% + 11%)
- Pagumen proration works for both strategies
- Calculation audit trail stored as immutable JSON in every PayrollEntry
- Payroll voiding and reprocessing functional
- Payslip PDF generated (bilingual, voided watermark)
- Bank export in Ethiopian bank format with decrypted account numbers
- Employee sees own payslips with calculation breakdown
- All amounts in integer cents — zero floating-point operations
- Idempotency prevents duplicate payroll runs
- Permission-based authorization on all endpoints

---

## Phase 4 Exit Criteria (Combined)

- [ ] All 9+ leave types configurable with accrual, carry-forward, half-day support
- [ ] Leave workflow with configurable approval chain and notifications
- [ ] Ethiopian holidays auto-detected (13 holidays) and integrated
- [ ] Dual calendar works throughout leave and payroll
- [ ] Pagumen handled correctly in leave counting and payroll proration
- [ ] Payroll rules engine: tax, pension, OT, allowances, loans — all configurable
- [ ] Payroll processing produces correct net salary with full calculation audit trail
- [ ] Payroll voiding and reprocessing functional
- [ ] Payslip PDFs generated (bilingual) and stored in MinIO
- [ ] Bank export, tax report, pension report exportable
- [ ] All amounts in integer cents — zero floating-point operations
- [ ] Idempotency on leave requests and payroll runs
- [ ] All Pest + Vitest tests passing
- [ ] TenantIsolationTest passes
- [ ] Browser verified: payroll flow end-to-end
