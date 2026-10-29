# E2E specification 07: payroll

Columns, personas and modes: see the [README](README.md#how-a-test-case-is-written). Run in QA Audit.
All money is ETB, stored in cents and shown as `X,XXX.XX ETB`. Every figure in this document is a
**pass or fail** figure: a difference of one cent is a defect.

**Before you start:**

- Payroll processing is limited to **2 runs (process or reprocess) per hour per organisation**. Plan
  the order in section 3 and do not waste a run.
- Processing is queued. A run stays `processing` until the queue drains (up to 10 minutes on
  production). Check `/api/v1/health` → `queue_detail` before calling it stuck.
- Only `tenant_admin` approves, voids and reprocesses. `finance_admin` processes and records payment (`payroll.markPaid`,
  which `tenant_admin` also holds). The person who
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

Tax = taxable × rate − deduction. Every amount in a run (tax, pension, overtime, allowances,
proration, Pagume, unpaid leave, lateness) is an exact fraction of cents, **rounded once, half away
from zero**, so a half cent rounds up (calculation log `rounding: integer_half_up`, RND-001). Cost
sharing alone rounds down (CS-003). The ladders are dated rows, not code: the organisation's own,
then the platform's for its kind of organisation, then the platform's. A date with no ladder in force
refuses the run by name.

### 1.2 Pension

| Scheme | Employee | Employer | Basis | Default for kind of organisation |
|---|---|---|---|---|
| Private | 7% | 11% | Proclamation 1268/2022 | Private, charity, international organisation (and any kind with no row of its own) |
| Public | 7% | 11% | Proclamation 1267/2022 | Government office; public enterprise (unconfirmed, research O1) |
| Public, military | 7% | 33% | Proclamation 1267/2022 Art. 11 | Defence or police |

The scheme used is the organisation's own pension scheme setting, else the default for its kind
(section 11). Every organisation that existed before 2026-10-27 had `private` written into its
settings, so changing its kind moves no pay (STAT-002).

Pension is on **basic salary after proration, unpaid leave and any lateness deduction**. Allowances and overtime are not
pensionable. Employees with nationality status `foreign` are exempt; `ethiopian_origin` is treated as
exempt but unconfirmed and must be acknowledged at approval.

### 1.3 Overtime (Labour Proclamation 1156/2019, Art. 68)

| Kind | Multiplier |
|---|---|
| Normal day, 06:00 to 22:00 | 1.5 |
| Night, 22:00 to 06:00 | 1.75 |
| Rest day | 2.0 |
| Public holiday (day or night) | 2.5 |

Hourly rate = basic after proration, unpaid leave and any lateness deduction ÷ (22 × 8). Pay is one
fraction, basic × minutes × multiplier ÷ (22 × 8 × 60), rounded once. 22, 8, the night window, the
floors and the 240 and 720 limits are statutory parameters (`payroll.working_days_per_month`,
`payroll.hours_per_day`, `overtime.night_window`, `overtime.minimum_rates`,
`overtime.legal_daily_minutes`, `overtime.legal_weekly_minutes`). On a normal day only time after the shift ends counts; on a
rest day or holiday the whole worked window counts. Limits (240 min a day, 720 a week, the
organisation's daily cap) **flag** the run; every minute is still paid.

### 1.4 Calculation order

Basic → Pagume rule (`full_month`, or `daily_rate` = monthly × 12 × Pagume days ÷ 365) → proration
by days employed → unpaid leave (monthly basic ÷ 22, rounded to the cent, per unpaid day) →
**lateness, only if the organisation switched it on** (decided minutes × daily rate ÷ 480, capped at
one third of the basic so far, rounded down, less loan instalments and cost sharing estimated on that
basic; section 9) → absences (flagged, **never deducted**) → overtime → allowances (percentages of
the basic after lateness) → gross → tax on (gross − non-taxable allowances) → pension → loans → cost
sharing (share of gross, rounded down) → **net = gross − tax − employee pension − loans − cost
sharing**. Lateness is already inside basic, not a separate deduction.

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

Every golden figure is whole cents before rounding, so the exact-rounding change of 2026-10-27
(#448) does not move any of them; RND-001 exercises the half-cent rule.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| RND-001 | P1 | W | Half-cent rounding | In run A, include E-PAY-8, basic 3,500.50 (API `salary_cents: 350050`), set up like G1 to G6 | Tax 225.08 (350,050 × 15% = 52,507.5 cents, rounded up to 52,508, less 30,000). Employee pension 245.04 (24,503.5, rounded up). Employer pension 385.06 (38,505.5, rounded up). Net 3,030.38. One cent lower on any figure means a half was rounded down: a defect |

## 3. Run order for a full pass

1. **Run A** (period P1, no allowance rules active): check G1 to G6, the run lifecycle, exports.
2. Approve run A. Check payslips, loans, the period lock.
3. Void run A, then **reprocess** it as run A′ (one more run in the hour budget). Check balances.
4. Next hour: activate the two allowance rules, approve E-PAY-7's unpaid leave in period P2.
   **Run B** (period P2): check G7, overtime, absences, pension exemptions. Deactivate the
   allowance rules.
5. TA switches lateness deductions on (LATE-001) and reconciliation to Advisory. Settle E-REC-1
   and E-LATE-1 to E-LATE-3 (sections 8 and 9). **Run C** (period P3, past days only, no allowance
   rules): RCN-006, LATE-005 to LATE-007. Next hour: Required mode, RCN-007 and RCN-008 (void and
   reprocess C).
6. Approve A′ and record its payment (PAID-001). **This cannot be undone: P1 can never be run again
   in QA Audit**, so the next pass needs new periods.
7. Set reconciliation to Off and lateness deductions off.

---

## 4. Configuration

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PAY-001 | P1 | R | Tax ladder | FIN: Settings → Payroll → tax brackets | The 1395/2025 ladder above, with its legal reference and effective date; source shown as platform (or `platform_type`, for a ladder scoped to its kind of organisation; none is seeded) unless the organisation set its own |
| PAY-002 | P1 | W | Organisation ladder | TA replaces the ladder (needs `payroll.manageConfig`) with 0 or 21 brackets, a rate of 101 | Refused (1 to 20 brackets, rates 0 to 100, effective date required). FIN cannot replace it (view only) |
| PAY-003 | P1 | R | Pension rules | Pension rules page | The three schemes above (the `pension.schemes` parameter; any other scheme is refused as invalid). Adding a rule dated on or before the latest one for that scheme: 422 `payroll.pension_rule_not_after_latest` |
| PAY-004 | P1 | W | Allowance rules | TA: rules → "QA Transport" fixed 600.00, not taxable; "QA Position" percentage 10, taxable | Saved, audited. Fixed without amount, percentage without percent, percent 101, name over 100 refused |
| PAY-005 | P1 | W | Overtime rates | TA sets normal 1.25 | Refused (minimums 1.5, 1.75, 2.0, 2.5, 2.5; maximum 10) |
| PAY-006 | P2 | W | Pagume rule | Settings: Pagume proration `full_month` vs `daily_rate` | Saved; used in PAY-027 |
| PAY-007 | P2 | R | Chart of accounts | Accounting → chart of accounts | Defaults 5100 Salary Expense, 5200 Pension Expense (Employer), 2100 Income Tax Payable, 2200 Pension Payable (Employee), 2201 Pension Payable (Employer), 2300 Net Salary Payable; editable by TA |

## 5. Run lifecycle

Run states: `processing` → `completed` (or `failed`) → `approved` → `paid`. Only `completed` and
`approved` can go to `voided`; reprocessing a voided run creates a new run. `approved` and `paid` are
both released: payslips, the period lock, the bank file and statutory schedules treat them alike. A
database trigger freezes a released or voided run's amounts.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PAY-010 | P1 | X | Process | FIN: Payroll → Process, period P1 (an Ethiopian month, for example Meskerem 2019 = 2026-09-11 to 2026-10-10) | 202, status processing; within minutes completed. Duplicate submit with the same key: 200 `was_duplicate: true` |
| PAY-011 | P1 | R | Golden figures | Open run A | E-PAY-1 to E-PAY-6 exactly as in section 2 |
| PAY-012 | P1 | R | Calculation log | Open one entry's breakdown | The full trace: version 1.5, `rounding: integer_half_up`, `statutory_rules` (STAT-005), inputs, the tax basis (legal reference, effective date, source, the whole ladder), pension rule, each step's value, and a `lateness` step when the organisation deducts lateness |
| PAY-013 | P1 | R | Skipped employees | Include an employee with no salary anywhere | Not in the run, and the run shows a skipped count. **Check it is visible on the run page**; a silent skip was a production defect (QA F1, 2026-10-15) |
| PAY-014 | P1 | R | Rights | HR processes; FIN approves; SUP opens runs | All refused |
| PAY-015 | P1 | R | Maker-checker | FIN (processed run A) approves | 422 "You processed this payroll run, so another approver in your organisation must approve it." If FIN were the only approver holder, the approval would go through and be audited `self_approved` |
| PAY-016 | P1 | X | Approve | TA approves run A | Approved; payslips visible to the employees; `payroll.approved` webhook; FIN notified. In Required reconciliation mode, approval is refused first if anything is open (RCN-007) |
| PAY-017 | P1 | R | Approve too early | Approve a run still processing | 422 "Payroll run has not been completed yet." |
| PAY-018 | P1 | W | Pension exemption acknowledgement | In run B include an employee with nationality status `foreign` | Approval without acknowledgement: 422 `pension-exemptions-unacknowledged`, "This run exempts 1 employee(s) from pension. Review them, then approve with that count." Approve with the count: accepted. The employee has no pension in the run and is left out of the pension schedule |
| PAY-019 | P1 | W | Absences banner | Run B covers a workday on which EMP3 had no attendance, leave or holiday | Banner "Absences nobody explained"; pay **not** reduced; approval does not need an acknowledgement, unless reconciliation is Required (RCN-007). No absences are flagged if the organisation recorded no attendance in the period, or for days after yesterday |
| PAY-020 | P1 | R | Contract salary | E-PAY-7 has salary only on a contract | Paid from the contract salary (QA F1 fixed this; regression check) |
| PAY-021 | P1 | W | Period lock | After approving (or paying) run A, edit attendance for a day in P1 (manual entry, correction, conflict keep) | 422 "This day is in an approved payroll run, so its attendance cannot be changed. Void the run first to reopen it." Device, offline and kiosk punches are still accepted |
| PAY-022 | P1 | X | Void | TA voids run A without a reason; with a reason | Reason required; voided; payslips flagged voided; `payroll.voided` webhook. Voiding a processing or already voided run: 422 "Only a completed or approved payroll run can be voided.". Voiding a paid run: 422 "This payroll run has been paid, so it cannot be voided. Correct it in the next payroll run." |
| PAY-023 | P1 | X | Reprocess | TA reprocesses voided run A | 201; a new run linked to the voided one; same figures as before for unchanged data. Reprocessing a run that is not voided: 422 "Only a voided payroll run can be reprocessed." |
| PAY-024 | P1 | R | Rate limit | A third process or reprocess within the hour | 429 with `Retry-After` |
| PAY-025 | P1 | R | Old ladder | Process a period starting before 2025-07-07 (if the organisation allows it; otherwise check the calculation log of a reprocessed old run) | Tax on the 979/2016 ladder, for example 5,000 taxable → 697.50 |
| PAY-026 | P2 | R | Leavers | A test employee resigned mid-period | Included, prorated by days employed |
| PAY-027 | P2 | R | Pagume | A period containing Pagume | Proration follows the Pagume setting |
| PAY-028 | P1 | R | G7 | Run B | E-PAY-7 exactly as in section 2 |
| PAY-029 | P1 | R | Overtime pay | EMP worked 2 h after shift end on a normal day and 2 h on a rest day in P2 (with records) | Paid at 1.5 and 2.0 of the hourly rate; over the daily cap or without an approved request (when approval is required) the run shows a flag but still pays |
| PAY-030 | P2 | R | Payroll plan gate | QA Isolation on Starter processes payroll | 403 plan feature (PLAN-001) |
| PAY-031 | P2 | W | Every capture channel pays the same | One day on shift S1, record 08:40 to 19:00 EAT five ways: EMP on the web, EMP2 at the kiosk, a third test employee by QR, HR manual entry for EMP3, a CSV import row for a fourth. Process the period | Five records with the same date and status (present). Each calculation log shows `normal` overtime 90 minutes (17:30 to 19:00) at 1.5. None is flagged absent that day. The imported row is rated against the shift (#443) |

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
| OUT-002 | P1 | R | Bank file | Approved or paid run → bank CSV | Columns Employee Code, Employee Name, Bank Name, Account Number, Net Pay (ETB), Currency (ETB); CRLF line ends; the primary bank account (else the first); net pay equals the run |
| OUT-003 | P1 | R | Register | Register CSV | Code, Name, Basic, Overtime, Allowances, Gross, Taxable, Income Tax, Employee Pension x%, Employer Pension y%, Loan, Cost Sharing, Net; a TOTAL row "N employees"; totals equal the run summary |
| OUT-004 | P1 | R | Income tax schedule | Statutory → income tax: preview, then download CSV and PDF | Preview without TINs; download with TINs, start date, basic, overtime, allowances, taxable, tax; each download audited `payroll.statutory_export`. Missing employer TIN prints "NOT SET"; the header status reads "Approved", "Approved and paid" or "NOT APPROVED" (draft figures) |
| OUT-005 | P1 | R | Pension schedule | Statutory → pension | Per employee pensionable amount and both contributions with the rate headers; exempt employees left out |
| OUT-006 | P1 | R | Payslip PDF | EMP: My Payslips → download | A5 portrait; earnings, deductions, net; employer pension shown; Amharic names correct; a voided run's payslip is marked voided |
| OUT-007 | P1 | R | Payslip visibility | EMP opens payslips before approval; EMP opens EMP2's payslip by id | None before approval; visible once approved or paid; EMP2's refused |
| OUT-008 | P1 | R | Journal | Accounting → journal and CSV export for run A | Debits equal credits; salary expense = gross; pension expense = employer pension; tax payable = tax; pension payable = employee and employer pension; net salary payable = net + other deductions; TOTALS row |
| OUT-009 | P2 | R | Totals agree | Compare run summary, register, bank file total, journal and statutory schedules | All agree to the cent |
| OUT-010 | P2 | R | Downgraded plan | QA Isolation after a downgrade opens an old run's register and bank file | Still readable |

## 8. Attendance reconciliation before payroll

Exceptions in a pay period, each linked to the request that settles it. `GET
/payroll/reconciliation?period_start&period_end` needs `payroll.viewAll` (FIN, TA; HR does not hold
it). `GET /attendance/my-exceptions` needs `attendance.viewOwn` (everyone). Three attendance
settings, `reconciliation_mode` (`off` / `advisory` / `required`), `lateness_deduction_enabled` and
`lateness_deduction_basis`, are saved through `PUT /attendance/settings` (`attendance.manage`), and
changing any of them also needs `payroll.manageConfig` (TA only). Settling uses existing requests:
`POST /leave/request` with `employee_public_id` (`leave.adjustBalance`: HR, TA), `POST
/attendance/corrections` for someone else (`attendance.manage`: HR, TA). Approving a lateness
decision needs `payroll.process` (FIN, TA). The answer is cached up to 10 minutes and cleared on any
change.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| RCN-001 | P1 | R | Off by default | TA: Settings → Attendance → "Reconcile attendance before payroll". Open a completed run | Off. No reconciliation banner on the run page; approval behaves exactly as PAY-016 |
| RCN-002 | P1 | W | Who changes the mode | HR sets Advisory (API); HR saves another attendance setting with the mode unchanged; TA sets Advisory | HR: control disabled, API 403; HR's other change saved. TA: saved and audited |
| RCN-003 | P1 | R | Reconcile tab | FIN: Payroll → Reconcile, From/To = P3; "Show which": "Still to settle", then "Everything"; then a 93-day range. HR and SUP open `/payroll?tab=reconcile` and call the API | Rows by employee: what, date, state ("Open", "Waiting", "Settled"), counts ":count open", ":count waiting", ":count settled"; ":count device punches matched to no one" when any. The default range is today's Gregorian month, so set the Ethiopian period yourself. 93 days: "Reconcile at most 92 days at a time." HR and SUP: no Payroll page, API 403 |
| RCN-004 | P1 | W | Settle absences as leave | TA: Reconcile, tick two "Absent, nothing on record" days of E-REC-1 → "Settle 2 selected"; Unpaid Leave for one, Excused Absence for the other, a reason → "File for approval". TA then tries to approve them | Offered: only Unpaid Leave, Excused Absence, Official Duty / Business Trip; hint "Unpaid leave is deducted once approved. Excused absence and business trips are paid." "2 request(s) filed for approval."; both rows "Waiting". TA approving: "You filed this request, so someone else must approve it." Once HR approves, both days leave the list |
| RCN-005 | P1 | W | Finance settling | FIN ticks an open absence and an open late arrival → Settle | From the code, each is refused (FIN holds neither `leave.adjustBalance` nor `attendance.manage`) and the dialog shows the refusal. Record a defect if finance is meant to settle from this tab (K-22) |
| RCN-006 | P1 | W | Settled absence in pay | Run C (no allowance rules). E-REC-1: basic 6,600.00, the two RCN-004 days approved | Unpaid day 6,600.00 ÷ 22 = 300.00 deducted; excused day nothing. Basic 6,300.00, gross 6,300.00, tax 760.00, employee pension 441.00, net 5,099.00, employer pension 693.00. E-REC-1's other absences flagged, not deducted |
| RCN-007 | P1 | W | Required mode refuses | TA sets Required. Run C completed with N items open or waiting. TA approves | 422 "Attendance for this period is not reconciled yet: N exceptions are still open or waiting for a decision. Settle them on the Reconcile tab first." N = the tab's open plus waiting. Run page: "Attendance for this period is not reconciled: :open open, :pending waiting for a decision.", "This run cannot be approved until they are settled.", "Open Reconcile". QA Audit employees with no attendance (G1 to G8) have every past workday open; settle each with one Excused Absence if N must reach 0 |
| RCN-008 | P1 | W | Decided after processing | Required mode, nothing blocking. Process; then approve any correction, leave or overtime request dated in the period; approve the run; then void, reprocess, approve | First approval: 422 "Attendance for this period was decided after the run was calculated. Reprocess the run so it pays from the decisions." After reprocessing: approved |
| RCN-009 | P2 | W | Required mode passes | A `uat-` organisation with two employees: settle every item, process, approve | "Attendance for this period is reconciled."; approved |
| RCN-010 | P2 | W | Advisory | Advisory, items open, approve | Banner lists them with "Check them before approving."; approved |
| RCN-011 | P2 | R | What counts | In P3 produce: an open high-severity integrity flag, a pending duplicate-punch conflict, a check-in open over 14 h, an uncorrected auto check-out, overtime past what was approved (approval required). Also overtime over a limit with approval not required, and late records with lateness deductions off | Listed as "Punch flagged for review", "Duplicate punches", "Check-out missing", "Closed by auto check-out", "Overtime past what was approved", each linking to its screen and blocking. Overtime with no approval requirement is not listed (it is on the overtime banner). Late or early records listed only when lateness deductions are on |
| RCN-012 | P2 | R | My exceptions | EMP: `GET /attendance/my-exceptions`; then a 93-day range | Own items only; default range the 1st of this month to today. 93 days: "Reconcile at most 92 days at a time." |

## 9. Lateness deductions (Labour Proclamation 1156/2019, Art. 59)

A lateness decision is an attendance correction with `kind: lateness` (`POST /attendance/corrections`,
`deduct_minutes` 0 to 1,440; 0 waives). It moves no punch. HR or TA file it for others
(`attendance.manage`), or the employee on their own record. Approval needs `correction.approve` plus
`payroll.process` (FIN, TA). Deduction = minutes × (basic ÷ 22, rounded to the cent) ÷ 480, taken
from basic after unpaid leave and before overtime, allowances, tax and pension. One third of that
basic (rounded down) is shared with loans and cost sharing estimated on basic; lateness takes only
what is left.

Worked figures, run C (no allowance rules; each basic 8,800.00, daily rate 400.00):

| Employee | Decided minutes | Loan instalment | Third | Lateness | Basic after | Tax | Employee pension | Loan | Net | Employer pension |
|---|---|---|---|---|---|---|---|---|---|---|
| E-LATE-1 | 90 | none | 2,933.33 | 75.00 | 8,725.00 | 1,331.25 | 610.75 | 0.00 | 6,783.00 | 959.75 |
| E-LATE-2 | 90 | 2,900.00 | 2,933.33 | 33.33 | 8,766.67 | 1,341.67 | 613.67 | 2,900.00 | 3,911.33 | 964.33 |
| E-LATE-3 | 0 | 3,000.00 | 2,933.33 | 0.00 | 8,800.00 | 1,350.00 | 616.00 | 3,000.00 | 3,834.00 | 968.00 |

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LATE-001 | P1 | W | Switch on | TA: Settings → Attendance → "Lateness deductions": on with the basis empty, then with "QA work rules clause 12" | Empty: 422 "State the basis for lateness deductions, such as the work rules or collective agreement clause, before switching them on." With a basis: saved. HR: card disabled, API 403 |
| LATE-002 | P1 | W | Decision rules | API: a decision while deductions are off; on a present record; on a record checked in 08:46 on S1 with 17 minutes; a second while the first is pending; 0 minutes | "Your organisation has not switched on lateness deductions."; "This record is not a late arrival or an early departure."; "At most 16 minutes can be deducted for this record."; "A lateness decision for this record is already waiting."; 0 accepted |
| LATE-003 | P1 | W | Who approves | A pending decision for E-LATE-1: SUP approves; HR approves; SUP rejects another with a reason; FIN approves | SUP and HR: 403 "A lateness deduction changes pay, so it must be approved by someone who processes payroll." SUP's rejection allowed. FIN: approved. Punches and status unchanged (still late); the corrections list shows "Lateness" with ":minutes min" |
| LATE-004 | P1 | R | Minutes measured | Manual entries on S1 (08:30 to 17:30, grace 15, early 15): 08:46 to 17:30; another day 08:35 to 17:10 | Late 16 minutes (from shift start, not from the end of grace). Left early 20 minutes. Reconcile shows these minutes; "Deduct the minutes missed" files exactly them |
| LATE-005 | P1 | W | Deducted in pay | E-LATE-1: late 08:46, 09:00, 09:14 (16 + 30 + 44 = 90 min), all decided in full and approved; run C | As the table: 90 × 40,000 ÷ 480 = 7,500 cents. Log step `lateness`: `deducted_minutes` 90, `uncapped_cents` 7500, `third_of_basic_cents` 293333, `cap_cents` 293333, `deduction_cents` 7500 |
| LATE-006 | P1 | W | One third shared with loans | E-LATE-2: loan 6,000.00 at 2,900.00 a month, the same 90 minutes; run C | Lateness 33.33 (293,333 − 290,000 = 3,333 cents), `capped` true. Loan deducted in full. Not on the ceiling banner (33.33 + 2,900.00 equals the third exactly) |
| LATE-007 | P1 | W | Ceiling banner | E-LATE-3: loan 3,000.00 a month, no lateness; run C | Lateness 0.00. Banner "Deductions over a third of basic pay": "1 payslip(s) deduct more than a third of basic pay for lateness, loans and cost sharing together.", E-LATE-3 3,000.00 ETB / 2,933.33 ETB. Loan not reduced; approval not blocked. Anyone whose loans pass the third is listed while deductions are on |
| LATE-008 | P2 | W | Switched off again | After LATE-005, TA switches deductions off; void and reprocess run C | E-LATE-1 paid on the full 8,800.00 (tax 1,350.00, pension 616.00); no `lateness` step, no ceiling banner. The approved decisions stay on the records |

## 10. Payment

`PUT /payroll/runs/{run}/paid` (`payroll.markPaid`: FIN, TA) takes `paid_on` (Y-m-d, not after
today), `payment_reference` (up to 120 characters) and `payment_note` (up to 500). Audited
`payroll.paid`; sends the `payroll.paid` webhook. **A paid run cannot be voided, so its period can
never be run again in that organisation.**

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PAID-001 | P1 | X | Record payment | FIN on the last approved run of the pass (A′, P1) → "Confirm payment": date paid, reference "QA-NOT-PAID", note "QA" | Dialog: "Record the day the bank paid this run. A paid run cannot be voided: a mistake in it is corrected in the next payroll run." Then "Payment recorded"; status paid; the "Paid" step shows the date, reference and note; audit `payroll.paid`; webhook `payroll.paid` with `public_id`, `period`, `paid_on`. Before payment the step reads "Confirm the payment once the bank has sent it." |
| PAID-002 | P1 | W | Rules | `paid_on` tomorrow; a 121-character reference; a 501-character note; payment on a completed run; again on the paid run | Each refused. Completed or paid run: 422 "Payment can only be recorded for an approved payroll run." |
| PAID-003 | P1 | R | Rights | HR, SUP, EMP call the endpoint | 403. Only FIN and TA see the button |
| PAID-004 | P1 | R | No void | TA voids the paid run (API); open the run page | 422 "This payroll run has been paid, so it cannot be voided. Correct it in the next payroll run." No Void or Reprocess button. Approving it: "Payroll run has not been completed yet." |
| PAID-005 | P1 | R | Paid counts as released | On the paid run: payslips; bank file; income tax schedule header; a manual entry in P1; a new run for P1 | Payslips visible; bank file downloads again; header "Approved and paid"; the manual entry: "This day is in an approved payroll run, so its attendance cannot be changed. Void the run first to reopen it."; a new run: "A payroll run already covers :start to :end (:label). Void that run first if this period must be computed again." |

## 11. Statutory rules by kind of organisation

Every statutory value is a dated row (`statutory_parameters`, `tax_brackets`, `pension_rules`),
optionally scoped to a kind of organisation; an organisation's own setting wins where the row allows
it. `GET /organization-types` is public. `PUT /settings/organization` with `organization_type` needs
`settings.manage` (TA). Calculation log version 1.5.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| STAT-001 | P1 | W | Kind of organisation | TA: Settings → Organisation; confirm the kind shown | "Kind of organisation in law", help "Decides the statutory payroll rules: the pension scheme and the fiscal year." Until confirmed: "Confirm the kind of organisation below. Payroll applies the pension scheme and fiscal year set for it in law." After confirming the notice is gone; audit `settings.organization_updated` with from and to. Options: Private organisation, Public enterprise, Government office (civil service), Charity or society, International organisation or embassy, Defence or police |
| STAT-002 | P1 | W | Existing organisation keeps its pay | Change QA Audit's kind to Government office; open Settings → Payroll; change it back | Pension scheme still `private`, fiscal year still Meskerem (written into every existing organisation's settings by the upgrade). Golden figures do not move |
| STAT-003 | P2 | W | New organisation defaults | Register a `uat-` organisation with type Government, and another with Bank; open Settings → Payroll in each | Government: Government office (flagged for review), scheme `public`, fiscal year start month 11 (Hamle). Bank: Private organisation, `private`, Meskerem |
| STAT-004 | P2 | W | Military scheme by kind | A `uat-` organisation that never saved a pension scheme: kind Defence or police; process an employee with basic 1,800.00 | Payroll settings show `public_military`. Employee pension 126.00, employer 594.00 (33%), net 1,674.00 |
| STAT-005 | P1 | R | Log 1.5 | Any entry's calculation log in run A | `version` 1.5, `rounding` `integer_half_up`. `statutory_rules` holds the kind of organisation and, per code, value, source (`platform`, `platform_type`, `tenant`, `tenant_setting`), legal reference and whether confirmed. The income tax step's `rule.brackets` is the whole six-band ladder |
