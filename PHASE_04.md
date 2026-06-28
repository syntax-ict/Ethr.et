# Phase 4 — Leave Management & Payroll

## Prerequisites
- Phase 2 complete (employees)
- Phase 3 complete (attendance, shifts, overtime)

## Objective
Full leave management with Ethiopian calendar awareness, and payroll processing with Ethiopian tax/pension rules engine.

---

## S19 — Leave Management

### Backend
- [ ] `leave_types`, `leave_balances`, `leave_requests` tables migration (see DATABASE.md)
- [ ] Models with BelongsToTenant, HasPublicId
- [ ] `LeaveTypeController`: CRUD for leave types (see PRD.md Section 8 for types)
  - Default leave types seeded from organization template
  - Gender-restricted types (maternity/paternity)
  - Configurable: days, accrual, carry-forward, notice period, attachment requirement
- [ ] `LeaveBalanceService`:
  - `calculateBalance(Employee, LeaveType, year)` — entitled + carried - used - pending
  - `accrueMonthly()` — scheduled job: add monthly accrual to balances
  - `carryForward(year)` — scheduled job: at year-end, carry forward up to max_carry_days
  - `adjustBalance(Employee, LeaveType, adjustment, reason)` — manual adjustment by HR
- [ ] `LeaveRequestController`:
  - `POST /api/v1/leave/request` — submit leave request
    - Validate: sufficient balance, min notice, max consecutive, no overlap with existing
    - Holiday-aware calculation: skip public holidays and weekends when counting days
    - Set `pending_days` on balance
  - `GET /api/v1/leave/my` — employee's own requests (paginated, filterable by status/type)
  - `GET /api/v1/leave/team` — manager's team requests
  - `GET /api/v1/leave/balance` — current user's balances (all types)
  - `GET /api/v1/leave/balance/{employee_id}` — specific employee (HR only)
  - `PUT /api/v1/leave/{id}/approve` — approve (advances chain)
  - `PUT /api/v1/leave/{id}/reject` — reject with reason
  - `PUT /api/v1/leave/{id}/cancel` — employee cancels pending request
- [ ] Approval chain: employee -> supervisor -> dept head -> HR (configurable)
- [ ] `LeaveApproved` event -> update balance (decrement remaining, clear pending)
- [ ] `LeaveRejected` event -> clear pending_days
- [ ] Team minimum staffing check: warn if too many people off on same days
- [ ] Notification at each step

### Frontend
- [ ] Leave request form:
  - Leave type selector (only types available to this employee, with balance shown)
  - Date range picker (dual calendar, highlight holidays/weekends)
  - Calculated days (excluding holidays/weekends, shown live)
  - Balance remaining after this request
  - Reason text field
  - Attachment upload (if type requires)
  - Submit button
- [ ] My leaves page:
  - Balance cards (per type: entitled, used, pending, remaining)
  - Request history table (type, dates, days, status, actions)
  - Cancel button on pending requests
- [ ] Team leave calendar (manager view):
  - Monthly calendar showing who is off (colored by leave type)
  - List view: pending requests with approve/reject buttons
  - Filter by department
- [ ] Leave approval page:
  - Pending requests list
  - Request detail: employee, type, dates, days, reason, attachment
  - Team calendar context (who else is off)
  - Approve / Reject buttons
  - Rejection requires reason
- [ ] HR leave dashboard:
  - Leave usage summary (by type, department)
  - Upcoming leaves this week/month
  - Balance alerts (employees with zero balance)

### Tests
- [ ] Leave request with balance check (Pest)
- [ ] Holiday-aware day calculation (Pest)
- [ ] Approval chain (submit -> approve -> approve -> balance updated) (Pest)
- [ ] Rejection clears pending_days (Pest)
- [ ] Cancellation (Pest)
- [ ] Maternity restricted to female employees (Pest)
- [ ] Min notice enforcement (Pest)
- [ ] Carry-forward at year-end (Pest)
- [ ] Monthly accrual job (Pest)
- [ ] Tenant isolation (Pest)
- [ ] Leave form with balance display (Vitest)
- [ ] Team calendar (Vitest)
- [ ] Approval UI (Vitest)

### Exit Criteria
- All 9 leave types configurable
- Balances calculate correctly with accrual, carry-forward, and pending
- Holiday-aware day counting with Ethiopian calendar
- Approval workflow with notifications
- Team calendar shows leave schedule

---

## S20 — Holiday Engine & Ethiopian Calendar Integration

### Backend
- [ ] `holidays` table migration
- [ ] `HolidayService`:
  - `getHolidays(tenantId, year)` — all holidays for the year (cached)
  - `isHoliday(date)` — check if date is a holiday
  - `getEthiopianHolidays(ethiopianYear)` — auto-detect Ethiopian public holidays:
    - Ethiopian New Year (Meskerem 1)
    - Timkat (Ter 11)
    - Adwa Victory Day (Yekatit 23)
    - Good Friday / Easter (computed)
    - Ethiopian Patriots Day (Miazia 27)
    - Labour Day (Miazia 23)
    - Downfall of the Derg (Ginbot 20)
    - Eid al-Fitr (computed)
    - Eid al-Adha (computed)
    - Mawlid (computed)
    - Meskel (Meskerem 17)
    - Christmas (Tahsas 29)
  - Auto-seed holidays when tenant enables Ethiopian calendar
