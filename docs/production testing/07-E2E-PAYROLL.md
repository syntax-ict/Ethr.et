# E2E specification 07: payroll

Columns, personas and modes: see the [README](README.md#how-a-test-case-is-written). Run in QA Audit.
All money is ETB, stored in cents and shown as `X,XXX.XX ETB`. Every figure in this document is a
**pass or fail** figure: a difference of one cent is a defect.

**Before you start:**

- Payroll processing is limited to **2 runs (process or reprocess) per hour per organisation**. Plan
  the order in section 3 and do not waste a run.
- Processing is queued. A run stays `processing` until the queue drains (up to 10 minutes on
  production). Check `/api/v1/health` → `queue_detail` before calling it stuck.
- Only `tenant_admin` approves, voids and reprocesses. `finance_admin` processes. The person who
  processed a run cannot approve it while another approver exists (maker-checker), so TA2 or FIN
  is needed.
- **Allowance rules apply to every active employee in the organisation.** Run the golden payslips
  (G1 to G6) with no active allowance rule; G7 needs its two allowance rules. Section 3 orders this.
- Mark every QA run "QA" and never send its bank file to a bank.

---

## 1. Statutory rules

### 1.1 Income tax

The ladder is chosen by the **period start date**, not by the day the run is processed.

**Proclamation 1395/2025, from 2025-07-07** (monthly taxable income):

| Band (ETB) | Rate | Deduction |
|---|---|---|
| 0 to 2,000 | 0% | 0 |
| 2,000.01 to 4,000 | 15% | 300 |
| 4,000.01 to 7,000 | 20% | 500 |
| 7,000.01 to 10,000 | 25% | 850 |
| 10,000.01 to 14,000 | 30% | 1,350 |
| over 14,000 | 35% | 2,050 |

**Proclamation 979/2016, 2016-07-08 to 2025-07-06:** 0 to 600 at 0%; to 1,650 at 10% less 60; to 3,200
at 15% less 142.50; to 5,250 at 20% less 302.50; to 7,800 at 25% less 565; to 10,900 at 30% less 955;
over 10,900 at 35% less 1,500.

Tax = taxable × rate − deduction, rounded to the cent.

### 1.2 Pension

| Scheme | Employee | Employer | Basis |
|---|---|---|---|
| Private (default) | 7% | 11% | Proclamation 1268/2022 |
| Public | 7% | 11% | Proclamation 1267/2022 |
| Public, military | 7% | 33% | Proclamation 1267/2022 Art. 11 |

Pension is on **basic salary after proration and unpaid leave**. Allowances and overtime are not
pensionable. Employees with nationality status `foreign` are exempt; `ethiopian_origin` is treated as
exempt but unconfirmed and must be acknowledged at approval.

### 1.3 Overtime (Labour Proclamation 1156/2019, Art. 68)

| Kind | Multiplier |
|---|---|
| Normal day, 06:00 to 22:00 | 1.5 |
| Night, 22:00 to 06:00 | 1.75 |
| Rest day | 2.0 |
| Public holiday (day or night) | 2.5 |

Hourly rate = prorated basic ÷ (22 × 8). On a normal day only time after the shift ends counts; on a
rest day or holiday the whole worked window counts. Limits (240 min a day, 720 a week, the
organisation's daily cap) **flag** the run; every minute is still paid.

### 1.4 Calculation order

Basic → proration by days employed → Pagume rule (`full_month`, or `daily_rate` = monthly × 12 ×
Pagume days ÷ 365) → unpaid leave (basic ÷ 22 per unpaid day) → absences (flagged, **never
deducted**) → overtime → allowances → gross → tax on (gross − non-taxable allowances) → pension →
loans → cost sharing → **net = gross − tax − employee pension − loans − cost sharing**.

Included: active employees, and anyone resigned, terminated or retired with a last day inside the
period. **An employee with no salary (neither on the record nor on a contract in force) is skipped
and counted as skipped.**

## 2. Golden payslips

Create these employees in QA Audit, hired before the period starts, nationality Ethiopian, no
attendance, no leave, no loans, private pension scheme. They match `docs/payroll/ACCOUNTANT-SIGN-OFF.md`
and the automated test `PayrollGoldenPayslipsTest`.

| Employee | Basic salary | Gross | Taxable | Income tax | Employee pension | Net | Employer pension |
|---|---|---|---|---|---|---|---|
| E-PAY-1 | 1,800.00 | 1,800.00 | 1,800.00 | 0.00 | 126.00 | 1,674.00 | 198.00 |
| E-PAY-2 | 3,500.00 | 3,500.00 | 3,500.00 | 225.00 | 245.00 | 3,030.00 | 385.00 |
| E-PAY-3 | 6,000.00 | 6,000.00 | 6,000.00 | 700.00 | 420.00 | 4,880.00 | 660.00 |
| E-PAY-4 | 9,000.00 | 9,000.00 | 9,000.00 | 1,400.00 | 630.00 | 6,970.00 | 990.00 |
| E-PAY-5 | 12,000.00 | 12,000.00 | 12,000.00 | 2,250.00 | 840.00 | 8,910.00 | 1,320.00 |
| E-PAY-6 | 25,000.00 | 25,000.00 | 25,000.00 | 6,700.00 | 1,750.00 | 16,550.00 | 2,750.00 |

**G7, the full case** (needs the allowance rules in PAY-004 and 2 approved unpaid leave days in the
period):

| Employee | Contract basic | Unpaid leave | Basic after | Allowances | Gross | Taxable | Tax | Employee pension | Net | Employer pension |
|---|---|---|---|---|---|---|---|---|---|---|
| E-PAY-7 | 8,800.00 (on a contract, none on the record) | 2 days × 400.00 | 8,000.00 | Transport 600.00 (not taxable) + position 10% = 800.00 (taxable) | 9,400.00 | 8,800.00 | 1,350.00 | 560.00 | 7,490.00 | 880.00 |

Boundary checks (tax only, current ladder): 2,000 → 0.00; 3,000 → 150.00; 4,000 → 300.00; 5,000 →
500.00; 7,000 → 900.00; 10,000 → 1,650.00; 14,000 → 2,850.00; 20,000 → 4,950.00.

Overtime example: basic 8,800 gives an hourly rate of 50.00. Two normal overtime hours pay 150.00;
two night hours pay 175.00; two rest-day hours 200.00; two holiday hours 250.00.

## 3. Run order for a full pass

1. **Run A** (period P1, no allowance rules active): check G1 to G6, the run lifecycle, exports.
2. Approve run A. Check payslips, loans, the period lock.
3. Void run A, then **reprocess** it (one more run in the hour budget). Check balances.
4. Next hour: activate the two allowance rules, approve E-PAY-7's unpaid leave in period P2.
   **Run B** (period P2): check G7, overtime, absences, pension exemptions.
5. Deactivate the allowance rules when finished, so they do not affect later runs.

---

## 4. Configuration

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PAY-001 | P1 | R | Tax ladder | FIN: Settings → Payroll → tax brackets | The 1395/2025 ladder above, with its legal reference and effective date; source shown as platform unless the organisation set its own |
| PAY-002 | P1 | W | Organisation ladder | TA replaces the ladder (needs `payroll.manageConfig`) with 0 or 21 brackets, a rate of 101 | Refused (1 to 20 brackets, rates 0 to 100, effective date required). FIN cannot replace it (view only) |
| PAY-003 | P1 | R | Pension rules | Pension rules page | The three schemes above. Adding a rule dated on or before the latest one for that scheme: 422 `payroll.pension_rule_not_after_latest` |
| PAY-004 | P1 | W | Allowance rules | TA: rules → "QA Transport" fixed 600.00, not taxable; "QA Position" percentage 10, taxable | Saved, audited. Fixed without amount, percentage without percent, percent 101, name over 100 refused |
| PAY-005 | P1 | W | Overtime rates | TA sets normal 1.25 | Refused (minimums 1.5, 1.75, 2.0, 2.5, 2.5; maximum 10) |
| PAY-006 | P2 | W | Pagume rule | Settings: Pagume proration `full_month` vs `daily_rate` | Saved; used in PAY-027 |
| PAY-007 | P2 | R | Chart of accounts | Accounting → chart of accounts | Defaults 5100 Salary Expense, 5200 Pension Expense (Employer), 2100 Income Tax Payable, 2200 Pension Payable (Employee), 2201 Pension Payable (Employer), 2300 Net Salary Payable; editable by TA |

## 5. Run lifecycle

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PAY-010 | P1 | X | Process | FIN: Payroll → Process, period P1 (an Ethiopian month, for example Meskerem 2019 = 2026-09-11 to 2026-10-10) | 202, status processing; within minutes completed. Duplicate submit with the same key: 200 `was_duplicate: true` |
| PAY-011 | P1 | R | Golden figures | Open run A | E-PAY-1 to E-PAY-6 exactly as in section 2 |
| PAY-012 | P1 | R | Calculation log | Open one entry's breakdown | The full trace: inputs, the tax basis (legal reference, effective date, source), pension rule, each step's value |
| PAY-013 | P1 | R | Skipped employees | Include an employee with no salary anywhere | Not in the run, and the run shows a skipped count. **Check it is visible on the run page**; a silent skip was a production defect (QA F1, 2026-10-15) |
| PAY-014 | P1 | R | Rights | HR processes; FIN approves; SUP opens runs | All refused |
| PAY-015 | P1 | R | Maker-checker | FIN (processed run A) approves | 422 "You processed this payroll run, so another approver in your organisation must approve it." If FIN were the only approver holder, the approval would go through and be audited `self_approved` |
| PAY-016 | P1 | X | Approve | TA approves run A | Approved; payslips visible to the employees; `payroll.approved` webhook; FIN notified |
| PAY-017 | P1 | R | Approve too early | Approve a run still processing | 422 "Payroll run has not been completed yet." |
| PAY-018 | P1 | W | Pension exemption acknowledgement | In run B include an employee with nationality status `foreign` | Approval without acknowledgement: 422 `pension-exemptions-unacknowledged`, "This run exempts 1 employee(s) from pension. Review them, then approve with that count." Approve with the count: accepted. The employee has no pension in the run and is left out of the pension schedule |
| PAY-019 | P1 | W | Absences banner | Run B covers a workday on which EMP3 had no attendance, leave or holiday | Banner "Absences nobody explained"; pay **not** reduced; approval does not need an acknowledgement. No absences are flagged if the organisation recorded no attendance in the period, or for days after yesterday |
| PAY-020 | P1 | R | Contract salary | E-PAY-7 has salary only on a contract | Paid from the contract salary (QA F1 fixed this; regression check) |
| PAY-021 | P1 | W | Period lock | After approving run A, edit attendance for a day in P1 (manual entry, correction, conflict keep) | 422 "This day is in an approved payroll run, so its attendance cannot be changed. Void the run first to reopen it." Device, offline and kiosk punches are still accepted |
| PAY-022 | P1 | X | Void | TA voids run A without a reason; with a reason | Reason required; voided; payslips flagged voided; `payroll.voided` webhook. Voiding a processing or already voided run: 422 "Only a completed or approved payroll run can be voided." |
| PAY-023 | P1 | X | Reprocess | TA reprocesses voided run A | 201; a new run linked to the voided one; same figures as before for unchanged data. Reprocessing a run that is not voided: 422 "Only a voided payroll run can be reprocessed." |
| PAY-024 | P1 | R | Rate limit | A third process or reprocess within the hour | 429 with `Retry-After` |
| PAY-025 | P1 | R | Old ladder | Process a period starting before 2025-07-07 (if the organisation allows it; otherwise check the calculation log of a reprocessed old run) | Tax on the 979/2016 ladder, for example 5,000 taxable → 697.50 |
| PAY-026 | P2 | R | Leavers | A test employee resigned mid-period | Included, prorated by days employed |
| PAY-027 | P2 | R | Pagume | A period containing Pagume | Proration follows the Pagume setting |
| PAY-028 | P1 | R | G7 | Run B | E-PAY-7 exactly as in section 2 |
| PAY-029 | P1 | R | Overtime pay | EMP worked 2 h after shift end on a normal day and 2 h on a rest day in P2 (with records) | Paid at 1.5 and 2.0 of the hourly rate; over the daily cap or without an approved request (when approval is required) the run shows a flag but still pays |
| PAY-030 | P2 | R | Payroll plan gate | QA Isolation on Starter processes payroll | 403 plan feature (PLAN-001) |

## 6. Loans and cost sharing

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LOAN-001 | P1 | W | Create | FIN: Loans → EMP, 3,000.00 at 1,000.00 a month | Active immediately (no approval step). Amount or instalment under 1.00 refused |
| LOAN-002 | P1 | W | Deducted on approval only | Process run A | Payslip shows 1,000.00 loan deduction; the loan balance is **unchanged** until the run is approved |
| LOAN-003 | P1 | W | Balance moves | Approve run A | Outstanding 2,000.00 |
| LOAN-004 | P1 | W | Void restores | Void run A | Outstanding back to 3,000.00; a loan the run had completed reopens. Voiding a completed (not approved) run restores nothing because nothing moved |
| LOAN-005 | P1 | W | Last instalment | A loan with 400.00 left and a 1,000.00 instalment | Deducts 400.00 and closes at 0 |
| LOAN-006 | P1 | W | Stale balance | Process a run; change the loan; approve | 422 "Loan or cost-sharing balances changed after this run was calculated. Void the run and reprocess the period." |
| LOAN-007 | P2 | W | Edit and cancel | Update or cancel an active loan; then a completed one | Active: allowed. Completed: 422 `payroll.loan_not_active` |
| LOAN-008 | P2 | W | Opening loans import | Import opening loan balances (template, preview, commit) | Same all-or-nothing rule as leave balances; needs `payroll.manageLoan` |
| CS-001 | P1 | W | Create | FIN: Cost sharing → EMP2, total 20,000.00, rate 10% | Active. Rate 0 or 101 refused; total under 1.00 refused |
| CS-002 | P1 | W | One per employee | A second active obligation for EMP2 | 422 `duplicate-obligation` |
| CS-003 | P1 | R | Deduction | In a run | floor(gross × 10%), capped at the outstanding balance |
| CS-004 | P2 | W | Status | Suspend, resume, cancel | Allowed: active to suspended, completed or cancelled; suspended to active or cancelled. No delete |

## 7. Outputs

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| OUT-001 | P1 | R | Bank file before approval | Download the bank file of a completed run | 422 "A bank file can only be exported for an approved payroll run." |
| OUT-002 | P1 | R | Bank file | Approved run → bank CSV | Columns Employee Code, Employee Name, Bank Name, Account Number, Net Pay (ETB), Currency (ETB); CRLF line ends; the primary bank account (else the first); net pay equals the run |
| OUT-003 | P1 | R | Register | Register CSV | Code, Name, Basic, Overtime, Allowances, Gross, Taxable, Income Tax, Employee Pension x%, Employer Pension y%, Loan, Cost Sharing, Net; a TOTAL row "N employees"; totals equal the run summary |
| OUT-004 | P1 | R | Income tax schedule | Statutory → income tax: preview, then download CSV and PDF | Preview without TINs; download with TINs, start date, basic, overtime, allowances, taxable, tax; each download audited `payroll.statutory_export`. Missing employer TIN prints "NOT SET"; a non-approved run prints "NOT APPROVED" |
| OUT-005 | P1 | R | Pension schedule | Statutory → pension | Per employee pensionable amount and both contributions with the rate headers; exempt employees left out |
| OUT-006 | P1 | R | Payslip PDF | EMP: My Payslips → download | A5 portrait; earnings, deductions, net; employer pension shown; Amharic names correct; a voided run's payslip is marked voided |
| OUT-007 | P1 | R | Payslip visibility | EMP opens payslips before approval; EMP opens EMP2's payslip by id | None before approval; EMP2's refused |
| OUT-008 | P1 | R | Journal | Accounting → journal and CSV export for run A | Debits equal credits; salary expense = gross; pension expense = employer pension; tax payable = tax; pension payable = employee and employer pension; net salary payable = net + other deductions; TOTALS row |
| OUT-009 | P2 | R | Totals agree | Compare run summary, register, bank file total, journal and statutory schedules | All agree to the cent |
| OUT-010 | P2 | R | Downgraded plan | QA Isolation after a downgrade opens an old run's register and bank file | Still readable |
