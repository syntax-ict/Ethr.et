# 14. Leave, calendar and restated figures

**At a glance.** This file holds the accuracy checks that sit beside the attendance ones in [12-ATTENDANCE-ACCURACY.md](12-ATTENDANCE-ACCURACY.md). Today it describes one thing that has been built and run: the Ethiopian calendar cross-check, in which the PHP class, the TypeScript module and a third implementation that counts days one at a time are compared against one shared table of month starts for every day from 1900 to 2100. It also records the register consistency test, which checks that every rule id cited by a case exists and that every Decided rule has a case or a stated reason for having none. It also describes the leave cases (section 7). The restated-figures section is empty on purpose.

## 1. Layers and status

| Layer | What it does | Status |
|---|---|---|
| Calendar table | Every Ethiopian month start from Ethiopian year 1892 to 2093, counted by the Odometer and committed as JSON | Built: section 3 |
| PHP check | `EthiopianCalendar` against the table, on every month start and every day | Built: section 3 |
| TypeScript check | `ethiopian.ts` against the same table | Built: section 3 |
| Clock check | All 1,440 minutes of a day through the Ethiopian clock and back | Built: section 3 |
| Register consistency | Rule ids cited by cases exist; Decided rules are covered or exempted with a reason | Built: section 4 |
| Leave cases | Hand-typed leave figures, run through the leave engine, and five seeded properties | Built: section 7 |
| Restated figures | Figures restated from the registers | Not written yet: section 8 |

No percentage exists for any of these. The calendar check compares complete ranges, not a sample.

## 2. Calendar rules

States mean what they mean in [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md): Decided is stated by a document and the code was read to agree; Implemented is what the code does with no document stating it; Undecided is not yet answered. `api/tests/Accuracy/Calendar/pins.php` names the test file that holds each rule.

| Id | Rule | Source | State |
|---|---|---|---|
| CAL-ETH-01 | An Ethiopian year has twelve months of 30 days, then Pagume. | `api/app/Services/Calendar/EthiopianCalendar.php` class comment; published month table (section 3) | Decided |
| CAL-ETH-02 | Pagume has 6 days when the Ethiopian year leaves 3 after division by 4, otherwise 5. | `EthiopianCalendar.php` class comment; `docs/production testing/09-NON-FUNCTIONAL.md` L10N-005 | Decided |
| CAL-ETH-03 | Meskerem 1 is the day after the last day of the previous year's Pagume. | published month table (section 3) | Decided |
| CAL-ETH-04 | Conversion in both directions agrees with the table for every day from Ethiopian year 1892 to 2093, which covers 1900-01-01 to 2100-12-31. | this file, section 3 | Decided |
| CAL-ETH-05 | The PHP class, the TypeScript module and the table give the same answer for every one of those days. | this file, section 3 | Decided |
| CAL-PAG-01 | `pagumeDaysInRange` counts the days of a range that fall in Pagume and names the Ethiopian year and its Pagume length. | `api/tests/Unit/EthiopianCalendarTest.php`; `EthiopianCalendar.php` | Implemented |
| CAL-CLK-01 | On the Ethiopian clock the hour is (h24 minus 6) modulo 12, with 0 written 12. | `src/src/lib/calendar/ethiopian-time.ts` comment; [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md) AR-CLOCK-03 | Decided |
| CAL-CLK-02 | The period is morning from 06:00 to 11:59, day from 12:00 to 17:59, evening from 18:00 to 23:59 and night from 00:00 to 05:59. | `ethiopian-time.ts` comment | Decided |
| CAL-CLK-03 | Every minute of the day converts to the Ethiopian clock and back to the same minute, and no two minutes read alike. | this file, section 3 | Implemented |

CAL-CLK-01 to CAL-CLK-03 exist only in TypeScript. The server settles the clock preference with the same rule, but there is no PHP conversion to test.

## 3. How the cross-check works

Three implementations answer the same question, and none borrows from another.

- `App\Services\Calendar\EthiopianCalendar` converts through a Julian day number and searches for the year.
- `src/src/lib/calendar/ethiopian.ts` converts through a Julian day number with a 1461-day cycle.
- `Tests\Accuracy\Oracle\Calendar\Odometer` keeps a Gregorian counter and an Ethiopian counter and moves both one day at a time away from one known pair, writing down the Gregorian date whenever the Ethiopian counter reads the first of a month. It uses no day number and no closed form. It shares only the leap rules with the other two.

The Odometer's output is the table `src/src/test/fixtures/ethiopian-calendar.json`: 202 Ethiopian years, 2,626 month starts and each year's Pagume length (50 of the 202 years have six days). The start pair is the published anchor `WP-NY-1998`. A PHP test regenerates the table in memory and fails if the committed file differs.