- [ ] `HolidayController`:
  - `GET /api/v1/holidays` — list for current year (with Ethiopian calendar dates)
  - `POST /api/v1/holidays` — add custom/regional holiday
  - `PUT /api/v1/holidays/{id}` — edit
  - `DELETE /api/v1/holidays/{id}` — remove
  - `POST /api/v1/holidays/auto-detect` — re-run auto-detection for year
- [ ] Branch-specific holidays (some holidays only for certain branches)
- [ ] Recurring holidays (same date every year)
- [ ] Integration with leave: skip holidays in leave day counting
- [ ] Integration with attendance: holiday shifts and holiday overtime rates
- [ ] Integration with payroll: holiday pay calculation

### Frontend
- [ ] Holiday management page:
  - Annual calendar view with holidays highlighted
  - Holiday list (name, date, type: national/religious/regional, branch scope)
  - Add holiday dialog (name, date with dual calendar picker, type, branch, recurring)
  - Auto-detect button (populate Ethiopian holidays for selected year)
  - Edit/delete actions
- [ ] Holiday display in:
  - Leave request date picker (holidays grayed out, not counted)
  - Attendance calendar (holidays marked)
  - Team calendar (holidays shown)

### Tests
- [ ] Ethiopian holiday auto-detection for known year (Pest)
- [ ] Holiday-aware leave calculation (Pest)
- [ ] Branch-specific holidays (Pest)
- [ ] Recurring holidays (Pest)
- [ ] Holiday CRUD (Pest)
- [ ] Holiday calendar view (Vitest)

### Exit Criteria
- Ethiopian holidays auto-detected correctly
- Custom/regional holidays manageable
- Holidays integrated with leave, attendance, and payroll
- Dual calendar display works

---

## S21 — Payroll Rules Engine

### Backend
- [ ] `payroll_rules`, `tax_brackets`, `employee_loans` tables migration
- [ ] `PayrollRule` model: category (tax, pension, overtime, allowance, deduction), formula (JSON)
- [ ] `TaxCalculator` service:
  - Ethiopian income tax brackets (current rates):
    - 0 - 600: 0%
    - 601 - 1,650: 10% - 60
    - 1,651 - 3,200: 15% - 142.50
    - 3,201 - 5,250: 20% - 302.50
    - 5,251 - 7,800: 25% - 565
    - 7,801 - 10,900: 30% - 955
    - 10,901+: 35% - 1,500
  - Configurable (rates can be updated when government changes them)
  - Method: `calculate(grossTaxableAmountCents): taxAmountCents`
- [ ] `PensionCalculator` service:
  - Employee contribution: 7% of basic salary (configurable)
  - Employer contribution: 11% of basic salary (configurable)
  - Method: `calculate(basicSalaryCents): { employee_cents, employer_cents }`
- [ ] `OvertimeCalculator` service:
  - Normal overtime: 1.25x hourly rate
  - Night overtime: 1.5x
  - Holiday overtime: 2.0x
  - Holiday night overtime: 2.5x
  - Configurable rates
  - Input: overtime_minutes, shift_type, is_holiday -> amount_cents
- [ ] `AllowanceService`: manage allowance types per tenant
  - Types: position, transport, housing, hardship, other
  - Fixed amount or percentage of basic
  - Configurable per position/grade
- [ ] `LoanService`:
  - `POST /api/v1/payroll/loans` — create loan (amount, monthly deduction, reason)
  - Automatic deduction in payroll until fully repaid
  - Loan tracking (remaining balance, payments)
- [ ] Default tax brackets seeded for Ethiopian tax law
- [ ] Default payroll rules seeded from organization template

### Frontend
- [ ] Payroll configuration page (admin/settings):
  - Tax brackets table (editable rows: min, max, rate, deduction)
  - Pension rates (employee %, employer %)
  - Overtime rates (normal, night, holiday, holiday night)
  - Allowance types CRUD (name, type: fixed/percentage, amount/rate)
- [ ] Loan management:
  - Employee loans list
  - Create loan dialog (employee, amount, monthly deduction, reason)
  - Loan detail: payments history, remaining balance

### Tests
- [ ] Tax calculation for each bracket (Pest)
- [ ] Pension calculation (Pest)
- [ ] Overtime calculation for all types (Pest)
- [ ] Allowance calculation (fixed + percentage) (Pest)
- [ ] Loan creation + deduction tracking (Pest)
- [ ] Tax config form (Vitest)

### Exit Criteria
- Ethiopian tax brackets calculate correctly
- Pension (7% + 11%) calculates correctly
- Overtime rates configurable and correct
- Allowances configurable per position/grade
- Loans track balance and monthly deductions

