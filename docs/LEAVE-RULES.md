# Leave rules: the register

Every rule the leave engine applies when it gives a person leave, counts the days a request charges, keeps a balance and hands days to payroll, in one place, each with an id. Golden cases for leave are typed from this file, and a person typing one should need nothing else. How attendance reads an approved leave day is in [ATTENDANCE-RULES.md](ATTENDANCE-RULES.md) section 8; the law as the team has read it is in [EMPLOYMENT-LAW-REGISTER.md](EMPLOYMENT-LAW-REGISTER.md). This file says what the answer must be.

**When a rule changes, change it here first**, then the code, then the golden case that cites its id. Other documents link to a rule id and do not restate its numbers.

**Leave accuracy is not certified.** Every statutory value in this file waits on counsel or HR in writing (section "Waiting for a decision"). A golden case that asserts one of them may be typed now, but it is reported as "rule undecided" until the item it depends on is signed.

## How to read a row

| State | Meaning |
|---|---|
| Decided | A document states it and the code was read to agree. The oracle implements it and a disagreement is a defect. |
| Implemented | The code does it and no document states it. The oracle copies it so that the tests can run, and the accuracy report counts these rules separately: agreement proves the behaviour is stable, not that it was chosen. |
| Undecided | Nobody has said what the answer should be, documents disagree with each other or with the code, or the law can be read more than one way. A statutory point not confirmed in writing by counsel or HR is Undecided even where the code picks a reading. The oracle does not guess: cases that depend on it are reported as "rule undecided", not as passes or failures. |

**A Decided row fixes the arithmetic, not the statutory values in it.** Where a Decided row's answer depends on a value or a reading that another row marks Undecided (the row names it as "value: LVR-..."), a case built on it is reported "rule undecided" until that row is ruled. Its worked example uses today's values.

A row marked **(legal)** waits on counsel or HR reading the law. The others wait on the owner, HR or the accountant choosing a behaviour.

Sources: **06** is [production testing/06-E2E-LEAVE-AND-APPROVALS.md](<production testing/06-E2E-LEAVE-AND-APPROVALS.md>), **07** is [production testing/07-E2E-PAYROLL.md](<production testing/07-E2E-PAYROLL.md>), **STAT** is [payroll/STATUTORY-PARAMETERS.md](payroll/STATUTORY-PARAMETERS.md), **LAW** is [EMPLOYMENT-LAW-REGISTER.md](EMPLOYMENT-LAW-REGISTER.md), **AZ** is [audit/AZ-REVIEW-2026-11-03.md](audit/AZ-REVIEW-2026-11-03.md), **SIGN** is [payroll/ACCOUNTANT-SIGN-OFF.md](payroll/ACCOUNTANT-SIGN-OFF.md), **AR** is [ATTENDANCE-RULES.md](ATTENDANCE-RULES.md). A path under `api/` is the code that was read. A line number is as read on 2026-11-28.

**What the law register holds for leave.** It has rows for Labour Proclamation 1156/2019 Arts. 81, 83, 84 (family events, unpaid days, summons) and Arts. 85, 86 (sick leave), all class 2: read from a re-typeset secondary copy, English column only. It has **no row** for Arts. 76 to 80 (annual leave, its seniority step, postponement), for Art. 88 (maternity) or for paternity leave. Every rule below that rests on one of those articles cites the code comment or the STAT row that names the article, says that the article text is not in the law register, and is Undecided (legal). STAT lists every `leave.*` row as `confirmed: no`, and SIGN row 12, which covers them, is not signed.

Dates are Gregorian unless a row says Ethiopian. Every example assumes no holiday is recorded in the span unless it names one, a Monday to Friday working week unless it names another, and a Gregorian leave year unless it names the Ethiopian.

**Holidays in the examples.** An organisation with the seeded public holidays has some inside the example spans: Genna on 7 January, Timket on 19 January, Adwa on 2 March. Each one recorded in a span lowers its working-day count by one. A golden case typed from an example must list the holidays its organisation has in the span.

**The Ethiopian months**, as `api/app/Services/Calendar/EthiopianCalendar.php` gives them (run on 2026-11-28). Year 2018 begins Meskerem 11 Sep 2025, Tikimt 11 Oct, Hidar 10 Nov, Tahsas 10 Dec, Tir 9 Jan 2026, Yekatit 8 Feb, Megabit 10 Mar, Miazia 9 Apr, Ginbot 9 May, Sene 8 Jun, Hamle 8 Jul, Nehase 7 Aug, Pagume 6 to 10 Sep 2026. Year 2019 has the same dates a year later, with Pagume 6 to 11 Sep 2027 (six days), so year 2020 begins on 12 September 2027. The entitlement date of Ethiopian month m (LVR-ACC-01) is its day 30; for month 12 it is the last day of Pagume (`LeaveYear.php` lines 115 to 124).

## 1. The leave year

| Id | Rule | Source | State |
|---|---|---|---|
| LVR-YEAR-01 | Balances run on the organisation's leave year: Ethiopian (Meskerem 1 to the last day of Pagume) unless the organisation chose Gregorian (1 January to 31 December). A new organisation gets the Ethiopian year. Ethiopian year 2019 runs from 11 September 2026 to 11 September 2027 (Pagume has six days that year). | 06 ground rules, LB-009, LB-010; `api/app/Services/Leave/LeaveYear.php` lines 13 to 41 | Decided |
| LVR-YEAR-02 | A balance row's year is the leave year's own number: 2019 for the Ethiopian year that starts on 11 September 2026, 2026 for the Gregorian year 2026. | 06 LB-010; `LeaveYear.php` `yearOf()` | Decided |
| LVR-YEAR-03 | An organisation that had leave used, pending or carried before 2026-11-02 kept the Gregorian year. Once any balance of the organisation has leave used, pending or carried, the leave year cannot change; a balance with only its entitlement is rebuilt under the new year. | 06 LB-009, LB-011; `LeaveYear.php` `canChange()`, `forExisting()` | Decided |
| LVR-YEAR-04 | Accrual counts twelve months in a leave year. On the Ethiopian year month 1 is Meskerem, and Pagume (5 or 6 days) is part of month 12: it earns no thirteenth month. A hire on 7 September 2026 (Pagume of Ethiopian year 2018) is counted as hired in month 12 of 2018. | `LeaveYear.php` lines 23 to 24, `monthOf()`, `monthEnds()` | Implemented |
| LVR-YEAR-05 | A balance screen shows the leave year of the organisation's today unless a year is asked for. | `api/app/Http/Controllers/Api/V1/Leave/LeaveRequestController.php` `balance()`, `employeeBalance()` | Implemented |

## 2. Entitlement

