# 14. Leave, calendar and restated figures

**At a glance.** This file holds the accuracy checks that sit beside the attendance ones in [12-ATTENDANCE-ACCURACY.md](12-ATTENDANCE-ACCURACY.md). Today it describes one thing that has been built and run: the Ethiopian calendar cross-check, in which the PHP class, the TypeScript module and a third implementation that counts days one at a time are compared against one shared table of month starts for every day from 1900 to 2100. It also records the register consistency test, which checks that every rule id cited by a case exists and that every Decided rule has a case or a stated reason for having none. The leave and restated-figures sections are empty on purpose.

## 1. Layers and status

| Layer | What it does | Status |
|---|---|---|
| Calendar table | Every Ethiopian month start from Ethiopian year 1892 to 2093, counted by the Odometer and committed as JSON | Built: section 3 |
| PHP check | `EthiopianCalendar` against the table, on every month start and every day | Built: section 3 |
| TypeScript check | `ethiopian.ts` against the same table | Built: section 3 |
| Clock check | All 1,440 minutes of a day through the Ethiopian clock and back | Built: section 3 |
| Register consistency | Rule ids cited by cases exist; Decided rules are covered or exempted with a reason | Built: section 4 |
| Leave cases | Hand-typed leave figures | Not written yet: section 7 |
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
- Two comments in `api/tests/Feature/Dashboard/ExecutiveMetricCorrectnessTest.php` give a wrong Ethiopian date: "31 October 2026 is Tikimt 20, 2019" (line 100) and "3 November 2026 is Tikimt 23, 2019" (line 135). The class, the TypeScript module and the Odometer all say Tikimt 21 and Tikimt 24, which is also what the same file's own line 103 implies (11 October 2026 is Tikimt 1). The comments are wrong, not the code. Neither pair is used as an anchor.
- The Wikipedia article's caption for the Ethiopian Millennium says 11 September 2007. The article's own month table, the three implementations and the rule in CAL-ETH-02 give 12 September 2007 for Meskerem 1, 2000, which follows a six-day Pagume. That caption is not used.
- There is no anchor from before 1930, and no independent anchor for a six-day Pagume: the independent ones are Meskerem 1 of 1992, 1996 and 1998 and one date in 2026. Years 1900 and 2100, which are not Gregorian leap years, are checked against the table only, and by the published table up to February 2100.

## 4. Register consistency

`api/tests/Accuracy/RegisterConsistencyTest.php` reads the rule rows of four registers, [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md) (`AR-`), [payroll/PAYROLL-RULES.md](../payroll/PAYROLL-RULES.md) (`PYR-`), [LEAVE-RULES.md](../LEAVE-RULES.md) (`LVR-`) and section 2 above (`CAL-`), and the rule ids cited by the case files in `api/tests/Accuracy/cases/`, `Payroll/cases/` and `Leave/cases/` and by `Calendar/pins.php`. It checks that:

- every row of a register parses;
- every cited id exists in its register and carries that register's prefix;
- every Decided rule is cited by a case or has an entry with a reason in `api/tests/Accuracy/register-exemptions.php`;
- no exemption is stale: its rule is Decided, exists, and is not cited by a case;
- a leave case citing a Decided rule whose text says "Value:" and names an Undecided rule carries `waits_on`.

An engine whose case directory does not exist yet is skipped, not failed. At the time of writing the payroll and leave case directories do not exist in this checkout.

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

Not written yet.

## 8. Restated figures

Not written yet.