---

## S22 — Payroll Processing & Payslips

### Backend
- [ ] `payroll_runs`, `payroll_entries` tables migration
- [ ] `PayrollEngine` service:
  - `process(tenantId, periodStart, periodEnd): PayrollRun`
  - Pipeline for each employee:
    1. Get confirmed attendance for period (worked days, OT hours)
    2. Get leave for period (deducted days)
    3. Calculate basic salary (prorate if mid-month hire/termination)
    4. Calculate overtime amount (OvertimeCalculator)
    5. Calculate allowances (AllowanceService)
    6. Sum gross salary
    7. Calculate income tax (TaxCalculator)
    8. Calculate pension (PensionCalculator)
    9. Deduct loans (LoanService)
    10. Deduct other deductions
    11. Calculate net salary
    12. Create PayrollEntry
  - All amounts in integer cents
  - Dispatch `PayrollProcessed` event on completion
- [ ] `PayrollController`:
  - `POST /api/v1/payroll/process` — run payroll for period
  - `GET /api/v1/payroll/runs` — list of payroll runs (status, period, totals)
  - `GET /api/v1/payroll/runs/{id}` — run detail with all entries
  - `PUT /api/v1/payroll/runs/{id}/approve` — approve run
  - `GET /api/v1/payroll/payslips/my` — employee's own payslips
  - `GET /api/v1/payroll/payslips/{employee_id}` — specific employee (finance admin)
- [ ] Payslip PDF generation (DomPDF):
  - Bilingual (EN + AM) layout
  - Company header (logo, name, address)
  - Employee details (name, code, department, position)
  - Earnings breakdown (basic, OT, allowances)
  - Deductions breakdown (tax, pension, loan, other)
  - Net salary
  - Saved to MinIO: `tenant-{id}/payroll/{year}/{month}/{employee_public_id}.pdf`
- [ ] Bank export file:
  - `GET /api/v1/payroll/runs/{id}/export/bank` — CSV/Excel for bank transfer
  - Format: account_name, bank_name, branch, account_number, amount
  - Ethiopian bank format support
- [ ] Tax report: `GET /api/v1/payroll/runs/{id}/export/tax`
- [ ] Pension report: `GET /api/v1/payroll/runs/{id}/export/pension`
- [ ] Payroll summary: `GET /api/v1/payroll/runs/{id}/summary` (by department, cost center)
- [ ] `PayrollPolicy`: finance_admin + tenant_admin for processing; employee for own payslips

### Frontend
- [ ] Payroll dashboard page:
  - Run history table (period, status, total employees, total net, actions)
  - "Run Payroll" button -> period selector -> processing with progress
  - Run detail: summary cards (gross, deductions, net) + entry table
- [ ] Payroll run detail page:
  - Summary: total gross, total tax, total pension, total deductions, total net
  - Department breakdown charts
  - Employee entries table: name, basic, OT, allowances, gross, tax, pension, deductions, net
  - Search + filter + sort
  - Approve button (if draft)
  - Export buttons: bank, tax, pension, full report
- [ ] Payslip viewer (employee self-service):
  - List of payslips by month
  - Click to view: styled payslip layout
  - Download PDF button
- [ ] Payslip PDF preview (same layout as printed version)
- [ ] Salary breakdown component: bar chart or breakdown list (earnings vs deductions)

### Tests
- [ ] Payroll pipeline end-to-end (Pest):
  - Employee with attendance, OT, leave, allowances, loan
  - Verify each calculation step
  - Verify net salary = gross - tax - pension - loan - deductions
- [ ] Proration for mid-month hire (Pest)
- [ ] Payslip PDF generation (Pest)
- [ ] Bank export format (Pest)
- [ ] Tax report (Pest)
- [ ] Employee can only see own payslips (Pest)
- [ ] Tenant isolation (Pest)
- [ ] Payroll dashboard (Vitest)
- [ ] Payslip viewer (Vitest)
- [ ] Salary breakdown component (Vitest)

### Exit Criteria
- Payroll processes end-to-end from attendance through net salary
- Ethiopian tax brackets applied correctly
- Pension calculated correctly
- Payslip PDF generated (bilingual)
- Bank export file in Ethiopian bank format
- Employee sees own payslips via self-service
- All amounts in integer cents with no rounding errors

---

## Phase 4 Exit Criteria

- [ ] All 9+ leave types configurable with accrual and carry-forward
- [ ] Leave workflow with approval chain and notifications
- [ ] Ethiopian holidays auto-detected and integrated
- [ ] Dual calendar works throughout leave and payroll
- [ ] Payroll rules engine handles tax, pension, OT, allowances, loans
- [ ] Payroll processing produces correct net salary
- [ ] Payslip PDFs generated and stored in MinIO
- [ ] Bank export, tax report, pension report exportable
- [ ] All amounts in integer cents, zero floating-point
- [ ] All Pest + Vitest tests passing
- [ ] Browser verified: payroll flow end-to-end
