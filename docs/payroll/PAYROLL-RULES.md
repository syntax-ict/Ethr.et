# Payroll rules: the register

Every rule the payroll engine applies when it turns a salary, attendance, leave, loans and the statutory rules into one person's payslip, in one place, each with an id. This is the specification that hand-valued golden payslips and an independent oracle are written against, by people who may read only this file. How a minute of attendance is counted is in [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md); this file starts where those minutes arrive and ends at the bank file.

**When a rule changes, change it here first**, then the code, then the golden case that cites its id. Other documents link to a rule id and do not restate its numbers. A deliberate change to a figure is also entered in [PAY-CHANGES.md](PAY-CHANGES.md).

Rule ids are `PYR-<AREA>-NN`. The prefix `PAY-` belongs to the test cases of 07 and is not used here.

## How to read a row

| State | Meaning |
|---|---|
| Decided | A document states it and the code was read to agree. The oracle implements it and a disagreement is a defect. |
| Implemented | The code does it and no document states it, or the code follows a value or choice that ETHR made and that still waits for confirmation (a statutory parameter with `confirmed` false, a research finding marked UNCONFIRMED, or a reading that SIGN rows 1, 2 or 7 to 14 ask the accountant to confirm and that was not taken from the law's own text at High confidence in RES). Every SIGN row is unsigned; rows 3 to 6 check examples, totals and policy and confirm no ETHR choice. The oracle copies it so that the tests can run, and the accuracy report counts these rules separately: agreement proves the behaviour is stable, not that it was chosen. |
| Undecided | ETHR's own documents disagree with each other or with the code and no later dated decision settles it, or no document picks an answer. The oracle does not guess: cases that depend on it are reported as "rule undecided", not as passes or failures. |

The line between the last two: a default ETHR chose and documented, which the code follows and the accountant has yet to confirm, is Implemented. Undecided is kept for a real conflict or a real gap.

Sources: **07** is [production testing/07-E2E-PAYROLL.md](<../production testing/07-E2E-PAYROLL.md>), **STAT** is [STATUTORY-PARAMETERS.md](STATUTORY-PARAMETERS.md), **SIGN** is [ACCOUNTANT-SIGN-OFF.md](ACCOUNTANT-SIGN-OFF.md) (prepared, not yet signed), **RES** is [STATUTORY-RULES-RESEARCH.md](STATUTORY-RULES-RESEARCH.md), **CHG** is [PAY-CHANGES.md](PAY-CHANGES.md), **LAW** is [EMPLOYMENT-LAW-REGISTER.md](../EMPLOYMENT-LAW-REGISTER.md), **AR** is [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md). A path under `api/` is the code that was read; `PayrollEngine` is `api/app/Services/Payroll/PayrollEngine.php`, and its arithmetic is `computeEntry()`.

All money is integer cents. 1.00 ETB is 100 cents. "Rounded" alone means rounded half away from zero to a whole cent (PYR-ROUND-01).

## 1. Period and calendar

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-PER-01 | A run covers the dates it is given, start to end inclusive, with the end after the start. The engine has no notion of a calendar month: an Ethiopian month (Meskerem 2019 is 2026-09-11 to 2026-10-10) and a Gregorian month are both just two dates. The period's length is its calendar days: 2026-09-11 to 2026-10-10 is 30. | 07 PAY-010; `api/app/Http/Requests/Payroll/ProcessPayrollRequest.php` rules; `PayrollEngine` line 1138 | Implemented |
| PYR-PER-02 | One live run per period: a run whose dates overlap any run that is not voided or failed is refused. | 07 PAY-040; `PayrollEngine::reserve()` | Decided |
| PYR-PER-03 | Every statutory value, the tax ladder, the pension rule and the overtime floors are resolved once, for the period's **start** date. A June 2025 period keeps June 2025's law when it is reprocessed in 2026. The exceptions are PYR-PER-06. | 07 section 1.1; `PayrollEngine::prepare()` | Decided |
| PYR-PER-06 | Exceptions to PYR-PER-03. Read at the period's **end**: the salary (PYR-SAL-01), pension minimum service (counted from the hire date to the period end) and the contract type that `pension.applies_to` tests. Read when the run is computed, with no date: whether lateness deductions are switched on, the organisation's own overtime rates (PYR-OT-03), its allowance rules (PYR-ALW-06) and its active loans (PYR-LOAN-05). | `PensionCalculator::exemption()` lines 137 to 139, `contractExemption()` line 161; `PayrollEngine` line 1246, `latenessDeductionEnabled()` | Implemented |
| PYR-PER-04 | `fiscal_year.start_month` is resolved and logged but changes no figure on a payslip. | `PayrollEngine` lines 1078, 1129 (log only) | Implemented |
| PYR-PER-05 | The run's stored label is the English Gregorian month of the period start: Meskerem 2019 is stored "September 2026". | 07 section 7; `PayrollEngine::begin()` | Decided |

## 2. Which salary pays the period

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-SAL-01 | The salary that pays the period (`monthly_basic`) is found in this order. (1) An employee with any salary history row is paid the row with the latest effective date on or before the period's **last** day; on an equal effective date the row created later wins. With no row on or before that day, the earliest row is paid (on an equal date, the one created first). A row below 0 counts as 0, and a row superseded by a later correction is never read. (2) An employee with no history row at all is paid the salary on the record. (3) If the figure from (1) or (2) is 0 or less, the contracts with a salary above 0 that overlap the period are read. A contract overlaps when it starts on or before the period end and its effective end (`ended_at`, else `end_date`, else none) is none or on or after the period start. Taking them latest start first, the first one in force on the period end (effective end none, or on or after the period end) pays; else the latest-starting overlapping one; else the salary is 0. Two contracts with the same start date have no defined order, so a test case must not use them. In a test case, history rows and contracts are created in the order listed, so a later list item is the one created later. | `PayrollEngine::salaryFor()` lines 701 to 722, `contractSalaryFor()` lines 631 to 657; `api/app/Services/Payroll/SalaryInForce.php` `on()` | Implemented |
| PYR-SAL-02 | A salary on a contract with none on the record is paid. G7's E-PAY-7 is paid 8,800.00 from a contract. | 07 PAY-020, PAY-028 | Decided |
| PYR-SAL-03 | One salary pays the whole period. A change dated inside the period does not split it by days. `salary_changed_in_period` is 1 when a history row other than the person's earliest (earliest by effective date, then by creation) takes effect after the period start and on or before the period end, else 0; it moves no figure. A raise from 6,000.00 to 9,000.00 effective on day 16 of a 30-day period pays 9,000.00, not 7,500.00, with the flag 1. SIGN row 14 asks the accountant to confirm or to rule for days. | SIGN row 14; `PayrollEngine` lines 1088 to 1096, 1122; `SalaryInForce::changedWithin()` | Implemented |
| PYR-SAL-04 | A person with no salary from any source (PYR-SAL-01 gives 0 or less) is not in the run and is counted as skipped. `run.skipped` = the number of people the run pays by PYR-SAL-05 who are not deleted, minus the number of entries written. | 07 section 1.4, PAY-013; `PayrollEngine::runEntries()` line 279 | Decided |
| PYR-SAL-05 | The people a run pays: status hired, probation or confirmed; and anyone resigned, terminated or retired whose termination date is on or after the period start. | 07 section 1.4, PAY-039; `PayrollEngine::payableEmployees()` | Decided |
| PYR-SAL-06 | A person in another status (suspended, for example) or a removed (soft-deleted) record is not paid and not counted as skipped. | `PayrollEngine::payableEmployees()`, `runEntries()` skipped count | Implemented |
| PYR-SAL-07 | `in_run` is 1, and the run has an entry for the person, exactly when three things hold: a payable status by PYR-SAL-05, the person is not deleted (PYR-SAL-06), and PYR-SAL-01 gives a salary above 0. The hire date is not read: a person hired after the period's end is in the run (PYR-PRO-04). `run.entries` is the number of such people. | `PayrollEngine::payableEmployees()`, `computeEntry()` lines 1098 to 1100 | Implemented |

## 3. Proration for joiners and leavers

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-PRO-01 | `days_employed` runs from the later of the hire date and the period start to the earlier of the termination date and the period end, both days included, and is 0 when the first of those days is after the last. A termination date outside the period changes nothing. `days_in_period` is the period's calendar days (PYR-PER-01). | 07 section 1.4, PAY-037, PAY-039; `PayrollEngine` lines 1138 to 1149, `lastPaidDay()` | Decided |
| PYR-PRO-02 | Prorated basic is the period basic (PYR-PAG) times days employed over the period's calendar days, rounded once. Hired on day 21 of a 30-day period: 6,000.00 × 10 / 30 = 2,000.00. Hired on the 25th of a 31-day month: 7/31. The month length does not matter: 28, 29, 30 and 31 are each the divisor of their own period. | 07 PAY-037; CHG 2026-11-04; `PayrollEngine` line 1175 | Decided |
| PYR-PRO-03 | The termination day itself is paid: a last day on the 10th of a 30-day period pays 10/30. | `PayrollEngine::lastPaidDay()` (inclusive) | Implemented |
| PYR-PRO-04 | A person in a payable status whose hire date is after the period's end has 0 days employed and still gets an entry: basic 0.00, and loans still deduct (PYR-NET-02). | `PayrollEngine` lines 1141 to 1146; `payableEmployees()` does not read the hire date | Implemented |

## 4. Pagume

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-PAG-01 | The default strategy is `full_month` (STAT `pagume.proration`, not confirmed). An organisation may choose `daily_rate`. SIGN row 8 asks which the organisation uses. | STAT; SIGN row 8 | Implemented |
| PYR-PAG-02 | Under `full_month`, or in any period that holds no Pagume day, the period basic is the monthly basic, however many days the period has. A period that runs from Nehase into Pagume (35 or 36 days) pays one month's basic. | 07 section 1.4; SIGN section 1 step 2 (Pagume days paid "as part of the month"); `PayrollEngine` lines 1157 to 1173 | Implemented |
| PYR-PAG-06 | What a period whose every day is Pagume (5 or 6 days) pays under `full_month`. The code pays a whole month's basic as `period_basic` (lines 1171 to 1175): 6,000.00. SIGN section 1 step 2 says the Pagume days are "paid as part of the month", and STAT's organisation types section speaks of an "organisation month (never Pagume)", both of which read as Pagume having no pay period of its own. The settings screen labels the choice "A full month". The reading tested: `period_basic` 0 for such a period, every later figure following from it; no source says how a period that holds Pagume and other days would differ, so that reading applies only when every day of the period is Pagume. | SIGN section 1 step 2; STAT "Organisation types"; `PayrollEngine` lines 1171 to 1175 | Undecided |
| PYR-PAG-03 | Under `daily_rate`, the period's Pagume days are paid at monthly basic × 12 × Pagume days / 365, rounded; any other days of the same period are paid monthly basic × those days / the period's days, rounded; the two are added. Basic 6,000.00 for a 5-day Pagume period: 600,000 × 60 / 365 = 98,630.14 cents, rounded 98,630 (986.30 ETB). | 07 section 1.4; `PayrollEngine` lines 1160 to 1170 | Implemented |
| PYR-PAG-04 | The divisor is 365 even in a year whose Pagume has 6 days. Basic 6,000.00 for a 6-day Pagume: 600,000 × 72 / 365 = 118,356.16 cents, rounded 118,356 (1,183.56 ETB); by 366: 118,032.79, so 1,180.33 ETB. No document gives an exception; 07 states 365. | 07 section 1.4; `PayrollEngine` line 1163 | Implemented |
| PYR-PAG-05 | The order of the Pagume step and proration for days employed. 07 section 1.4 and the code apply Pagume first and prorate its rounded result (lines 1151 to 1156, 1175); SIGN section 1 lists proration as step 1 and Pagume as step 2. Under `full_month`, and in a period with no Pagume day, the order moves nothing. Under `daily_rate` it moves the rounding. The code: `prorated_basic` = round(`period_basic` × days employed / period days). The reading tested, which SIGN does not spell out: first B' = round(monthly basic × days employed / period days), then `prorated_basic` = round(B' × 12 × Pagume days / 365) + round(B' × other days / period days), the second term 0 when there are no other days; `period_basic` itself is unchanged. Hired on Pagume 3 of a 5-day Pagume, basic 12,345.67: code 121,766 cents, reading tested 121,765. A mixed period Nehase 1 to Pagume 5 (2026-08-07 to 2026-09-10, 35 days, 5 of them Pagume), hired 2026-08-23 (19 days), basic 12,345.67: code `period_basic` = 202,943 + 1,058,200 = 1,261,143, `prorated_basic` = 1,261,143 × 19 / 35 = 684,620.49, so 684,620; reading tested B' = 670,193.51, so 670,194, then 110,169 + 574,452 = 684,621. | 07 section 1.4; SIGN section 1 steps 1 and 2 | Undecided |

## 5. Daily and hourly rate

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-RATE-01 | `daily_rate` = round(monthly basic of PYR-SAL-01 / `payroll.working_days_per_month`), before proration, Pagume and leave. 8,800.00 / 22 = 400.00. Unpaid leave, sick leave and lateness all read this one rounded figure (whether they should is PYR-ROUND-04). The 22 is not confirmed. | 07 section 1.4, PAY-034; SIGN section 1 step 3; `PayrollEngine` line 1199 | Implemented |
| PYR-RATE-03 | The hourly rate for overtime is the monthly basic divided by (`payroll.working_days_per_month` × `payroll.hours_per_day`): 8,800.00 / 176 = 50.00. It is never rounded on its own (PYR-OT-01). The 22 is not confirmed. | 07 section 1.3; CHG 2026-11-03 | Implemented |

## 6. Unpaid leave

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-LEAVE-01 | Unpaid days are approved leave of a type that is not paid and is not an Art. 85 sick type, overlapping the period. A request wholly inside the period counts its stored days; one crossing a period edge is clipped to the period and its days recounted on the person's own working days (rotation, then shift, then the organisation's week). Every request path stores a whole number of days, so there is no half day (LVR-DAYS-03). | CHG 2026-11-04 (A-Z E-06); 07 section 1.4; LVR-DAYS-03 in [LEAVE-RULES.md](../LEAVE-RULES.md); `PayrollEngine::gatherUnpaidLeaveDays()`; `LeaveDayCalculator::calculateDays()` (a count of whole dates) | Decided |
| PYR-LEAVE-06 | The column `leave_requests.days` holds one decimal place (`decimal(5,1)`), so a row written straight into the database can carry tenths of a day. The engine deducts such a fraction: the days are taken in thousandths, round(days × 1000). No request, recall or import path writes one; a test case that uses a fraction is fixture-only. | `0001_01_01_000005_create_leave_tables.php` line 60; `PayrollEngine` line 1203 | Implemented |
| PYR-LEAVE-02 | `unpaid_leave_deduction` is 0 with no unpaid days; otherwise the smaller of round(`daily_rate` × thousandths of a day / 1000) and `prorated_basic`. `basic_after_unpaid_leave` = `prorated_basic` − that. 2 days (2,000 thousandths) × 400.00 = 800.00; G7 basic 8,800.00 becomes 8,000.00. The 22 is not confirmed. | 07 G7, RCN-006; SIGN section 1 step 3; `PayrollEngine` lines 1199 to 1205 | Implemented |
| PYR-LEAVE-03 | Days are carried in thousandths of a day, and the deduction is never more than the prorated basic left. | `PayrollEngine` lines 1202 to 1205 | Implemented |
| PYR-LEAVE-05 | Unpaid and sick days are counted over the whole period, not only over the days employed: a day of unpaid leave before a joiner's hire date or after a leaver's last day still deducts. The only limit is that the deduction never exceeds the prorated basic left (PYR-LEAVE-03, PYR-SICK-02). | `PayrollEngine::gatherUnpaidLeaveDays()` lines 1505 to 1541 (clipped to the period dates only), `SickLeaveTiers::inPeriod()`; `PayrollEngine` lines 1203, 1228 | Implemented |
| PYR-LEAVE-04 | Unpaid leave dated in an approved or paid period is refused, so it never reaches a run that is already released. | 07 PAY-038 | Decided |

