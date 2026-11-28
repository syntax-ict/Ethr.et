# Accuracy report, 2026-11-28

Session 0: the baseline session. It was run on the development branch `docs/accuracy-session`, not for a release. The template is [10, section 10](<../production testing/10-TEMPLATES-AND-REFERENCE.md#10-accuracy-report>) and the runbook is [15](<../production testing/15-ACCURACY-SESSION.md>). Every figure below is a count of designed cases or tests. None is an estimate of real-world accuracy, and no confidence interval appears anywhere in this report.

## Scopes

| Scope | Covers | Verdict | Link and date |
|---|---|---|---|
| A. Calculation accuracy | Attendance, payroll, leave, calendar, restated figures | This report | This report, 2026-11-28. A baseline session on the development branch, not run for a release |
| B. Application functional testing | Every screen and flow | Not a verdict of this report | Latest QA production log: [QA-PRODUCTION-2026-11-19-dev-074-log.md](QA-PRODUCTION-2026-11-19-dev-074-log.md), 2026-11-19, one case. Latest pass of the pack's cases: [QA-PRODUCTION-2026-11-17-seventh-edition-log.md](QA-PRODUCTION-2026-11-17-seventh-edition-log.md), 2026-11-17. Not run for this release |
| C. Security and tenant isolation | Access, isolation, rate limits, dependencies | Not a verdict of this report | Latest isolation audit found in `docs/audit/`: [TENANT-ISOLATION-AUDIT.md](TENANT-ISOLATION-AUDIT.md), measured 2026-09-25. No later security review file exists there. Not run for this release |
| D. Real-world operational acceptance | People and hardware in use | Not a verdict of this report | No filled UAT sign-off sheet exists in `docs/audit/`. Not run for this release |

## Session

| | |
|---|---|
| Date (UTC) | 2026-11-28 16:42 |
| Commit and tag | 3db6e406b2, no tag (development branch) |
| Branch | docs/accuracy-session |
| Machine | MINGW64_NT-10.0-26100 3.6.7-fb42d713.x86_64 x86_64; PHP 8.3.36, Composer 2.9.8, Node v24.15.0, gh 2.101.0 |
| Session sheet | The session folder `api/storage/logs/accuracy-session/2026-11-28-1639/` (ignored by git, kept on the machine that ran it) |
| Run by | script (`./scripts/accuracy-session.sh`, full run, exit 0) |

Worktree clean (tracked files). Test files on disk: Unit 19, Feature 561, together 580. A database server answered on 127.0.0.1:3306. The local stack at http://localhost:8081 did not answer.

| Charter | Status | Duration | Log | Note |
|---|---|---|---|---|
| preflight | passed | 0:01 | 01-preflight.log | worktree: clean (tracked files) |
| conformance | passed | 1:49 | 02-conformance.log | |
| robustness | not built yet | - | - | |
| races | passed | 0:57 | 04-races.log | |
| strength | not built yet | - | - | |
| restated | not run: local stack not running | - | - | |
| production | not run: no src/node_modules | - | - | |

## Engines

Counts are the last Pest "Tests:" line of each run. Incomplete means a recorded finding and skipped means the rule is undecided; both were counted.

| Engine | Conformance | Robustness | Real-world agreement | Open rulings |
|---|---|---|---|---|
| Attendance | 171 passed, 0 failed, 0 incomplete, 4 skipped, on the whole `tests/Accuracy` suite. 79 of those are golden cases (unique `GC-` ids under `api/tests/Accuracy/cases/`, counted); the other tests are the oracle and independence tests, which run the same cases through a second implementation of the register. These are designed cases, not a sample | The `races` charter (punches racing from separate processes on MariaDB) passed. The generated run and the properties are not built | Not measured, no real data | 11 rules Undecided |
| Payroll | 30 passed, 0 failed, 0 incomplete, 0 skipped, on `PayrollGoldenPayslipsTest` and `TaxCalculatorTest`: eight hand-worked golden payslips (G1 to G8) and 15 tax points. No cases typed from the register and no oracle yet | Not measured | Not measured | 12 rules Undecided |
| Leave | Not measured | Not measured | Not measured. Not certified: legal rulings are open | 20 rules Undecided, 15 of them legal |
| Ethiopian calendar | Not measured | Not measured | Not measured | None written |
| Restated figures | Not measured | Not measured | Not measured | None written |

## Findings

Session 0 produced no failure and no incomplete case, so the triage table has no rows.

| # | Engine | Case or check | What the engine said, and what was expected | Class | Filed as |
|---|---|---|---|---|---|
| | | | none | | |

An engine defect found by the accuracy suite before this session (overtime counted from the shift's end for a record that began after it) was fixed and is recorded in [BASELINE section 25br](BASELINE.md#25br-overtime-counted-from-the-shifts-end-for-a-record-that-began-after-it-2026-11-28). It is not a finding of session 0.

**Candidates read from code, not run.** These are not findings of this session and none is a confirmed defect. Each is a question raised by reading, listed so it is not lost.

- The two bank outputs of one run can disagree when an employee was deleted after approval: the server CSV drops the entry and the run page's file keeps it (PYR-BANK-04 to PYR-BANK-06, `PAYROLL-RULES.md`).
- Net pay has no floor, so a loan instalment can make it negative (PYR-NET-02).
- Pack case 06 LR-002 expects 3 days for a Friday to Tuesday request with Monday a holiday. The register's LVR-DAYS-01 gives 2 on a Monday to Friday week (`LEAVE-RULES.md`, conflicts table).
- Pack case 06 LB-010 gives the end of Ethiopian year 2019 as 10 September 2027. The register gives 11 September 2027, because Pagume has six days that year (LVR-YEAR-01).
- AR-LATE-03 in `ATTENDANCE-RULES.md` is marked Decided with "rounded down", while the code rounds half up. The payroll register records this as PYR-ROUND-05, Undecided, and says AR-LATE-03 must drop to Undecided too.
- `api/lang/en/disciplinary.php` line 8 carries an em dash in a product string, against the house copy style.
- The two pension agency names in `api/database/migrations/2026_10_14_000005_create_pension_rules_and_statutory_layouts.php` (lines 173 and 180) carry an em dash.
- Two workers on one payroll run: `ProcessPayrollJob.php` line 120 reads the status and then computes, with no unique index on `payroll_entries` (file 15, section 8).
- Leave accrual reads then increments (`LeaveBalanceService.php` lines 136 to 140), so two runs at the same moment would each add the gap (file 15, section 8).
- A busy leave lock is not caught: `LeaveRequestController.php` line 137 calls `block(10)` with no handling of a timeout (file 15, section 8).
- A re-sent payroll approve gets a 422, and a re-sent identical leave request gets a 422 overlap instead of the first answer (file 15, section 8).

## Rules waiting for a decision

Counted as the rows whose state is Undecided. Each register lists them with the question each asks; they are not copied here.

| Rule id | Register | Question | Who decides | Cases waiting |
|---|---|---|---|---|
| 11 rules | [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md#waiting-for-a-decision) | See its "Waiting for a decision" section | See the register | 4 skipped tests in this session, each an undecided case |
| 12 rules | [PAYROLL-RULES.md](../payroll/PAYROLL-RULES.md#waiting-for-a-decision) | See its "Waiting for a decision" section | See the register | No payroll case is typed from the register yet |
| 20 rules, 15 of them legal | [LEAVE-RULES.md](../LEAVE-RULES.md#waiting-for-a-decision) | See its "Waiting for a decision" section | See the register | No leave case exists yet |

## Not run, and why

| Charter or measure | Status | Reason |
|---|---|---|
| robustness | not built yet | No generated run and no `api/tests/Feature/Idempotency`. Milestone: after payroll, calendar and leave cases (generated runs, idempotency and race tests) |
| strength | not built yet | No `accuracy-mutation` job in any workflow. Milestone: the same, after payroll, calendar and leave cases (the mutation job) |
| restated | not run: local stack not running | No server answered at the `e2e-att` address of the browser journeys. The restated-figures tests are not built; milestone: after that (restated and displayed figures) |
| production | not run: no src/node_modules | `npm ci` was not run in `src/`, and no saved session was made |
| Payroll cases from the register and an oracle | not built yet | Next milestone |
| Ethiopian calendar cross-check | not built yet | Next milestone |
| Leave cases | not built yet | Next milestone |
| Real-world agreement for every engine | not measured | No signed real data exists |

## How the session itself was checked

- A tester who had not seen the work ran a session from file 15 alone: exit 0. The tester named nine gaps and all nine are closed.
- The script's skip path was exercised with `ACCURACY_SESSION_DB_PORT=1`, which recorded `races` as `not run: no database server`. Its deadline was exercised by running `sleep 20` under a 2 second bound, which returned 124. An unknown charter name and an output folder inside the repository that git would show each exit 2.
- Two independent reviewers worked payslips and leave figures from the registers alone. Their corrections were applied: 16 for payroll and 17 for leave.
- Conformance was run on the commit above and its log holds no colour codes, so the stripping and the summary-line parsing were checked end to end.

## Closing statement

This report covers scope A, calculation accuracy, for the engines named above, on commit 3db6e406b2, on 2026-11-28. For each engine it states what was measured and how; "not measured" means no measurement exists. Attendance conformance was measured on designed cases only, payroll conformance on eight golden payslips and 15 tax points only, and attendance concurrency on one run of the race test. Robustness for payroll, leave, the calendar and restated figures, test strength, and real-world agreement for every engine were not measured. It does not cover application functional testing, security or tenant isolation, or real-world operational acceptance (scopes B, C and D), whose verdicts are recorded where the table at the top says. An engine with an open legal or accounting ruling is not certified, whatever its tests show. This report is not an approval of the ETHR product as a whole.