| Id | Rule | Source | State |
|---|---|---|---|
| LVR-ENT-01 | A type's yearly entitlement is its own `default_days`, plus `seniority_extra_days` for every whole `seniority_every_years` of completed service on the date the entitlement is taken (LVR-ACC-01, LVR-ACC-08), when both step fields are above zero and the hire date is before that date: `default_days + intdiv(completed years, every) x extra`. With today's values (16, every 2, extra 1) and 4 completed years: 16 + 2 x 1 = 18. Value: LVR-ENT-02; which year earns the first step: LVR-ENT-03 (both Undecided). | 06 ground rules, LB-002; `api/app/Services/Leave/LeaveBalanceService.php` lines 21 to 33 | Decided |
| LVR-ENT-02 | The statutory values: 16 working days of annual leave (`leave.annual.base_days`) and one more day for every 2 years (`leave.annual.seniority`), both citing Art. 77(1). The article text is not in the law register; STAT marks both rows unconfirmed; SIGN row 12 is not signed. | STAT; SIGN row 12; `api/database/migrations/2026_11_09_000005_add_leave_and_deduction_statutory_parameters.php` lines 94 to 97 | Undecided (legal) |
| LVR-ENT-03 | Which completed year earns the first extra day. The code gives `intdiv(y, 2)` extra days for y completed years: 16, 17, 17, 18, 18 for 1 to 5 years. A second reading (16 for the first year, then one for every two years after it, `intdiv(y minus 1, 2)`) gives 16, 16, 17, 17, 18. The two disagree at 2 and 4 completed years. The code's own docblock ("sixteen days in the first year, one more for every additional two years", lines 18 to 19) reads as the second reading, while line 29 implements the first. Worked in full in "Waiting for a decision", item 1. | `LeaveBalanceService.php` lines 18 to 19 (second reading) and line 29 (first); `api/tests/Feature/LeaveEntitlementLawTest.php` lines 44 to 57; `api/tests/Feature/Leave/LeaveEntitlementCharacterisationTest.php` lines 39 to 57 | Undecided (legal) |
| LVR-ENT-04 | Completed years are whole years from the hire date to the date the entitlement is taken, cut down. The anniversary day itself completes the year. A hire on 29 February completes each year on 1 March in a common year. Hired 2024-06-01: 1 year on 2026-05-31, 2 on 2026-06-01. Hired 2024-02-29: 1 year on 2026-02-28, 2 on 2026-03-01, 4 on 2028-02-29. | `LeaveBalanceService.php` line 28 (`diffInYears`, cast to int); run against Carbon 3.15.1 on 2026-11-28 | Implemented |
| LVR-ENT-05 | The organisation's own leave type is what pays. The statutory parameter is copied into a new type when the organisation is set up, and checked as a floor on `default_days` when a type coded `annual`, `maternity` or `paternity` is saved. A later change of the parameter changes no existing type; a type already below the floor keeps paying its days until edited; a type with any other code, for example `annual_ls`, is not held. An annual type saved at 15 days before #682 still grants 15. | 06 LT-006; `api/app/Services/Onboarding/LeaveTypeCatalog.php` lines 176 to 180, 250 to 266; `api/app/Http/Requests/Leave/Concerns/ValidatesLeaveTypeIntegrity.php` lines 60 to 72 | Decided |
| LVR-ENT-06 | The seniority step is not held to the law. STAT marks `leave.annual.seniority` as a locked minimum, but the floor check reads `default_days` only, and an organisation may set the step to anything or clear it. Annual 16 with the step cleared gives a ten-year employee 16 days, against 21 with it. | STAT; `ValidatesLeaveTypeIntegrity.php` lines 53 to 72; `LeaveEntitlementLawTest.php` lines 117 to 137 | Undecided |
| LVR-ENT-07 | Who is entitled, and from when. Every employee accrues from the hire month, probation and the first year included; nothing waits. Whether annual leave is earned during probation, or only after a qualifying period, is not in the law register. Hired 2026-03-10 on probation: 5.3 days from 1 June (March to June, LVR-ACC-01). | `LeaveBalanceService.php` `monthsEmployed()`; no probation check in `api/app/Services/Leave` or `LeaveRequestController.php` | Undecided (legal) |
| LVR-ENT-08 | An employee with no hire date gets no seniority step, and monthly accrual counts them from month 1 of the year. | `LeaveBalanceService.php` lines 27, 46 | Implemented |
| LVR-ENT-09 | A type restricted to one gender (maternity: women; paternity: men) is not accrued, not offered and refused on request for anyone else, including a person with no gender recorded. | 06 LR-008; `api/app/Models/LeaveType.php` `isAvailableForGender()`; `LeaveBalanceService.php` line 125 | Decided |

## 3. Accrual