## 7. Sick leave (Labour Proclamation 1156/2019, Art. 85)

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-SICK-01 | Each charged day of a sick type marked `follows_art85` takes its place in a twelve-month window that starts on the first sick day not already in a window. With 22 working days a month: days 1 to 22 full pay, 23 to 66 half pay, 67 to 132 unpaid; a day past six months that was approved anyway is unpaid. How days are placed is `api/app/Services/Leave/SickLeaveTiers.php` `tiersOf()`, which was not read in full for this register. | 07 section 12; CHG 2026-11-06 (A-Z E-08); STAT `leave.sick.*` (not confirmed); `SickLeaveTiers::inPeriod()` | Implemented |
| PYR-SICK-02 | Withheld thousandths th = unpaid days × 1000 + half-pay days × w, where w = 1000 × (denominator − numerator) / denominator of `leave.sick.half_pay_ratio`. The ratio is locked at 1/2, so w is exactly 500; th is rounded to a whole number once. Full-pay days withhold nothing. `sick_deduction` is 0 when th is 0, otherwise the smaller of round(`daily_rate` × th / 1000) and `basic_after_unpaid_leave`. `basic_after_sick_leave` = `basic_after_unpaid_leave` − that. 5 half-pay days at 400.00: th 2,500, 40,000 × 2,500 / 1,000 = 100,000 cents; basic 8,800.00 becomes 7,800.00. | 07 SICK-002; `PayrollEngine` lines 1220 to 1230 | Implemented |
| PYR-SICK-03 | An Art. 85 day is never also counted as unpaid leave. | 07 section 12; `gatherUnpaidLeaveDays()` skips `follows_art85` | Decided |
| PYR-SICK-04 | Sick leave dated in an approved or paid period is refused. | 07 SICK-004 | Decided |

