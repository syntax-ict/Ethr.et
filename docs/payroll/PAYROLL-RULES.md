# Payroll rules: the register

Every rule the payroll engine applies when it turns a salary, attendance, leave, loans and the statutory rules into one person's payslip, in one place, each with an id. This is the specification that hand-valued golden payslips and an independent oracle are written against, by people who may read only this file. How a minute of attendance is counted is in [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md); this file starts where those minutes arrive and ends at the bank file.

**When a rule changes, change it here first**, then the code, then the golden case that cites its id. Other documents link to a rule id and do not restate its numbers. A deliberate change to a figure is also entered in [PAY-CHANGES.md](PAY-CHANGES.md).

Rule ids are `PYR-<AREA>-NN`. The prefix `PAY-` belongs to the test cases of 07 and is not used here.

## How to read a row

| State | Meaning |
|---|---|
| Decided | A document states it and the code was read to agree. The oracle implements it and a disagreement is a defect. |
| Implemented | The code does it and no document states it, or the code follows a value or choice that ETHR made and that still waits for confirmation (a statutory parameter with `confirmed` false, a research finding marked UNCONFIRMED, or a choice that SIGN rows 2, 8, 10, 11, 13 or 14 ask the accountant to confirm). The oracle copies it so that the tests can run, and the accuracy report counts these rules separately: agreement proves the behaviour is stable, not that it was chosen. |
| Undecided | ETHR's own documents disagree with each other or with the code and no later dated decision settles it, or no document picks an answer. The oracle does not guess: cases that depend on it are reported as "rule undecided", not as passes or failures. |

The line between the last two: a default ETHR chose and documented, which the code follows and the accountant has yet to confirm, is Implemented. Undecided is kept for a real conflict or a real gap.

Sources: **07** is [production testing/07-E2E-PAYROLL.md](<../production testing/07-E2E-PAYROLL.md>), **STAT** is [STATUTORY-PARAMETERS.md](STATUTORY-PARAMETERS.md), **SIGN** is [ACCOUNTANT-SIGN-OFF.md](ACCOUNTANT-SIGN-OFF.md) (prepared, not yet signed), **RES** is [STATUTORY-RULES-RESEARCH.md](STATUTORY-RULES-RESEARCH.md), **CHG** is [PAY-CHANGES.md](PAY-CHANGES.md), **LAW** is [EMPLOYMENT-LAW-REGISTER.md](../EMPLOYMENT-LAW-REGISTER.md), **AR** is [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md). A path under `api/` is the code that was read; `PayrollEngine` is `api/app/Services/Payroll/PayrollEngine.php`, and its arithmetic is `computeEntry()`.

All money is integer cents. 1.00 ETB is 100 cents. "Rounded" alone means rounded half away from zero to a whole cent (PYR-ROUND-01).

## 1. Period and calendar

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-PER-01 | A run covers the dates it is given, start to end inclusive, with the end after the start. The engine has no notion of a calendar month: an Ethiopian month (Meskerem 2019 is 2026-09-11 to 2026-10-10) and a Gregorian month are both just two dates. The period's length is its calendar days: 2026-09-11 to 2026-10-10 is 30. | 07 PAY-010; `api/app/Http/Requests/Payroll/ProcessPayrollRequest.php` rules; `PayrollEngine` line 1138 | Implemented |
| PYR-PER-02 | One live run per period: a run whose dates overlap any run that is not voided or failed is refused. | 07 PAY-040; `PayrollEngine::reserve()` | Decided |
| PYR-PER-03 | Every statutory value, the tax ladder, the pension rule and the overtime floors are resolved once, for the period's **start** date. A June 2025 period keeps June 2025's law when it is reprocessed in 2026. | 07 section 1.1; `PayrollEngine::prepare()` | Decided |
| PYR-PER-04 | `fiscal_year.start_month` is resolved and logged but changes no figure on a payslip. | `PayrollEngine` lines 1078, 1129 (log only) | Implemented |
| PYR-PER-05 | The run's stored label is the English Gregorian month of the period start: Meskerem 2019 is stored "September 2026". | 07 section 7; `PayrollEngine::begin()` | Decided |

