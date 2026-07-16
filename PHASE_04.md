# Phase 4 — Leave Management & Payroll (v2.0)

## Prerequisites
- Phase 2 complete (employees, org structure)
- Phase 3 complete (attendance, shifts, overtime) — all gaps closed

## Objective
Full leave management with Ethiopian calendar awareness, and payroll processing with Ethiopian tax/pension rules engine, calculation audit trail, and voiding/reprocessing.

**SPLIT RECOMMENDATION:** This phase is delivered in two sub-phases to reduce risk and deliver value earlier:
- **Phase 4A:** Leave Management + Holiday Engine (S20 + S21) — delivers value independently
- **Phase 4B:** Payroll Rules Engine + Processing (S22 + S23) — builds on attendance + leave data

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