## 8. Lateness deduction (Art. 59)

The minutes come from the attendance register: AR-LATE-01 and AR-LATE-02 decide which minutes may be deducted. This section turns them into cents.

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-LATE-01 | Nothing is deducted, and no `lateness` step is logged (`lateness_step_logged` 0), unless the organisation switched lateness deductions on. Then the minutes are the sum of `pay_deduct_minutes` on the person's records in the period that are not voided, and `lateness_step_logged` is 1. With deductions off, `basic` is `basic_after_sick_leave`. | 07 LATE-001, LATE-008; AR-LATE-01; `PayrollEngine` lines 1246, 1813 to 1848 | Decided |
| PYR-LATE-02 | `lateness_uncapped` = round(minutes × `daily_rate` / (`payroll.hours_per_day` × 60)). 90 minutes at 40,000 cents a day: 90 × 40,000 / 480 = 7,500 cents. The direction of that rounding is PYR-ROUND-05. The daily rate reads the unconfirmed 22. | 07 section 9, LATE-005; `PayrollEngine` line 1250 | Implemented |
| PYR-LATE-03 | `lateness_ceiling` = intdiv(base × numerator, denominator) of `payroll.deduction_cap_ratio` (one third, not confirmed), that is, rounded down. Which base is PYR-LATE-06; the code's is `basic_after_sick_leave`. With 880,000: 880,000 / 3 = 293,333 cents. | 07 section 9, LATE-005; `PayrollEngine` line 1261 (`intdiv`) | Implemented |
| PYR-LATE-06 | The base of the one-third ceiling. The code (line 1261) and 07 section 9 take `basic_after_sick_leave`. [APPROVAL-ENGINE-DESIGN.md](../APPROVAL-ENGINE-DESIGN.md) (line 303) says "a third of the period's basic"; STAT says "1/3 of the month's wage". The readings tested: "the period's basic" is `prorated_basic` (after proration and Pagume, before any leave); "the month's wage" is the monthly basic of PYR-SAL-01. Neither source defines its term further. Each reading changes only the ceiling; the cap and deduction follow by PYR-LATE-04. | 07 section 9; APPROVAL-ENGINE-DESIGN; STAT | Undecided |
| PYR-LATE-04 | Lateness takes only the headroom that loans and cost sharing leave under the ceiling. `lateness_loans_and_cost_sharing` = L + C, `lateness_cap` = max(0, ceiling − L − C), `lateness_deduction` = min(`lateness_uncapped`, cap), `basic_after_lateness` = `basic_after_sick_leave` − deduction. L is the sum over the person's active loans of max(0, min(instalment, remaining)); a loan that is not active adds 0. C is the cost sharing of the one obligation PYR-CS-05 picks, estimated on `basic_after_sick_leave`: 0 with no such obligation, or when that basic or the outstanding balance is 0 or less; otherwise min(intdiv(basic × r, 10,000), outstanding), r being the rate in hundredths of a percent (PYR-CS-06). Ceiling 293,333, loan 290,000: lateness 3,333 cents, not 7,500. | 07 section 9, LATE-006; `LoanService.php` lines 34, 37 to 41; `CostSharingService.php` lines 43 to 60; [APPROVAL-ENGINE-DESIGN.md](../APPROVAL-ENGINE-DESIGN.md) (one total, decided 2026-10-28); `PayrollEngine` lines 1262 to 1270 | Decided |
| PYR-LATE-05 | The deduction is taken from **basic**, after unpaid and sick leave, before overtime, allowances, tax and pension. It is not taken from gross. E-LATE-1: basic 8,725.00, pension 610.75. | 07 section 9, LATE-005; `PayrollEngine` line 1271 | Decided |

## 9. Absences

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-ABS-01 | Unexplained absences are listed on the run and never deducted. | 07 PAY-019, RCN-006; AR-ABS-06; `PayrollEngine` lines 1288 to 1292 | Decided |

## 10. Overtime pay

The minutes of each kind (normal, night, rest day, holiday, holiday night) come from the attendance register (AR-OT-01 to AR-OT-14). This section turns them into cents.

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-OT-01 | Pay for one kind (`overtime_normal`, `overtime_night`, `overtime_rest_day`, `overtime_holiday`, `overtime_holiday_night`) = round(monthly basic of PYR-SAL-01 × minutes × multiplier / (working days × hours per day × 60)), one fraction, rounded once; the monthly basic is taken before proration, leave and lateness. A kind with no minutes pays 0. `overtime` is the sum of the five, not rounded again. Basic 8,800.00, 120 normal minutes at 1.5: 880,000 × 120 × 1.5 / 10,560 = 15,000 cents. The 22 is not confirmed. | 07 section 1.3, G8, PAY-036; CHG 2026-11-03; AR-OT-05; `api/app/Services/Payroll/OvertimeCalculator.php` `calculate()` | Implemented |
| PYR-OT-02 | The multiplier for each kind is the larger of the organisation's own rate for that kind and the floor of `overtime.minimum_rates` for it; a kind the organisation did not set is paid at the floor. | 07 PAY-005; STAT (confirmed); `OvertimeCalculator::ratesFor()` lines 118 to 123 | Decided |
| PYR-OT-03 | The organisation's own rates are its overtime rule active at the time the run is computed, not a rule dated for the period. Reprocessing a past period after raising a rate pays the new rate. | `OvertimeCalculator::ratesFor()` (latest active `PayrollRule`, only the floor is dated) | Implemented |
| PYR-OT-04 | Only the period's own days are paid; a joiner's or leaver's overtime is paid at the full hourly rate. | 07 section 1.3; CHG 2026-11-03 | Decided |
| PYR-OT-05 | The daily and weekly limits and the approval requirement flag the run. Every minute is still paid. | 07 section 1.3, PAY-029; AR-OT-06 | Decided |

## 11. Allowances

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-ALW-01 | Every active allowance rule of the organisation applies to every person the run pays. There is no per-person assignment. | 07 "Before you start"; `api/app/Services/Payroll/AllowanceService.php` | Decided |
| PYR-ALW-02 | A percentage allowance is that percent of basic after proration, unpaid leave, sick leave and lateness, rounded. 10% of 8,000.00 = 800.00. | 07 section 1.4, G7; `AllowanceService::amountFor()`; `PayrollEngine` line 1336 | Decided |
| PYR-ALW-03 | A fixed allowance is its monthly amount times days employed over the period's days, rounded, and is not reduced by unpaid leave. Transport 600.00 for 10 of 30 days = 200.00. | 07 PAY-037; CHG 2026-11-04; `AllowanceService::resolve()` | Decided |
| PYR-ALW-04 | Each allowance is taxable or not by its own flag. | 07 G7; SIGN section 1 step 5 | Decided |
| PYR-ALW-05 | An allowance that comes to 0 or less is left off the payslip and out of every sum (`allowance.<key>` 0). `allowances_total` is the sum of the allowances paid; `allowances_taxable` and `allowances_non_taxable` split it by the taxable flag (PYR-ALW-04). None is rounded again. | `AllowanceService::resolve()` lines 57 to 81 | Implemented |
| PYR-ALW-06 | The rules applied are those active when the run is computed, not rules dated for the period. | `AllowanceService::resolve()` (no date predicate) | Implemented |
| PYR-ALW-07 | What a fixed allowance pays in a period that holds Pagume days under `daily_rate`. The code pays it as in PYR-ALW-03, ignoring the Pagume step. No source says how a Pagume rule would apply to an allowance. The reading tested: the basic's Pagume rule first, F' = round(F × 12 × Pagume days / 365) + round(F × other days / period days) (the second term 0 with no other days), then PYR-ALW-03's proration on F' when days employed are fewer than the period's. Transport 600.00, 5-day Pagume period: code 60,000 cents, reading tested 9,863. Hired on Pagume 3 of 5: code round(60,000 × 3 / 5) = 36,000; reading tested round(9,863 × 3 / 5) = 5,917.8, so 5,918. | none | Undecided |

## 12. Gross and taxable income

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-GROSS-01 | Gross = basic (after PYR-PRO, PYR-PAG, PYR-LEAVE, PYR-SICK, PYR-LATE) + overtime + all allowances. G7: 8,000.00 + 0 + 1,400.00 = 9,400.00. | 07 section 1.4; SIGN step 6; `PayrollEngine` line 1349 | Decided |
| PYR-GROSS-02 | Taxable income = gross − non-taxable allowances. Overtime is taxable. G7: 9,400.00 − 600.00 = 8,800.00. | 07 section 1.4, G7, G8; SIGN step 7; `PayrollEngine` line 1360 | Decided |