## 2. Which salary pays the period

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-SAL-01 | The salary is found in this order. An employee with salary history rows is paid the row with the latest effective date on or before the period's **last** day (a later row id wins a tie; a row superseded by a later correction is never read); with no row on or before that day, the earliest row. An employee with no rows is paid the salary on the record. If that is zero or missing, the contract in force on the period's last day states it, else the latest contract that overlaps the period. | `PayrollEngine::salaryFor()`, `contractSalaryFor()`; `api/app/Services/Payroll/SalaryInForce.php` `on()` | Implemented |
| PYR-SAL-02 | A salary on a contract with none on the record is paid. G7's E-PAY-7 is paid 8,800.00 from a contract. | 07 PAY-020, PAY-028 | Decided |
| PYR-SAL-03 | One salary pays the whole period. A change dated inside the period does not split it by days: it is flagged as `salary_changed_in_period`. A raise from 6,000.00 to 9,000.00 effective on day 16 of a 30-day period pays 9,000.00, not 7,500.00. SIGN row 14 asks the accountant to confirm or to rule for days. | SIGN row 14; `PayrollEngine` lines 1088 to 1096, 1122 | Implemented |
| PYR-SAL-04 | A person with no salary from any source is not in the run and is counted as skipped. | 07 section 1.4, PAY-013 | Decided |
| PYR-SAL-05 | The people a run pays: status hired, probation or confirmed; and anyone resigned, terminated or retired whose termination date is on or after the period start. | 07 section 1.4, PAY-039; `PayrollEngine::payableEmployees()` | Decided |
| PYR-SAL-06 | A person in another status (suspended, for example) or a removed (soft-deleted) record is not paid and not counted as skipped. | `PayrollEngine::payableEmployees()`, `runEntries()` skipped count | Implemented |

## 3. Proration for joiners and leavers

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-PRO-01 | Days employed run from the later of the hire date and the period start to the earlier of the termination date and the period end, both days included. A termination date outside the period changes nothing. | 07 section 1.4, PAY-037, PAY-039; `PayrollEngine` lines 1138 to 1149, `lastPaidDay()` | Decided |
| PYR-PRO-02 | Prorated basic is the period basic (PYR-PAG) times days employed over the period's calendar days, rounded once. Hired on day 21 of a 30-day period: 6,000.00 × 10 / 30 = 2,000.00. Hired on the 25th of a 31-day month: 7/31. The month length does not matter: 28, 29, 30 and 31 are each the divisor of their own period. | 07 PAY-037; CHG 2026-11-04; `PayrollEngine` line 1175 | Decided |
| PYR-PRO-03 | The termination day itself is paid: a last day on the 10th of a 30-day period pays 10/30. | `PayrollEngine::lastPaidDay()` (inclusive) | Implemented |
| PYR-PRO-04 | A person in a payable status whose hire date is after the period's end has 0 days employed and still gets an entry: basic 0.00, and loans still deduct (PYR-NET-02). | `PayrollEngine` lines 1141 to 1146; `payableEmployees()` does not read the hire date | Implemented |

## 4. Pagume

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-PAG-01 | The default strategy is `full_month` (STAT `pagume.proration`, not confirmed). An organisation may choose `daily_rate`. SIGN row 8 asks which the organisation uses. | STAT; SIGN row 8 | Implemented |
| PYR-PAG-02 | Under `full_month`, or in any period that holds no Pagume day, the period basic is the monthly basic, however many days the period has. A period that is Pagume alone (5 or 6 days) therefore pays a full month's basic before proration: 6,000.00. The settings screen calls this "A full month"; SIGN reads "the 5 to 6 days paid as part of the month". | 07 section 1.4; SIGN section 1 step 2; `PayrollEngine` lines 1157 to 1173 | Implemented |
| PYR-PAG-03 | Under `daily_rate`, the period's Pagume days are paid at monthly basic × 12 × Pagume days / 365, rounded; any other days of the same period are paid monthly basic × those days / the period's days, rounded; the two are added. Basic 6,000.00 for a 5-day Pagume period: 600,000 × 60 / 365 = 98,630.14, so 986.30. | 07 section 1.4; `PayrollEngine` lines 1160 to 1170 | Implemented |
| PYR-PAG-04 | The divisor is 365 even in a year whose Pagume has 6 days. Basic 6,000.00 for a 6-day Pagume: 600,000 × 72 / 365 = 1,183.56 (by 366 it would be 1,180.33). No document gives an exception; 07 states 365. | 07 section 1.4; `PayrollEngine` line 1163 | Implemented |
| PYR-PAG-05 | Proration for days employed (PYR-PRO-02) is applied after the Pagume step, on its result. Hired on Pagume 3 of 5 under `full_month`: 6,000.00 × 3 / 5 = 3,600.00. | `PayrollEngine` lines 1151 to 1156, 1175 | Implemented |

## 5. Daily and hourly rate

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-RATE-01 | The daily rate is the **monthly** basic of PYR-SAL (before proration, Pagume and leave) divided by `payroll.working_days_per_month`. 8,800.00 / 22 = 400.00. Unpaid leave, sick leave and lateness all read this one daily rate. Whether it is rounded to a whole cent before it is used is PYR-ROUND-04. The 22 is not confirmed. | 07 section 1.4, PAY-034; SIGN section 1 step 3; `PayrollEngine` line 1199 | Implemented |
| PYR-RATE-03 | The hourly rate for overtime is the monthly basic divided by (`payroll.working_days_per_month` × `payroll.hours_per_day`): 8,800.00 / 176 = 50.00. It is never rounded on its own (PYR-OT-01). The 22 is not confirmed. | 07 section 1.3; CHG 2026-11-03 | Implemented |