The tests then compare:

- the table with itself: each month start is the previous one plus 30 days (or Pagume's length), and each Meskerem 1 follows Pagume;
- the PHP class on every month start, on the day before it, and on all 73,780 days of the table, converting both ways and checking that each day is followed by the next Ethiopian date;
- the TypeScript module on the same checks;
- 28 anchors (below), the Odometer and the PHP class;
- a published month-start table (below);
- `pagumeDaysInRange` over each year's Pagume, alone and padded by ten days each side.

### Anchors

`api/tests/Accuracy/Calendar/anchors.php` holds 28 Gregorian to Ethiopian pairs, each with its source. Four come from outside the repository and are marked independent: three New Year dates and the date on the infobox of the Wikipedia article on the Ethiopian calendar, read on 2026-11-28. The other 24 come from the repository's own unit tests, other tests and documents. They were written by the team that wrote the calendar, so they show that the implementations have not drifted apart, not that any of them is right.

The same article carries a table of the Gregorian day on which each Ethiopian month begins, valid for March 1900 to February 2100, with a second column for the year after an Ethiopian leap day. The test applies that table to Ethiopian years 1893 to 2091 (2,587 month starts). It is a published reference work, and a tertiary one.

### Findings from building it

- All three implementations agreed on every date. No calendar defect was found.
- Two comments in `api/tests/Feature/Dashboard/ExecutiveMetricCorrectnessTest.php` give a wrong Ethiopian date: "31 October 2026 is Tikimt 20, 2019" (line 100) and "3 November 2026 is Tikimt 23, 2019" (line 135). The class, the TypeScript module and the Odometer all say Tikimt 21 and Tikimt 24, which is also what the same file's own line 103 implies (11 October 2026 is Tikimt 1). The comments are wrong, not the code. Neither pair is used as an anchor. Both comments were corrected on 2026-11-28 (the file is `api/tests/Feature/Dashboard/ExecutiveMetricCorrectnessTest.php`; no code changed).
- The Wikipedia article's caption for the Ethiopian Millennium says 11 September 2007. The article's own month table, the three implementations and the rule in CAL-ETH-02 give 12 September 2007 for Meskerem 1, 2000, which follows a six-day Pagume. That caption is not used.
- There is no anchor from before 1930, and no independent anchor for a six-day Pagume: the independent ones are Meskerem 1 of 1992, 1996 and 1998 and one date in 2026. Years 1900 and 2100, which are not Gregorian leap years, are checked against the table only, and by the published table up to February 2100.

## 4. Register consistency

`api/tests/Accuracy/RegisterConsistencyTest.php` reads the rule rows of four registers, [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md) (`AR-`), [payroll/PAYROLL-RULES.md](../payroll/PAYROLL-RULES.md) (`PYR-`), [LEAVE-RULES.md](../LEAVE-RULES.md) (`LVR-`) and section 2 above (`CAL-`), and the rule ids cited by the case files in `api/tests/Accuracy/cases/`, `Payroll/cases/` and `Leave/cases/` and by `Calendar/pins.php`. It checks that:

- every row of a register parses;
- every cited id exists in its register and carries that register's prefix;
- every Decided rule is cited by a case or has an entry with a reason in `api/tests/Accuracy/register-exemptions.php`;
- no exemption is stale: its rule is Decided, exists, and is not cited by a case;
- a leave case citing a Decided rule whose text says "Value:" and names an Undecided rule carries `waits_on`.

An engine whose case directory does not exist yet is skipped, not failed. The attendance, payroll and leave case directories all exist now, so all three are checked. All three checks passed in the last run.

On the attendance register today, 19 Decided rules have no case and each has an exemption: AR-CLOCK-03, AR-CLOCK-05, AR-SHIFT-01 to AR-SHIFT-04, AR-STATUS-02, AR-BREAK-04, AR-BREAK-05, AR-NIGHT-03, AR-AUTO-03, AR-ABS-06, AR-OT-04 to AR-OT-07 and AR-LATE-01 to AR-LATE-03. They are the rules [12-ATTENDANCE-ACCURACY.md](12-ATTENDANCE-ACCURACY.md) section 8 lists as having no golden case yet, less those that are Undecided.

## 5. Run it

```bash
cd api
php -d memory_limit=-1 vendor/bin/pest -c phpunit.accuracy.xml tests/Accuracy/Calendar tests/Accuracy/RegisterConsistencyTest.php
cd ../src
npx vitest run src/test/ethiopian-calendar-table.test.ts src/test/ethiopian-clock-roundtrip.test.ts
```

`./scripts/gates.sh accuracy` runs the whole accuracy suite, the PHP calendar and register tests included. The two Vitest files match the Vitest include pattern, so `./scripts/gates.sh frontend` runs them with the rest of the frontend suite. To regenerate the table after a deliberate change to the Odometer or its start pair:

```bash
cd api
CALENDAR_TABLE_WRITE=1 php -d memory_limit=-1 vendor/bin/pest -c phpunit.accuracy.xml tests/Accuracy/Calendar
cd ../src
npx prettier --write src/test/fixtures/ethiopian-calendar.json
```

## 6. What this does not cover

- Holidays that follow the Hijri calendar (Eid al-Fitr, Eid al-Adha, Mawlid). The class converts them on the tabular Islamic calendar and says itself that the result is an estimate; this check has no opinion on them. Orthodox Easter is not checked either.
- The clock exists only in TypeScript, so only one implementation is tested for it, against the rule written out in the test.
- Time zones. The TypeScript tests compare the local date of the `Date` that `toGregorian` returns, and were run in the machine's local zone only.
- Dates before Ethiopian year 1892 and after 2093.
- Any comparison with the printed calendars the Ethiopian Orthodox Church publishes.

## 7. Leave

Leave is measured for conformance only: does the leave engine do what [LEAVE-RULES.md](../LEAVE-RULES.md) says? Robustness and real-world agreement are not built. There is **no oracle**, by design: a second implementation of statutory values nobody has signed would only copy the first reading. A signed reference replaces it, and none exists yet.

### What exists

- `api/tests/Accuracy/Leave/cases/` holds 131 designed cases in eight files: `acc` (accrual), `balreq` (the balance and whether a request is accepted), `carry` (carry-over), `days` (the days a request charges), `ent` (yearly entitlement), `recall` (cancelling and ending approved leave early), `sick` (Art. 85 sick leave) and `yearpay` (the leave year, and the unpaid days payroll counts). Each expected value was worked by hand from the register before the engine was asked, and each case carries its working lines. The format is that of the attendance cases ([12, section 4](12-ATTENDANCE-ACCURACY.md#4-how-a-case-is-built)) with a leave world instead of punches: `LeaveFixture.php` builds the organisation, leave type, person, balances, holidays and requests from the case, and `LeaveRunner.php` asks through the leave API (file, approve, reject, cancel and recall) and through the engine's own services (the days a request charges, accrual, carry-forward, the leave year, the sick-leave tiers and the payroll engine's unpaid-leave step). It returns every disagreement, one line each.
- `LeaveGoldenCasesTest.php` runs the cases. `LeaveCases.php` loads them and fails on a mistyped key.
- `LeavePropertiesTest.php` holds four tests over five properties, each over a small table of inputs generated from one fixed seed (`LeaveProperties::SEED`, 20261128). Each reports the seed, the number of cases and the number of disagreements, and no percentage.
- The register consistency test ([section 4](#4-register-consistency)) checks every rule id a leave case cites, and that a case citing a Decided rule whose text says "Value:" and names an Undecided rule carries `waits_on`. `register-exemptions.php` holds the reason for each Decided leave rule that no case cites.

### The cases by state

Counted in `api/tests/Accuracy/Leave/cases/` on 2026-11-28.

| State | Cases | What the test does |
|---|---|---|
| Asserted | 97 | Compares the engine with the hand value |
| Waiting for a signature | 27 | Typed now and skipped until HR or counsel sign the expected value. The case carries `waits_on` (an item of the register's "Waiting for a decision" list) and `signed` null. The items are 1, 2, 3, 4, 7, 8 and 20 |
| Undecided | 7 | Skipped, never asserted: GL-ACC-019, GL-ACC-022, GL-ACC-023, GL-REQ-014, GL-CARRY-008, GL-CARRY-009 and GL-RECALL-007 |
| Total | 131 | |

A case that waits can state what each reading of the question gives (`readings`) and which one the engine follows (`ruling`). A signed case would carry the date, the role and the sign-off row in `signed`; none does.

### The properties

| Property | What it asserts |
|---|---|
| Splitting | A request split in two adjacent requests charges the same total, and the days charged are never negative and never more than the calendar days of the span. Run for a Monday to Friday week, a six-day shift and a four-day rotation |
| Holidays | Adding a holiday inside a request never raises the days charged, and lowers them by exactly one when that date was charged |
| Accrual | The accrual of a later month of a year is never lower than that of an earlier one, whatever the type's days, the hire date and a termination date recorded part-way through |
| Repeat | Running accrual twice for the same moment, and carry-forward twice, changes nothing |

Splitting and the bound on the days charged share one test, so the four tests cover five properties. A property that fails is never weakened: the smallest failing input is named in the failure.

### Two values withdrawn or corrected

- **GL-DAYS-013.** First typed as 4 days, on the case writer's own reading of the word "recurring" for a holiday. That value had no source in the register. The first run showed the engine charging 5, and the case was recorded as a finding. The register then stated the rule (LVR-DAYS-08: a holiday lowers the days charged only where an active row exists for that exact date, and "recurring" is not read when days are counted; a monthly job creates next year's rows). The case was retyped as 5 and the first typing is withdrawn, with the reason recorded in the case (`4a85679d84`). It was not an engine defect.
- **GL-REQ-020.** First typed as 4 days from the register's own worked example for 1 to 5 May 2028, which named one holiday in that week. The week holds two, Labour Day on 1 May and Patriots Day on 5 May, so the engine charges 3. The register's example was corrected first (`1403871cac`), and the case was retyped as 3 from the corrected example (`1c833bba61`). No finding is left. The wrong value was the register's, not the engine's.

Both were found because the case was typed before the run and the disagreement was read, not smoothed over.

### Two errors in the QA pack

The engine and the register agree against two expected results in [06-E2E-LEAVE-AND-APPROVALS.md](06-E2E-LEAVE-AND-APPROVALS.md), and the cases confirm it:

- **LR-002** said a request from Friday to the next Tuesday with Monday a public holiday counts 3 days. On the organisation's Monday to Friday week it counts 2 (the Friday and the Tuesday), case GL-DAYS-015. It counts 3 only for a person whose working week includes Saturday, case GL-DAYS-016. The rule is LVR-DAYS-01.
- **LB-010** said the Ethiopian year 2019 runs from 11 September 2026 to 10 September 2027. Pagume has six days that year, so it ends on 11 September 2027, case GL-YEAR-003 (rules LVR-YEAR-01 and LVR-YEAR-04). Case GL-ACC-021 confirms the year number of the balance row and the entitlement of months 2 and 3.

Both cases in file 06 are corrected, with a note in that file's change notes.

### Run it

```bash
cd api
php -d memory_limit=-1 vendor/bin/pest -c phpunit.accuracy.xml tests/Accuracy/Leave
LEAVE_CASE=GL-DAYS-015,GL-YEAR-003 php -d memory_limit=-1 vendor/bin/pest -c phpunit.accuracy.xml tests/Accuracy/Leave
```

`./scripts/gates.sh accuracy` runs this with the rest of the suite. It needs no database server. A case that disagrees is listed in `api/tests/Accuracy/Leave/findings.php` (empty today) and reports as incomplete. Never change a hand value to make a case pass.

### The last run

From the session sheet of 2026-11-28 18:56 UTC, commit `6ebca2c779`, worktree clean (tracked files). Leave: **102 passed, 0 failed, 0 incomplete, 7 skipped as undecided, 27 skipped as waiting for a signature.** The 102 are the 97 asserted cases, the test that checks every case's shape, and the four property tests. The properties printed:

```text
LEAVE PROPERTY splitting: seed=20261128 cases=60 disagreements=0
LEAVE PROPERTY holidays: seed=20261128 cases=45 disagreements=0
LEAVE PROPERTY accrual: seed=20261128 cases=10 disagreements=0
LEAVE PROPERTY repeat: seed=20261128 cases=8 disagreements=0
```

The same run passed the 9 PHP calendar tests and the 14 TypeScript calendar and clock tests. The shared guards, which include the register consistency test, passed (23 tests). These are counts of designed cases and generated inputs, not a rate.


### What this does not prove

- **Leave accuracy is not signed off.** The register's own words are "Leave accuracy is not certified": every statutory value in it waits on counsel or HR in writing ([Waiting for a decision](../LEAVE-RULES.md#waiting-for-a-decision), 23 items, none signed). The 27 cases that wait on a signature are the measure of that gap. A pass on the 97 asserted cases says the engine does what the register says where the register has an answer. It does not say the law or a customer's policy is met.
- The cases and the register share one reading, so a misreading they share passes. The register's Implemented rows copy what the code does, so agreement on one shows the engine and the hand value are consistent with the code, never that what the code does is right.
- No real balance or request has been compared with a signed figure.
- Not covered: attachments and medical certificates, who may read a leave reason, approval chains and their reminders, delegation, notifications and webhooks, leave reports and the team calendar (the register's "What this register does not cover"). Two requests racing from separate processes is not tested here either: the register consistency exemption for LVR-BAL-07 points to the concurrency gate.

## 8. Restated figures

Not written yet.