## 13. Income tax

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-TAX-01 | From 2025-07-07, Proclamation 1395/2025, monthly, in cents: 0 to 200,000 at 0%; 200,001 to 400,000 at 15% less 30,000; 400,001 to 700,000 at 20% less 50,000; 700,001 to 1,000,000 at 25% less 85,000; 1,000,001 to 1,400,000 at 30% less 135,000; 1,400,001 and over at 35% less 205,000. A taxable figure falls in the band whose floor and top contain it: 200,000 cents is in the first band. The ladder was not read in the Gazette (RES T1, Medium) and SIGN row 1 asks the accountant to confirm it is the schedule in force. | 07 section 1.1; SIGN section 1, row 1; `api/database/seeders/TaxBracketSeeder.php` `PROCLAMATION_1395_2025` | Implemented |
| PYR-TAX-02 | From 2016-07-08 to 2025-07-06, Proclamation 979/2016, in cents: 0 to 60,000 at 0%; 60,001 to 165,000 at 10% less 6,000; 165,001 to 320,000 at 15% less 14,250; 320,001 to 525,000 at 20% less 30,250; 525,001 to 780,000 at 25% less 56,500; 780,001 to 1,090,000 at 30% less 95,500; 1,090,001 and over at 35% less 150,000. 500,000 cents taxable: 697.50. Taken from secondary sources (RES T4, Medium). | 07 section 1.1, PAY-025; RES T4; `TaxBracketSeeder::PROCLAMATION_979_2016` | Implemented |
| PYR-TAX-03 | Tax = taxable × rate, rounded, minus the band's deduction. This quick form gives the cumulative form's answer to the cent, because each deduction is whole cents. G4: 900,000 × 25% − 85,000 = 140,000 cents; cumulatively 300 + 600 + 500 = 1,400.00. RND-001: 350,050 × 15% = 52,507.5, rounded 52,508, less 30,000 = 22,508. | 07 section 1.1, RND-001; SIGN section 2; `TaxCalculator::apply()` | Decided |
| PYR-TAX-04 | The ladder is the one in force on the period start (PYR-PER-03). The change of ladder falls between 6 and 7 July 2025. RES T3 records that sources disagree on 1, 7 or 8 July. A period starting 2025-07-01 with 5,000.00 taxable is taxed 697.50 on 979/2016; it would be 500.00 on 1395/2025. | 07 section 1.1; RES T3 (UNCONFIRMED); `TaxCalculatorTest` "the boundary falls between 6 and 7 July 2025" | Implemented |
| PYR-TAX-05 | The ladder is taken whole from the first owner that has one in force: the organisation's own (saved before 2026-11-09 and still in force), then its kind's on the platform, then the kind's parent's, then every kind's. A date with no ladder refuses the run. | STAT "How a value is found"; 07 STAT-010; `TaxCalculator::resolve()` | Decided |
| PYR-TAX-06 | A taxable figure that falls in no band (below 0) is taxed 0. | `TaxCalculator::apply()` | Implemented |

## 14. Pension

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-PEN-01 | The scheme is the organisation's own `pension.default_scheme` row, else its kind's default, else its kind's parent's, else every kind's (STAT). The defaults: public for `government` and for `regional_government` (through its parent), public for `public_enterprise`, public_military for `defence_police`, private for every other kind. Every organisation that existed before 2026-10-27 has `private` as its own row. | 07 section 1.2, STAT-002, STAT-003; STAT | Decided |
| PYR-PEN-02 | Rates by scheme from `pension_rules`: private employee 7%, employer 11% (1268/2022 Art. 10); public 7% and 11% (1267/2022 Art. 10); public_military 7% and 33% (1267/2022 Art. 11). | 07 section 1.2, STAT-004; RES P1, P3, P4 (High) | Decided |
| PYR-PEN-03 | The pensionable amount is the entry's basic after proration, Pagume, unpaid leave, sick leave and lateness. G7: 8,000.00. SICK-002: 7,800.00. E-LATE-1: 8,725.00. | 07 section 1.2, section 12 ("so lowers pension too"), LATE-005; CHG 2026-11-06; `PayrollEngine` line 1377 | Decided |
| PYR-PEN-04 | Allowances and overtime are not pensionable. The seeded rule's base is `basic`. A rule with base `regular_salary` would add every allowance the run paid, percentage and non-taxable ones included, although the code's own comment says "fixed allowances"; no rule uses it. RES P7 leaves basic against regular-hours salary open; SIGN row 2 asks. | 07 section 1.2; SIGN section 1; RES P7 (UNCONFIRMED); `PensionCalculator::apply()` line 87; `PayrollEngine` line 1377 passes total allowances | Implemented |
| PYR-PEN-05 | Employee share = pensionable × employee rate, rounded; employer share = pensionable × employer rate, rounded; each separately. RND-001: 350,050 × 7% = 24,503.5, so 24,504; × 11% = 38,505.5, so 38,506. | 07 RND-001; `PensionCalculator::apply()` | Decided |
| PYR-PEN-06 | No ceiling and no floor on the pensionable amount. The code supports both; the seeded rules set neither. | RES P6 (Medium); `PensionCalculator::apply()` lines 100 to 106 | Implemented |
| PYR-PEN-07 | Nationality status `foreign` is exempt: both shares 0.00. The exemption must be acknowledged with its count at approval. | 07 section 1.2, PAY-018; RES P8 (High) | Decided |
| PYR-PEN-08 | Nationality status `ethiopian_origin` is exempt until RES P8's opt-in is settled. Unset counts as Ethiopian. | 07 section 1.2; RES P8 (UNCONFIRMED); `PensionCalculator::exemption()` | Implemented |
| PYR-PEN-09 | Every contract type is covered, casual and consultancy included (`pension.applies_to`, not confirmed; SIGN row 13). | STAT; RES P11 | Implemented |
| PYR-PEN-10 | The 45-day rule of 1268/2022 Art. 2 is not applied: a person hired 10 days before the period ends pays pension on their prorated basic. `min_service_days` is seeded empty. | RES P10 (UNCONFIRMED); `PensionCalculator::exemption()` | Implemented |
| PYR-PEN-11 | Whether staff of an organisation kind that RES P9 names as excluded from the private scheme pay pension. RES P9 (confidence High) reads 1268/2022 Art. 3(4) as excluding "employees of governmental international organisations and foreign diplomatic missions; sole owners and their managers/employees". The code charges them: the kinds `international_organization`, `diplomatic_mission` and `sole_proprietor` resolve to the private scheme through their parent (RES O2: "Seeded private until confirmed"; SIGN row 11). | RES P9, O2; SIGN row 11; STAT `pension.default_scheme` | Undecided |
| PYR-PEN-13 | Cooperatives and religious organisations, which RES P9 does not name, are paid on the private scheme through their parent until SIGN row 11 decides. | STAT; SIGN row 11 | Implemented |
| PYR-PEN-14 | Whether a domestic worker pays pension. RES P9 (High) names "Domestic workers" as excluded from the private scheme. The code has no way to mark a domestic worker and charges them as any employee. | RES P9; no `domestic` in `api/app` | Undecided |
| PYR-PEN-12 | The employer share is not taken from the employee's pay. | SIGN step 10; `PayrollEngine` line 1451 | Decided |
| PYR-PEN-15 | `pension_exempt` is 1 when the nationality status is `foreign` or `ethiopian_origin` (PYR-PEN-07, PYR-PEN-08) under a rule that exempts non-nationals, which every seeded rule does; both shares are then 0. `pensionable` is the entry's basic (PYR-PEN-03) for everyone, an exempt person included. | `PensionCalculator::apply()` lines 87 to 95, `exemption()`; `PayrollEngine` lines 1377 to 1392 | Implemented |

## 15. Loans

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-LOAN-01 | Each active loan deducts `loan.<key>` = max(0, min(instalment, remaining)); several loans are added into `loans`. A loan that is not active deducts 0; a loan whose deduction is 0 is not itemised. `loan.<key>.remaining_after` = remaining − that deduction. A loan with 400.00 left and a 1,000.00 instalment deducts 400.00. | 07 LOAN-002, LOAN-005; `api/app/Services/Payroll/LoanService.php` `deductionFor()`; `PayrollEngine` lines 1397 to 1413 | Decided |
| PYR-LOAN-02 | Loans come off net pay only: they change neither taxable income nor pension. | 07 section 1.4; SIGN step 10 | Decided |
| PYR-LOAN-03 | Calculating moves no balance. Approval sets each loan's remaining to max(0, remaining − what the payslip deducted) and closes the loan (status completed) when that reaches 0. Voiding an approved run gives it back and reopens a loan the run closed. | 07 LOAN-002 to LOAN-005; `LoanService::applyDeduction()`; `PayrollBalanceService` | Decided |
| PYR-LOAN-04 | The one-third ceiling does not reduce a loan. A loan over the ceiling is listed on the run's ceiling banner, only while lateness deductions are on. Loan 3,000.00 against a third of 2,933.33: 3,000.00 is deducted. | 07 LATE-007; `RunRegisterService::deductionCeiling()` | Decided |
| PYR-LOAN-05 | Any loan active when the run is computed deducts, whatever period the run covers. There is no loan start period. | `LoanService::getActiveLoans()` (no date predicate) | Implemented |