## 6. Unpaid leave

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-LEAVE-01 | Unpaid days are approved leave of a type that is not paid and is not an Art. 85 sick type, falling in the period. A request wholly inside the period counts its stored days (half days allowed); one crossing a period edge is clipped and its days recounted on the person's own working days (rotation, then shift, then the organisation's week). | CHG 2026-11-04 (A-Z E-06); 07 section 1.4; `PayrollEngine::gatherUnpaidLeaveDays()` | Decided |
| PYR-LEAVE-02 | The deduction is the daily rate (PYR-RATE-01) times the unpaid days, taken from prorated basic. 2 days × 400.00 = 800.00; G7 basic 8,800.00 becomes 8,000.00. The 22 is not confirmed. | 07 G7, RCN-006; SIGN section 1 step 3 | Implemented |
| PYR-LEAVE-03 | Days are carried in thousandths of a day, and the deduction is never more than the prorated basic left. | `PayrollEngine` lines 1202 to 1205 | Implemented |
| PYR-LEAVE-04 | Unpaid leave dated in an approved or paid period is refused, so it never reaches a run that is already released. | 07 PAY-038 | Decided |

## 7. Sick leave (Labour Proclamation 1156/2019, Art. 85)

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-SICK-01 | Each charged day of a sick type marked `follows_art85` takes its place in a twelve-month window that starts on the first sick day not already in a window. With 22 working days a month: days 1 to 22 full pay, 23 to 66 half pay, 67 to 132 unpaid; a day past six months that was approved anyway is unpaid. How days are placed is `api/app/Services/Leave/SickLeaveTiers.php` `tiersOf()`, which was not read in full for this register. | 07 section 12; CHG 2026-11-06 (A-Z E-08); STAT `leave.sick.*` (not confirmed); `SickLeaveTiers::inPeriod()` | Implemented |
| PYR-SICK-02 | The deduction is daily rate × (unpaid days + half-pay days × (1 − `leave.sick.half_pay_ratio`)), taken from basic after unpaid leave and before lateness, never more than the basic left. 5 half-pay days at 400.00: 5 × 400.00 × 1/2 = 1,000.00; basic 8,800.00 becomes 7,800.00. | 07 SICK-002; `PayrollEngine` lines 1220 to 1230 | Implemented |
| PYR-SICK-03 | An Art. 85 day is never also counted as unpaid leave. | 07 section 12; `gatherUnpaidLeaveDays()` skips `follows_art85` | Decided |
| PYR-SICK-04 | Sick leave dated in an approved or paid period is refused. | 07 SICK-004 | Decided |

## 8. Lateness deduction (Art. 59)

