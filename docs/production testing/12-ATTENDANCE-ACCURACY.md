# 12. Attendance accuracy

**At a glance.** This file says how ETHR measures whether the attendance engine gives the right answer, which of the layers exist today, how to run them, and how to add a case. It states only what has been built and run. Everything else is listed as not built yet. The same method for payroll is in [13](13-PAYROLL-ACCURACY.md), for leave and the calendar in [14](14-LEAVE-CALENDAR-AND-RESTATED-FIGURES.md), and the session that runs them all in [15](15-ACCURACY-SESSION.md).

Companion documents: the rule register [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md) (every rule, with an id), [ATTENDANCE-PIPELINE.md](../ATTENDANCE-PIPELINE.md) (how a punch travels), and the expected values in [05](05-E2E-ATTENDANCE-SHIFTS-DEVICES.md) and [07](07-E2E-PAYROLL.md) that the golden cases were typed from.

## 1. What is measured

Three measures, kept apart because they answer different questions and only the third needs real data.

| Measure | Question | Needs real data |
|---|---|---|
| Conformance | Does the engine do what ETHR's written rules say? | No |
| Robustness | Does the answer stay the same whichever channel, order, retry, duplicate or time zone the punches arrive by? | No |
| Real-world agreement | Does the engine's month agree with what an HR officer signed for the same people and days? | Yes |

A number for one of them says nothing about the others. A conformance figure of 100% means the engine follows the register, not that the register matches how a customer pays.

## 2. The layers and their status

| Layer | What it does | Measure | Status |
|---|---|---|---|
| Rule register | Every rule with an id, a source and a state: Decided, Implemented or Undecided | all | Built: [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md) |
| Golden cases | Hand-typed expected values, run through the engine | Conformance | Built: 79 cases, sections 3 to 5 |
| Independent oracle | A second implementation of the rules, written from the register alone, run on the same cases | Conformance | Built: section 3a |
| Generated run | Seeded employee-months compared with the oracle | Conformance | Not built yet |
| Properties | Replay, duplicate, zone, channel and order do not change the answer | Robustness | Not built yet |
| Mutation testing | Whether the tests notice a deliberately wrong engine | Strength of the tests | Not built yet |
| Simulated month over HTTP | A seeded month through the real device webhook and queue | Robustness | Not built yet |
| Displayed figures | The numbers on each screen compared with the expected ones | Conformance | Not built yet |
| Production check | A read-only look at one known day on the QA organisation | Real-world agreement | Not built yet |
| Backtest | A real month replayed and compared with HR's signed sheet | Real-world agreement | Not built yet |

No accuracy percentage exists yet. The golden cases are a fixed list, not a sample, so a pass rate over them does not estimate how often the engine is right on real days.

## 3. Run the golden cases

```bash
./scripts/gates.sh accuracy
```

It runs `vendor/bin/pest -c phpunit.accuracy.xml` from `api/`. It needs no database server: it uses SQLite in memory and the array cache, like the backend suite. It is opt-in and is not part of the full sweep. The suite sits in `api/tests/Accuracy`, outside the Unit and Feature testsuites on purpose, so the collection-count check in the root [CLAUDE.md](../../CLAUDE.md) is unchanged.