## 16. Cost sharing

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-CS-01 | `cost_sharing` is computed on **gross** (PYR-GROSS-01): min(intdiv(gross × r, 10,000), outstanding), r being the rate in hundredths of a percent (PYR-CS-06), so it is rounded **down**. It is 0 when there is no obligation (PYR-CS-05), when gross is 0 or less, or when nothing is outstanding. `cost_sharing_outstanding_after` = outstanding − that; it does not exist without an obligation. Gross 630,055 cents at 10% (r 1,000): intdiv(630,055 × 1,000, 10,000) = 63,005 cents. | 07 CS-003; `api/app/Services/Payroll/CostSharingService.php` `calculateDeduction()` lines 43 to 61 | Decided |
| PYR-CS-02 | A second active obligation for the same person is refused when it is created. | 07 CS-002 | Decided |
| PYR-CS-05 | Only one obligation deducts: among the person's obligations with status active, the earliest by start date, and on an equal start date the one created first. A suspended, completed or cancelled one is never picked. | `CostSharingService::getActiveObligation()` | Implemented |
| PYR-CS-06 | The rate is stored with two decimals of a percent (column `decimal(5,2)`) and read through the model's `decimal:2` cast, which rounds half away from zero; the engine then takes r = round(rate × 100), hundredths of a percent. On MariaDB the column already rounds on write; on SQLite the cast rounds on read. Either way 10.125% runs as 10.13% (r 1,013), and the engine reads the stored value, not what was typed. | `EmployeeCostSharing` casts line 76; `2026_08_25_000001_create_employee_cost_sharing_table.php` line 42; `CostSharingService.php` line 57 | Implemented |
| PYR-CS-07 | Approval sets the obligation's outstanding to max(0, outstanding − what the payslip deducted) and completes it when that reaches 0; voiding an approved run gives it back and reopens it. | `CostSharingService::applyDeduction()`, `restoreDeduction()` | Implemented |
| PYR-CS-03 | A suspended, completed or cancelled obligation deducts nothing. | 07 CS-004; `calculateDeduction()` (`isDeductible`) | Implemented |
| PYR-CS-04 | Cost sharing is not reduced by the one-third ceiling (as PYR-LOAN-04). | 07 section 9 ("lateness takes only what is left") | Decided |

## 17. Net pay and the order of deductions

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-NET-01 | `other_deductions` = `loans` + `cost_sharing`. `total_deductions` = `income_tax` + `employee_pension` + `loans` + `cost_sharing`. `net` = `gross` − `total_deductions`. Unpaid leave, sick leave and lateness are already inside basic and are not deducted again. G7: 9,400.00 − 1,350.00 − 560.00 = 7,490.00. Because no deduction is capped, their order does not move a figure. | 07 section 1.4; SIGN step 10; `PayrollEngine` lines 1451, 1452, 1493 | Decided |
| PYR-NET-02 | Whether net pay may be negative, and what happens to the difference. The code has no floor. No source says which deduction would absorb a shortfall or what would be carried, so no alternative can be computed: in a case whose net is below 0, `net`, `loans`, every `loan.<key>` and `loan.<key>.remaining_after`, `cost_sharing`, `cost_sharing_outstanding_after`, `other_deductions`, `total_deductions`, the after-approval balances, and every run figure built on them have no expected value. `income_tax` and the pension shares are not affected. | none | Undecided |

## 18. Rounding

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-ROUND-01 | Every amount is an exact fraction of cents, rounded half away from zero (`integer_half_up`). Sums (gross, total deductions, net) add figures already rounded and are not rounded again. | 07 section 1.1, RND-001; CHG 2026-10-27; `api/app/Support/Cents.php` `mulDiv()`, `percentOf()` | Decided |
| PYR-ROUND-02 | Cost sharing rounds down (PYR-CS-01). The one-third ceiling rounds down (PYR-LATE-03). | 07 CS-003, section 9; `Cents::mulDivDown()`, `intdiv` | Decided |
| PYR-ROUND-03 | Where a figure is rounded more than once in the code: overtime once per kind (PYR-OT-01); pension once per share (PYR-PEN-05); tax once before the deduction (PYR-TAX-03); a `daily_rate` Pagume period once for its Pagume part, once for its other days, then once more for proration (PYR-PAG-03, PYR-PAG-05). | `OvertimeCalculator`, `PensionCalculator`, `TaxCalculator`, `PayrollEngine` lines 1163 to 1175 | Implemented |
| PYR-ROUND-04 | Whether the daily rate is rounded before it is multiplied. The code rounds it (line 1199), then rounds again when it multiplies by days in thousandths (lines 1203, 1228) and by minutes (line 1250). 07 sections 1.4 and 9 say the daily rate is "rounded to the cent". For one rounding: 07 section 1.1 says unpaid leave, sick leave and lateness are each "rounded once"; CHG 2026-10-27 says every amount is "rounded once" without naming leave; SIGN section 1 step 3 writes "monthly basic ÷ 22 working days × unpaid days" with no rounding in between. The reading tested for one rounding (no source writes the composition out): unpaid = min(round(monthly basic × thousandths / (working days × 1000)), `prorated_basic`); sick = min(round(monthly basic × th / (working days × 1000)), `basic_after_unpaid_leave`); `lateness_uncapped` = round(minutes × monthly basic / (working days × hours per day × 60)). The `daily_rate` field is the rounded figure under both readings. | 07 sections 1.1, 1.4, 9; CHG 2026-10-27; SIGN section 1 step 3 | Undecided |
| PYR-ROUND-05 | Which way the lateness deduction is rounded. The code rounds half up (`Cents::mulDiv`, line 1250), as 07 section 1.1 says of lateness; AR-LATE-03 says "rounded down". | 07 section 1.1; AR-LATE-03 | Undecided |

## 19. Run lifecycle

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-RUN-01 | A preview computes every figure with the same code and rules as a run and writes nothing. Its payslips equal the run's to the cent. | 07 STAT-014, STAT-015; `PayrollEngine::preview()` | Decided |
| PYR-RUN-02 | Approval is refused when loan or cost-sharing balances, or the attendance and leave figures, changed after the run was calculated. | 07 LOAN-006, RCN-008; `AttendanceInputsCheck` | Decided |
| PYR-RUN-03 | Approval is also refused, for a run whose entries were calculated at log 1.9 or later, when the salary a run would pay now differs from the logged one, when a leaving date moved the last paid day, when a person with an entry is no longer payable (a removed record included), or when a payable person with a salary has no entry. | `PayrollEngine::inputsChangedSince()`, `payableEmployeeMissing()` | Implemented |
| PYR-RUN-04 | A reprocess of a voided run is a new run computed from the data and rules of the day it runs, for the same dates. Unchanged data gives the same figures. | 07 PAY-023 | Decided |
| PYR-RUN-05 | A released (approved or paid) or voided run's amounts are frozen. A paid run cannot be voided; a mistake in it is corrected in a later run. | 07 section 5, PAY-022, PAID-004 | Decided |
| PYR-RUN-06 | Attendance in an approved or paid period cannot be changed by hand (device punches are still accepted), so a reprocess does not move figures through edited records. | 07 PAY-021; AR-BREAK-05 | Decided |
| PYR-RUN-07 | The counterfactual for a released entry computes what it paid and what it would pay now, both with today's rules and data, differing only in salary and leaving date, and with the loans and cost-sharing rate taken from the entry's log. It writes nothing. A person counts as due only when hired by the period's end and not left before its start (lines 969 to 971), so for someone hired after the period it finds nothing due, while the run itself paid them a 0.00 entry (PYR-PRO-04). | `PayrollEngine::counterfactual()` | Implemented |

## 20. Restated figures

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-RST-01 | The register reads every figure from the stored entry and its log, never recomputing. Its totals equal the run's gross, net and tax totals. | SIGN section 3; 07 OUT-003, OUT-009; `RunRegisterService::register()`; `PayrollGoldenPayslipsTest` "produces a register whose totals tie to the run and to the accounting journal" | Decided |
| PYR-RST-02 | The journal: debit 5100 salary expense = sum of gross; debit 5200 = sum of employer pension; credit 2100 = sum of income tax; credit 2200 = sum of employee pension; credit 2201 = sum of employer pension (equal to 5200); credit 2300 = sum of net; credit 1400 = sum of loans withheld; credit 2400 = sum of cost sharing withheld; 1400 and 2400 appear only when not 0 (a line not present is 0). Debits equal credits for every run, because net is gross less the four withheld kinds. (An entry calculated before deductions were itemised keeps its unitemised other deductions in 2300; no current entry has any.) | 07 OUT-008; `api/app/Services/Accounting/AccountingExportService.php` lines 57 to 147 | Decided |
| PYR-RST-03 | The statutory schedules are built from the register's rows. The income tax schedule has one row per entry; its income tax total is the sum of the entries' income tax. The pension schedule has one row per entry that is not pension-exempt (PYR-PEN-15); its employee total is the sum of those rows' employee pension. | 07 OUT-004, OUT-005, OUT-009; `StatutorySchedules::build()` lines 84 to 124, `onPensionSchedule()` | Decided |
| PYR-RST-04 | The payslip prints basic already reduced by unpaid leave, sick leave and lateness, an overtime line when overtime was paid, each allowance by name, and gross; the lines add up. | 07 OUT-006 | Decided |