The minutes come from the attendance register: AR-LATE-01 and AR-LATE-02 decide which minutes may be deducted. This section turns them into cents.

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-LATE-01 | Nothing is deducted, and no `lateness` step is logged, unless the organisation switched lateness deductions on. Then the minutes are the sum of `pay_deduct_minutes` on the person's records in the period that are not voided. | 07 LATE-001, LATE-008; AR-LATE-01; `PayrollEngine` lines 1246, 1813 to 1848 | Decided |
| PYR-LATE-02 | Uncapped deduction = minutes × daily rate / (`payroll.hours_per_day` × 60). 90 minutes at 40,000 cents a day: 90 × 40,000 / 480 = 7,500 cents. The rounding of this figure is PYR-ROUND-05. The daily rate reads the unconfirmed 22. | 07 section 9, LATE-005; `PayrollEngine` line 1250 | Implemented |
| PYR-LATE-03 | The ceiling is one third (`payroll.deduction_cap_ratio`, not confirmed) of the basic left after unpaid and sick leave, rounded down: 880,000 / 3 = 293,333 cents. STAT words the value as "of the month's wage"; 07 and the code use that basic. | 07 section 9, LATE-005; `PayrollEngine` line 1261 (`intdiv`) | Implemented |
| PYR-LATE-04 | Lateness takes only the headroom that loans and cost sharing leave under the ceiling: cap = max(0, ceiling − this period's loan instalments − cost sharing estimated on that same basic). Deduction = the smaller of the uncapped figure and the cap. Ceiling 293,333, loan 290,000: lateness 3,333 cents, not 7,500. | 07 section 9, LATE-006; [APPROVAL-ENGINE-DESIGN.md](../APPROVAL-ENGINE-DESIGN.md) (one total, decided 2026-10-28); `PayrollEngine` lines 1262 to 1270 | Decided |
| PYR-LATE-05 | The deduction is taken from **basic**, after unpaid and sick leave, before overtime, allowances, tax and pension. It is not taken from gross. E-LATE-1: basic 8,725.00, pension 610.75. | 07 section 9, LATE-005; `PayrollEngine` line 1271 | Decided |

## 9. Absences

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-ABS-01 | Unexplained absences are listed on the run and never deducted. | 07 PAY-019, RCN-006; AR-ABS-06; `PayrollEngine` lines 1288 to 1292 | Decided |

## 10. Overtime pay

The minutes of each kind (normal, night, rest day, holiday, holiday night) come from the attendance register (AR-OT-01 to AR-OT-14). This section turns them into cents.

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-OT-01 | Pay for one kind = monthly basic (PYR-SAL, before proration, leave and lateness) × minutes × multiplier / (working days × hours per day × 60), one fraction rounded once. The kinds are rounded separately and then added. Basic 8,800.00, 120 normal minutes at 1.5: 880,000 × 120 × 1.5 / 10,560 = 15,000 cents. The 22 is not confirmed. | 07 section 1.3, G8, PAY-036; CHG 2026-11-03; AR-OT-05; `api/app/Services/Payroll/OvertimeCalculator.php` `calculate()` | Implemented |
| PYR-OT-02 | The multiplier for each kind is the organisation's own rate where it set a higher one, else the floor of `overtime.minimum_rates`. A stored rate below the floor is raised to it. | 07 PAY-005; STAT (confirmed); `OvertimeCalculator::ratesFor()` | Decided |
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
| PYR-ALW-05 | An allowance that comes to 0 or less is left out. | `AllowanceService::resolve()` | Implemented |
| PYR-ALW-06 | The rules applied are those active when the run is computed, not rules dated for the period. | `AllowanceService::resolve()` (no date predicate) | Implemented |
| PYR-ALW-07 | What a fixed allowance pays in a period that holds Pagume days under `daily_rate`. The code pays it as in PYR-ALW-03, ignoring the Pagume step. | none | Undecided |

## 12. Gross and taxable income

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-GROSS-01 | Gross = basic (after PYR-PRO, PYR-PAG, PYR-LEAVE, PYR-SICK, PYR-LATE) + overtime + all allowances. G7: 8,000.00 + 0 + 1,400.00 = 9,400.00. | 07 section 1.4; SIGN step 6; `PayrollEngine` line 1349 | Decided |
| PYR-GROSS-02 | Taxable income = gross − non-taxable allowances. Overtime is taxable. G7: 9,400.00 − 600.00 = 8,800.00. | 07 section 1.4, G7, G8; SIGN step 7; `PayrollEngine` line 1360 | Decided |

## 13. Income tax

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-TAX-01 | From 2025-07-07, Proclamation 1395/2025, monthly, in cents: 0 to 200,000 at 0%; 200,001 to 400,000 at 15% less 30,000; 400,001 to 700,000 at 20% less 50,000; 700,001 to 1,000,000 at 25% less 85,000; 1,000,001 to 1,400,000 at 30% less 135,000; 1,400,001 and over at 35% less 205,000. A taxable figure falls in the band whose floor and top contain it: 200,000 cents is in the first band. The ladder was not read in the Gazette (RES T1, Medium; SIGN row 1 open). | 07 section 1.1; SIGN section 1; `api/database/seeders/TaxBracketSeeder.php` `PROCLAMATION_1395_2025` | Decided |
| PYR-TAX-02 | From 2016-07-08 to 2025-07-06, Proclamation 979/2016, in cents: 0 to 60,000 at 0%; 60,001 to 165,000 at 10% less 6,000; 165,001 to 320,000 at 15% less 14,250; 320,001 to 525,000 at 20% less 30,250; 525,001 to 780,000 at 25% less 56,500; 780,001 to 1,090,000 at 30% less 95,500; 1,090,001 and over at 35% less 150,000. 500,000 cents taxable: 697.50. | 07 section 1.1, PAY-025; `TaxBracketSeeder::PROCLAMATION_979_2016` | Decided |
| PYR-TAX-03 | Tax = taxable × rate, rounded, minus the band's deduction. This quick form gives the cumulative form's answer to the cent, because each deduction is whole cents. G4: 900,000 × 25% − 85,000 = 140,000 cents; cumulatively 300 + 600 + 500 = 1,400.00. RND-001: 350,050 × 15% = 52,507.5, rounded 52,508, less 30,000 = 22,508. | 07 section 1.1, RND-001; SIGN section 2; `TaxCalculator::apply()` | Decided |
| PYR-TAX-04 | The ladder is the one in force on the period start (PYR-PER-03). The change of ladder falls between 6 and 7 July 2025. RES T3 records that sources disagree on 1, 7 or 8 July. A period starting 2025-07-01 with 5,000.00 taxable is taxed 697.50 on 979/2016; it would be 500.00 on 1395/2025. | 07 section 1.1; RES T3 (UNCONFIRMED); `TaxCalculatorTest` "the boundary falls between 6 and 7 July 2025" | Implemented |
| PYR-TAX-05 | The ladder is taken whole from the first owner that has one in force: the organisation's own (saved before 2026-11-09 and still in force), then its kind's on the platform, then the kind's parent's, then every kind's. A date with no ladder refuses the run. | STAT "How a value is found"; 07 STAT-010; `TaxCalculator::resolve()` | Decided |
| PYR-TAX-06 | A taxable figure that falls in no band (below 0) is taxed 0. | `TaxCalculator::apply()` | Implemented |

## 14. Pension

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-PEN-01 | The scheme is the organisation's own `pension.default_scheme` row, else its kind's default (STAT). Every organisation that existed before 2026-10-27 has `private` as its own row. | 07 section 1.2, STAT-002; STAT | Decided |
| PYR-PEN-02 | Rates by scheme from `pension_rules`: private employee 7%, employer 11% (1268/2022 Art. 10); public 7% and 11% (1267/2022 Art. 10); public_military 7% and 33% (1267/2022 Art. 11). | 07 section 1.2, STAT-004; RES P1, P3, P4 (High) | Decided |
| PYR-PEN-03 | The pensionable amount is the entry's basic after proration, Pagume, unpaid leave, sick leave and lateness. G7: 8,000.00. SICK-002: 7,800.00. E-LATE-1: 8,725.00. | 07 section 1.2, section 12 ("so lowers pension too"), LATE-005; CHG 2026-11-06; `PayrollEngine` line 1377 | Decided |
| PYR-PEN-04 | Allowances and overtime are not pensionable. The seeded rule's base is `basic`. A rule with base `regular_salary` would add every allowance the run paid, percentage and non-taxable ones included, although the code's own comment says "fixed allowances"; no rule uses it. RES P7 leaves basic against regular-hours salary open; SIGN row 2 asks. | 07 section 1.2; SIGN section 1; RES P7 (UNCONFIRMED); `PensionCalculator::apply()` line 87; `PayrollEngine` line 1377 passes total allowances | Implemented |
| PYR-PEN-05 | Employee share = pensionable × employee rate, rounded; employer share = pensionable × employer rate, rounded; each separately. RND-001: 350,050 × 7% = 24,503.5, so 24,504; × 11% = 38,505.5, so 38,506. | 07 RND-001; `PensionCalculator::apply()` | Decided |
| PYR-PEN-06 | No ceiling and no floor on the pensionable amount. The code supports both; the seeded rules set neither. | RES P6 (Medium); `PensionCalculator::apply()` lines 100 to 106 | Implemented |
| PYR-PEN-07 | Nationality status `foreign` is exempt: both shares 0.00. The exemption must be acknowledged with its count at approval. | 07 section 1.2, PAY-018; RES P8 (High) | Decided |
| PYR-PEN-08 | Nationality status `ethiopian_origin` is exempt until RES P8's opt-in is settled. Unset counts as Ethiopian. | 07 section 1.2; RES P8 (UNCONFIRMED); `PensionCalculator::exemption()` | Implemented |
| PYR-PEN-09 | Every contract type is covered, casual and consultancy included (`pension.applies_to`, not confirmed; SIGN row 13). | STAT; RES P11 | Implemented |
| PYR-PEN-10 | The 45-day rule of 1268/2022 Art. 2 is not applied: a person hired 10 days before the period ends pays pension on their prorated basic. `min_service_days` is seeded empty. | RES P10 (UNCONFIRMED); `PensionCalculator::exemption()` | Implemented |
| PYR-PEN-11 | International organisations, diplomatic missions, sole proprietors, cooperatives and religious organisations are paid on the private scheme (their parent's) until SIGN row 11 decides. Basic 10,000.00 at an international organisation: 700.00 and 1,100.00. | STAT; RES O2, P9; SIGN row 11 | Implemented |
| PYR-PEN-12 | The employer share is not taken from the employee's pay. | SIGN step 10; `PayrollEngine` line 1451 | Decided |

## 15. Loans

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-LOAN-01 | Each active loan deducts its instalment or what it still owes, whichever is less; several loans are added. A loan with 400.00 left and a 1,000.00 instalment deducts 400.00. | 07 LOAN-002, LOAN-005; `api/app/Services/Payroll/LoanService.php` `deductionFor()` | Decided |
| PYR-LOAN-02 | Loans come off net pay only: they change neither taxable income nor pension. | 07 section 1.4; SIGN step 10 | Decided |
| PYR-LOAN-03 | Calculating moves no balance. Approval draws each balance down by exactly what the payslip deducted; voiding an approved run gives it back and reopens a loan the run closed. | 07 LOAN-002 to LOAN-004; `PayrollBalanceService` | Decided |
| PYR-LOAN-04 | The one-third ceiling does not reduce a loan. A loan over the ceiling is listed on the run's ceiling banner, only while lateness deductions are on. Loan 3,000.00 against a third of 2,933.33: 3,000.00 is deducted. | 07 LATE-007; `RunRegisterService::deductionCeiling()` | Decided |
| PYR-LOAN-05 | Any loan active when the run is computed deducts, whatever period the run covers. There is no loan start period. | `LoanService::getActiveLoans()` (no date predicate) | Implemented |

## 16. Cost sharing

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-CS-01 | The deduction is gross × the obligation's rate, rounded **down**, never more than the outstanding balance. Gross 6,300.55 at 10%: 630.05. | 07 CS-003; `api/app/Services/Payroll/CostSharingService.php` `calculateDeduction()` | Decided |
| PYR-CS-02 | A second active obligation for the same person is refused when it is created. | 07 CS-002 | Decided |
| PYR-CS-05 | If two active obligations exist anyway, only the earliest started one deducts. | `CostSharingService::getActiveObligation()` | Implemented |
| PYR-CS-03 | A suspended, completed or cancelled obligation deducts nothing. | 07 CS-004; `calculateDeduction()` (`isDeductible`) | Implemented |
| PYR-CS-04 | Cost sharing is not reduced by the one-third ceiling (as PYR-LOAN-04). | 07 section 9 ("lateness takes only what is left") | Decided |

## 17. Net pay and the order of deductions

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-NET-01 | Net = gross − income tax − employee pension − loans − cost sharing. Unpaid leave, sick leave and lateness are already inside basic and are not deducted again. `other_deductions` on the entry is loans + cost sharing. G7: 9,400.00 − 1,350.00 − 560.00 = 7,490.00. Because no deduction is capped, their order does not move a figure. | 07 section 1.4; SIGN step 10; `PayrollEngine` lines 1451, 1452, 1493 | Decided |
| PYR-NET-02 | Whether net pay may be negative, and what happens to the difference. The code has no floor. | none | Undecided |

## 18. Rounding

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-ROUND-01 | Every amount is an exact fraction of cents, rounded half away from zero (`integer_half_up`). Sums (gross, total deductions, net) add figures already rounded and are not rounded again. | 07 section 1.1, RND-001; CHG 2026-10-27; `api/app/Support/Cents.php` `mulDiv()`, `percentOf()` | Decided |
| PYR-ROUND-02 | Cost sharing rounds down (PYR-CS-01). The one-third ceiling rounds down (PYR-LATE-03). | 07 CS-003, section 9; `Cents::mulDivDown()`, `intdiv` | Decided |
| PYR-ROUND-03 | Where a figure is rounded more than once in the code: overtime once per kind (PYR-OT-01); pension once per share (PYR-PEN-05); tax once before the deduction (PYR-TAX-03); a `daily_rate` Pagume period once for its Pagume part, once for its other days, then once more for proration (PYR-PAG-03, PYR-PAG-05). | `OvertimeCalculator`, `PensionCalculator`, `TaxCalculator`, `PayrollEngine` lines 1163 to 1175 | Implemented |
| PYR-ROUND-04 | Whether the daily rate is rounded before it is multiplied. The code rounds it (line 1199), then rounds again when it multiplies by days in thousandths (lines 1203, 1228) and by minutes (line 1250). 07 sections 1.4 and 9 say "rounded to the cent"; 07 section 1.1 and CHG 2026-10-27 say every unpaid leave, sick leave and lateness amount is "rounded once". | 07 sections 1.1, 1.4, 9; CHG | Undecided |
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
| PYR-RUN-07 | The counterfactual for a released entry computes what it paid and what it would pay now, both with today's rules and data, differing only in salary and leaving date, and with the loans and cost-sharing rate taken from the entry's log. It writes nothing. | `PayrollEngine::counterfactual()` | Implemented |

## 20. Restated figures

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-RST-01 | The register reads every figure from the stored entry and its log, never recomputing. Its totals equal the run's gross, net and tax totals. | SIGN section 3; 07 OUT-003, OUT-009; `RunRegisterService::register()`; `PayrollGoldenPayslipsTest` "produces a register whose totals tie to the run and to the accounting journal" | Decided |
| PYR-RST-02 | The journal: debit 5100 salary expense = total gross; debit 5200 = total employer pension; credit 2100 = total tax; credit 2200 = employee pension; credit 2201 = employer pension; credit 2300 = total net; credit 1400 = loans withheld; credit 2400 = cost sharing withheld (the last two only when not zero). Debits equal credits. | 07 OUT-008; `api/app/Services/Accounting/AccountingExportService.php` | Decided |
| PYR-RST-03 | The income tax and pension schedules are built from the register's rows; a pension-exempt person is left out of the pension schedule. Their totals equal the register's. | 07 OUT-004, OUT-005, OUT-009; `StatutorySchedules.php` | Decided |
| PYR-RST-04 | The payslip prints basic already reduced by unpaid leave, sick leave and lateness, an overtime line when overtime was paid, each allowance by name, and gross; the lines add up. | 07 OUT-006 | Decided |

## 21. Bank file

| Id | Rule | Source | State |
|---|---|---|---|
| PYR-BANK-01 | A bank file is built only for an approved or paid run. | 07 OUT-001, PAID-005; `PayrollController::ensureApprovedForPayment()` | Decided |
| PYR-BANK-02 | Each row pays the entry's net into the person's primary account, else the first account on record. A person with no account keeps a row with an empty account, and the warning names them. | 07 OUT-002, OUT-012; `BankExportService::primaryBankDetail()` | Decided |
| PYR-BANK-03 | For a run in which no person's record was removed: bank file total = register total net = journal 2300 credit. A run holding only G1 to G6: 42,014.00 in all three. (An entry calculated before deductions were itemised keeps its unitemised other deductions in 2300, which then exceeds net by that amount; no current entry has any.) | 07 OUT-008, OUT-009; SIGN checklist row 5; `BankExportService::generateCsv()`, `PayrollController::bankExport()`, `AccountingExportService` line 130 | Decided |
| PYR-BANK-04 | Whether a payable entry in an approved, unpaid run stays payable when the person's record is removed, and whether a reprocess of that period must still pay them. | none | Undecided |
| PYR-BANK-05 | Whether the bank file must carry such an entry. Today the two files disagree: the server CSV (`BankExportService::generateCsv()`, lines 48 to 51) leaves the row out; the run page's file (`PayrollController::bankExport()`) keeps a row with no name and the net, and counts it in its total. | none | Undecided |

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
| `pension.default_scheme` | private for every kind; public for `government` (confirmed) and `public_enterprise` (not confirmed); public_military for `defence_police` (confirmed) | see value | PYR-PEN-01 |
| `pension.applies_to` | every contract type | no | PYR-PEN-09 |
| `fiscal_year.start_month` | 1 (Meskerem); 11 (Hamle) for `government` | no | none: logged in `inputs`, moves no figure (PYR-PER-04) |
| `pagume.proration` | `full_month` | no | PYR-PAG-01 to PYR-PAG-04 |
| `leave.sick.tiers` | 1 month full, 2 half, 3 unpaid | no | PYR-SICK-01 |
| `leave.sick.window_months` | 12 | no | PYR-SICK-01 |
| `leave.sick.half_pay_ratio` | 1/2 | no | PYR-SICK-02 |
| `payroll.deduction_cap_ratio` | 1/3 | no | PYR-LATE-03 |

The pension rates are `pension_rules` rows, not parameters (PYR-PEN-02). The tax ladders are `tax_brackets` rows (PYR-TAX-01, PYR-TAX-02).

## Waiting for a decision

These are the Undecided rows, in the order they matter to pay. Each needs the owner's ruling, and the accountant's where money moves, before a test can call the engine right or wrong on it. Until then a test reports a field that depends on one as undecided, and only when the rule's readings would give different answers for that input.

1. **PYR-BANK-04 and PYR-BANK-05**, a removed person in a released run. Does a registered payable entry stay payable when its employee's record is removed, and must the bank file carry it? Example: a run holding only G1 to G6 is approved; E-PAY-3's record (net 4,880.00) is then removed. Register total net and journal 2300: 4,201,400 cents. Server bank CSV: 3,713,400 cents, five rows. Run page bank file: 4,201,400 cents, six rows, one without a name. Reading one (stays payable, carried): every file 4,201,400. Reading two (no longer payable): 3,713,400 in the bank file, and the register and journal must then say why 488,000 is not paid. A reprocess after a void today leaves the person out without counting them as skipped (PYR-SAL-06).
2. **PYR-NET-02**, negative net pay. May net be below zero, and if not, what is reduced or carried? Example: basic 3,000.00, tax 150.00, pension 210.00, loan instalment 3,000.00. Code: net −36,000 cents, and the bank file carries −360.00. A floor at zero: net 0, with 36,000 cents of the loan either not taken this month or carried to the next. The same arises for a person hired after the period's end with an active loan (PYR-PRO-04): net −100,000 cents for a 1,000.00 instalment.
3. **PYR-ALW-07**, a fixed allowance in a `daily_rate` Pagume period. Is it paid in full or by the Pagume rule? Example: transport 600.00 in a 5-day Pagume period. Code: 60,000 cents. By the basic's Pagume rule: 60,000 × 60 / 365 = 9,863 cents.
4. **PYR-ROUND-04**, the daily rate rounded first or the whole deduction rounded once. Example: basic 10,000.00, 3 days of unpaid leave, 22 working days. Rounded first: 45,455 × 3 = 136,365 cents. Rounded once: 1,000,000 × 3 / 22 = 136,363.6, so 136,364 cents. The difference grows with the days, by up to half a cent a day.
5. **PYR-ROUND-05**, lateness rounded half up or down. Example: 2 minutes at a daily rate of 40,000 cents: 80,000 / 480 = 166.67. Half up: 167 cents. Down: 166 cents. At most one cent a payslip.

No row is Undecided only because a statutory value is unconfirmed: those rows are Implemented, and the accountant's sign-off ([ACCOUNTANT-SIGN-OFF.md](ACCOUNTANT-SIGN-OFF.md) rows 1, 2, 7 to 14) settles them. The ones that move a figure are PYR-RATE-01, PYR-RATE-03, PYR-LEAVE-02, PYR-SICK-01, PYR-SICK-02, PYR-LATE-02, PYR-LATE-03, PYR-OT-01, PYR-PAG-01 to PYR-PAG-05, PYR-SAL-03, PYR-TAX-04, PYR-PEN-04, PYR-PEN-06, PYR-PEN-08 to PYR-PEN-11.

## Where other documents say something else

Found on 2026-11-28 while this register was written. Where a later dated decision covers the point, the register follows it and the other text is to be corrected; where none does, the row is Undecided above.

| Document | What it says | The rule |
|---|---|---|
| SIGN section 1, Pension, and order step 8 | Pension on basic "after proration and unpaid leave", "step 3's figure"; the order has no sick leave or lateness step | PYR-PEN-03, decided later by A-Z E-08 (CHG 2026-11-06, 07 section 12) and the lateness work (07 section 9). SIGN's order needs steps for Art. 85 sick leave and lateness. |
| 07 section 1.1; CHG 2026-10-27 | Unpaid leave, sick leave and lateness are each "rounded once" | PYR-ROUND-04, Undecided: 07 sections 1.4 and 9 round the daily rate first, as the code does. |
| AR-LATE-03 | The lateness deduction is "rounded down" | PYR-ROUND-05, Undecided: 07 section 1.1 and the code round half up. |
| AR-LATE-03 | Lateness is capped "so that lateness, loans and cost sharing together stay within one third of basic" | PYR-LATE-04, PYR-LOAN-04: only lateness is capped; loans and cost sharing are never reduced, so the total can pass the third (07 LATE-007). |
| 07 OUT-002 | The bank file has "one row per entry" | PYR-BANK-05: the server CSV leaves out an entry whose person's record was removed. |
| STAT `payroll.deduction_cap_ratio` | "1/3 of the month's wage" | PYR-LATE-03: 07 section 9 and the code take the third of basic after unpaid and sick leave, not of gross. |
| `api/app/Services/Payroll/PensionCalculator.php` comment on `BASES` | `regular_salary` is basic "plus fixed allowances" | PYR-PEN-04: the engine passes every allowance, percentage and non-taxable ones included. No rule uses this base today. |

## Reachability of a removed person (observation for PYR-BANK-04)

`PayrollEntry::employee()` is a plain `belongsTo` and `Employee` uses `SoftDeletes`, so a removed record reads as no employee. Which product paths can remove a person who has an entry in a run not yet paid:

- **Single delete, `DELETE /api/v1/employees/{employee}`** (`api/routes/api.php` line 628, `EmployeeController::destroy()` lines 351 to 360): reachable. It checks only `employee.delete` and reach (`EmployeePolicy::delete()` lines 36 to 40), not payroll. A caller in the web interface was not found; that search was incomplete.
- **Bulk archive and the undo of an imported list** (`EmployeeArchiver::archive()` lines 60 to 84, via `EmployeeBulkController` and `ListUndo::archiveChunk()`): not reachable. They refuse anyone who has a `payroll_entries` row (`AdditionUse` line 61).
- **Discarding an import list** (`MigrationController::destroy()`): not reachable. Only a list that wrote nobody can be discarded.
- **Organisation deletion** (`AdminTenantController::destroy()` lines 1126 to 1168): not reachable. It removes the organisation's row only; employees stay.
- **SCIM deprovisioning** (`ScimUserController::destroy()`): not a removal. It suspends the employee.

What a removal then does: a **completed** run cannot be approved (PYR-RUN-03). An **approved** run that is not yet paid, or a **paid** run exported again, reaches the bank file as PYR-BANK-05 describes, while the register, schedules and journal keep the entry with an empty name. A **reprocess** after a void leaves the person out and does not count them as skipped.

## What this register does not cover

- Attendance minutes: late, early, worked and overtime minutes are [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md).
- Rules for civil servants under the Federal Civil Servants Proclamation 1353/2024, and government salary scales. LAW notes that Art. 85 sick pay is applied to every organisation kind, which it says is wrong for civil servants, and that the probation limit of Art. 85 is not applied; no document gives the civil-service rule, so there is no second figure to test against (SIGN rows 11 and 12, section 5).
- Legal exemption limits on non-taxable allowances. The engine has only a taxable flag (PYR-ALW-04); no source records a limit (SIGN row 6).
- Arrears and recoveries for a salary change back-dated into a month already paid, and how arrears are taxed (SIGN row 14).
- Severance, final pay and its deadline, and any deduction other than loans, cost sharing and lateness (court orders, union dues). LAW rows on Arts. 36 to 45 and 59.
- Remittance schedule layouts and bank-specific transfer formats (SIGN section 5; [STATUTORY-SCHEDULES.md](STATUTORY-SCHEDULES.md)).
- Who may process, approve, void and pay, and the refusals that protect a run: 07 sections 4, 5 and 10.
