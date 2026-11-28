# Accuracy report, 2026-11-28, session 1

This report follows session 0, [ACCURACY-REPORT-2026-11-28.md](ACCURACY-REPORT-2026-11-28.md), and does not change its figures. Session 1 was run on the development branch `test/accuracy-robustness`, not for a release. The template is [10, section 10](<../production testing/10-TEMPLATES-AND-REFERENCE.md#10-accuracy-report>) and the runbook is [15](<../production testing/15-ACCURACY-SESSION.md>). Every figure below is a count of designed cases, generated inputs or tests. None is an estimate of real-world accuracy, and no interval or percentage is given anywhere.

## Scopes

| Scope | Covers | Verdict | Link and date |
|---|---|---|---|
| A. Calculation accuracy | Attendance, payroll, leave, calendar, restated figures | This report | This report, 2026-11-28. A development-branch session, not run for a release |
| B. Application functional testing | Every screen and flow | Not a verdict of this report | Latest QA production log: [QA-PRODUCTION-2026-11-19-dev-074-log.md](QA-PRODUCTION-2026-11-19-dev-074-log.md), 2026-11-19, one case. Latest pass of the pack's cases: [QA-PRODUCTION-2026-11-17-seventh-edition-log.md](QA-PRODUCTION-2026-11-17-seventh-edition-log.md), 2026-11-17. Not run for this release |
| C. Security and tenant isolation | Access, isolation, rate limits, dependencies | Not a verdict of this report | Latest isolation audit in `docs/audit/`: [TENANT-ISOLATION-AUDIT.md](TENANT-ISOLATION-AUDIT.md), measured 2026-09-25. Not run for this release |
| D. Real-world operational acceptance | People and hardware in use | Not a verdict of this report | No filled UAT sign-off sheet exists in `docs/audit/`. Not run for this release |

## Session

| | |
|---|---|
| Date (UTC) | 2026-11-28 20:41 |
| Commit and tag | 195bb49708, no tag (development branch) |
| Branch | test/accuracy-robustness |
| Machine | MINGW64_NT-10.0-26100 3.6.7-fb42d713.x86_64 x86_64; PHP 8.3.36, Composer 2.9.8, Node v24.15.0, gh 2.101.0 |
| Session sheet | The session folder `api/storage/logs/accuracy-session/2026-11-28-2031/` (ignored by git, kept on the machine that ran it) |
| Run by | script (`./scripts/accuracy-session.sh`, full run, exit 0), read by an agent |

Worktree clean (tracked files). Test files on disk: Unit 19, Feature 567, together 586. A database server answered on 127.0.0.1:3306. The local stack at http://localhost:8081 did not answer.

| Charter | Status | Duration | Log | Note |
|---|---|---|---|---|
| preflight | passed | 0:00 | 01-preflight.log | worktree: clean (tracked files) |
| conformance | passed | 7:48 | 02-conformance.log | |
| robustness | passed | 0:14 | 03-robustness.log | |
| races | passed | 1:38 | 04-races.log | two races recorded as incomplete: findings below |
| strength | not run: workflow not on GitHub | 0:01 | 05-strength.log | `mutation.yml` exists in this checkout, GitHub has no workflow of that name yet |
| restated | passed | 0:01 | 06-restated.log | browser journeys not run: local stack not running; the restated-figures tests were counted from the conformance run |
| production | not run: no saved session | - | - | |

## Engines

Each cell is a measured figure with what it was measured on, or "not measured". The counts of tests are Pest's, from the session's conformance run: passed 751, failed 0, incomplete 3, skipped 38 over the whole PHP suite.

| Engine | Conformance | Robustness | Real-world agreement | Open rulings |
|---|---|---|---|---|
| Attendance | Conforms to the register on 81 designed cases (unique `GC-` ids under `api/tests/Accuracy/cases/`, counted), through the engine and the independent oracle: 178 tests passed, 0 failed, 4 skipped, each a case on an Undecided rule. Of the 46 rules the cases cite, 40 are Decided, 4 Implemented and 2 Undecided | A seeded generated run against the oracle: base seed 20261128, 70 months requested and completed, 2131 employee-days, 3450 punches, 0 disagreements, known findings none. Five properties over 15 months (replay, zone, a 30 minute late arrival, method change, invariants), 0 unexplained violating dates. The punch race tests passed. Input distribution is in the sheet; no interval is computed | Not measured. HR's signed attendance sheet for the same people and days would measure it | 11 Undecided rules |
| Payroll | Conforms to the register on 103 designed cases (90 compared in full, 13 on Undecided rules compared except the withheld fields): 342 tests passed, 0 failed. Of the 89 rules the cases cite, 40 are Decided, 37 Implemented and 12 Undecided. Agreement on an Implemented rule shows consistency with the code, not that the code is right | No generated payroll run exists. Idempotency tests passed (25 in the Idempotency folder, shared with leave and attendance). One race recorded: finding 1 | Not measured. The accountant's signed payslips for the same people and months would measure it | 13 Undecided rules |
| Leave | Conforms to the register on 97 asserted cases of 131 designed cases (27 wait for a signature, 7 are on Undecided rules): 102 tests passed, 0 failed. Of the 60 rules the cases cite, 35 are Decided, 16 Implemented and 9 Undecided | Four leave property tests over five properties, with their seed printed (see below). Idempotency tests passed. One race recorded: finding 2 | Not measured. A signed expected value from HR or counsel for each legally significant case, then real balances against it, would measure it | 20 Undecided rules, 15 of them legal |
| Ethiopian calendar | Measured against one table counted by a third implementation: 9 PHP tests and 14 TypeScript tests passed, 0 failed | Not measured | Not measured. A printed calendar published by the Church would measure it | None written |
| Restated figures | The payslip PDF, the timesheet grid, workbook and PDF, the bank file and the schedules read back and held against the engine: 10 tests passed, 3 incomplete (one recorded finding in three outputs, finding 3). Per the commit message: payslip 183 printed figures over 13 payslips, bank file 77, schedules 42, no difference | Not measured | Not measured. The displayed-figures browser journeys were not run | None written |

**What was not compared** (copied from the sheet): `PAYROLL TALLY: 295 stated fields not comparable against the engine (days_employed x147, days_in_period x148); 130 fields withheld in 13 undecided cases (GP-LATE-007, GP-LATE-008, GP-LATE-010, GP-LEAVE-005, GP-LEAVE-006, GP-LOAN-005, GP-PAG-003, GP-PAG-007, GP-PAG-008, GP-PEN-007, GP-PRO-009, GP-RUN-003, GP-SICK-005)`. The engine's log carries dates and a proration factor, not day counts, so those two fields are checked by hand value and oracle only.

**Generated run and properties, as printed** (lines copied from the sheet):

```text
ACCURACY_SEED=20261128 ACCURACY_MONTHS=70 (month i uses seed base + i)
ACCURACY_SEED=20261128 ACCURACY_PROPERTY_MONTHS=15
LEAVE PROPERTY accrual: seed=20261128 cases=10 disagreements=0
LEAVE PROPERTY holidays: seed=20261128 cases=45 disagreements=0
LEAVE PROPERTY repeat: seed=20261128 cases=8 disagreements=0
LEAVE PROPERTY splitting: seed=20261128 cases=60 disagreements=0
```

The generated attendance months compare two readings of one register, so agreement shows consistency with that reading and nothing more. Days of one month are not independent.

## Findings

The sheet's triage table was left empty by the script. The classes below are proposals, made from the test messages and the findings files, for the owner to confirm.

| # | Engine | Case or check | What the engine said, and what was expected | Class | Filed as |
|---|---|---|---|---|---|
| 1 | Payroll | `IdempotencyRaceTest`, two `ProcessPayrollJob` handles on one run | 80 entries for 40 employees (40 distinct), status completed, `employee_count` 80, `gross_total_cents` 64000000 where one set is 32000000. One set of entries was expected. The job is not unique and `payroll_entries` has no unique index on run and employee | Engine defect (proposed). Not fixed here; a fix task is proposed. Reachability in production not yet established (the job has one try; a continuation job or a second call of the cron queue endpoint could overlap) | IDEM-01 in `api/tests/Feature/Idempotency/findings.php` |
| 2 | Leave | `IdempotencyRaceTest`, two accrual runs for one tenant | `entitled_days` sums to 1254 over 30 balances where one run credits 660; 27 balances over-credited. One credit was expected. Each run reads `entitled_days`, works out the gap and adds it | Engine defect (proposed). Not fixed here; a fix task is proposed. Reachability in production not yet established (the job has three tries and the scheduler can start a second run while the first runs) | IDEM-02 |
| 3a | Restated figures | `TimesheetRestatedTest`, grid, grand total of worked hours | The ledger holds 9050 worked minutes, 150.8 hours at one decimal; the footer shows 150.9, the three rows (34.5, 84.2, 32.2) added after each was rounded | Rule undecided (proposed): no register row says whether a footer adds the printed rows or the minutes. The owner decides which total is right | GR-TIMESHEET-001 in `api/tests/Accuracy/Restated/findings.php` |
| 3b | Restated figures | The same, workbook, Summary sheet, Grand total row | The cell holds 150.9, the same three rows added after rounding | The same finding in a second output | GR-TIMESHEET-001 |
| 3c | Restated figures | The same, PDF, Summary table, Grand total line | The page prints 150.9 | The same finding in a third output | GR-TIMESHEET-001 |

Findings 3a, 3b and 3c are one finding in three outputs. Whether it reaches a pay figure was not checked here and is not claimed either way.

**The over-credited count differs between runs because a race does not land the same way twice.** The same accrual race test recorded 1100 over 30 balances with 20 over-credited in the earlier session folder `2026-11-28-2016`, 1254 with 27 in this session, and 1232 with 26 in the commit message of `0cc6eb54cf`. Each is "more than one run credits"; none is a stable figure. The payroll race gave 80 entries and 64000000 in both session folders that recorded it.

### Recorded gaps, not defects

From `api/tests/Feature/Idempotency/findings.php`: what the product does today when the same thing is sent twice or two things race. The data is right in each; the answer or a log is not what a client expects. Severity is the file's own.

| Id | Severity | What happens today |
|---|---|---|
| IDEM-03 | client | A repeat payroll approve, mark paid or void after a success is answered 422, not the first answer, whatever `Idempotency-Key` it carries. Money and balances move once |
| IDEM-04 | client | The identical leave request sent twice: one row, and the second answered 422 overlap, not the first request |
| IDEM-05 | client | A repeat leave approve, reject, cancel or recall after a success is answered 422. Balances move once |
| IDEM-06 | client | Two leave decisions racing: the balance moves once and one audit row is written, but the loser is not always refused (422, 403, or 200 with the winner's outcome) |
| IDEM-07 | noise | The missing-punch and anomaly scan jobs handled twice leave every recipient two in-app notifications for the same day. No record, pay or balance changes |
| IDEM-08 | noise | A terminal event pulled after it was pushed leaves one live record and a voided twin, and each further pull appends a duplicate entry to the punch log |
| IDEM-09 | noise | Committing an attendance import twice answers 201 with created 0 and skipped 1, and writes a second audit row |

### Resolved since session 0

- **GP-PRO-009 (payroll).** The first three-way run disagreed on it: hand value and oracle against the engine, for a person in a payable status whose termination date is before the period. The register rule read two ways. It was split: PYR-PRO-01 was rewritten and PYR-PRO-05 is Undecided. The case is no longer asserted on the fields that depend on it. This is a disagreement resolved by changing the register ([13, section 8](<../production testing/13-PAYROLL-ACCURACY.md#8-the-runs-and-the-review>)).
- **Two attendance findings (one defect).** GC-REST-001 and GC-REST-002 recorded that late and early minutes were counted on a rest day or a branch holiday (S1 worked 09:00 to 13:00 on such a day: 30 late and 270 early, against 0 expected, AR-MIN-06). Main fixed it in #952 (`198dc43952`). `api/tests/Accuracy/findings.php`, `generated-findings.php` and `property-findings.php` now return an empty list, and the generated run shows no disagreement.
- **Two pack corrections in [06](<../production testing/06-E2E-LEAVE-AND-APPROVALS.md>).** LR-002 (a Friday to Tuesday request with Monday a holiday counts 2 on a Monday to Friday week, 3 on a Monday to Saturday shift; GL-DAYS-015 and GL-DAYS-016) and LB-010 (the Ethiopian year 2019 ends on 11 September 2027; GL-YEAR-003).
- **Two leave values withdrawn or corrected.** GL-DAYS-013 had no source in the register and was retyped after LVR-DAYS-08. GL-REQ-020 was typed from a register example that named one holiday where two fall; the register was corrected and the case retyped as 3. Neither was an engine defect.

## Rules waiting for a decision

Counted as the rows whose state is Undecided on this branch. Each register lists them with the question each asks; they are not copied here.

| Rule id | Register | Question | Who decides | Cases waiting |
|---|---|---|---|---|
| 11 rules | [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md#waiting-for-a-decision) | See its "Waiting for a decision" section | See the register | 4 skipped tests, each a case on an Undecided rule |
| 13 rules | [PAYROLL-RULES.md](../payroll/PAYROLL-RULES.md#waiting-for-a-decision) | See its "Waiting for a decision" section | See the register | 13 cases compared except 130 withheld fields |
| 20 rules, 15 of them legal | [LEAVE-RULES.md](../LEAVE-RULES.md#waiting-for-a-decision) | See its "Waiting for a decision" section (23 items, none signed) | Counsel, HR, the accountant or the owner, as each item says | 7 cases on Undecided rules and 27 waiting for a signature |

## Exit criteria

The criteria proposed in [15, section 11](<../production testing/15-ACCURACY-SESSION.md#11-the-report-and-sign-off>), each held against the sheet. A criterion is met only where the sheet shows it.

| Criterion | Status | Evidence |
|---|---|---|
| Every Decided rule has a golden case, and engine, oracle and hand value agree, or each disagreement is a filed finding | Not met as worded | No case failed. 45 Decided rules have a reasoned exemption instead of a case (attendance 20 of 60, payroll 14 of 55, leave 11 of 46); `RegisterConsistencyTest` passed |
| Generated runs show zero unexplained disagreements over at least 100,000 employee-days and 10,000 payslips | Not met | Zero unexplained disagreements, but the run was 2131 employee-days of attendance and no payslip was generated |
| All eight idempotency and concurrency items are verified, or each gap is filed | Met, with limits | 25 idempotency tests and 8 race tests ran; every item has a test or a filed finding (file 15, section 8). The punch race calls the engine directly, so HTTP and a device push racing a phone are not in it |
| Every reconciliation property is green | Not met | The timesheet grand total differs in three outputs (finding 3). Properties over generated months passed |
| The mutation score is measured, and each surviving arithmetic or comparison mutant is reviewed | Not measurable yet | `strength` not run: the workflow is not on GitHub; the job has never run end to end |
| Leave has a signed expected value for every legally significant case | Not met | 27 leave cases wait for a signature; none is signed |
| Real world: employee-day agreement with HR's signed sheet, and the accountant's signed payslips matched to the cent | Not measurable yet | No backtest exists; real-world agreement is "not measured" for every engine |
| No open money defect | Not met | Findings 1 and 2 are open |

## Not run, and why

| Charter or measure | Status | Reason |
|---|---|---|
| strength (mutation testing) | not run | The workflow `mutation.yml` is not on GitHub; the job has never run end to end |
| restated, browser journeys | not run | The local stack at http://e2e-att.localhost:3000/login did not answer |
| production, read-only check | not run | No saved session |
| Generated payroll runs | not built yet | Only attendance has a generated run |
| Generated leave runs | not built yet | Leave has seeded properties only |
| Displayed-figures journeys | not built yet | No browser check compares each screen's figure with the engine's |
| Backtest against HR's signed sheet and the accountant's signed payslips | not built yet | No signed reference has been supplied |

## How the session itself was checked

Only what git and the test files record:

- The race tests that settle correctly (two payroll approvals, two leave decisions, identical leave requests) each go red with their lock removed (commit message of `0cc6eb54cf`). The two races recorded as findings are incomplete, and neither is fixed.
- The idempotency tests assert the end state read back, not the status code alone (same message).
- The session script records `strength` as "not run" when GitHub or the workflow cannot be reached, and fails only on a completed run that failed; it lists every incomplete test with its message (`10be53d33c`).
- `OracleIndependenceTest` holds self-tests that the scans find each kind of thing they forbid, and `PayrollCaseFormatTest` that a malformed case is refused.
- The mutation job has not been run, so the strength of the tests against a deliberately wrong engine is not measured.

## Closing statement

This report covers scope A, calculation accuracy, for the engines named above, on commit 195bb49708, on 2026-11-28. For each engine it states what was measured and how; "not measured" means no measurement exists. It does not cover application functional testing, security or tenant isolation, or real-world operational acceptance (scopes B, C and D), whose verdicts are recorded where the table at the top says. An engine with an open legal or accounting ruling is not certified, whatever its tests show. This report is not an approval of the ETHR product as a whole.

The verdict of this session is not a go. Two engine defects are open, both found by the race tests: two payroll job handles can double a run's entries, and two accrual runs can credit leave twice. Neither is fixed here, and whether either can happen in production is not yet established. Payroll, leave and attendance each have rules waiting for a ruling. No real-world agreement has been measured for any engine.