## 21. Bank file

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-BANK-01 | A bank file is built only for an approved or paid run. | 07 OUT-001, PAID-005; `PayrollController::ensureApprovedForPayment()` | Decided |
| PYR-BANK-02 | Each row pays the entry's net into the person's primary account, else the first account on record. A person with no account keeps a row with an empty account, and the warning names them. With nobody deleted, `bank.rows` and `bank_page.rows` both equal the number of entries. | 07 OUT-002, OUT-012; `BankExportService::primaryBankDetail()`, `generateCsv()`; `PayrollController::bankExport()` | Decided |
| PYR-BANK-03 | For a run in which no person was deleted: bank file total = register total net = journal 2300 credit. A run holding only G1 to G6: 42,014.00 in all three. (An entry calculated before deductions were itemised keeps its unitemised other deductions in 2300, which then exceeds net by that amount; no current entry has any.) | 07 OUT-008, OUT-009; SIGN checklist row 5; `BankExportService::generateCsv()`, `PayrollController::bankExport()`, `AccountingExportService` line 130 | Decided |
| PYR-BANK-04 | Whether a payable entry in an approved, unpaid run stays payable when the person is deleted in the app (the record can be restored), whether a reprocess of that period must still pay them, or whether the deletion must be refused while such an entry exists. | none | Undecided |
| PYR-BANK-05 | Whether the bank file must carry such an entry. | none | Undecided |
| PYR-BANK-06 | The run page's bank file and the server CSV of one run carry the same rows and amounts (07 OUT-002). The code keeps that only while nobody in the run has been deleted: `BankExportService::generateCsv()` (lines 48 to 51) drops an entry whose employee is deleted, while `PayrollController::bankExport()` (lines 556 to 573) keeps it with no name and counts its net in the total, and `RunRegisterService::row()` (lines 427 to 435) keeps it with an empty name. | 07 OUT-002; code as cited | Undecided |

## Figures on a payslip, in order

Each figure is defined by the rules named; the formula is in the rule row. A figure not listed is not a payslip figure. "Rounded" is PYR-ROUND-01.

| Field | Rules | Definition |
|---|---|---|
| `in_run` | PYR-SAL-07, PYR-SAL-04, PYR-SAL-05, PYR-SAL-06, PYR-PRO-04 | Whether the run has an entry for the person. When it is 0, no other figure exists. |
| `salary_changed_in_period` | PYR-SAL-03 | The flag for a salary change inside the period. Moves nothing. |
| `monthly_basic` | PYR-SAL-01, PYR-SAL-02, PYR-SAL-03 | The one salary that pays the period. |
| `days_in_period` | PYR-PER-01 | The period's calendar days. |
| `days_employed` | PYR-PRO-01, PYR-PRO-03, PYR-PRO-04 | The days of the period the person was employed. |
| `period_basic` | PYR-PAG-02, PYR-PAG-03, PYR-PAG-04, PYR-PAG-06 | The Pagume step's result. |
| `prorated_basic` | PYR-PRO-02, PYR-PAG-05 | `period_basic` for the days employed. |
| `daily_rate` | PYR-RATE-01, PYR-ROUND-04 | The monthly basic per working day. |
| `unpaid_leave_deduction` | PYR-LEAVE-01, PYR-LEAVE-02, PYR-LEAVE-03, PYR-LEAVE-05, PYR-LEAVE-06 | Unpaid leave taken from `prorated_basic`. |
| `basic_after_unpaid_leave` | PYR-LEAVE-02 | `prorated_basic` less that. |
| `sick_deduction` | PYR-SICK-01, PYR-SICK-02, PYR-SICK-03 | Art. 85 half-pay and unpaid days. |
| `basic_after_sick_leave` | PYR-SICK-02 | `basic_after_unpaid_leave` less that. |
| `lateness_step_logged` | PYR-LATE-01 | Whether lateness deductions are on. When 0, the lateness figures below do not exist. |
| `lateness_uncapped` | PYR-LATE-02, PYR-ROUND-04, PYR-ROUND-05 | Decided minutes at the minute rate. |
| `lateness_ceiling` | PYR-LATE-03, PYR-LATE-06 | One third of the ceiling's base. |
| `lateness_loans_and_cost_sharing` | PYR-LATE-04, PYR-CS-05, PYR-CS-06 | L + C, what loans and cost sharing take from the ceiling. |
| `lateness_cap` | PYR-LATE-04 | The headroom left. |
| `lateness_deduction` | PYR-LATE-04, PYR-LATE-05 | The smaller of uncapped and cap. |
| `basic_after_lateness` | PYR-LATE-05 | `basic_after_sick_leave` less that. |
| `basic` | PYR-GROSS-01, PYR-LATE-01, PYR-RST-04 | The basic on the payslip: `basic_after_lateness`, or `basic_after_sick_leave` when lateness is off. |
| `overtime_normal`, `overtime_night`, `overtime_rest_day`, `overtime_holiday`, `overtime_holiday_night` | PYR-OT-01, PYR-OT-02, PYR-OT-03, PYR-OT-04 | Each kind's pay. The minutes are AR-OT-01 to AR-OT-14. |
| `overtime` | PYR-OT-01 | The five added. |
| `allowance.<key>` | PYR-ALW-01, PYR-ALW-02, PYR-ALW-03, PYR-ALW-05, PYR-ALW-06, PYR-ALW-07 | One allowance as paid; 0 when it is left off. |
| `allowances_total`, `allowances_taxable`, `allowances_non_taxable` | PYR-ALW-04, PYR-ALW-05 | The allowances paid, in all and by taxable flag. |
| `gross` | PYR-GROSS-01 | `basic` + `overtime` + `allowances_total`. |
| `taxable` | PYR-GROSS-02 | `gross` less non-taxable allowances. |
| `income_tax` | PYR-PER-03, PYR-TAX-01 to PYR-TAX-06 | Tax on `taxable` from the ladder in force on the period start. |
| `pension_exempt` | PYR-PEN-15, PYR-PEN-07, PYR-PEN-08, PYR-PEN-11, PYR-PEN-14 | Whether pension is not charged. |
| `pensionable` | PYR-PEN-03, PYR-PEN-04, PYR-PEN-06, PYR-PEN-15 | `basic`, exempt or not. |
| `employee_pension`, `employer_pension` | PYR-PEN-01, PYR-PEN-02, PYR-PEN-05, PYR-PEN-09, PYR-PEN-10, PYR-PEN-12, PYR-PEN-13 | The two shares of `pensionable`; 0 and 0 when exempt. |
| `loan.<key>` | PYR-LOAN-01, PYR-LOAN-04, PYR-LOAN-05 | What one loan deducts. |
| `loan.<key>.remaining_after` | PYR-LOAN-01 | What that loan would owe after this payslip. |
| `loans` | PYR-LOAN-01, PYR-LOAN-02 | The loans added. |
| `cost_sharing` | PYR-CS-01, PYR-CS-03 to PYR-CS-06 | The cost sharing on `gross`. |
| `cost_sharing_outstanding_after` | PYR-CS-01 | What that obligation would owe after this payslip; absent with no obligation. |
| `other_deductions` | PYR-NET-01 | `loans` + `cost_sharing`. |
| `total_deductions` | PYR-NET-01 | Tax, employee pension, loans and cost sharing. |
| `net` | PYR-NET-01, PYR-NET-02 | `gross` less `total_deductions`. |
| `loan.<key>.remaining_after_approval`, `loan.<key>.closed_after_approval` | PYR-LOAN-03 | The loan's balance and status after the run is approved. |
| `cost_sharing_outstanding_after_approval` | PYR-CS-07 | The obligation's balance after the run is approved. |
| `run.entries` | PYR-SAL-07 | The people with an entry. |
| `run.skipped` | PYR-SAL-04, PYR-SAL-06 | Payable, not deleted, and no salary. |
| `register.gross_total`, `register.income_tax_total`, `register.employee_pension_total`, `register.employer_pension_total`, `register.net_total` | PYR-RST-01 | Sums of the entries' figures. |
| `journal.5100.debit`, `journal.5200.debit`, `journal.2100.credit`, `journal.2200.credit`, `journal.2201.credit`, `journal.2300.credit`, `journal.1400.credit`, `journal.2400.credit` | PYR-RST-02 | The journal lines and the sum that feeds each. |
| `journal.balanced` | PYR-RST-02 | Debits equal credits. |
| `schedule.income_tax_total`, `schedule.pension_rows`, `schedule.pension_employee_total` | PYR-RST-03 | The statutory schedules' rows and totals. |
| `bank.rows`, `bank.total` | PYR-BANK-02, PYR-BANK-03, PYR-BANK-05, PYR-BANK-06 | The server bank CSV. |
| `bank_page.rows`, `bank_page.total` | PYR-BANK-02, PYR-BANK-03, PYR-BANK-06 | The run page's bank file. |

