# E2E specification 07: payroll

Columns, personas and modes: see the [README](README.md#how-a-test-case-is-written). Run in QA Audit.
All money is ETB, stored in cents and shown as `X,XXX.XX ETB`. Every figure in this document is a
**pass or fail** figure: a difference of one cent is a defect.

**Third edition (2026-11-08).** Cases were added for the organisation's own working days per month
(#642), Art. 85 sick leave in pay (#637, section 12), a run that finishes across several requests
(#639), payslips in the reader's language and calendar (#520), and the payroll items of the A-Z
review train #624: overtime at the contract's hourly rate (#554), fixed allowances prorated (#572),
the journal's withheld deductions (#566), the bank file naming who has no account (#582) and unpaid
leave refused in an approved period (#568). Cases these changed were corrected in place: section 1,
PAY-001, PAY-002, PAY-007, PAY-010, PAY-011, PAY-012, PAY-020, PAY-029, OUT-002, OUT-003, OUT-006, OUT-008, OUT-009,
RCN-007, STAT-005 and the section 9 and 11 notes. The role rules behind pay fields (#488) and "nobody
below the organisation admin changes their own pay" (#490) are file 03's ACC-008, ACC-020 and
ACC-022; the reader's calendar in files generally is 09's L10N-008; dashboard payroll months and
analytics figures are 08's DASH-011, ANA-002 and ANA-003. Priorities were re-tiered: **P1** is kept
for what would be an S1 or block payroll: every money figure (tax, pension, overtime, net, golden
payslips, rounding, deductions, loan and cost-sharing balances), maker-checker and self-approval
refusals, editing your own pay, exposure of another person's pay, and the one happy path of a run
(process, approve, pay). Other flows, export formats and messages are **P2**; cosmetic checks are
**P3**.

**Before you start:**

- Payroll processing is limited to **2 runs (process or reprocess) per hour per organisation**. Plan
  the order in section 3 and do not waste a run.
- Processing is queued. A run stays `processing` until the queue drains (every 5 minutes on
  production; allow 10). Check `/api/v1/health` → `queue_detail` before calling it stuck. A run
  writes its entries 100 at a time and a large one carries on over more than one drain (PAY-035).
- Only `tenant_admin` approves, voids and reprocesses. `finance_admin` processes and records payment (`payroll.markPaid`,
  which `tenant_admin` also holds). The person who
  processed a run cannot approve it while another approver exists (maker-checker), so TA2 or FIN
  is needed.
- **Allowance rules apply to every active employee in the organisation.** Run the golden payslips
  (G1 to G6) with no active allowance rule; G7 needs its two allowance rules. Section 3 orders this.
- **The month length is 22 working days unless the organisation sets its own** (PAY-032). Every
  daily and hourly rate in this document uses 22; PAY-033 is the one case that changes it, and sets
  it back.
- Mark every QA run "QA" and never send its bank file to a bank.

**Extra test data for this file** (add it with the rest of [01, section 6](01-TEST-PLAN.md#6-test-data)).
Each is set up like G1 to G6 (Ethiopian, private pension scheme, no loans) unless the row says
otherwise:

| Employee | Set-up | Used by |
|---|---|---|
| **E-PAY-9** | Basic 8,800.00 on the record, hired before P1, shift S1. In P3: 2 days of approved Unpaid Leave (one request, 2 days) and one other workday recorded 08:30 to 19:30 (HR manual entry) | PAY-036 (golden G8) |
| **E-PAY-10** | Basic 6,000.00 on the record, created after run A′ is processed (section 3, step 3), hire date the 21st day of P2 | PAY-037 |
| **E-SICK-1** | Basic 8,800.00 on the record, hired over a year before P3, no leave other than below. Approved "Sick Leave" (the wizard's Art. 85 type) of exactly 22 charged days dated before P1, then 5 charged days inside P3 | SICK-001, SICK-002, SICK-004 |
| **E-SICK-2** | As E-SICK-1, with only the 5 charged days of Sick Leave inside P3 | SICK-003 |

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
proration, Pagume, unpaid leave, sick leave, lateness) is an exact fraction of cents, **rounded once,
half away from zero**, so a half cent rounds up (calculation log `rounding: integer_half_up`, RND-001).
Cost sharing alone rounds down (CS-003). The ladders are dated rows, not code: the organisation's own,
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

Pension is on **basic salary after proration, unpaid leave, sick leave and any lateness deduction**.
Allowances and overtime are not pensionable. Employees with nationality status `foreign` are exempt;
`ethiopian_origin` is treated as exempt but unconfirmed and must be acknowledged at approval.

### 1.3 Overtime (Labour Proclamation 1156/2019, Art. 68)

| Kind | Multiplier |
|---|---|
| Normal day, 06:00 to 22:00 | 1.5 |
| Night, 22:00 to 06:00 | 1.75 |
| Rest day | 2.0 |
| Public holiday (day or night) | 2.5 |

Hourly rate = **the contract's monthly basic, before proration, unpaid leave, sick leave and
lateness**, ÷ (working days per month × 8). Since 2026-11-03 (#554 in #624, calculation log 1.6, log
field `hourly_rate_base_cents`); before that it was the reduced basic, so a short month paid less an
hour. Pay is one fraction per kind of hour, monthly basic × minutes × multiplier ÷ (working days × 8
× 60), rounded once. The working days (22 unless the organisation set its own, PAY-032), 8, the night
window, the floors and the 240 and 720 limits are statutory parameters (`payroll.working_days_per_month`,
`payroll.hours_per_day`, `overtime.night_window`, `overtime.minimum_rates`,
`overtime.legal_daily_minutes`, `overtime.legal_weekly_minutes`). On a normal day only time after the shift ends counts; on a
rest day or holiday the whole worked window counts. Limits (240 min a day, 720 a week, the
organisation's daily cap) **flag** the run; every minute is still paid.

### 1.4 Calculation order

Basic → Pagume rule (`full_month`, or `daily_rate` = monthly × 12 × Pagume days ÷ 365) → proration
by days employed → unpaid leave (daily rate = monthly basic ÷ working days per month, rounded to the
cent, per unpaid day) → **Art. 85 sick leave** (half the daily rate per half-pay day, the whole daily
rate per unpaid day; section 12) → **lateness, only if the organisation switched it on** (decided
minutes × daily rate ÷ 480, capped at one third of the basic so far, rounded down, less loan
instalments and cost sharing estimated on that basic; section 9) → absences (flagged, **never
deducted**) → overtime (on the contract's monthly basic, 1.3) → allowances (percentages of the basic
after lateness; fixed amounts prorated by days employed as basic is, #572 in #624, and not reduced by
unpaid leave) → gross → tax on (gross − non-taxable allowances) → pension → loans → cost sharing
(share of gross, rounded down) → **net = gross − tax − employee pension − loans − cost sharing**.
Unpaid leave, sick leave and lateness are already inside basic, not separate deductions.

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
| E-PAY-7 | 8,800.00 (on a contract, none on the record) | 2 days × 400.00 (8,800.00 ÷ 22) | 8,000.00 | Transport 600.00 (not taxable) + position 10% = 800.00 (taxable) | 9,400.00 | 8,800.00 | 1,350.00 | 560.00 | 7,490.00 | 880.00 |

**G8, overtime at the contract's rate** (run C, no allowance rules; `PayrollGoldenPayslipsTest` G8):

| Employee | Basic | Unpaid leave | Basic after | Overtime | Gross | Taxable | Tax | Employee pension | Net | Employer pension |
|---|---|---|---|---|---|---|---|---|---|---|
| E-PAY-9 | 8,800.00 | 2 days × 400.00 | 8,000.00 | 2 h normal × 50.00 × 1.5 = 150.00 (50.00 = 8,800.00 ÷ 22 ÷ 8) | 8,150.00 | 8,150.00 | 1,187.50 | 560.00 | 6,402.50 | 880.00 |

Boundary checks (tax only, current ladder): 2,000 → 0.00; 3,000 → 150.00; 4,000 → 300.00; 5,000 →
500.00; 7,000 → 900.00; 10,000 → 1,650.00; 14,000 → 2,850.00; 20,000 → 4,950.00.

Overtime example: basic 8,800 gives an hourly rate of 50.00. Two normal overtime hours pay 150.00;
two night hours pay 175.00; two rest-day hours 200.00; two holiday hours 250.00. The rate is the same
in a month with unpaid leave, sick leave or lateness (G8).

Every golden figure is whole cents before rounding, so the exact-rounding change of 2026-10-27
(#448) does not move any of them; RND-001 exercises the half-cent rule.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| RND-001 | P1 | W | Half-cent rounding | In run A, include E-PAY-8, basic 3,500.50 (API `salary_cents: 350050`), set up like G1 to G6 | Tax 225.08 (350,050 × 15% = 52,507.5 cents, rounded up to 52,508, less 30,000). Employee pension 245.04 (24,503.5, rounded up). Employer pension 385.06 (38,505.5, rounded up). Net 3,030.38. One cent lower on any figure means a half was rounded down: a defect |

## 3. Run order for a full pass

0. Before run A: set up E-SICK-1's 22 days of sick leave before P1 (SICK-001), and E-PAY-9,
   E-SICK-1 and E-SICK-2 as in the extra test data.
1. **Run A** (period P1, no allowance rules active): check G1 to G6, the run lifecycle (PAY-035),
   the default month length (PAY-034), exports.
2. Approve run A. Check payslips, loans, the period lock, unpaid leave refused in P1 (PAY-038).
3. Void run A, then **reprocess** it as run A′ (one more run in the hour budget). Check balances.
   Then create E-PAY-10 (PAY-037).
4. Next hour: activate the two allowance rules, approve E-PAY-7's unpaid leave in period P2.
   **Run B** (period P2): check G7, PAY-037, overtime, absences, pension exemptions. Then the month
   length (PAY-032), void B and reprocess it as B′ with 20 days (PAY-033), and set 22 again.
   Deactivate the allowance rules.
5. TA switches lateness deductions on (LATE-001) and reconciliation to Advisory. Settle E-REC-1
   and E-LATE-1 to E-LATE-3 (sections 8 and 9); approve E-PAY-9's unpaid leave and record its long
   day, and the P3 sick leave of E-SICK-1 and E-SICK-2. **Run C** (period P3, past days only, no
   allowance rules): RCN-006, LATE-005 to LATE-007, PAY-036, SICK-002, SICK-003. Next hour: Required
   mode, RCN-007 and RCN-008 (void and reprocess C).
6. Approve A′ and record its payment (PAID-001). **This cannot be undone: P1 can never be run again
   in QA Audit**, so the next pass needs new periods. Then SICK-004 and LOAN-009.
7. Set reconciliation to Off and lateness deductions off.

---

## 4. Configuration

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PAY-001 | P2 | R | Tax ladder | TA: Settings → Payroll rules → tax brackets. FIN: open the same page; call `GET /payroll/tax-brackets` | TA: the 1395/2025 ladder above, with its legal reference and effective date; source shown as platform (or `platform_type`, for a ladder scoped to its kind of organisation; none is seeded) unless the organisation set its own. FIN: the Payroll rules page is for the organisation admin only and is not shown; the API answers (FIN holds `payroll.viewConfig`) with the same ladder |
| PAY-002 | P2 | W | Organisation ladder | TA replaces the ladder (needs `payroll.manageConfig`) with 0 or 21 brackets, a rate of 101 | Refused (1 to 20 brackets, rates 0 to 100, effective date required). FIN: `PUT /payroll/tax-brackets` answers 403 |
| PAY-003 | P2 | R | Pension rules | Pension rules page | The three schemes above (the `pension.schemes` parameter; any other scheme is refused as invalid). Adding a rule dated on or before the latest one for that scheme: 422 `payroll.pension_rule_not_after_latest` |
| PAY-004 | P2 | W | Allowance rules | TA: rules → "QA Transport" fixed 600.00, not taxable; "QA Position" percentage 10, taxable | Saved, audited. Fixed without amount, percentage without percent, percent 101, name over 100 refused |
| PAY-005 | P2 | W | Overtime rates | TA sets normal 1.25 | Refused (minimums 1.5, 1.75, 2.0, 2.5, 2.5; maximum 10) |
| PAY-006 | P2 | W | Pagume rule | Settings: Pagume proration `full_month` vs `daily_rate` | Saved; used in PAY-027 |
| PAY-007 | P2 | R | Chart of accounts | Accounting → chart of accounts | Defaults 5100 Salary Expense, 5200 Pension Expense (Employer), 2100 Income Tax Payable, 2200 Pension Payable (Employee), 2201 Pension Payable (Employer), 2300 Net Salary Payable, 1400 Employee Loans Receivable, 2400 Cost Sharing Payable (the last two since #566 in #624); editable by TA |
| PAY-032 | P2 | W | The organisation's own month length (#642) | TA: Settings → Payroll rules → Payroll schedule. Read "Working days per month". Enter 19, then 32, and save. API: `PUT /settings` with `{"settings":{"working_days_per_month":19}}`. Change only "Payroll run day" and save, with the browser's Network tab open. FIN: open the page; call the same `PUT` | The field shows 22, with the help "Monthly salary is divided by this for a day of unpaid leave, an hour of overtime and sick-leave pay. 22 for a five-day week, 26 for a six-day week." 19 or 32: "Enter a number of days from 20 to 31" under the field and nothing sent. API: 422 on `settings.working_days_per_month` (20 to 31). Saving the run day alone: "Payroll schedule saved", and the request carries no `working_days_per_month`, so the organisation stays on the platform's value. FIN: the page is not shown; the API answers 403 (`settings.manage`) |
| PAY-033 | P1 | X | A different month length moves every daily and hourly rate (#642) | After PAY-028: TA sets "Working days per month" to 20 and saves ("Payroll schedule saved"). Void run B ("QA"), reprocess it as B′. Open E-PAY-7 and the calculation log; then G1 to G6. Then set the field back to 22 and save | E-PAY-7 in B′: daily rate 8,800.00 ÷ 20 = 440.00; unpaid leave 2 × 440.00 = 880.00; basic 7,920.00; position 10% = 792.00, transport 600.00; gross 9,312.00; taxable 8,712.00; tax 8,712 × 25% − 850 = 1,328.00; employee pension 7% × 7,920 = 554.40; employer pension 11% × 7,920 = 871.20; net 9,312.00 − 1,328.00 − 554.40 = 7,429.60. Log: `unpaid_leave.daily_rate_cents` 44000; `statutory_rules.rules["payroll.working_days_per_month"]` value 20, source `tenant_setting`, `confirmed` false. G1 to G6 unchanged (no leave, sick leave, overtime or lateness). Back at 22 the source stays `tenant_setting` with value 22 and the figures are those of section 2 again |
| PAY-034 | P1 | R | The default month length still holds (#642) | Run A, before PAY-033: open any entry's calculation log; TA: `GET /settings` | `statutory_rules.rules["payroll.working_days_per_month"]`: value 22, source `platform_type` (one platform row per kind of organisation since #642; `platform` only for an organisation with no kind), `confirmed` false, legal reference "ETHR convention, set per organisation type (ACCOUNTANT-SIGN-OFF.md)". `GET /settings` → `payroll.working_days_per_month` 22. Every 22-based figure in this file holds unchanged: G7's 400.00 = 8,800.00 ÷ 22, RCN-006's 300.00 = 6,600.00 ÷ 22, section 9's daily rate 400.00 and 90 × 40,000 ÷ 480 = 7,500 cents, the 50.00 hourly rate = 8,800.00 ÷ 22 ÷ 8 |

## 5. Run lifecycle

Run states: `processing` → `completed` (or `failed`) → `approved` → `paid`. Only `completed` and
`approved` can go to `voided`; reprocessing a voided run creates a new run. `approved` and `paid` are
both released: payslips, the period lock, the bank file and statutory schedules treat them alike. A
database trigger freezes a released or voided run's amounts.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PAY-010 | P1 | X | Process | FIN: Payroll → Process, period P1 (an Ethiopian month, for example Meskerem 2019 = 2026-09-11 to 2026-10-10) | 202, status processing, toast "Payroll processing started"; within 10 minutes completed. Duplicate submit with the same key: 200 `was_duplicate: true` |
| PAY-011 | P1 | R | Golden figures | Open run A. (#625 and #639 changed how a run reads what is the same for the organisation and how it writes its entries; neither may move a figure) | E-PAY-1 to E-PAY-6 exactly as in section 2 |
| PAY-012 | P2 | R | Calculation log | Open one entry's breakdown ("How this pay was calculated") and its log (`?include_log=1`) | The full trace: version 1.7, `rounding: integer_half_up`, `statutory_rules` (STAT-005), inputs, the tax basis (legal reference, effective date, source, the whole ladder), pension rule, each step's value, a `sick_leave` step after `unpaid_leave` (section 12), `hourly_rate_base_cents` on the `overtime` step (the contract's monthly basic), and a `lateness` step when the organisation deducts lateness |
| PAY-013 | P2 | R | Skipped employees | Include an employee with no salary anywhere | Not in the run, and the run shows a skipped count. **Check it is visible on the run page**; a silent skip was a production defect (QA F1, 2026-10-15) |
| PAY-014 | P1 | R | Rights | HR processes; FIN approves; SUP opens runs | All refused |
| PAY-015 | P1 | R | Maker-checker | FIN (processed run A) approves | 422 "You processed this payroll run, so another approver in your organisation must approve it." If FIN were the only approver holder, the approval would go through and be audited `self_approved` |
| PAY-016 | P1 | X | Approve | TA approves run A | Approved; payslips visible to the employees; `payroll.approved` webhook; FIN notified. In Required reconciliation mode, approval is refused first if anything is open (RCN-007) |
| PAY-017 | P2 | R | Approve too early | Approve a run still processing | 422 "Payroll run has not been completed yet." |
| PAY-018 | P1 | W | Pension exemption acknowledgement | In run B include an employee with nationality status `foreign` | Approval without acknowledgement: 422 `pension-exemptions-unacknowledged`, "This run exempts 1 employee(s) from pension. Review them, then approve with that count." Approve with the count: accepted. The employee has no pension in the run and is left out of the pension schedule |
| PAY-019 | P2 | W | Absences banner | Run B covers a workday on which EMP3 had no attendance, leave or holiday | Banner "Absences nobody explained"; pay **not** reduced; approval does not need an acknowledgement, unless reconciliation is Required (RCN-007). No absences are flagged if the organisation recorded no attendance in the period, or for days after yesterday |
| PAY-020 | P2 | R | Contract salary | HR gives E-PAY-7 a contract of 8,800.00 with no salary on the record. FIN tries to add a contract with a salary to another test employee | E-PAY-7 is paid from the contract salary (QA F1 fixed this; regression check; the figure is PAY-028). FIN: 403; a contract whose salary differs from the record needs `employee.updateFinancial`, which Finance does not hold (#488; your own record is ACC-022) |
| PAY-021 | P2 | W | Period lock | After approving (or paying) run A, edit attendance for a day in P1 (manual entry, correction, conflict keep) | 422 "This day is in an approved payroll run, so its attendance cannot be changed. Void the run first to reopen it." Device, offline and kiosk punches are still accepted |
| PAY-022 | P2 | X | Void | TA voids run A without a reason; with a reason | Reason required; voided; payslips flagged voided; `payroll.voided` webhook. Voiding a processing or already voided run: 422 "Only a completed or approved payroll run can be voided.". Voiding a paid run: 422 "This payroll run has been paid, so it cannot be voided. Correct it in the next payroll run." |
| PAY-023 | P2 | X | Reprocess | TA reprocesses voided run A | 201; a new run linked to the voided one; same figures as before for unchanged data. Reprocessing a run that is not voided: 422 "Only a voided payroll run can be reprocessed." |
| PAY-024 | P2 | R | Rate limit | A third process or reprocess within the hour | 429 with `Retry-After` |
| PAY-025 | P1 | R | Old ladder | Process a period starting before 2025-07-07 (if the organisation allows it; otherwise check the calculation log of a reprocessed old run) | Tax on the 979/2016 ladder, for example 5,000 taxable → 697.50 |
| PAY-026 | P2 | R | Leavers | A test employee resigned mid-period | Included, prorated by days employed |
| PAY-027 | P2 | R | Pagume | A period containing Pagume | Proration follows the Pagume setting |
| PAY-028 | P1 | R | G7 | Run B | E-PAY-7 exactly as in section 2 |
| PAY-029 | P1 | R | Overtime pay | EMP worked 2 h after shift end on a normal day and 2 h on a rest day in P2 (with records) | Paid at 1.5 and 2.0 of the hourly rate (EMP's contract basic ÷ 22 ÷ 8); over the daily cap or without an approved request (when approval is required) the run shows a flag but still pays |
| PAY-030 | P2 | R | Payroll plan gate | QA Isolation on Starter processes payroll | 403 plan feature (PLAN-001) |
| PAY-031 | P2 | W | Every capture channel pays the same | One day on shift S1, record 08:40 to 19:00 EAT five ways: EMP on the web, EMP2 at the kiosk, a third test employee by QR, HR manual entry for EMP3, a CSV import row for a fourth. Process the period | Five records with the same date and status (present). Each calculation log shows `normal` overtime 90 minutes (17:30 to 19:00) at 1.5. None is flagged absent that day. The imported row is rated against the shift (#443) |
| PAY-035 | P2 | R | A run finishes across as many requests as it needs (#639) | FIN processes run A and stays on the run page. Watch `/api/v1/health` → `queue_detail`, then TA: Settings → Audit log and the bell | The page refreshes itself every 3 seconds while the run is `processing`, then shows `completed` with its employee count, skipped count and totals, which are written once, when the last batch is in. Exactly one `payroll.processed` audit row and one "Payroll for :period has been calculated." notice to FIN and TA (unless muted), never one per batch. QA Audit (under 100 people) finishes in one drain. A run of more than 100 people writes its entries 100 at a time, stops after half the host's 30-second request limit, and continues on the next drain: entries can appear while it still reads `processing`, and approving then gives PAY-017's message. A run that fails for good ends `failed` with no entries left and can be submitted again. Larger runs are measured only on the local rehearsal, never on production |
| PAY-036 | P1 | W | Overtime at the contract's hourly rate (#554 in #624, golden G8) | Run C. Open E-PAY-9 and its calculation log | Exactly G8 in section 2: basic 8,000.00, overtime 150.00, gross 8,150.00, taxable 8,150.00, tax 8,150 × 25% − 850 = 1,187.50, employee pension 560.00, net 8,150.00 − 1,187.50 − 560.00 = 6,402.50, employer pension 880.00. Log `overtime.hourly_rate_base_cents` 880000, `overtime_minutes` 120. Overtime of 136.36 (8,000.00 × 120 × 1.5 ÷ 10,560) is the old reduced-basic rule: a defect |
| PAY-037 | P1 | W | Fixed allowances are prorated for a mid-period hire (#572 in #624) | Run B, P2 an Ethiopian month of 30 days (not Pagume), the two allowance rules active. Open E-PAY-10 (hired on the 21st day of P2) | Days employed 10 of 30. Basic 6,000.00 × 10 ÷ 30 = 2,000.00. Transport 600.00 × 10 ÷ 30 = 200.00 (not taxable); position 10% of 2,000.00 = 200.00. Gross 2,400.00; taxable 2,200.00; tax 2,200 × 15% − 300 = 30.00; employee pension 140.00; employer pension 220.00; net 2,400.00 − 30.00 − 140.00 = 2,230.00. A transport line of 600.00 (gross 2,800.00, net 2,630.00) is the old full-month rule: a defect |
| PAY-038 | P1 | W | Unpaid leave in an approved period is refused (#568 in #624) | After run A is approved (section 3, step 2), before it is voided: HR files one day of Unpaid Leave for EMP3 dated in P1, on EMP3's behalf; TA approves it. Then the same with one day of annual leave | Unpaid: 422 `payroll-period-closed`, "This unpaid leave falls in a payroll period that is already approved. Void and reprocess that run first, so the deduction is paid from it." The request stays pending. Annual (paid) leave: approved, as it moves no pay. Art. 85 sick leave is a paid type and is not refused (SICK-004). Reject the pending unpaid request afterwards |

## 6. Loans and cost sharing

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LOAN-001 | P2 | W | Create | FIN: Loans → EMP, 3,000.00 at 1,000.00 a month | Active immediately (no approval step). Amount or instalment under 1.00 refused |
| LOAN-002 | P1 | W | Deducted on approval only | Process run A | Payslip shows 1,000.00 loan deduction; the loan balance is **unchanged** until the run is approved |
| LOAN-003 | P1 | W | Balance moves | Approve run A | Outstanding 2,000.00 |
| LOAN-004 | P1 | W | Void restores | Void run A | Outstanding back to 3,000.00; a loan the run had completed reopens. Voiding a completed (not approved) run restores nothing because nothing moved |
| LOAN-005 | P1 | W | Last instalment | A loan with 400.00 left and a 1,000.00 instalment | Deducts 400.00 and closes at 0 |
| LOAN-006 | P1 | W | Stale balance | Process a run; change the loan; approve | 422 "Loan or cost-sharing balances changed after this run was calculated. Void the run and reprocess the period." |
| LOAN-007 | P2 | W | Edit and cancel | Update or cancel an active loan; then a completed one | Active: allowed. Completed: 422 `payroll.loan_not_active` |
| LOAN-008 | P2 | W | Opening loans import | Import opening loan balances (template, preview, commit) | Same all-or-nothing rule as leave balances; needs `payroll.manageLoan` |
| LOAN-009 | P1 | W | Finance and their own deductions (the #490 rule) | After PAID-001, as FIN on FIN's own employee record: create a loan of 1,000.00 at 100.00 a month; lower its instalment to 1.00; cancel it. Create a cost-sharing obligation of 1,000.00 at 1%, then cancel it | From the code each is accepted: the loan and cost-sharing endpoints check only `payroll.manageLoan` and `payroll.manageCostSharing`, and #490's "Someone else in your organisation must change your own pay or bank details." (ACC-022) covers salary, TIN, bank details, contracts and personnel actions, not deductions. Lowering your own instalment raises your own net pay, so **record a defect** unless the owner decides Finance may manage its own deductions. Leave nothing active |
| CS-001 | P2 | W | Create | FIN: Cost sharing → EMP2, total 20,000.00, rate 10% | Active. Rate 0 or 101 refused; total under 1.00 refused |
| CS-002 | P2 | W | One per employee | A second active obligation for EMP2 | 422 `duplicate-obligation` |
| CS-003 | P1 | R | Deduction | In a run | floor(gross × 10%), capped at the outstanding balance |
| CS-004 | P2 | W | Status | Suspend, resume, cancel | Allowed: active to suspended, completed or cancelled; suspended to active or cancelled. No delete |

## 7. Outputs

The run page builds the bank file and the register CSV itself from the API's JSON; the server's
`export/bank-csv` and `register-csv` endpoints keep an older layout, given here so a defect names the
right one. File names carry the run's stored label, which is the English Gregorian month of the
period start (Meskerem 2019 is stored "September 2026"), whatever the reader's language or calendar.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| OUT-001 | P1 | R | Bank file before approval | Download the bank file of a completed run | 422 "A bank file can only be exported for an approved payroll run." |
| OUT-002 | P1 | R | Bank file | Approved or paid run → run page → bank file. Then `GET /payroll/runs/{run}/export/bank-csv` | Run page: `bank-transfer-September-2026.csv` (the stored label), no byte-order mark, columns Employee Name, Employee Code, Bank, Branch, Account Number, Net Amount (ETB); the primary bank account (else the first); one row per entry; net amounts equal the run. API: `bank-export-<run id>.csv`, columns Employee Code, Employee Name, Bank Name, Account Number, Net Pay (ETB), Currency (ETB), CRLF line ends, the same amounts. Each download audited `payroll.bank_export` |
| OUT-003 | P2 | R | Register | Run page → register CSV, interface in English, then in Amharic. Then `GET /payroll/runs/{run}/register-csv` | Run page: `payroll-register-September-2026.csv`, headers in the reader's language: Employee Code, Employee, Basic, Overtime, Allowances, Gross, Taxable, Income Tax, Employee Pension 7%, Employer Pension 11%, Loan, Cost Sharing, Net Pay; a last row "TOTAL", the number of employees, then each column's total; totals equal the run summary. API: the same columns with "(ETB)" after each amount heading, "Employee Name", and a TOTAL row reading "N employees" |
| OUT-004 | P2 | R | Income tax schedule | Statutory → income tax: preview, then download CSV and PDF | Preview without TINs; download with TINs, start date, basic, overtime, allowances, taxable, tax; each download audited `payroll.statutory_export`. Missing employer TIN prints "NOT SET"; the header status reads "Approved", "Approved and paid" or "NOT APPROVED" (draft figures). The period is named in the organisation's calendar, in English (the agency's layout): "Meskerem 2019" for P1 in an organisation on the Ethiopian calendar, "September 2026" on the Gregorian (#520) |
| OUT-005 | P2 | R | Pension schedule | Statutory → pension | Per employee pensionable amount and both contributions with the rate headers; exempt employees left out |
| OUT-006 | P2 | R | Payslip PDF | EMP: My Payslips → download. FIN: download E-PAY-9's payslip from run C, and E-PAY-7's from run B | A5 portrait, `payslip-<id>.pdf`; earnings (basic, each allowance by name, gross), deductions (income tax, employee pension with its rate, "Loan repayment", "Cost sharing"), net, employer pension with its rate; Amharic names correct; a voided run's payslip is watermarked "VOIDED". Basic is printed already reduced by unpaid leave, sick leave and lateness (K-37). **From the code no overtime line is printed**: E-PAY-9's earnings list basic 8,000.00 and gross 8,150.00 with nothing for the 150.00 between them. Record a defect (the payslip does not add up) |
| OUT-007 | P1 | R | Payslip visibility | EMP opens payslips before approval; EMP opens EMP2's payslip by id | None before approval; visible once approved or paid; EMP2's refused |
| OUT-008 | P1 | R | Journal | Accounting → journal and CSV export for run A (EMP's 1,000.00 loan and EMP2's cost sharing in it) | Debits equal credits; salary expense = gross; pension expense = employer pension; tax payable = tax; pension payable = employee and employer pension; 2300 Net Salary Payable = net, the same as the bank file total; 1400 Employee Loans Receivable credited with the loans withheld (1,000.00 here); 2400 Cost Sharing Payable credited with the cost sharing withheld; TOTALS row. A run with neither keeps six lines. 2300 equal to net plus those deductions is the old rule (#566 in #624): a defect |
| OUT-009 | P2 | R | Totals agree | Compare run summary, register, bank file total, journal and statutory schedules | All agree to the cent; the journal's 2300 equals the bank file total |
| OUT-010 | P2 | R | Downgraded plan | QA Isolation after a downgrade opens an old run's register and bank file | Still readable |
| OUT-011 | P2 | R | Payslip in the reader's language and calendar (#520) | FIN downloads one payslip of run A four times: interface English on the Gregorian calendar; English on the Ethiopian calendar; አማርኛ on the Ethiopian calendar; Afaan Oromoo on the Ethiopian calendar. P1 is Meskerem 2019 (2026-09-11 to 2026-10-10, middle day 2026-09-25) | The month is named by the period's middle day in the downloader's calendar: "Payslip for September 2026"; "Payslip for Meskerem 2019"; «የመስከረም 2019 የደመወዝ ወረቀት» with amounts in ብር; "Waraqaa mindaa Fulbaana 2019" with amounts in Birrii. The footer's "Generated on :date. This is a computer-generated document." gives the date in the reader's calendar and the time in their clock. Ethiopic text never prints as `??` or empty boxes (L10N-008). The figures are identical in all four |
| OUT-012 | P2 | R | The bank file names who cannot be paid from it (#582 in #624) | Approved run A, in which the golden employees have no bank account: run page → bank file | The file still has every row and its total equals the run. A warning stays on screen: "Bank transfer file downloaded. :count people have no account number and cannot be paid from it: :names.", naming the first five as "Name (code)" and then "and :count more". A run with exactly one such person reads "Bank transfer file downloaded. 1 person has no account number and cannot be paid from it: :names."; with none, "Bank transfer file downloaded" |

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
| RCN-001 | P2 | R | Off by default | TA: Settings → Attendance → "Reconcile attendance before payroll". Open a completed run | Off. No reconciliation banner on the run page; approval behaves exactly as PAY-016 |
| RCN-002 | P2 | W | Who changes the mode | HR sets Advisory (API); HR saves another attendance setting with the mode unchanged; TA sets Advisory | HR: control disabled, API 403; HR's other change saved. TA: saved and audited |
| RCN-003 | P2 | R | Reconcile tab | FIN: Payroll → Reconcile, From/To = P3; "Show which": "Still to settle", then "Everything"; then a 93-day range. HR and SUP open `/payroll?tab=reconcile` and call the API | Rows by employee: what, date, state ("Open", "Waiting", "Settled"), counts ":count open", ":count waiting", ":count settled"; ":count device punches matched to no one" when any. The default range is today's Gregorian month, so set the Ethiopian period yourself. 93 days: "Reconcile at most 92 days at a time." HR and SUP: no Payroll page, API 403 |
| RCN-004 | P1 | W | Settle absences as leave | TA: Reconcile, tick two "Absent, nothing on record" days of E-REC-1 → "Settle 2 selected"; Unpaid Leave for one, Excused Absence for the other, a reason → "File for approval". TA then tries to approve them | Offered: only Unpaid Leave, Excused Absence, Official Duty / Business Trip; hint "Unpaid leave is deducted once approved. Excused absence and business trips are paid." "2 request(s) filed for approval."; both rows "Waiting". TA approving: "You filed this request, so someone else must approve it." Once HR approves, both days leave the list |
| RCN-005 | P2 | W | Finance settling | FIN ticks an open absence and an open late arrival → Settle | From the code, each is refused (FIN holds neither `leave.adjustBalance` nor `attendance.manage`) and the dialog shows the refusal. Record a defect if finance is meant to settle from this tab (K-22) |
| RCN-006 | P1 | W | Settled absence in pay | Run C (no allowance rules). E-REC-1: basic 6,600.00, the two RCN-004 days approved | Unpaid day 6,600.00 ÷ 22 = 300.00 deducted; excused day nothing. Basic 6,300.00, gross 6,300.00, tax 760.00, employee pension 441.00, net 5,099.00, employer pension 693.00. E-REC-1's other absences flagged, not deducted |
| RCN-007 | P2 | W | Required mode refuses | TA sets Required. Run C completed with N items open or waiting. TA approves | 422 "Attendance for this period is not reconciled yet: N exceptions are still open or waiting for a decision. Settle them on the Reconcile tab first." N = the tab's open plus waiting. Run page: "Attendance for this period is not reconciled: :open open, :pending waiting for a decision.", "This run cannot be approved until they are settled.", "Open Reconcile". QA Audit employees with no attendance (G1 to G8 and the extra test data of this file) have every past workday open; settle each with one Excused Absence if N must reach 0. E-PAY-9's 120 overtime minutes are listed as "Overtime past what was approved" when overtime needs approval |
| RCN-008 | P2 | W | Decided after processing | Required mode, nothing blocking. Process; then approve any correction, leave or overtime request dated in the period; approve the run; then void, reprocess, approve | First approval: 422 "Attendance for this period was decided after the run was calculated. Reprocess the run so it pays from the decisions." After reprocessing: approved |
| RCN-009 | P2 | W | Required mode passes | A `uat-` organisation with two employees: settle every item, process, approve | "Attendance for this period is reconciled."; approved |
| RCN-010 | P2 | W | Advisory | Advisory, items open, approve | Banner lists them with "Check them before approving."; approved |
| RCN-011 | P2 | R | What counts | In P3 produce: an open high-severity integrity flag, a pending duplicate-punch conflict, a check-in open over 14 h, an uncorrected auto check-out, overtime past what was approved (approval required). Also overtime over a limit with approval not required, and late records with lateness deductions off | Listed as "Punch flagged for review", "Duplicate punches", "Check-out missing", "Closed by auto check-out", "Overtime past what was approved", each linking to its screen and blocking. Overtime with no approval requirement is not listed (it is on the overtime banner). Late or early records listed only when lateness deductions are on |
| RCN-012 | P2 | R | My exceptions | EMP: `GET /attendance/my-exceptions`; then a 93-day range | Own items only; default range the 1st of this month to today. 93 days: "Reconcile at most 92 days at a time." |

## 9. Lateness deductions (Labour Proclamation 1156/2019, Art. 59)

A lateness decision is an attendance correction with `kind: lateness` (`POST /attendance/corrections`,
`deduct_minutes` 0 to 1,440; 0 waives). It moves no punch. HR or TA file it for others
(`attendance.manage`), or the employee on their own record. Approval needs `correction.approve` plus
`payroll.process` (FIN, TA). Deduction = minutes × (basic ÷ working days per month, rounded to the
cent) ÷ 480, taken from basic after unpaid leave and sick leave and before overtime, allowances, tax
and pension. One third of that basic (rounded down) is shared with loans and cost sharing estimated
on basic; lateness takes only what is left.

Worked figures, run C (no allowance rules; each basic 8,800.00, daily rate 400.00 = 8,800.00 ÷ 22,
the default month length, PAY-034):

| Employee | Decided minutes | Loan instalment | Third | Lateness | Basic after | Tax | Employee pension | Loan | Net | Employer pension |
|---|---|---|---|---|---|---|---|---|---|---|
| E-LATE-1 | 90 | none | 2,933.33 | 75.00 | 8,725.00 | 1,331.25 | 610.75 | 0.00 | 6,783.00 | 959.75 |
| E-LATE-2 | 90 | 2,900.00 | 2,933.33 | 33.33 | 8,766.67 | 1,341.67 | 613.67 | 2,900.00 | 3,911.33 | 964.33 |
| E-LATE-3 | 0 | 3,000.00 | 2,933.33 | 0.00 | 8,800.00 | 1,350.00 | 616.00 | 3,000.00 | 3,834.00 | 968.00 |

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LATE-001 | P2 | W | Switch on | TA: Settings → Attendance → "Lateness deductions": on with the basis empty, then with "QA work rules clause 12" | Empty: 422 "State the basis for lateness deductions, such as the work rules or collective agreement clause, before switching them on." With a basis: saved. HR: card disabled, API 403 |
| LATE-002 | P2 | W | Decision rules | API: a decision while deductions are off; on a present record; on a record checked in 08:46 on S1 with 17 minutes; a second while the first is pending; 0 minutes | "Your organisation has not switched on lateness deductions."; "This record is not a late arrival or an early departure."; "At most 16 minutes can be deducted for this record."; "A lateness decision for this record is already waiting."; 0 accepted |
| LATE-003 | P2 | W | Who approves | A pending decision for E-LATE-1: SUP approves; HR approves; SUP rejects another with a reason; FIN approves | SUP and HR: 403 "A lateness deduction changes pay, so it must be approved by someone who processes payroll." SUP's rejection allowed. FIN: approved. Punches and status unchanged (still late); the corrections list shows "Lateness" with ":minutes min" |
| LATE-004 | P2 | R | Minutes measured | Manual entries on S1 (08:30 to 17:30, grace 15, early 15): 08:46 to 17:30; another day 08:35 to 17:10 | Late 16 minutes (from shift start, not from the end of grace). Left early 20 minutes. Reconcile shows these minutes; "Deduct the minutes missed" files exactly them |
| LATE-005 | P1 | W | Deducted in pay | E-LATE-1: late 08:46, 09:00, 09:14 (16 + 30 + 44 = 90 min), all decided in full and approved; run C | As the table: 90 × 40,000 ÷ 480 = 7,500 cents. Log step `lateness`: `deducted_minutes` 90, `uncapped_cents` 7500, `third_of_basic_cents` 293333, `cap_cents` 293333, `deduction_cents` 7500 |
| LATE-006 | P1 | W | One third shared with loans | E-LATE-2: loan 6,000.00 at 2,900.00 a month, the same 90 minutes; run C | Lateness 33.33 (293,333 − 290,000 = 3,333 cents), `capped` true. Loan deducted in full. Not on the ceiling banner (33.33 + 2,900.00 equals the third exactly) |
| LATE-007 | P1 | W | Ceiling banner | E-LATE-3: loan 3,000.00 a month, no lateness; run C | Lateness 0.00. Banner "Deductions over a third of basic pay": "1 payslip(s) deduct more than a third of basic pay for lateness, loans and cost sharing together.", E-LATE-3 3,000.00 ETB / 2,933.33 ETB. Loan not reduced; approval not blocked. Anyone whose loans pass the third is listed while deductions are on |
| LATE-008 | P1 | W | Switched off again | After LATE-005, TA switches deductions off; void and reprocess run C | E-LATE-1 paid on the full 8,800.00 (tax 1,350.00, pension 616.00); no `lateness` step, no ceiling banner. The approved decisions stay on the records |

## 10. Payment

`PUT /payroll/runs/{run}/paid` (`payroll.markPaid`: FIN, TA) takes `paid_on` (Y-m-d, not after
today), `payment_reference` (up to 120 characters) and `payment_note` (up to 500). Audited
`payroll.paid`; sends the `payroll.paid` webhook. **A paid run cannot be voided, so its period can
never be run again in that organisation.**

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PAID-001 | P1 | X | Record payment | FIN on the last approved run of the pass (A′, P1) → "Confirm payment": date paid, reference "QA-NOT-PAID", note "QA" | Dialog: "Record the day the bank paid this run. A paid run cannot be voided: a mistake in it is corrected in the next payroll run." Then "Payment recorded"; status paid; the "Paid" step shows the date, reference and note; audit `payroll.paid`; webhook `payroll.paid` with `public_id`, `period`, `paid_on`. Before payment the step reads "Confirm the payment once the bank has sent it." |
| PAID-002 | P2 | W | Rules | `paid_on` tomorrow; a 121-character reference; a 501-character note; payment on a completed run; again on the paid run | Each refused. Completed or paid run: 422 "Payment can only be recorded for an approved payroll run." |
| PAID-003 | P2 | R | Rights | HR, SUP, EMP call the endpoint | 403. Only FIN and TA see the button |
| PAID-004 | P2 | R | No void | TA voids the paid run (API); open the run page | 422 "This payroll run has been paid, so it cannot be voided. Correct it in the next payroll run." No Void or Reprocess button. Approving it: "Payroll run has not been completed yet." |
| PAID-005 | P2 | R | Paid counts as released | On the paid run: payslips; bank file; income tax schedule header; a manual entry in P1; a new run for P1 | Payslips visible; bank file downloads again; header "Approved and paid"; the manual entry: "This day is in an approved payroll run, so its attendance cannot be changed. Void the run first to reopen it."; a new run: "A payroll run already covers :start to :end (:label). Void that run first if this period must be computed again." |

## 11. Statutory rules by kind of organisation

Every statutory value is a dated row (`statutory_parameters`, `tax_brackets`, `pension_rules`),
optionally scoped to a kind of organisation; an organisation's own setting wins where the row allows
it. Since #642 the month length (`payroll.working_days_per_month`) is one of those an organisation
may set (PAY-032), and has one platform row per kind of organisation, all 22 and unconfirmed until
the accountant's sign-off. `GET /organization-types` is public. `PUT /settings/organization` with
`organization_type` needs `settings.manage` (TA). Calculation log version 1.7.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| STAT-001 | P2 | W | Kind of organisation | TA: Settings → Organisation; confirm the kind shown | "Kind of organisation in law", help "Decides the statutory payroll rules: the pension scheme and the fiscal year." Until confirmed: "Confirm the kind of organisation below. Payroll applies the pension scheme and fiscal year set for it in law." After confirming the notice is gone; audit `settings.organization_updated` with from and to. Options: Private organisation, Public enterprise, Government office (civil service), Charity or society, International organisation or embassy, Defence or police |
| STAT-002 | P1 | W | Existing organisation keeps its pay | Change QA Audit's kind to Government office; open Settings → Payroll; change it back | Pension scheme still `private`, fiscal year still Meskerem (written into every existing organisation's settings by the upgrade). Golden figures do not move |
| STAT-003 | P2 | W | New organisation defaults | Register a `uat-` organisation with type Government, and another with Bank; open Settings → Payroll in each | Government: Government office (flagged for review), scheme `public`, fiscal year start month 11 (Hamle). Bank: Private organisation, `private`, Meskerem |
| STAT-004 | P1 | W | Military scheme by kind | A `uat-` organisation that never saved a pension scheme: kind Defence or police; process an employee with basic 1,800.00 | Payroll settings show `public_military`. Employee pension 126.00, employer 594.00 (33%), net 1,674.00 |
| STAT-005 | P2 | R | Log 1.7 | Any entry's calculation log in run A | `version` 1.7, `rounding` `integer_half_up`. `statutory_rules` holds the kind of organisation and, per code, value, source (`platform`, `platform_type`, `tenant`, `tenant_setting`), legal reference and whether confirmed. `payroll.working_days_per_month` is among them (PAY-034). The income tax step's `rule.brackets` is the whole six-band ladder |

## 12. Sick leave in pay (Labour Proclamation 1156/2019, Art. 85)

Since #637 a leave type marked `follows_art85` (every type with the code `sick`, which is the setup
wizard's "Sick Leave") pays by each day's place in a twelve-month period that starts on the first
sick day not already in one: the first month in full, the next two months at half the daily rate,
the last three months unpaid. A month is the working days per month (22 by default), counted in the
person's charged days, so days 1 to 22 are full pay, 23 to 66 half pay and 67 to 132 unpaid. The
daily rate is the one unpaid leave uses (monthly basic ÷ working days per month), and the deduction
is taken from basic after unpaid leave, before lateness, and so lowers pension too. A request past
six months is refused with the leave file's message (06). Art. 85 days are never counted as unpaid
leave as well. The calculation dialog shows the line "Sick leave (Art. 85)", ":half days at half pay,
:unpaid days unpaid"; the payslip shows only the reduced basic (K-37). HR files past days on the
employee's behalf (`employee_public_id`); someone else approves.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SICK-001 | P2 | W | The first month is on record | Before run A: HR files "Sick Leave" for E-SICK-1 on their behalf, dated before P1, totalling exactly 22 charged days (each request shows its days; split around a holiday), the first less than twelve months before the end of P3; TA approves each | Accepted and approved; no balance refusal (Art. 85 types are not held to the annual 30 days). Runs A and B deduct nothing for them (they fall outside P1 and P2) |
| SICK-002 | P1 | W | Half pay from day 23 (#637) | Before run C: HR files 5 charged days of Sick Leave for E-SICK-1 inside P3 (days 23 to 27 of the twelve-month period); TA approves. Run C. Open E-SICK-1 and the calculation log | Half pay on 5 days: 5 × 400.00 × ½ = 1,000.00 (log `sick_leave`: `half_pay_days` 5, `unpaid_days` 0, `daily_rate_cents` 40000, `deduction_cents` 100000, `basic_after_sick_leave_cents` 780000). Basic 7,800.00; gross 7,800.00; tax 7,800 × 25% − 850 = 1,100.00; employee pension 546.00; employer pension 858.00; net 7,800.00 − 1,100.00 − 546.00 = 6,154.00. No `unpaid_leave` deduction. The dialog row reads "5 days at half pay, 0 days unpaid" |
| SICK-003 | P1 | W | The first month is paid in full (#637) | Before run C: 5 charged days of Sick Leave for E-SICK-2 inside P3, approved (E-SICK-2 has no earlier sick leave). Run C | No deduction: `sick_leave.deduction_cents` 0. Basic 8,800.00; tax 1,350.00; employee pension 616.00; net 6,834.00; employer pension 968.00 |
| SICK-004 | P1 | W | Sick leave dated in an approved period | After PAID-001: HR files 1 charged day of Sick Leave for E-SICK-1 dated in P1 (now paid); TA approves | From the code it is approved with no refusal: the approved-period check (PAY-038) covers unpaid types only, and Sick Leave is a paid type. That day becomes the 23rd of E-SICK-1's period, a half-pay day of 200.00 that no run will ever deduct, and it moves each later sick day one place along. **Record a defect** (a deduction lost, as A-Z review E-03 was for unpaid leave) unless the owner decides otherwise. Leave the request in place; it is QA data |

---

*Figures in sections 2, 4, 5, 9 and 12 were worked from `PayrollEngine`, `SickLeaveTiers`,
`AllowanceService`, `OvertimeCalculator` and `Cents` at `af68fa4959`; the month length 20 in PAY-033
is the only override this file uses.*