A run took 47 to 50 seconds on the machine it was first run on (three runs on 2026-11-28), and 3.5 minutes once while other work shared the machine. To run chosen cases, from `api/`: `ACCURACY_CASE=GC-STATUS-002,GC-ZONE-001 vendor/bin/pest -c phpunit.accuracy.xml`. (PHPUnit's `--filter` cannot see a dataset key.)

### What a result means

| Result | Meaning |
|---|---|
| Passed | The engine's answer equals every hand value in the case. |
| Skipped, "rule undecided" | The case depends on a rule the register marks Undecided. It is listed so it is not forgotten and is never asserted. |
| Incomplete, "RECORDED FINDING" | The engine disagrees with the hand value and the case is listed in `api/tests/Accuracy/findings.php` with what the engine answered. It needs triage (section 6). |
| Failed | The engine disagrees and the case is not listed. Either the engine changed or the case is new. |

When the engine disagrees with a hand value, the message ends with a line starting `THREE-WAY`: what the oracle (section 3a) says about the same case. "The engine is outvoted two to one" means the oracle agrees with the hand value. "The oracle also disagrees" means the hand value or the register is the first suspect. Two against one names the suspect; it does not prove it.

## 3a. The independent oracle

`api/tests/Accuracy/Oracle/` is a second implementation of the register in plain PHP, on `DateTimeImmutable` alone. It was written by an agent that was allowed to read the register and its source documents (this pack's files 05 and 07, [ATTENDANCE-PIPELINE.md](../ATTENDANCE-PIPELINE.md)) and nothing of the engine. It is in three parts: a rater (status, late, early, worked and overtime buckets for one record), a resolver (which records a stream of punches makes: pairing, merges, repeats, breaks, second sessions, night dating, close windows, automatic check-out) and a day classifier (expected-day class and absence).

| File | What it checks |
|---|---|
| `OracleCasesTest.php` | The oracle against every case's hand values. A disagreement is listed in `oracle-findings.php` or fails. |
| `OracleReadingsTest.php` | Each Undecided rule blanks the field it should on an input at its boundary, and does not blank it one minute or one second away. |
| `OracleIndependenceTest.php` | The oracle uses only its own classes and named PHP date classes (`toOnlyUse`), nothing from `App`, `Illuminate`, `Carbon` or `Database`; a recursive token scan finds no clock function, `date`, file read, environment read, `require`, variable `new` or `call_user_func`; only `Calendar.php` builds a date, and only from a validated absolute string. A self-test proves the scan catches 23 bad snippets. |
| `GoldenCasesTest.php` | Asks the oracle for its verdict when the engine disagrees (the `THREE-WAY` line above). |

All of them run under `./scripts/gates.sh accuracy`. `ORACLE_CASE=GC-A,GC-B` runs chosen oracle cases, and `ORACLE_REPORT=1` prints, per case, the rules the oracle's answer relied on.

**The oracle never reads the answer.** It is given the case's punches and set-up and the dates to report, never `expect`. `tests/Accuracy/OracleComparer.php`, outside the oracle's namespace, is the only code that reads hand values. Every time the oracle returns carries its seconds.

**The oracle does not guess an Undecided rule.** It works each day out under the register's plain reading and again under each alternative reading of every Undecided rule (`Oracle/Readings.php`). A field that comes out the same under all of them is answered; a field that differs is returned as "undecided" with the rule that made the difference. So an Undecided rule blanks a field only when it matters for that input: of the 79 golden cases, AR-STATUS-07 blanks one field and AR-NIGHT-05 one, and every other Undecided rule none. Each answer also carries a per-date trace of the Decided, Implemented and Undecided rules it used, for the generated run. It has no opinion on the answer given to a merged or manual punch, on the status of a record on a rest day or holiday, or on per-record fields of a date with several records unless they are asked for as a list.

**Shown to fail, both ways (2026-11-28).** With the engine's grace comparison changed from "at or before" to "before", GC-STATUS-003 and GC-ZONE-002 failed and the oracle outvoted the engine. With the oracle's night window moved to start at 23:00, GC-OT-004 and GC-OT-012 failed in `OracleCasesTest` (night 60 expected, oracle said normal 330). Both files were restored.

What the oracle is not: ground truth. It was written in the same week as the hand values, from the same register, so a misreading of the register that both share passes. That is what the real-data backtest, the last layer in section 2, is for.

### Read a failing case

Each message names the case id, the rule id and the field, for example `[GC-STATUS-002] rule AR-STATUS-01, AR-MIN-01: 2026-10-13 record #1 status expected 'late', engine said 'present'`. Then:

1. Open the case in `api/tests/Accuracy/cases/` by its id and read its `source`. The hand value was typed from that QA pack case or from the register row.
2. Read the rule in the register. If the register and the hand value disagree, the case was mistyped.
3. Check the fixture before the engine: the date, the zone, the shift and that the clock was set to the punch moment.
4. If the fixture and the hand value both agree with the rule, it is an engine defect. File it. Do not fix it in this suite.

## 4. How a case is built

A case is a PHP array in `api/tests/Accuracy/cases/*.php`. The files hold data only. `GoldenCasesTest.php` is the only code: it builds the organisation, branch, shift, assignment, holiday and person from the case, freezes the clock at each punch moment and sends the punch through `AttendanceEngine::record()` (a manual entry through `EnteredAttendance`, as the manual endpoint does), then reads back the stored records, `AttendanceEngine::lateMinutes` and `earlyMinutes`, `AttendanceRecord::workedMinutes()` and `TimesheetLedger::build()` for the day's class, overtime buckets and timesheet code. It implements no rule.

| Key | Meaning |
|---|---|
| `id` | `GC-`, a theme, a dash and a number |
| `rule` | One or more ids from the register |
| `source` | The QA pack case it was typed from, or `register` for a boundary the register implies |
| `zone`, `shift` | The organisation's zone (default `Africa/Addis_Ababa`); `S1` 08:30 to 17:30, `S2` 22:00 to 06:00 every day, `S3` 08:00 to 17:00, or `null` for no shift |
| `setup` | Extras: `holiday`, `holiday_elsewhere`, `leave`, `hire_date`, `peer` (a second person's record, which makes the organisation one that records attendance), `auto_checkout` |
| `punches` | In order: type (`in`, `out`, `auto`, `manual`), local time (`08:46`, `08:45:30`, `+1 06:05` for the next day), method |
| `now` | When the day is read, for absence |
| `expect` | Hand values: punch `outcomes`, `live` records, `conflicts`, and per work date the status, late, early, worked, check-in, check-out, `overtime` buckets, `class` and timesheet `code` |
| `undecided` | `true` when the rule is Undecided in the register |

S1 has grace 15, early departure 15, break 60 and works Monday to Friday. The fixed day is Tuesday 2026-10-13.

What the golden cases do not cover: the 422 a manual entry gets for a check-out 14 hours or more after the check-in (it is a request rule, not the engine's), approval of a correction (COR-002 needs an approval chain), a rotation's rest day (the second half of CAP-002), and every refusal and flag that is not about time.

## 5. Add a golden case

1. Find the rule in the register. If there is none, the register gets the rule first, then the case.
2. Type the expected values by hand from the register or the QA pack. **Write them before you run the engine.**
3. Add the case to the file for its theme in `api/tests/Accuracy/cases/`.
4. Run it with `ACCURACY_CASE=<its id>`.
5. If the engine disagrees: **never change the expected value to make it pass.** Re-check the fixture first. If the fixture is right, leave the case as typed and add one line to `api/tests/Accuracy/findings.php`: the case id and what the engine answered. The case then shows as incomplete, with that text, instead of passing silently. A listed case that the engine later agrees with fails until the entry is removed.
6. If the rule is Undecided in the register, add `'undecided' => true`.
7. Every helper function in the test file is global in Pest. Prefix a new one with `accuracy` and run `vendor/bin/pest --list-tests` from `api/`, which loads every file and shows a clash.

## 6. Classify a finding

| Class | Meaning | What happens |
|---|---|---|
| Engine defect | The hand value follows the register and the engine does not | A failing-test-first fix, in its own pull request |
| Wrong hand value | The case contradicts the register | The register is checked first, then the case is corrected in a pull request that says why |
| Fixture error | The set-up did not match the case | The fixture is fixed, the case is untouched |
| Rule undecided | The register has no answer | The case waits for the owner's ruling |

## 7. A real export

Real punches from a customer's terminal are not in the repository and do not enter it. If one is used for a backtest:

- It is kept only under `private-docs/accuracy/`. That folder is excluded from git by `.git/info/exclude` in the owner's checkout, not by `.gitignore`, so a fresh clone does not exclude it: run `git check-ignore -v private-docs/accuracy/x` before putting anything there.
- Badge numbers are hashed in anything written down, and names are not copied.
- Nothing from it is committed: not a row, not a date tied to a person, not a screenshot.
- The customer's agreement to use it for testing is held before it is read.

## 8. Traceability

Generated from the case files and the register on 2026-11-28. The State column is the rule's state in the register: a case on an Implemented rule shows the behaviour is stable, not that it was chosen. A case on an Undecided rule is not asserted.

| Rule | State | Golden cases | QA pack case | File |
|---|---|---|---|---|
| AR-ABS-01 | Decided | GC-ABS-001, GC-ABS-002, GC-ABS-003, GC-ABS-004, GC-ABS-005 | 05 ABS-001 | `cases/expected-days.php` |
| AR-ABS-02 | Decided | GC-ABS-003, GC-OT-007 | 05 ABS-001 | `cases/expected-days.php`, `cases/overtime.php` |
| AR-ABS-03 | Decided | GC-ABS-002, GC-ABS-007, GC-OT-006, GC-OT-009 | 05 ABS-001; 05 HOL-003 | `cases/expected-days.php`, `cases/overtime.php` |
| AR-ABS-04 | Decided | GC-ABS-001, GC-ABS-006, GC-ABS-007 | 05 ABS-001; 05 HOL-003 | `cases/expected-days.php` |
| AR-ABS-05 | Decided | GC-ABS-008 | register only | `cases/expected-days.php` |
| AR-AUTO-01 | Decided | GC-AUTO-001, GC-AUTO-002 | 05 AUTO-001 | `cases/day.php` |
| AR-AUTO-02 | Decided | GC-AUTO-003, GC-AUTO-004 | 05 AUTO-002 | `cases/day.php` |
| AR-BREAK-01 | Decided | GC-BREAK-001, GC-BREAK-002 | 05 PUNCH-011 | `cases/day.php` |
| AR-BREAK-02 | Decided | GC-BREAK-003, GC-BREAK-004 | 05 PUNCH-012 | `cases/day.php` |
| AR-BREAK-03 | Decided | GC-BREAK-001, GC-BREAK-005 | 05 PUNCH-011 | `cases/day.php` |
| AR-CLOCK-01 | Decided | GC-ZONE-001, GC-ZONE-002, GC-ZONE-003 | 05 ATT-002; 05 ATT-003 | `cases/status.php` |
| AR-CLOCK-02 | Decided | GC-DATE-001 | 05 ATT-008 | `cases/status.php` |
| AR-CLOCK-04 | Implemented | GC-MIN-001, GC-WORK-002, GC-WORK-004 | register only | `cases/status.php` |
| AR-DAY-01 | Decided | GC-DAY-010 | 05 ATT-007 | `cases/day.php` |
| AR-DAY-02 | Decided | GC-DAY-001, GC-DAY-007 | 05 PUNCH-008 | `cases/day.php` |
| AR-DAY-03 | Decided | GC-DAY-005, GC-DAY-006, GC-DAY-009 | 05 CON-002; 05 MAN-003 | `cases/day.php` |
| AR-DAY-04 | Decided | GC-DAY-002 | 05 PUNCH-007 | `cases/day.php` |
| AR-DAY-05 | Decided | GC-DAY-003, GC-DAY-004 | 05 PUNCH-007; 05 ATT-006 | `cases/day.php` |
| AR-DAY-06 | Decided | GC-DAY-011 | 05 PUNCH-004 | `cases/day.php` |
| AR-DAY-07 | Decided | GC-DAY-008, GC-DAY-009 | 05 CON-001; 05 MAN-003 | `cases/day.php` |
| AR-DAY-09 | Decided | GC-DAY-012 | register only | `cases/day.php` |
| AR-MIN-01 | Decided | GC-BREAK-001, GC-CHAN-004, GC-LATE4-001, GC-MIN-001, GC-NIGHT-002, GC-OT-008, GC-STATUS-001, GC-STATUS-002, GC-STATUS-004, GC-STATUS-009 | 05 PUNCH-011; 05 SHF-004; 05 ATT-001; 05 ATT-002; 07 LATE-004; 05 CAP-002 | `cases/day.php`, `cases/night.php`, `cases/overtime.php`, `cases/status.php` |
| AR-MIN-02 | Decided | GC-EARLY-001, GC-EARLY-003, GC-LATE4-002 | 05 ATT-005; 07 LATE-004 | `cases/status.php` |
| AR-MIN-03 | Decided | GC-EARLY-005, GC-LATE4-001, GC-LATE4-002, GC-LATE4-003 | 05 PUNCH-010; 07 LATE-004 | `cases/status.php` |
| AR-MIN-04 | Decided | GC-AUTO-001, GC-BREAK-001, GC-BREAK-005, GC-CHAN-001, GC-CHAN-002, GC-CHAN-003, GC-NIGHT-001, GC-WORK-001, GC-WORK-002, GC-WORK-004 | 05 PUNCH-011; 05 AUTO-001; 05 SHF-003; 05 CAP-001; 05 CAP-001, MAN-002; 05 CAP-001, AIMP-002 | `cases/day.php`, `cases/night.php`, `cases/status.php` |
| AR-MIN-05 | Implemented | GC-SHIFT-001, GC-WORK-003 | 05 ATT-009 | `cases/status.php` |
| AR-MIN-07 | Decided | GC-STATUS-008 | register only | `cases/status.php` |
| AR-NIGHT-01 | Decided | GC-CHAN-001, GC-CHAN-002, GC-CHAN-003, GC-NIGHT-001, GC-NIGHT-002, GC-OT-005, GC-OT-013 | 05 SHF-003; 05 SHF-004; 05 CAP-001; 05 CAP-001, MAN-002; 05 CAP-001, AIMP-002 | `cases/night.php`, `cases/overtime.php` |
| AR-NIGHT-02 | Decided | GC-NIGHT-003, GC-NIGHT-004 | 05 SHF-013 | `cases/night.php` |
| AR-NIGHT-04 | Decided | GC-CHAN-002, GC-CHAN-003 | 05 CAP-001, MAN-002; 05 CAP-001, AIMP-002 | `cases/night.php` |
| AR-NIGHT-05 | Undecided | GC-AUTO-004 | register only | `cases/day.php` |
| AR-OT-01 | Decided | GC-OT-001, GC-OT-002, GC-OT-003, GC-OT-004, GC-OT-005, GC-OT-008, GC-OT-010, GC-OT-011 | 07 PAY-031; 05 PUNCH-012 | `cases/overtime.php` |
| AR-OT-02 | Decided | GC-OT-006, GC-OT-007, GC-OT-009, GC-OT-014, GC-OT-015 | register only | `cases/overtime.php` |
| AR-OT-03 | Decided | GC-OT-004, GC-OT-012, GC-OT-013 | register only | `cases/overtime.php` |
| AR-OT-08 | Decided | GC-SHIFT-001 | 05 ATT-009 | `cases/status.php` |
| AR-OT-09 | Decided | GC-OT-003 | register only | `cases/overtime.php` |
| AR-OT-11 | Decided | GC-OT-015 | register only | `cases/overtime.php` |
| AR-OT-12 | Implemented | GC-OT-014 | register only | `cases/overtime.php` |
| AR-SHIFT-05 | Decided | GC-NIGHT-002 | 05 SHF-004 | `cases/night.php` |
| AR-SHIFT-06 | Decided | GC-SHIFT-001 | 05 ATT-009 | `cases/status.php` |
| AR-STATUS-01 | Decided | GC-NIGHT-001, GC-OT-008, GC-STATUS-001, GC-STATUS-002, GC-STATUS-003, GC-STATUS-004, GC-STATUS-006, GC-STATUS-007, GC-STATUS-008, GC-ZONE-001, GC-ZONE-002, GC-ZONE-003 | 05 SHF-003; 05 ATT-001; 05 ATT-002; 05 ATT-003 | `cases/night.php`, `cases/overtime.php`, `cases/status.php` |
| AR-STATUS-03 | Decided | GC-EARLY-001, GC-EARLY-002, GC-EARLY-003, GC-EARLY-004 | 05 ATT-005; 05 ATT-004 | `cases/status.php` |
| AR-STATUS-04 | Decided | GC-EARLY-005, GC-LATE4-003 | 05 PUNCH-010; 07 LATE-004 | `cases/status.php` |
| AR-STATUS-05 | Decided | GC-CHAN-002, GC-CHAN-003, GC-CHAN-004, GC-LATE4-001, GC-LATE4-002, GC-LATE4-003 | 05 CAP-001, MAN-002; 05 CAP-001, AIMP-002; 07 LATE-004; 05 CAP-002 | `cases/night.php`, `cases/status.php` |
| AR-STATUS-07 | Undecided | GC-STATUS-005 | register only | `cases/status.php` |

Rules with no golden case yet: AR-ABS-06, AR-ABS-07 (undecided), AR-ABS-08 (undecided), AR-AUTO-03, AR-BREAK-04, AR-BREAK-05, AR-CLOCK-03, AR-CLOCK-05, AR-CLOCK-06 (undecided), AR-CLOCK-07 (undecided), AR-DAY-08 (undecided), AR-LATE-01, AR-LATE-02, AR-LATE-03, AR-MIN-06, AR-MIN-08 (undecided), AR-NIGHT-03, AR-OT-04, AR-OT-05, AR-OT-06, AR-OT-07, AR-OT-10 (undecided), AR-OT-13 (undecided), AR-OT-14 (undecided), AR-SHIFT-01, AR-SHIFT-02, AR-SHIFT-03, AR-SHIFT-04, AR-STATUS-02, AR-STATUS-06.