## Pagume dates

From the project's own calendar (`App\Services\Calendar\EthiopianCalendar`, `ethiopianToGregorian()` and `daysInPagume()`, run on 2026-11-28). A period's Pagume days are the days it shares with these spans (`pagumeDaysInRange()`). Meskerem 1 is the day after the last Pagume day.

| Ethiopian year | Pagume, Gregorian | Days | Next Meskerem 1 |
|---|---|---|---|
| 2008 | 2016-09-06 to 2016-09-10 | 5 | 2016-09-11 |
| 2009 | 2017-09-06 to 2017-09-10 | 5 | 2017-09-11 |
| 2010 | 2018-09-06 to 2018-09-10 | 5 | 2018-09-11 |
| 2011 | 2019-09-06 to 2019-09-11 | 6 | 2019-09-12 |
| 2012 | 2020-09-06 to 2020-09-10 | 5 | 2020-09-11 |
| 2013 | 2021-09-06 to 2021-09-10 | 5 | 2021-09-11 |
| 2014 | 2022-09-06 to 2022-09-10 | 5 | 2022-09-11 |
| 2015 | 2023-09-06 to 2023-09-11 | 6 | 2023-09-12 |
| 2016 | 2024-09-06 to 2024-09-10 | 5 | 2024-09-11 |
| 2017 | 2025-09-06 to 2025-09-10 | 5 | 2025-09-11 |
| 2018 | 2026-09-06 to 2026-09-10 | 5 | 2026-09-11 |
| 2019 | 2027-09-06 to 2027-09-11 | 6 | 2027-09-12 |
| 2020 | 2028-09-06 to 2028-09-10 | 5 | 2028-09-11 |
| 2021 | 2029-09-06 to 2029-09-10 | 5 | 2029-09-11 |

## Statutory parameters used

The values below are STAT's, given here with their code so a reader can compute without opening STAT. They are not this register's own: a change is made in STAT's table first. "Confirmed" is STAT's column. Every rule whose cents depend on an unconfirmed value is Implemented. The three overtime limits move no cents (they only flag a run, PYR-OT-05; their values are AR-OT-10), and the public and private schemes pay the same rates, so PYR-OT-05 and PYR-PEN-01 stay Decided.

| Code | Value (STAT) | Confirmed | Rules that read it |
|---|---|---|---|
| `payroll.working_days_per_month` | 22; an organisation may set 20 to 31 | no | PYR-RATE-01, PYR-LEAVE-02, PYR-SICK-02, PYR-LATE-02, PYR-OT-01 |
| `payroll.hours_per_day` | 8 | yes | PYR-OT-01, PYR-LATE-02 |
| `overtime.minimum_rates` | normal 1.5, night 1.75, rest day 2.0, holiday 2.5, holiday night 2.5 | yes | PYR-OT-02 |
| `overtime.night_window` | 22:00 to 06:00 | yes | AR-OT-03 (minutes only) |
| `overtime.legal_daily_minutes` | 240 | no | PYR-OT-05 (flags only) |
| `overtime.legal_weekly_minutes` | 720 | no | PYR-OT-05 (flags only) |
| `overtime.limited_types` | normal, night | no | PYR-OT-05 (flags only) |
| `pension.schemes` | private, public, public_military | yes | PYR-PEN-01 |
| `pension.default_scheme` | private for every kind; public for `government` (confirmed) and `public_enterprise` (not confirmed); public_military for `defence_police` (confirmed). A kind with no row of its own takes its parent's: `regional_government` is public through `government`; the private, non-profit and international child kinds are private | see value | PYR-PEN-01 |
| `pension.applies_to` | every contract type | no | PYR-PEN-09 |
| `fiscal_year.start_month` | 1 (Meskerem); 11 (Hamle) for `government` | no | none: logged in `inputs`, moves no figure (PYR-PER-04) |
| `pagume.proration` | `full_month` | no | PYR-PAG-01 to PYR-PAG-06 |
| `leave.sick.tiers` | 1 month full, 2 half, 3 unpaid | no | PYR-SICK-01 |
| `leave.sick.window_months` | 12 | no | PYR-SICK-01 |
| `leave.sick.half_pay_ratio` | 1/2 | no | PYR-SICK-02 |
| `payroll.deduction_cap_ratio` | 1/3 | no | PYR-LATE-03, PYR-LATE-06 |

The pension rates are `pension_rules` rows, not parameters (PYR-PEN-02). The tax ladders are `tax_brackets` rows (PYR-TAX-01, PYR-TAX-02). Every seeded pension rule has base `basic`, no ceiling, no floor, exempts non-nationals and has no minimum service (PYR-PEN-04, PYR-PEN-06, PYR-PEN-10, PYR-PEN-15). Locked, so no organisation changes them: `payroll.hours_per_day`, `overtime.minimum_rates` (an organisation may only pay more, PYR-OT-02), `payroll.deduction_cap_ratio`, `leave.sick.half_pay_ratio`, `leave.sick.tiers` and `leave.sick.window_months`.

## Waiting for a decision

These are the Undecided rows, in the order they matter to pay. Each needs the owner's ruling, and the accountant's where money moves, before a test can call the engine right or wrong on it. Until then a test reports a field that depends on one as undecided, and only when the rule's readings would give different answers for that input.

1. **PYR-PAG-06**, a period whose every day is Pagume, under `full_month`. Does it pay a month's basic, or are the Pagume days covered by the month they belong to? Example: basic 6,000.00, a run for the 5 days of Pagume. Code: `period_basic` 600,000 cents. Reading tested: `period_basic` 0, so every figure after it is 0 except what does not read basic (overtime, fixed allowances). Once a year, for everyone.
2. **PYR-PEN-11 and PYR-PEN-14**, staff RES P9 names as outside the private scheme (international organisations and diplomatic missions, sole owners and their managers and employees, domestic workers). Do they pay pension? Example: basic 10,000.00. Code: `pension_exempt` 0, employee 70,000 cents and employer 110,000 cents every month. Reading tested: `pension_exempt` 1, both shares 0, `pensionable` still the basic; `total_deductions` and `net` follow.
3. **PYR-BANK-04, PYR-BANK-05 and PYR-BANK-06**, a person deleted in the app (the record can be restored) while a released run holds their payable entry. Does the entry stay payable, must the bank file carry it, or must the deletion be refused while an unpaid entry exists? `EmployeeController::destroy()` checks only the policy and no deleting hook blocks it; `BankExportService::generateCsv()` drops the row (lines 48 to 51), while `PayrollController::bankExport()` (lines 556 to 573) and `RunRegisterService::row()` (lines 427 to 435) keep it. Example: a run holding only G1 to G6 is approved; E-PAY-3 (net 4,880.00) is then deleted. Code: register total net and journal 2300 4,201,400 cents; `bank.rows` 5 and `bank.total` 3,713,400; `bank_page.rows` 6 and `bank_page.total` 4,201,400, one row without a name. Ruling one (stays payable and carried): every output 4,201,400, 6 rows. Ruling two (no longer payable): 3,713,400 and 5 rows in both bank files, and the register and journal must then say why 488,000 is not paid. Ruling three (deletion refused): the run is unchanged, 4,201,400 and 6 rows everywhere. These are rulings, not readings of a formula: a case that deletes a person after approval has no expected value for any run figure until one is chosen. Payslip figures are not affected. A reprocess after a void today leaves the person out without counting them as skipped (PYR-SAL-06).
4. **PYR-NET-02**, negative net pay. May net be below zero, and if not, what is reduced or carried? Example: basic 3,000.00, tax 150.00, pension 210.00, loan instalment 3,000.00. Code: net −36,000 cents, and the bank file carries −360.00. No source says which deduction absorbs a shortfall or what is carried, so there is no second figure: the fields the row lists have no expected value in such a case. The same arises for a person hired after the period's end with an active loan (PYR-PRO-04): net −100,000 cents for a 1,000.00 instalment.
5. **PYR-ALW-07**, a fixed allowance in a `daily_rate` Pagume period. Is it paid in full or by the Pagume rule? Example: transport 600.00 in a 5-day Pagume period. Code: 60,000 cents. Reading tested: 60,000 × 60 / 365 = 9,863 cents. Hired on Pagume 3 of 5: code 36,000, reading tested 5,918 (the row gives the composition).
6. **PYR-LATE-06**, the base of the one-third ceiling. Example: a joiner with basic 12,345.67 employed 15 of 31 days, 2.5 days of unpaid leave, a 1,500.00 loan and 47 decided minutes. Daily rate 1,234,567 / 22 = 56,117 cents; uncapped lateness 47 × 56,117 / 480 = 5,494.79, so 5,495 cents. Month's wage (monthly basic): 1,234,567 / 3 = 411,522, cap 261,522, lateness 5,495. Period's basic (`prorated_basic`): 1,234,567 × 15 / 31 = 597,371, third 199,123, cap 49,123, lateness 5,495. The code (`basic_after_sick_leave`): 597,371 − 140,293 = 457,078, third 152,359, cap 2,359, lateness 2,359 cents.
7. **PYR-ROUND-04**, the daily rate rounded first or each deduction rounded once. Examples at basic 10,000.00 and 22 working days (daily rate 45,455): 3 days of unpaid leave, rounded first 45,455 × 3 = 136,365 cents, once 1,000,000 × 3 / 22 = 136,363.6, so 136,364; 3 half-pay sick days (th 1,500), rounded first 45,455 × 1,500 / 1,000 = 68,182.5, so 68,183, once 1,000,000 × 1,500 / 22,000 = 68,181.8, so 68,182; 240 decided minutes, rounded first 240 × 45,455 / 480 = 22,727.5, so 22,728, once 240 × 1,000,000 / 10,560 = 22,727.3, so 22,727. The difference grows with the days, by up to half a cent a day.
8. **PYR-PAG-05**, Pagume before or after proration under `daily_rate`. Examples in the row: hired on Pagume 3 of 5, 121,766 cents (the code) against 121,765; a mixed Nehase and Pagume period, 684,620 against 684,621. At most a cent.
9. **PYR-ROUND-05**, lateness rounded half up or down. Example: 2 minutes at a daily rate of 40,000 cents: 80,000 / 480 = 166.67. Half up: 167 cents. Down: 166 cents. At most one cent a payslip.