| Id | Rule | Source | State |
|---|---|---|---|
| LVR-ACC-01 | A type with monthly accrual is topped up to a target: `round(yearly entitlement x months employed this leave year / 12, 1)`. The months employed include the current month, so a month's share is credited in advance, by the 00:30 UTC run on the month's first day (an Ethiopian month's first day on that year). The code takes the entitlement at the last day of the current leave month; that choice is LVR-ACC-07 (Undecided). 16 days, no step, employed all of Gregorian 2026: 1.3 from 1 January, 2.7 from 1 February, 16.0 from 1 December. A 20-day type has 1.7 on 1 January. | 06 LB-003, LB-010; `LeaveBalanceService.php` lines 116, 129 to 142; `api/tests/Feature/LeaveAccrualLogicTest.php` lines 14 to 36, 120 to 139 | Decided |
| LVR-ACC-11 | Rounding of the target is PHP `round()` to one decimal, half away from zero, on the exact quotient, and the rounded value is what is stored. 17 x 3 / 12 = 4.25 is stored as 4.3; 16 x 1 / 12 = 1.333 as 1.3. | `LeaveBalanceService.php` lines 134, 137; run on 2026-11-28 | Implemented |
| LVR-ACC-02 | Accrual runs every day at 00:30 UTC and recomputes the target, so a repeat changes nothing and a missed day is caught up. The target is rounded, not each month's share, so twelve months give exactly the entitlement: 20 days give 20.0, not 20.4 (1.7 x 12). | 06 LB-003; `api/routes/console.php` lines 108 to 115; `api/app/Jobs/AccrueLeaveBalancesJob.php`; `LeaveAccrualLogicTest.php` lines 38 to 60, 95 to 116 | Decided |
| LVR-ACC-03 | Accrual never lowers a balance. When the target is below the entitled days already on the balance, nothing changes. | 06 LB-003; `LeaveBalanceService.php` lines 137 to 142 | Decided |
| LVR-ACC-04 | Months employed run from the hire month (or month 1 when hired in an earlier year) to the leaving month (or the current month), each counted whole however few days of it were worked. Hired 2026-03-31: from 1 June 4 months, 16 x 4 / 12 = 5.3. Hired 2026-01-01 and left 2026-02-20: on the Gregorian year 2 months, 2.7, and still 2.7 in September; on the Ethiopian year those dates fall in Tahsas, Tir and Yekatit of 2018, 3 months, 4.0. Whether a part month should earn a whole one is LVR-ACC-05. | `LeaveBalanceService.php` lines 41 to 68; `LeaveEntitlementLawTest.php` lines 70 to 97 | Implemented |
| LVR-ACC-05 | How a person who joins or leaves part-way through a year earns leave: a part month counted whole (the code), only complete months, or by days. The law register has no text on it; the test file's header says Art. 77 asks for leave "proportional to service", which is the team's reading, not a register row. Worked in "Waiting for a decision", item 8. | `LeaveEntitlementLawTest.php` lines 14 to 18 | Undecided (legal) |
| LVR-ACC-06 | A termination date recorded after accrual has run leaves the days already accrued (LVR-ACC-03). Accrued 8.0 by 1 June 2026, then a termination date of 2026-02-20 recorded: the target is 2.7 and the balance stays 8.0. An employee whose status says they left but who has no termination date keeps accruing. | `LeaveBalanceService.php` lines 56 to 65, 137 to 142 | Implemented |
| LVR-ACC-07 | A seniority step reached part-way through the year, on a monthly type, applies to the whole year back to month 1, because the target uses the entitlement at the current month's end (`monthEnds()`). The step therefore counts from the first day of the anniversary's month. Hired 2024-03-15, Gregorian 2026, annual 16 with the step: from 1 February 16 x 2 / 12 = 2.7; from 1 March, two weeks before the anniversary, 17 x 3 / 12 = 4.25, stored as 4.3 (LVR-ACC-11); from 1 December 17.0. Weighting each month by the rate in force gives 16.8. Worked in "Waiting for a decision", item 7. | `LeaveBalanceService.php` lines 116, 134 | Undecided (legal) |
| LVR-ACC-08 | A type with annual or one-time accrual gets its whole yearly entitlement when the year's balance is first created, taken at the first day of the leave year, and never recomputed. A joiner is not prorated, and a step reached later in the year waits for the next year. A 30-day sick type gives a person hired 2026-06-01 the full 30 for 2026. An annual-accrual type with the step gives a person hired 2024-03-15 no step for 2026 (1 completed year on 1 January 2026) and the step from 2027. | `LeaveBalanceService.php` lines 71 to 91; `LeaveAccrualLogicTest.php` lines 78 to 91 | Implemented |
| LVR-ACC-09 | A one-time type (maternity, paternity, marriage, sabbatical and work injury in the catalogue) refills every leave year exactly as an annual type: `one_time` is read nowhere. AZ E-07 expects one entitlement per occurrence. Marriage leave of 3 days is 3 again every year. | `LeaveBalanceService.php` line 73; `api/app/Enums/AccrualType.php`; AZ E-07, U-E4 | Undecided |
| LVR-ACC-10 | An opening balance imported above the accrual target holds accrual still until the target passes it. 10.0 imported for a 16-day monthly type: nothing is added until month 8, when the target 10.7 adds 0.7. AZ E-12 proposes keeping imported leftovers apart (U-E8). | 06 LB-005; `LeaveBalanceService.php` lines 134 to 142; AZ E-12 | Undecided |

## 4. Carry-over

| Id | Rule | Source | State |
|---|---|---|---|
| LVR-CARRY-01 | On each of the first seven days of the leave year, at 01:00 UTC, every active type with carry forward on carries each old-year balance with remaining above zero: carried = the smaller of remaining and `max_carry_days`, or all of remaining when no cap is set. It is assigned, not added, so a repeat changes nothing. 2025: entitled 20, used 8, cap 5: carried into 2026 is 5. | 06 LB-004; `LeaveBalanceService.php` lines 149 to 201; `api/app/Jobs/CarryForwardLeaveBalancesJob.php`; `console.php` lines 117 to 131; `LeaveAccrualLogicTest.php` lines 162 to 190 | Decided |
| LVR-CARRY-02 | The setup wizard's annual type carries forward, capped at 16. A type the wizard creates otherwise carries nothing. | 06 LT-001; `LeaveTypeCatalog.php` lines 33 to 34, 230 to 231 | Decided |
| LVR-CARRY-03 | Carried days never expire. Remaining includes the old year's own carried days, so they are carried again, inside the cap, and with no cap they pile up. 2025: entitled 16, carried 16, used 0: remaining 32, carried into 2026 is 16 with the cap of 16 and 32 with none. AZ E-11 expects carried days to be used first and then to lapse. | `api/app/Models/LeaveBalance.php` lines 63 to 70; AZ E-11, U-E7 | Undecided |
| LVR-CARRY-04 | How long postponed annual leave may be kept, and whether it may be postponed at all (Art. 79). The law register has no row for Art. 79; its only mention is AZ U-E7, which asks for it to be confirmed. Nothing in the code applies it. | AZ U-E7 | Undecided (legal) |
| LVR-CARRY-05 | Pending days on the last day of the year reduce what is carried. A run on days 1 to 7 recomputes, so a decision in that week moves the carry; a decision after day 7 does not. 2025: entitled 16, used 0, pending 5 on 31 December: carried 11. Rejected on 10 January: the 2025 balance's remaining goes back to 16, and 2026's carried stays 11. | `LeaveBalance.php` `remainingDays()`; `console.php` line 124 | Implemented |
| LVR-CARRY-06 | Carry is computed for every balance row of the old year, including a person who has left, as long as the employee row exists. | `LeaveBalanceService.php` lines 172 to 196 | Implemented |

## 5. Counting the days a request charges

| Id | Rule | Source | State |
|---|---|---|---|
| LVR-DAYS-01 | A request charges the days in its span that the person is scheduled to work (their rotation, else their shift's working days, else the organisation's working week, Monday to Friday unless set), minus active holidays of the whole organisation or of the person's branch. The schedule is resolved one day at a time, by the assignment that governs that day (AR-SHIFT-01, AR-SHIFT-02). A rotation is a cycle of `cycle_days` steps counted from its anchor date; a day's step is its distance from the anchor modulo the cycle, and a step with no shift, or an offset with no step, is a rest day, which is not charged (AR-SHIFT-03). Monday 2025-01-06 to Friday 2025-01-10 with a holiday on 8 January: 4 (with Genna on 7 January recorded too: 3). Monday 2025-01-06 to Saturday 2025-01-11 on a Monday to Saturday shift: 6; on Monday to Friday: 5. A 4-day rotation (work, work, rest, rest) anchored on Monday 2026-06-01, leave Monday 1 to Sunday 7 June: 1, 2, 5 and 6 June are charged, 4 days. That annual leave is counted in working days with holidays excluded is itself statutory: value LVR-ENT-02 (Undecided). | 06 ground rules, LR-020; `api/app/Services/Leave/LeaveDayCalculator.php`; `api/app/Services/Attendance/ShiftMatcher.php` `workingDays()`; `api/app/Services/Shift/ShiftRotationResolver.php` `offsetFor()`; `api/tests/Unit/LeaveDayCalculatorTest.php` | Decided |
| LVR-DAYS-02 | A type counted in calendar days (maternity in the catalogue) charges every day of the span, rest days and holidays included. 14 consecutive days charge 14. | 06 LR-021; `LeaveRequestController.php` lines 85 to 95 | Decided |
| LVR-DAYS-03 | There are no half days. Every charged day counts 1, so every request charges a whole number. Two readers are written to accept a fraction (`ExpectedDays.php` line 212, the comment at `PayrollEngine.php` line 1200); no request path creates one. AR-ABS-07 is the attendance side. | 06 ground rules; `LeaveDayCalculator.php` `calculateDays()`; AZ U-E3 | Decided |
| LVR-DAYS-04 | A span with no charged day is refused: a request for Saturday and Sunday only. | 06 LR-003; `LeaveRequestController.php` lines 97 to 104 | Decided |
| LVR-DAYS-05 | A request that crosses the leave year's end is charged wholly to the year it starts in, and every later move (approval, rejection, cancel, recall) uses that year's balance. AZ E-04 expects the days split between the two years. Worked in "Waiting for a decision", item 5. | 06 ground rules, LR-014; `LeaveRequestController.php` line 143; `api/app/Services/Leave/LeaveDecisionService.php` line 189; AZ E-04, U-E5 | Undecided |
| LVR-DAYS-06 | The days of a request are counted once, when it is filed, and stored. The balance, approval, rejection and cancel use the stored number; a holiday or shift changed later does not change it. Three readers count again from today's calendar instead: ending leave early (the kept days), payroll for a request that crosses a pay period's edge, and the sick-leave tiers. A Monday to Friday request stores 5; HR then adds a holiday on the Wednesday; cancelling gives back 5; ending it on Thursday keeps 3 (Monday, Tuesday, Thursday) and gives back 2. | `LeaveRequestController.php` line 195; `api/app/Services/Leave/LeaveRecallService.php` `daysUntil()`; `api/app/Services/Payroll/PayrollEngine.php` lines 1532 to 1538; `api/app/Services/Leave/SickLeaveTiers.php` `datesOf()` | Implemented |
| LVR-DAYS-07 | For attendance, a holiday or rest day inside a leave is a holiday or rest day, not a leave day; the other days of approved leave are on leave. | AR-ABS-01; `api/app/Services/Attendance/ExpectedDays.php` | Decided |

## 6. The balance

| Id | Rule | Source | State |
|---|---|---|---|
| LVR-BAL-01 | Remaining = entitled + carried minus used minus pending, rounded to one decimal. Entitled 16, carried 3, used 5, pending 2: remaining 12. | 06 ground rules; `LeaveBalance.php` lines 63 to 70 | Decided |
| LVR-BAL-02 | Filing a request adds its days to pending at once. A 5-day request on remaining 12 leaves 7. | 06 LR-001; `LeaveRequestController.php` line 202 | Decided |
| LVR-BAL-03 | A request must fit the remaining days of its year's balance when the type is limited: a type of the kind `leave` that is paid or grants more than 0 days. Duty and excused types, and an unpaid type with 0 days, are not limited. Remaining 4.3: a 4-day request is accepted and a 5-day request refused. An unpaid type with 0 days accepts 5. | 06 LT-005, LR-006, LR-016; `LeaveType.php` `limitedByBalance()`; `LeaveRequestController.php` lines 164 to 171 | Decided |
| LVR-BAL-04 | Pending days are added even for a type that is not limited, so its remaining goes below zero. An unpaid type with 0 days and a pending 5-day request shows remaining minus 5. | `LeaveRequestController.php` line 202 | Implemented |
| LVR-BAL-05 | Approval moves the request's days from pending to used and does not check the balance again. | 06 LD-001, LR-022; `LeaveDecisionService.php` lines 107 to 112 | Decided |
| LVR-BAL-06 | Rejection, and the employee cancelling a pending request, take its days off pending. The employee cannot cancel an approved request. | 06 LD-002, LR-011, LR-012; `LeaveDecisionService.php` lines 153 to 157; `LeaveRequestController.php` lines 357 to 412 | Decided |
| LVR-BAL-07 | Requests of one person are taken one at a time, from the balance check to the pending days, so two filed together cannot both pass. Remaining 3 and two 3-day requests at once: one accepted, one refused. | 06 LR-022; `LeaveRequestController.php` lines 131 to 205 | Decided |
| LVR-BAL-08 | An Art. 85 sick type is not held to its yearly days (30 in the catalogue). Its card shows the days taken in the current twelve-month period (approved days only; a pending request is not counted), the days allowed and the tier of the next sick day, never a negative remaining. The API still returns `remaining_days` as LVR-BAL-01 computes it, which goes below zero after a long illness: entitled 30, used 41 gives minus 11. | 06 LR-018, LB-012; `api/app/Http/Resources/LeaveBalanceResource.php`; `SickLeaveTiers.php` `standing()` | Decided |
| LVR-BAL-09 | An opening-balance import replaces entitled, used and carried for its rows, and writes nothing while any row is invalid. | 06 LB-005, LB-006 | Decided |

## 7. Whether a request is accepted

| Id | Rule | Source | State |
|---|---|---|---|
| LVR-REQ-01 | An employee's request must start on or after today. HR filing on someone's behalf may date it in the past. | 06 LR-004, LR-017 | Decided |
| LVR-REQ-02 | Minimum notice is whole days from the organisation's today: with 7 days' notice and today 2026-12-01, the earliest start is 2026-12-08. HR filing on someone's behalf is not held to it. | 06 LR-009; `LeaveRequestController.php` lines 106 to 120 | Decided |
| LVR-REQ-03 | A type's maximum consecutive days is compared with the charged days of one request. Maximum 2, a request of 3 working days: refused. | 06 LR-010; `LeaveRequestController.php` lines 122 to 129 | Decided |
| LVR-REQ-04 | A request whose span overlaps a pending or approved request of the same person is refused. | 06 LR-007; `LeaveRequestController.php` lines 173 to 187 | Decided |
| LVR-REQ-05 | Neither the person on leave nor the person who filed it for them may approve it. | 06 LR-017, LD-003; `LeaveDecisionService.php` lines 44 to 54 | Decided |
| LVR-REQ-06 | Approving an unpaid or an Art. 85 sick request is refused while any of its days lies in an approved payroll period. Other paid leave is approved there. | 07 PAY-038, SICK-004; `LeaveDecisionService.php` lines 56 to 69 | Decided |

## 8. Recall and cancellation of approved leave

| Id | Rule | Source | State |
|---|---|---|---|
| LVR-RECALL-01 | HR may cancel approved leave: it becomes cancelled and its stored days come off used. An approved 5-day request cancelled: used down 5, remaining up 5. This section is the product's own rule for the balance, stated by 06; whether the law lets an employer recall a worker from annual leave, and what is then owed, is not in the law register (Waiting item 17). | 06 LD-010; `LeaveRecallService.php` lines 70 to 84 | Decided |
| LVR-RECALL-02 | HR may end approved leave early. The new last day must be on or after the first day and before the current last day, and must leave at least one charged day. The kept days are counted again up to the new last day (calendar days for a calendar type), and stored days minus kept days come off used. Monday to Friday ended on Wednesday: 3 kept, 2 given back. | 06 LD-011, LD-013; `LeaveRequestController.php` lines 451 to 460; `LeaveRecallService.php` `daysUntil()` | Decided |
| LVR-RECALL-03 | Nobody may recall their own leave, and a recall is refused when any day it gives back lies in an approved payroll period, whatever the type. | 06 LD-012, LD-014; `LeaveRequestController.php` lines 432 to 467 | Decided |
| LVR-RECALL-04 | The days go back to the balance of the year the request was charged to (LVR-DAYS-05). | 06 section 4; `LeaveRecallService.php` line 78 | Decided |
| LVR-RECALL-05 | Used days are never set below zero by a recall. | `LeaveRecallService.php` line 83 | Implemented |

## 9. Sick leave

| Id | Rule | Source | State |
|---|---|---|---|
| LVR-SICK-01 | A type marked `follows_art85` (every type with the code `sick`, the wizard's Sick Leave) follows the tiers below. Any other paid type is a plain yearly bucket. | 07 section 12; 06 section 3; `SickLeaveTiers.php` lines 18 to 36; `LeaveTypeCatalog.php` line 48 | Decided |
| LVR-SICK-02 | Sick days are the days the leave charges (LVR-DAYS-01), or every day for a type counted in calendar days. | 07 section 12; `SickLeaveTiers.php` `datesBetween()` | Decided |
| LVR-SICK-03 | The code counts a month of sick leave as `payroll.working_days_per_month` in force for the organisation: 22 unless the organisation set another number from 20 to 31. Whether a month in the law is this or a calendar month: value LVR-SICK-07 (Undecided). | 07 section 12; STAT; `LeaveRequestController.php` line 151; `SickLeaveTiers.php` line 148 | Decided |
| LVR-SICK-04 | A period starts on the first sick day (approved, and pending when a request is being filed) that is not already inside a period, and ends on that day plus `window_months` months minus 1 day, that last day inside. This row fixes the end-date arithmetic only. The code's window is fixed, not rolling: value LVR-SICK-05; the length: value LVR-SICK-07 (both Undecided). With today's 12 months and first day 2026-04-01: the period ends 2027-03-31, and a sick day on 2027-04-01 starts a new period at full pay. First day 2028-02-29: the period ends 2029-02-28 (run on 2026-11-28). | 06 section 3; `SickLeaveTiers.php` lines 77 to 87; `api/tests/Feature/Leave/SickLeaveArt85Test.php` lines 188 to 194 | Decided |
| LVR-SICK-05 | Whether the law's twelve months are that fixed window or any twelve months looking back from each sick day. LAW reads "six months in any twelve from the first day of sickness". Worked in "Waiting for a decision", item 3. | LAW Arts. 85, 86 | Undecided (legal) |
| LVR-SICK-06 | The tier of the n-th charged sick day of a period, with a month of M days: full pay up to `full_months` x M, half pay up to (`full_months` + `half_months`) x M, unpaid up to (`full_months` + `half_months` + `unpaid_months`) x M, beyond after that. Value: LVR-SICK-07 (Undecided). With today's 1, 2, 3 and M = 22: days 1 to 22 full, 23 to 66 half, 67 to 132 unpaid, 133 and later beyond. | 07 section 12; `SickLeaveTiers.php` `tierFor()`; `LeaveEntitlementCharacterisationTest.php` lines 59 to 77 | Decided |
| LVR-SICK-07 | The statutory values: 1 month full, 2 half, 3 unpaid (`leave.sick.tiers`), a window of 12 months (`leave.sick.window_months`), half pay as one half (`leave.sick.half_pay_ratio`), and a month read as 22 working days rather than a calendar month. LAW rates Arts. 85, 86 class 2; STAT marks all three rows unconfirmed. Worked in "Waiting for a decision", item 4. | LAW; STAT; SIGN row 12 | Undecided (legal) |
| LVR-SICK-08 | Each period takes the tiers and window in force on its own first day. | `SickLeaveTiers.php` lines 78 to 81 | Implemented |
| LVR-SICK-13 | The month length M is not read per sick period. Payroll reads it for the pay period being run, the request check at the request's start date, and the balance card at today. A change of `payroll.working_days_per_month` therefore re-tiers past sick days. 30 charged days with M = 22: days 23 to 30 at half pay; after the organisation sets 24, a later run reads days 25 to 30 at half pay. | `PayrollEngine.php` lines 1085, 1220; `LeaveRequestController.php` line 151; `SickLeaveTiers.php` line 148 | Implemented |
| LVR-SICK-09 | A request is refused when any of its days would be beyond (LVR-SICK-06) in its period, counting pending requests too. Value: LVR-SICK-07 (Undecided). With today's limit of 132, a first period with 110 approved charged days: a further 30-day request is refused (days 111 to 140); a 20-day request is accepted (days 111 to 130). | 06 LR-019; `LeaveRequestController.php` lines 150 to 163 | Decided |
| LVR-SICK-10 | A sick day beyond six months that was approved anyway is paid as unpaid. | `SickLeaveTiers.php` lines 45 to 46, 173 to 175 | Implemented |
| LVR-SICK-11 | Sick leave is given during probation, and nothing checks the next-day notice or a medical certificate. LAW says Art. 85 sick leave applies after probation only, and asks for notice and a certificate. | LAW Arts. 85, 86; AZ E-09 | Undecided (legal) |
| LVR-SICK-12 | Every kind of organisation gets the same tiers. LAW says federal civil servants follow 1353/2024 Art. 66, whose two languages disagree (Amharic: 4 months full, 2 half; English: 6 full, 2 half; neither unpaid). | LAW Arts. 85, 86 and 1353 Art. 66; STAT (types "all") | Undecided (legal) |

## 10. Maternity, paternity and the other types

| Id | Rule | Source | State |
|---|---|---|---|
| LVR-OTHER-01 | Maternity leave is for women only, counted in calendar days, and its type may not grant fewer days than `leave.maternity.days`. Value: LVR-OTHER-02 (Undecided), which also covers whether maternity is consecutive calendar days and who is entitled to it. With today's 120, a 14-day request leaves 106 while pending. | 06 LT-001, LT-006, LR-008, LR-021; `LeaveTypeCatalog.php` lines 51 to 63 | Decided |
| LVR-OTHER-02 | The maternity value: 120 days citing Art. 88, with a parameter note of 30 days before and 90 after confinement. The article text is not in the law register; STAT marks the row unconfirmed; nothing in the code applies a before and after split. A request of 120 days starting on the day of birth is accepted. | STAT; migration line 98 to 99 | Undecided (legal) |
| LVR-OTHER-03 | Paternity leave: men only, counted in working days, 3 days (`leave.paternity.days`). No article is cited anywhere; the parameter note says the citation is still to do. | 06 LT-001, LT-006; STAT; migration lines 100 to 101 | Undecided (legal) |
| LVR-OTHER-04 | Bereavement leave is 3 days a year (annual accrual) and marriage leave 3 days, refilled each year (LVR-ACC-09). LAW reads Arts. 81, 83, 84 as three working days paid for marriage or for the death of a listed relative, which reads as per event. Two deaths in one year: 3 days in all by the code, 6 by the per-event reading. | 06 LT-001; `LeaveTypeCatalog.php` lines 74 to 91; LAW Arts. 81, 83, 84 | Undecided (legal) |
| LVR-OTHER-05 | Unpaid leave: 0 days, not limited (LVR-BAL-03), and every approved day is deducted in payroll (LVR-PAY-02). LAW reads up to five unpaid days for exceptional events, twice a budget year (Arts. 81, 83, 84), and says unpaid leave granted at the worker's request is a suspension of the contract (Art. 17(2)). 12 unpaid days in one year are all accepted today. | 06 LR-016; `LeaveType.php` `limitedByBalance()`; LAW | Undecided (legal) |
| LVR-OTHER-06 | Study (10 days), sabbatical (180), work injury (30) and rest and recuperation (5) are employer defaults the organisation is expected to edit, not law. | 06 LT-001; `LeaveTypeCatalog.php` lines 17 to 20, 92 to 132 | Decided |
| LVR-OTHER-07 | Official duty and excused absence are paid, draw on no balance, and appear on no balance card. Their approved days are on leave for attendance. | 06 LT-005; `LeaveType.php` `isLeave()`, `limitedByBalance()` | Decided |
| LVR-ORG-01 | No leave value differs by kind of organisation: every `leave.*` row applies to all kinds (STAT). LAW says that is wrong for federal civil servants' sick leave (LVR-SICK-12), and has no civil-service annual leave row to compare. | STAT; LAW 1353 Art. 66 | Undecided (legal) |
| LVR-ORG-02 | A government profile provisions study leave as well; private and NGO profiles do not. The days of each type are the same for every profile. | `api/app/Services/Onboarding/PolicyProfileCatalog.php` lines 54, 70, 86 | Implemented |

## 11. What payroll reads from leave

| Id | Rule | Source | State |
|---|---|---|---|
| LVR-PAY-01 | Paid leave changes no pay. | `PayrollEngine.php` lines 1195 to 1197 | Implemented |
| LVR-PAY-02 | Unpaid leave: the approved days of a type that is not paid and not Art. 85 inside the pay period, times the daily rate (contract monthly basic divided by working days per month), taken from prorated basic and never more than it. Basic 8,800.00 over 22 days is 400.00 a day; 3 unpaid days deduct 1,200.00. | 07 section 12 (the daily rate); `PayrollEngine.php` lines 1195 to 1213, 1505 to 1542 | Decided |
| LVR-PAY-03 | Art. 85 sick leave: daily rate times (unpaid and beyond days, plus half-pay days times one minus the half-pay ratio), taken from basic after unpaid leave and before lateness, and never more than the basic left. The lower basic lowers pension too (07 section 12). The daily rate is `Cents::mulDiv(basic cents, 1, M)`, rounded once to the cent, half away from zero; days are carried in thousandths and the deduction is `Cents::mulDiv(daily rate, thousandths, 1000)`, rounded the same way. Value of the ratio and the tiers: LVR-SICK-07 (Undecided). With today's ratio of one half: basic 8,800.00, 5 half-pay days at 400.00: 1,000.00. Basic 5,000.00: daily rate 500000 / 22 = 22727.27, so 22727 cents; 5 half-pay days are 2500 thousandths, 22727 x 2500 / 1000 = 56817.5, so 56818 cents. | 07 section 12, SICK-002; `PayrollEngine.php` lines 1215 to 1239 | Decided |
| LVR-PAY-04 | An Art. 85 day is never counted as unpaid leave as well. | 07 section 12; `PayrollEngine.php` line 1523 | Decided |
| LVR-PAY-05 | Payroll reads no leave balance. A leaver's final pay holds nothing for unused leave and recovers nothing for leave taken beyond what was earned. | search of `api/app` for every reader of `LeaveBalance` and `remainingDays()`: none in `Services/Payroll` | Implemented |

## Waiting for a decision

These are the Undecided rows, in the order they matter to pay and to the law, with two Implemented rows the owner should confirm (LVR-ACC-08 in item 7, LVR-CARRY-05 in item 21), and four questions for counsel that no row of the law register covers (items 17 to 20). Each can be handed to HR or counsel as it stands. Until an item is ruled on, a golden case that depends on it reports "rule undecided", and only when the readings give different answers for that input. Items marked **legal** need counsel or HR to read the law in writing.

1. **Annual leave seniority step (LVR-ENT-03). Legal. Counsel.**
   Question: after how many completed years does an employee first earn the extra day of Art. 77(1)?
   Reading A (the code, 06 LB-002): one extra day for every two completed years, so the first extra day comes after 2 completed years.
   Reading B (the code's own docblock, `LeaveBalanceService.php` lines 18 to 19): 16 days cover the first year, and one day is added for every two years after the first, so the first extra day comes after 3 completed years.
   The table below is the answer each reading gives; a golden case uses the table, not a formula.

   | Completed years | Reading A | Reading B |
   |---|---|---|
   | 1 | 16 | 16 |
   | 2 | 17 | 16 |
   | 3 | 17 | 17 |
   | 4 | 18 | 17 |
   | 5 | 18 | 18 |

   Example: hired 2022-06-01, entitlement taken on 2026-12-31 (4 completed years): 18 days by A, 17 by B. 06 LB-002 asserts 18.

2. **The statutory leave values (LVR-ENT-02, LVR-OTHER-02, LVR-OTHER-03). Legal. Counsel, then the accountant for SIGN row 12.**
   Question: are these the law's figures, from an official copy? Annual 16 working days (Art. 77(1)); maternity 120 calendar days, 30 before and 90 after confinement (Art. 88); paternity 3 days (no article cited). None has a law register row.
   Two further questions, also not in the law register: is annual leave counted in working days with public holidays excluded (LVR-DAYS-01), and is maternity leave consecutive calendar days, and for whom (LVR-OTHER-01)?
   Reading A: the figures and counting as listed. Reading B: whatever the official text says instead.
   Example: a maternity request of 120 days starting on the day of birth is accepted today; under a 30 before and 90 after rule only 90 days after birth would be leave. An annual request of Monday 2026-12-28 to Friday 2027-01-08 charges 10 days today; counted in calendar days it would charge 12.

3. **Fixed or rolling sick-leave window (LVR-SICK-05). Legal. Counsel.**
   Question: is "six months in any twelve from the first day of sickness" counted in a fixed twelve-month period opened by the first sick day, or in the twelve months looking back from each sick day?
   Example: 22 charged sick days in April 2026 (1 to 30 April); 22 more between 1 and 30 March 2027 (21 if the organisation records Adwa on 2 March; the counts below then drop by one); then 5 days from Monday 2027-04-05.
   Reading A (the code): the first period ran from 2026-04-01 to 2027-03-31. The April 2027 days open a new period: full pay.
   Reading B: the twelve months back from 2027-04-05 hold 19 April 2026 days (6 to 30 April) and 22 March days, 41 in all. The April 2027 days are days 42 to 46: half pay.

4. **What a month of sick leave is, and the tiers (LVR-SICK-07). Legal. Counsel and the accountant.**
   Question: is a month in Art. 85 22 working days (the code, `payroll.working_days_per_month`) or a calendar month? Are 1 month full, 2 half, 3 unpaid and a ratio of one half confirmed?
   Example: a person on a Monday to Saturday shift is sick for all of June 2026 (Monday 1 to Tuesday 30 June), which charges 26 days.
   Reading A (the code): days 1 to 22 full pay, days 23 to 26 half pay.
   Reading B: the whole calendar month at full pay; half pay starts on 1 July.

5. **Leave that crosses the leave year's end (LVR-DAYS-05). Owner and HR.**
   Question: which year's balance does a request crossing the year end draw on?
   Example: Gregorian year, Monday 2026-12-28 to Friday 2027-01-08: 10 charged days, 4 in 2026 and 6 in 2027 (9 and 5 if the organisation records Genna on 7 January).
   Reading A (the code): all 10 from 2026. Reading B (AZ E-04): 4 from 2026 and 6 from 2027. Reading C (AZ U-E5, interim): refused, filed as two requests.

6. **Carried days and Art. 79 (LVR-CARRY-03, LVR-CARRY-04). Legal for the limit. Counsel, then the owner and the accountant.**
   Question: may annual leave be postponed, for how long, and do carried days lapse?
   Example: 2025: entitled 16, carried 16 (from 2024), used 6, cap 16.
   Reading A (the code): remaining 26, carried into 2026 is 16, and the 2024 days can be among them for ever.
   Reading B (AZ U-E7): carried days are used first, so the 6 used days come from the 16 carried; 10 carried days lapse at the end of 2025 and only 2025's own 16 can be carried: 16 into 2026.
   Reading C: whatever limit Art. 79 sets, read from an official copy.

7. **A seniority step reached part-way through the year (LVR-ACC-07, LVR-ACC-08). Legal. Counsel or HR.**
   Question: from when does a step reached mid-year count?
   Example: hired 2024-03-15, Gregorian 2026, annual 16 with the step, monthly accrual.
   Reading A (the code, monthly types): 17 for the whole year, 17.0 at the year end.
   Reading B: 16 for January and February, 17 from March: (2 x 16 + 10 x 17) / 12 = 16.8.
   Reading C (the code, annual-accrual types): 16 for all of 2026, 17 from 2027.

8. **A joiner's or leaver's part month (LVR-ACC-04, LVR-ACC-05). Legal. Counsel or HR.**
   Question: how much leave does a person earn for a month they worked only part of?
   Example: hired 2026-03-31, entitlement 16, balance read on 1 June 2026 (June credited in advance, LVR-ACC-01).
   Reading A (the code): March counts whole: 4 months, 5.3. Reading B: only complete months: 3, 4.0. Reading C: by days, 92 of 365: 16 x 92 / 365 = 4.03, 4.0.

9. **Sick leave in probation, and its evidence (LVR-SICK-11). Legal. Counsel, then HR.**
   Question: does Art. 85 sick leave start only after probation, and what does a sick day during probation become?
   Example: hired 2026-05-04 on probation, sick Monday 1 to Friday 5 June 2026.
   Reading A (the code): 5 days of sick leave at full pay. Reading B (LAW): not Art. 85 leave; HR rules what it is (another paid type, unpaid, or absence).

10. **Civil servants' sick leave (LVR-SICK-12, LVR-ORG-01). Legal. Counsel.**
    Question: which tiers apply to a federal civil servant, and which language of 1353 Art. 66 prevails?
    Example: the 30th charged sick day of a period.
    Reading A (the code): half pay. Reading B (1353 Amharic): full pay, half pay from month 5. Reading C (1353 English): full pay, half pay from month 7.
    What the law register's 1353 rows hold, as it states them: Art. 66, six months in twelve from the first day of sickness (or twelve months in four years) in the Amharic, eight months in the English, neither with an unpaid tier, and a probationer has one month with pay (class 3); Arts. 66(5) and 67, tell the office as soon as possible, a certificate from a licensed medical institution for more than three consecutive days (Amharic), and uncertified sick days limited to six in a budget year (class 1 / 3); Arts. 68 and 69, seven days a year in total for mourning, a wedding and examinations, and paid leave for the time a summons takes (class 1). It holds no annual leave row for civil servants.

11. **Family events: per event or per year (LVR-OTHER-04, LVR-ACC-09). Legal. Counsel, then HR.**
    Question: are marriage and bereavement leave given per event, as LAW reads Arts. 81, 83, 84?
    Example: two deaths of listed relatives in 2026. Reading A (the code): 3 bereavement days in all. Reading B (LAW): 3 working days per event, 6 in all.

12. **The limit on unpaid days (LVR-OTHER-05). Legal. Counsel, then HR.**
    Question: is unpaid leave limited, as LAW reads Arts. 81, 83, 84 ("up to five days unpaid for exceptional events, twice a budget year")?
    Example: 12 unpaid days asked for in one budget year, in three requests of 4. Reading A (the code): all 12 accepted. Reading B (LAW): at most 5 per event and two events, so at most 10; the third request is refused or becomes something else.

13. **Unpaid leave as a suspension (LVR-OTHER-05). Legal. Counsel.**
    Question: LAW Art. 17(2) says unpaid leave granted at the worker's request suspends the contract. Is an approved unpaid request a suspension, and does it then stop the days counting toward anything else?
    Example: an approved 10-day unpaid request. Reading A (the code): 10 unpaid leave days, deducted from pay, nothing else changes. Reading B: a suspension of the contract for 10 days, recorded as such.

14. **Annual leave in probation and the first year (LVR-ENT-07). Legal. Counsel.**
    Question: is annual leave earned from the first day, including probation?
    Example: hired 2026-03-10 on probation. Reading A (the code): 5.3 days from 1 June, takeable at once. Reading B: earned but not takeable until probation ends. Reading C: nothing earned until a qualifying period passes.

15. **Clearing the seniority step (LVR-ENT-06). Owner, with counsel.**
    Question: may an organisation set the step below the law's, or clear it?
    Example: a ten-year employee. Reading A (the code): whatever the type says, 16 with the step cleared. Reading B (STAT, locked minimum): never below 21 (item 1 reading A) or 20 (item 1 reading B).

16. **One-time types (LVR-ACC-09). Owner and HR.**
    Question: is maternity, paternity, marriage, sabbatical or injury leave a yearly pool or one entitlement per occurrence?
    Example: a sabbatical of 180 days. Reading A (the code): 180 again every leave year. Reading B (AZ U-E4): 180 per occurrence, with its evidence.

17. **Recall from annual leave (LVR-RECALL-01 to LVR-RECALL-05). Legal. Counsel. Not in the law register.**
    Question: may an employer call a worker back from annual leave, and is anything owed when it does (the unused days kept, travel costs, pay)?
    Example: approved annual leave Monday to Friday, ended by HR on Wednesday. Reading A (the code): 3 days used, 2 back in the balance, nothing else. Reading B: the 2 days back and whatever the law adds.

18. **Payment for unused leave on leaving (LVR-PAY-05). Legal. Counsel, then the accountant. Not in the law register.**
    Question: must unused annual leave be paid out when employment ends, and how is a day valued?
    Example: a leaver with 6.0 days remaining and basic 8,800.00 on 22 days. Reading A (the code): nothing paid. Reading B: 6 x 400.00 = 2,400.00 on the final payslip, or another valuation the law sets.

19. **Sickness during annual leave. Legal. Counsel, then HR. Not in the law register.**
    Question: when a worker falls sick during approved annual leave, do the sick days stop the annual leave and count as sick leave?
    Example: annual leave Monday 1 to Friday 12 June 2026 (10 days), sick with a certificate on 8 to 10 June. Reading A (the code): 10 annual days used, no sick leave. Reading B: 7 annual days and 3 sick days.

20. **Public holidays inside annual leave. Legal. Counsel. Not in the law register.**
    Question: is a public holiday that falls inside annual leave not charged, as the code does (LVR-DAYS-01)?
    Example: annual leave Monday 2027-03-01 to Friday 2027-03-05 with Adwa on 2 March recorded. Reading A (the code): 4 days charged. Reading B: 5 days charged.

21. **Pending days at the year end (LVR-CARRY-05). Owner.**
    Question: should a request decided after the first week of the new year still move the carry?
    Example: pending 5 on 31 December, rejected on 10 January. Reading A (the code): carried stays 11. Reading B: carried becomes 16 (capped).

22. **Imported opening balances (LVR-ACC-10). Owner.**
    Example: 10.0 imported in March for a 16-day monthly type. Reading A (the code): nothing accrues until 1 August. Reading B (AZ U-E8): the import above the target goes to carried, and accrual continues: entitled 4.0 from 1 March (16 x 3 / 12) plus 6.0 carried.

One row for each item: the reading chosen (A, B, C or another, written out), the official copy of the law that was read (title, number, article, where the copy came from), the signer's role and the date. Items 2 and 4 need the accountant's row as well as counsel's.

| Item | Reading chosen | Official copy read | Role | Date | Signature |
|---|---|---|---|---|---|
| 1 | | | | | |
| 2 | | | | | |
| 2 (accountant) | | | | | |
| 3 | | | | | |
| 4 | | | | | |
| 4 (accountant) | | | | | |
| 5 | | | | | |
| 6 | | | | | |
| 7 | | | | | |
| 8 | | | | | |
| 9 | | | | | |
| 10 | | | | | |
| 11 | | | | | |
| 12 | | | | | |
| 13 | | | | | |
| 14 | | | | | |
| 15 | | | | | |
| 16 | | | | | |
| 17 | | | | | |
| 18 | | | | | |
| 19 | | | | | |
| 20 | | | | | |
| 21 | | | | | |
| 22 | | | | | |

Leave accuracy is not certified until every item marked legal above is signed.

## Where other documents say something else

Found on 2026-11-28 while this register was written. Where the register marks a row Decided, it is right and the other text is to be corrected. Where it marks the row Undecided, neither is right yet.

| Document | What it says | The rule |
|---|---|---|
| 06 LR-002 | Friday to the next Tuesday, with Monday a public holiday, counts 3 days | LVR-DAYS-01 gives 2 on a Monday to Friday week (Friday and Tuesday). 3 is right only if Saturday is a working day for that person |
| 06 LB-002 | Hired 4 years and 2 months ago: 18 | LVR-ENT-03. 18 is reading A; reading B gives 17 |
| 06 LB-010 | The Ethiopian year 2019 runs from 11 September 2026 to 10 September 2027 | LVR-YEAR-01. It ends on 11 September 2027: Pagume has six days that year (`EthiopianCalendar.php` line 36, `isLeapYear()`) |
| `LeaveBalanceService.php` docblock, lines 18 to 19 | "Sixteen days in the first year, one more for every additional two years" | LVR-ENT-03. The docblock reads as reading B; line 29 implements reading A |
| [audit/BLUEPRINT-ASSESSMENT-2026-10-20.md](audit/BLUEPRINT-ASSESSMENT-2026-10-20.md) LV2, B10 | No seniority step | Stale: built 2026-10-20 (LVR-ENT-01) |
| LAW Arts. 85, 86 | Sick leave after probation only, with next-day notice and a certificate | LVR-SICK-11. The code applies none of the three |
| LAW Arts. 81, 83, 84 | Three days paid per family event; at most five unpaid days, twice a budget year | LVR-OTHER-04, LVR-OTHER-05. The code gives yearly buckets and unlimited unpaid days |
| STAT `leave.annual.seniority` | A locked minimum | LVR-ENT-06. Nothing holds a type's step to it |
| 06 ground rules ("There is no half-day leave") | No half days | Agrees with LVR-DAYS-03, but `ExpectedDays.php` line 212 and the unpaid step's comment in `PayrollEngine.php` are written for fractional days |
| `api/tests/Feature/LeaveEntitlementLawTest.php` header | Art. 77 asks for leave proportional to service for a joiner or leaver | LVR-ACC-05. That is not a row of the law register |
| SIGN row 12 | `PolicyProfileCatalog` cites the repealed 1064/2017 | Not a counting rule. Its public profile adds study leave only (LVR-ORG-02) |

## What this register does not cover

- **Payment for unused leave on leaving, and recovery of leave taken beyond what was earned.** No code does either (LVR-PAY-05). The payout question is "Waiting for a decision", item 18.
- Annual leave for civil servants under 1353/2024: the law register has no annual leave row for it, and the code applies the same types and values as for private employers (LVR-ORG-01).
- Attachments and medical certificates (`requires_attachment` is never enforced, AZ E-09), who may read a leave reason (AZ E-13), approval chains and their reminders (06 section 5), delegation, notifications and webhooks, leave reports, and the team calendar.
- How attendance rates a record made on a day of approved leave: AR-ABS-08.