No row is Undecided only because a statutory value or an ETHR reading is unconfirmed: those rows are Implemented, and the accountant's sign-off ([ACCOUNTANT-SIGN-OFF.md](ACCOUNTANT-SIGN-OFF.md) rows 1, 2 and 7 to 14, the same list as in "How to read a row") settles them. The ones that move a figure are PYR-RATE-01, PYR-RATE-03, PYR-LEAVE-02, PYR-SICK-01, PYR-SICK-02, PYR-LATE-02, PYR-LATE-03, PYR-OT-01, PYR-PAG-01 to PYR-PAG-04, PYR-SAL-03, PYR-TAX-01, PYR-TAX-02, PYR-TAX-04, PYR-PEN-04, PYR-PEN-06, PYR-PEN-08 to PYR-PEN-10, PYR-PEN-13.

## Where other documents say something else

Found on 2026-11-28 while this register was written. Where a later dated decision covers the point, the register follows it and the other text is to be corrected; where none does, the row is Undecided above.

| Document | What it says | The rule |
|---|---|---|
| SIGN section 1, Pension, and order step 8 | Pension on basic "after proration and unpaid leave", "step 3's figure"; the order has no sick leave or lateness step | PYR-PEN-03, decided later by A-Z E-08 (CHG 2026-11-06, 07 section 12) and the lateness work (07 section 9). SIGN's order needs steps for Art. 85 sick leave and lateness. |
| 07 section 1.1; CHG 2026-10-27 | Unpaid leave, sick leave and lateness are each "rounded once" | PYR-ROUND-04, Undecided: 07 sections 1.4 and 9 round the daily rate first, as the code does. |
| AR-LATE-03 (marked Decided there) | The lateness deduction is "rounded down"; the divisor is a fixed 480 | PYR-ROUND-05, Undecided: 07 section 1.1 and the code (`Cents::mulDiv`, engine line 1250) round half up. The code divides by `payroll.hours_per_day` × 60, which is 480 only while that value is 8 (PYR-LATE-02). **AR-LATE-03 must drop to Undecided in ATTENDANCE-RULES.md too**, so the two registers do not disagree about its state; this register does not edit that file. |
| AR-LATE-03 | Lateness is capped "so that lateness, loans and cost sharing together stay within one third of basic" | PYR-LATE-04, PYR-LOAN-04: only lateness is capped; loans and cost sharing are never reduced, so the total can pass the third (07 LATE-007). |
| 07 OUT-002 | The bank file has "one row per entry", and the server file carries "the same amounts" as the run page's | PYR-BANK-06, Undecided: the server CSV leaves out an entry whose person was deleted; the run page's file keeps it. |
| STAT `payroll.deduction_cap_ratio`; [APPROVAL-ENGINE-DESIGN.md](../APPROVAL-ENGINE-DESIGN.md) line 303 | "1/3 of the month's wage"; "a third of the period's basic" | PYR-LATE-06, Undecided: 07 section 9 and the code take the third of basic after unpaid and sick leave. Three bases, three caps. |
| SIGN section 1, steps 1 and 2 | Proration (step 1) before Pagume (step 2) | PYR-PAG-05, Undecided: 07 section 1.4 and the code apply Pagume first. Under `daily_rate` the order moves the rounding. |
| SIGN section 1 step 2; STAT "Organisation types" | Pagume days are paid "as part of the month"; an "organisation month (never Pagume)" | PYR-PAG-06, Undecided: the code pays a whole month's basic for a period that is Pagume alone under `full_month`. |
| RES P9 (High) | 1268/2022 Art. 3(4) excludes domestic workers, employees of governmental international organisations and foreign diplomatic missions, and sole owners and their managers and employees | PYR-PEN-11, PYR-PEN-14, Undecided: the code charges all of them on the private scheme. |
| `PayrollEngine::salaryFor()` docblock, lines 693 to 695 | A person with history rows and none in force on the period's last day is paid "nothing" | PYR-SAL-01: `SalaryInForce::on()` returns the earliest row in that case, so they are paid it. The docblock is to be corrected; the PHP file was not edited. |
| LVR-DAYS-03 in [LEAVE-RULES.md](../LEAVE-RULES.md) | "There are no half days" | PYR-LEAVE-01 agrees for every request path. LVR-DAYS-03 should add that `leave_requests.days` is `decimal(5,1)`, so a row written directly can hold tenths of a day, and payroll deducts them (PYR-LEAVE-06). |
| `api/app/Services/Payroll/PensionCalculator.php` comment on `BASES` | `regular_salary` is basic "plus fixed allowances" | PYR-PEN-04: the engine passes every allowance, percentage and non-taxable ones included. No rule uses this base today. |

## Reachability of a deleted person (observation for PYR-BANK-04)

`PayrollEntry::employee()` is a plain `belongsTo` and `Employee` uses `SoftDeletes`, so a person deleted in the app (the record can be restored, `EmployeeController::restore()`) reads as no employee. Which product paths can delete a person who has an entry in a run not yet paid:

- **Single delete, `DELETE /api/v1/employees/{employee}`** (`api/routes/api.php` line 628, `EmployeeController::destroy()` lines 351 to 360): reachable. It checks only `employee.delete` and reach (`EmployeePolicy::delete()` lines 36 to 40), not payroll, and `Employee::booted()` has no deleting hook that could block it. A caller in the web interface was not found; that search was incomplete.
- **Bulk archive and the undo of an imported list** (`EmployeeArchiver::archive()` lines 60 to 84, via `EmployeeBulkController` and `ListUndo::archiveChunk()`): not reachable. They refuse anyone who has a `payroll_entries` row (`AdditionUse` line 61).
- **Discarding an import list** (`MigrationController::destroy()`): not reachable. Only a list that wrote nobody can be discarded.
- **Organisation deletion** (`AdminTenantController::destroy()` lines 1126 to 1168): not reachable. It removes the organisation's row only; employees stay.
- **SCIM deprovisioning** (`ScimUserController::destroy()`): not a removal. It suspends the employee.

What a removal then does: a **completed** run cannot be approved (PYR-RUN-03). An **approved** run that is not yet paid, or a **paid** run exported again, reaches the bank files as PYR-BANK-06 describes, while the register, schedules and journal keep the entry with an empty name. A **reprocess** after a void leaves the person out and does not count them as skipped.

## What this register does not cover

- Attendance minutes: late, early, worked and overtime minutes are [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md).
- Rules for civil servants under the Federal Civil Servants Proclamation 1353/2024, and government salary scales. LAW notes that Art. 85 sick pay is applied to every organisation kind, which it says is wrong for civil servants, and that the probation limit of Art. 85 is not applied; no document gives the civil-service rule, so there is no second figure to test against (SIGN rows 11 and 12, section 5).
- Legal exemption limits on non-taxable allowances. The engine has only a taxable flag (PYR-ALW-04); no source records a limit (SIGN row 6).
- Arrears and recoveries for a salary change back-dated into a month already paid, and how arrears are taxed (SIGN row 14).
- Severance, final pay and its deadline, and any deduction other than loans, cost sharing and lateness (court orders, union dues). LAW rows on Arts. 36 to 45 and 59.
- Remittance schedule layouts and bank-specific transfer formats (SIGN section 5; [STATUTORY-SCHEDULES.md](STATUTORY-SCHEDULES.md)).
- Who may process, approve, void and pay, and the refusals that protect a run: 07 sections 4, 5 and 10.
