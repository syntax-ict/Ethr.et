# 15. Accuracy session

**At a glance.** This file is the runbook for a one-day accuracy testing session: how it is organised, what the machine runs for you (`./scripts/accuracy-session.sh`), how to read each result, what to do with a disagreement, and what the report may and may not say. It states only what has been built and run. Everything else is listed as not built yet or planned. A session measures calculation accuracy and signs nothing else.

Companion documents: [12](12-ATTENDANCE-ACCURACY.md) (the three measures, the golden cases and the oracle for attendance), the rule registers [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md), [PAYROLL-RULES.md](../payroll/PAYROLL-RULES.md) and [LEAVE-RULES.md](../LEAVE-RULES.md), the sheet and report templates in [10, sections 9 and 10](10-TEMPLATES-AND-REFERENCE.md#9-accuracy-session-sheet), and the rules for a machine in [11, section 9](11-CLAUDE-CODE-AND-PLAYWRIGHT.md#9-running-an-accuracy-session).

## 1. What a session is and is not

A session is **session-based testing** aimed at one question: does each calculating engine give the right answer? It has charters (a named job with a timebox), a session sheet that records what happened, and a debrief. The script runs the machine charters in order and writes a draft sheet. A person or an agent then triages what it found and writes the report.

It is not a functional test of the application, not a security review, and not user acceptance. It does not certify ETHR. It says, per engine, what was measured, how, and what is still unmeasured.

## 2. The four scopes

Testing ETHR answers four different questions. Each has its own evidence and its own place where the verdict is recorded. A green result in one says nothing about the others.

| Scope | Covers | Evidence | Verdict recorded in |
|---|---|---|---|
| A. Calculation accuracy | Attendance, payroll, leave, calendar, restated figures | The accuracy session | `docs/audit/ACCURACY-REPORT-<date>.md` |
| B. Application functional testing | Every screen and flow | Pack files 03 to 08, the execution log in 10, `docs/audit/QA-PRODUCTION-*-log.md` | The latest QA production log |
| C. Security and tenant isolation | Access, isolation, rate limits, dependencies | File 09, `CrossTenantRouteSweepTest`, `TenantScopeBypassInventoryTest`, `./scripts/gates.sh security`, [SECURITY.md](../../SECURITY.md) | The latest security review in `docs/audit/` |
| D. Real-world operational acceptance | People and hardware in use | UAT sign-off ([02, section 9](02-UAT-GUIDE.md#9-sign-off)), a backtest on signed figures, an on-site soak | The UAT sign-off sheet |

**The session signs scope A only.** A report never says ETHR is certified. An engine with an open legal or accounting ruling is "not certified" whatever its tests say.

## 3. The three measures

The same three measures as [12, section 1](12-ATTENDANCE-ACCURACY.md#1-what-is-measured), applied to every engine.

| Measure | Question | Needs real data |
|---|---|---|
| Conformance | Does the engine do what ETHR's written rules say? | No |
| Robustness | Does the answer stay the same whichever channel, order, retry, duplicate or time zone it arrives by? | No |
| Real-world agreement | Does the engine's month agree with what a person signed for the same people and days? | Yes |

A figure for one says nothing about the others.

## 4. Engines and their status today

Checked against the files on 2026-11-28.

| Engine | Written rules | Measured today | Status |
|---|---|---|---|
| Attendance | [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md) | 79 golden cases (unique `GC-` ids under `api/tests/Accuracy/cases/`), and the independent oracle run on the same cases: conformance | Measured for conformance. Robustness and real-world agreement not measured |
| Payroll | [PAYROLL-RULES.md](../payroll/PAYROLL-RULES.md) | Hand-worked golden payslips G1 to G8 in `PayrollGoldenPayslipsTest.php` and 15 tax points in `TaxCalculatorTest.php`. No oracle, and the payroll cases are not yet in the accuracy gate | Partly measured |
| Leave | [LEAVE-RULES.md](../LEAVE-RULES.md) | Nothing | Not measured. Not certified until HR or counsel sign the legally significant cases |
| Ethiopian calendar | None | Nothing | Not measured |
| Restated figures | None | Nothing | Not measured |

## 5. Before you start

The script checks most of this in `preflight` and records what it found.

| Needs | For | Notes |
|---|---|---|
| A worktree at the release commit | Everything | The sheet records the commit and whether tracked files are clean |
| `api/vendor` and `api/.env` with `BROADCAST_CONNECTION=null` | Every PHP charter | A fresh checkout: [CONTRIBUTING.md](../../CONTRIBUTING.md) |
| GNU `timeout` | Every charter | Git Bash has it. Windows' own `timeout.exe` is not it, and the script refuses it |
| MariaDB on `127.0.0.1:3306` | `races` only | See below |
| The local stack, and a queue worker | The browser journeys in `restated` only | See below |
| A saved QA session, made just before | `production` only | See below |
| `gh` signed in | `strength` only | Not signed in is a skip, not a failure |

**MariaDB on the owner's Windows machine.** Start it in a visible, minimised window, never hidden:

```powershell
Start-Process cmd.exe -ArgumentList '/c','C:\xampp\mysql_start.bat' -WindowStyle Minimized
```

A hidden start has corrupted its system tables before. The script never starts a database server. The `races` charter also wants its own scratch database, `ethr_concurrency` (the gate says so and refuses another name).

**The local stack.** Bring up the production-shaped stack with `scripts/local-production/up.sh`, or run `php artisan serve` and `npm run dev`, and keep `php artisan queue:work --queue=attendance,notifications,default,exports --stop-when-empty` running for the payroll journey. The script probes the address that `./scripts/gates.sh e2e-attendance` itself uses (`E2E_ORIGIN`, default `http://{tenant}.localhost:3000`, tenant `e2e-att`), not only port 8081, and the journeys run only when that answers. They also seed organisations of their own into the database that `DB_*` names.

**A saved session.** A person saves one with the command in [11, section 3](11-CLAUDE-CODE-AND-PLAYWRIGHT.md#3-one-time-setup-and-saving-a-session). A session is good for 15 idle minutes, so a session saved in the morning is stale by the time charter 7 comes round. Save it just before, and run `./scripts/accuracy-session.sh --only production` straight after.

**Not part of a session:** the full backend suite on MariaDB. It takes over an hour on a local machine and CI runs it on every pull request.

## 6. Run it

```bash
./scripts/accuracy-session.sh                   # every charter, in order
./scripts/accuracy-session.sh --list            # what exists and what can run here; runs nothing
./scripts/accuracy-session.sh --only conformance,races
./scripts/accuracy-session.sh --out <dir>       # write somewhere else
```

`--list` still makes a few cheap probes (tool versions, one TCP connection, three bounded HTTP requests, `gh auth status`) and creates nothing. `--only` chooses among the charters; `preflight` always runs, because the others decide from what it finds. The script never edits a test, a case or a document, never starts a database server, never types or reads a credential, never retries, and gives every command a deadline.

**Where the output goes.** `api/storage/logs/accuracy-session/<UTC yyyy-mm-dd-HHMM>/`: one log per charter and `session-sheet.md`. That folder is ignored by git because everything under `api/storage/logs/` is. The more obvious `api/storage/accuracy-session/` is not ignored, which is why it is not used. The script checks with `git check-ignore` and refuses an `--out` inside the repository that git would show.

**Two-part charters** (`conformance`, `robustness`, `restated`) follow one rule: failed if any part that ran failed; passed if at least one part ran and every part that ran passed; otherwise the skip reasons, joined. A part that did not run is named in the sheet's Note column.

| Exit code | Meaning |
|---|---|
| 0 | Every charter that ran passed. Skips never fail the session |
| 1 | A charter that ran failed. A deadline that expired counts as failed |
| 2 | Preflight failed (no GNU `timeout`, no `php` or `node`, no `api/vendor`, an `--out` git would show), or the arguments were wrong |

A charter whose precondition is missing is recorded `not run: <reason>`. One whose files do not exist yet in this checkout is recorded `not built yet`, and starts running by itself the day the directory, gate mode or workflow job appears.

## 7. The charters

| # | Charter | What it runs | Needs | Timebox | Status today |
|---|---|---|---|---|---|
| 1 | `preflight` | Commit, branch, whether tracked files are clean, PHP, Composer and Node versions, `api/vendor`, the count of test files on disk, probes for the database, the stack and `gh` | Nothing | 2 min | Runs today |
| 2 | `conformance` | `./scripts/gates.sh accuracy`, then `PayrollGoldenPayslipsTest` and `TaxCalculatorTest` while `api/tests/Accuracy/Payroll` does not exist | `api/vendor` | 15 + 10 min | Runs today |
| 3 | `robustness` | The generated seeded run and properties, and `api/tests/Feature/Idempotency` | `api/vendor` | 45 min | Not built yet |
| 4 | `races` | `./scripts/gates.sh concurrency` | A database server | 20 min | Runs today when a server answers |
| 5 | `strength` | Reads the latest completed run of the CI job `accuracy-mutation` with `gh` | `gh` signed in | 5 min | Not built yet |
| 6 | `restated` | Restated-figures tests, and `./scripts/gates.sh e2e-attendance` | The local stack for the journeys | 40 min | Journeys run today when the stack answers. Tests not built yet |
| 7 | `production` | `npm run test:e2e:production` | A fresh saved session | 20 min | Runs today when a session is fresh |

**Plan for the day.** The script covers the machine part. The rest is for a person or an agent.

| Block | Time | Who |
|---|---|---|
| Preflight | 0:30 | Script |
| Conformance | 1:00 | Script, then read |
| Volume and robustness | 1:30 | Script, then read |
| Test strength | 0:30 | Script reads CI |
| System level | 1:30 | Script, then read |
| One number everywhere | 1:00 | Person or agent: the same figure on each screen and export (planned in 14, not written yet) |
| Production read-only | 0:30 | Script, with a session saved just before |
| Triage | 1:00 | Person or agent |
| Report | 0:30 | Person or agent |

**How to read each result.**

- **preflight.** The two numbers are the test files under `api/tests/Unit` and `api/tests/Feature`, the same ones the root [CLAUDE.md](../../CLAUDE.md) says to compare with what Pest collects. A dirty worktree is recorded, not refused: say so in the report.
- **conformance.** The counts come from Pest's own last `Tests:` line, per engine. **Incomplete** is a recorded finding (the engine disagrees with a hand value and the case is listed in `findings.php`). **Skipped** is a rule that is undecided in its register. **Failed** is new. The attendance row is the whole `tests/Accuracy` suite, oracle tests included, so it holds more tests than the 79 cases. Read a failing case as [12, section 3](12-ATTENDANCE-ACCURACY.md#read-a-failing-case) says. If there is no summary line, the sheet says so; it never writes zeros.
- **robustness.** Not built yet. When it exists, the sheet copies the seed, the number of cases, the disagreements and the input distribution unchanged from the test's output, and computes nothing (section 9).
- **races.** Needs a real server: it is the only proof of attendance above a single process ([BASELINE](../audit/BASELINE.md) section 25j). Without one it is `not run`, and the report says attendance concurrency was not measured.
- **strength.** Reads the conclusion of the latest completed run that did not skip the manual job. It starts no CI run. It also notes when that run was on another commit than this checkout.
- **restated.** The journeys read back what the API stored, not a toast, and never retry. They seed the local database and need the queue worker for the payroll journey.
- **production.** Read-only by construction ([11, section 4](11-CLAUDE-CODE-AND-PLAYWRIGHT.md#4-running-the-scripted-checks-playwright-cli)): every spec imports its `test` from `src/e2e-production/guard.ts`, which aborts any `/api/v1/` call that is not GET, HEAD or OPTIONS, and service workers are blocked. The script switches off the secret-path probe (S-05). A persona whose session is stale is recorded Blocked by the spec, not Failed.

## 8. Idempotency and concurrency

Each item is checked against the behaviour the product has today, read from the tests and the code on 2026-11-28. No API contract changes in this work. A guard that is missing is recorded as a finding and fixed in its own pull request, not inside the accuracy session.

| # | Item | Verified today by | What is not verified | Where a new test would go |
|---|---|---|---|---|
| 1 | Duplicate attendance submissions | `api/tests/Feature/Attendance/IdempotencyKeyHeaderTest.php`: "treats a replayed header key as a duplicate rather than a second record", "accepts the documented Idempotency-Key header in place of the body field", "still rejects a write that supplies no idempotency key at all". `KioskSecurityTest`: "treats a retried punch with the same idempotency key as a duplicate, not a second punch". `AutoCheckoutReplacementTest`: "answers a replay of the same check-out as a duplicate, and changes nothing twice". `RejoinSplitDayTest`: "answers a resent morning check-out as a duplicate after the rejoin, and does not close the day". Golden cases in `api/tests/Accuracy/cases/day.php`: GC-DAY-001, 002, 007, 010, 011 (duplicate outcomes) and GC-DAY-005, 006, 009 (two methods within 5 minutes merge into one live record). `EngineCorrectnessTest.php` has no duplicate test: it covers shifts and geofences | A replayed key is read back over HTTP for web check-in, kiosk and the offline sync only. `CaptureChannelParityTest` captures each channel once, never twice. The golden cases run the engine directly, not over HTTP. A repeated manual entry or a repeated CSV import commit has no repeat test found | `api/tests/Feature/Idempotency/`, one sequential repeat per capture channel |
| 2 | Repeated biometric device events | A push resend: `Device/ZktecoAdmsTest` "records pushed punches in the device zone, acknowledges every line, and dedupes a resend"; `Device/DevicePushInboxTest` "puts a ZKTeco punch in the inbox once, however often it is sent" (and the Suprema and ADMS twins); `Device/HikvisionInboxTest` "keeps one row and queues one job when the terminal sends the same event again"; `Device/DevicePunchKeyTest` "records the in and the out of the longest badge an employee holds, and a resend changes nothing"; `Device/DeviceHealthTruthTest` "moves with a push, and counts a resend as a duplicate". Browser: `src/e2e/attendance-payroll/device-health-counts.spec.ts`, `one-arrival-many-methods.spec.ts` | The known gap is confirmed. A pushed event is keyed `webhook:<vendor>:<device id>:<badge>:<time>` (`DeviceController.php` lines 724 and 864, `AdmsController.php` line 121). A pulled event is keyed `device:<device id>:<badge>:<time>` (`PullDeviceEventsJob.php` line 479). The keys differ, so the key cannot recognise the same event arriving by both. Only the day rules (duplicate and 5-minute merge) can, and no test sends one event by push and by pull. No test pulls the same events twice and reads back one record | `api/tests/Feature/Idempotency/`: the same terminal event by push then by pull, one live record read back |
| 3 | Concurrent attendance submissions | `api/tests/Concurrency/AttendancePunchRaceTest.php`, run by `./scripts/gates.sh concurrency`: "keeps one live record when two methods check a person in at the same instant", "answers a retry that races its original with the one record", "closes the day once when two methods check a person out at the same instant", "records the punch when the lock cannot be had in time", "reopens the day once when two check-ins race the return from a break". Each punch is a process of its own (`punch-child.php`), released together | The children call `AttendanceEngine::record()` directly, so the HTTP layer, middleware and a device push racing a phone are not in the race. Only attendance is raced: no other write is. The gate is opt-in and needs a scratch MariaDB database | `api/tests/Concurrency/`, on the `punch-child.php` pattern |
| 4 | Duplicate payroll processing or approval requests | Processing: `PayrollQueuedProcessingTest` "does not queue a second job when an idempotency key is replayed", "refuses to recompute a run that is no longer processing" (the job handled twice, entry count unchanged); `ContractSalaryAndRunOverlapTest` "replaying the same idempotency key still answers with the existing run", "a second run over the same period is refused"; `LeaverAndReprocessRunTest` "a voided run reprocessed a second time with a new key is refused". Balances: `PayrollBalanceMovementTest` "calculating a run moves no balance until it is approved", "void before approval and reprocess draws the balances down once", "void after approval gives back exactly what the run took, and reprocess takes it once". Approval rules: `PayrollReleaseControlsTest` (who may approve). The engine re-reads the run under `lockForUpdate` in `approve`, `markPaid` and `void` (`PayrollEngine.php` lines 398, 480, 511) and locks each balance row (`PayrollBalanceService.php` line 210), and `reserve` takes a cache lock per organisation | Nothing found: the same approve request sent twice in a row, read back as pay and loan and cost-sharing balances moved once. The controller answers a second approve with a 422 `invalid-state` (`PayrollController.php` lines 143 to 150) and the engine refuses under the lock, but no test sends it. Nothing found: two approvals of one run from separate processes (the payroll tests are sequential, and the default suite runs on SQLite, where a row lock does nothing). The same for two process requests racing the period check | `api/tests/Feature/Idempotency/` for the repeat; `api/tests/Concurrency/` for the race |
| 5 | Repeated leave requests | `LeaveManagementTest` "leave request detects overlap" (a pending request exists, a second over the same days is refused). The controller takes a lock per person (`LeaveRequestController.php` lines 136 and 137) and refuses an overlap with a pending or approved request (lines 173 to 186) | Nothing found: the identical request submitted twice in a row, read back as one request and `pending_days` raised once. By the code the second is refused with a 422 overlap, which is a different answer from the first (the first was created). The request has no idempotency key, so a client that lost the first response sees an error, not its request | `api/tests/Feature/Idempotency/` |
| 6 | Concurrent leave balance updates | `Leave/LeaveDecidedOnceTest`: "takes the days once when two approvals race", "does not reject a request another approver has just approved", "does not cancel a request approved since the employee opened it". Each case decides a stale copy after the first decision, in one process. `LeaveDecisionService.php` and `LeaveRecallService.php` re-read the request under `lockForUpdate` and check its status | No process-level race test exists. The "race" is two sequential calls, one with a stale copy. Nothing found for recalling or cancelling the same leave twice (`LeaveRecallTest` has no repeat). Nothing found: two submissions racing the balance check, although the code takes a lock for it | `api/tests/Concurrency/` for approve against approve and request against request; `api/tests/Feature/Idempotency/` for a repeated recall |
| 7 | Retry after a network interruption | Attendance: `MobileOfflineAttendanceTest` "offline sync is idempotent" (the same batch twice: first created, second duplicate), "a punch outside the offline window is a final refusal" and the other final and not-final cases; `KioskOfflineSyncTest` "records a sealed punch made during an outage, at the moment it was made" (it sends the same punch again after a retry); `KioskSecurityTest` as in row 1. Browser: `src/e2e/attendance-payroll/offline-punch.spec.ts` (a punch saved offline is sent and read back). Payroll: processing carries an idempotency key and a replay is answered with the same run (row 4) | Nothing found for payroll approve, mark paid and void, or for any leave write (request, approve, reject, cancel, recall): none carries an idempotency key, and none has a test that re-sends it after a lost response. By the code a re-sent approve is answered with an error, not the first answer. The offline browser journey sends once, never twice | `api/tests/Feature/Idempotency/` |
| 8 | Repeated queue execution | Payroll: `ProcessPayrollJob` is not unique and has one try; it skips a run that is not `processing` (`ProcessPayrollJob.php` line 120), tested as in row 4. Leave: `LeaveBalanceJobsTest` "the accrual job is safe to re-run in the same month" and "the carry-forward job is safe to re-run" (each run three times, one result; the accrual sets a target and the carry-forward assigns). Personnel changes that move salary: `ScheduledPersonnelActionTest` ("Running again changes nothing and logs nothing"). Devices: `ProcessDeviceInboundPunchesJob` and `RecordHeldPunchesJob` are `ShouldBeUniqueUntilProcessing`; `HikvisionInboxTest` "sweeps a pending row older than two minutes and processes it" reads back one record; `HeldBadgeAutoRecordTest` "leaves quietly while a manual assign holds the badge, and records nothing twice". Pay differences: `ComputePayDifferencesJob` retries rows still pending or failed; `PayDifferenceTest` "fails the job so the queue retries, and the retry works the failed rows again" | Nothing found: `PullDeviceEventsJob` run twice over the same events (it relies on the key per event, row 2). Nothing found: `RollForwardHolidaysJob` run twice (a comment says it is idempotent). `ScanMissingPunchesJob` and `ScanAttendanceAnomaliesJob` change no record, pay or balance, but a second run sends the same notifications again, and no test runs either twice. The unique and overlap guards are tested by sweeping, not by two jobs running at once | `api/tests/Feature/Idempotency/`, each job handled twice and the result read back |

Candidate findings (read from code, not run):

- Two workers on one payroll run. `ProcessPayrollJob.php` line 120 reads the status and then computes; the job is not unique and has no overlap guard. `payroll_entries` has only a plain index on `(payroll_run_id, employee_id)`, named `pay_run_emp` (`database/migrations/2026_07_01_000003_add_performance_indexes.php` line 42), and no unique one. If two workers took the same run at once, the check could pass for both. The queue's single try makes this unlikely, not impossible.
- Leave accrual read then increment. `app/Services/Leave/LeaveBalanceService.php` lines 136 to 140 read `entitled_days`, work out the gap to the target and add it with `increment`. Two runs at the same moment would each add the gap. Sequential re-runs are safe and tested.
- A busy leave lock is not caught. `LeaveRequestController.php` line 137 calls `$lock->block(10)` with no handling of a timeout. The attendance engine catches `LockTimeoutException` and lets the punch through; the leave controller does not, so a lock held for ten seconds would end the request in an error.
- A re-sent payroll approve gets an error. `PayrollController.php` lines 143 to 150 answer a second approve with a 422. The money is safe (the engine re-checks under the lock), but a client that lost the first response cannot tell its approve worked.
- A re-sent leave request gets an error. `LeaveRequestController.php` lines 173 to 186 answer the identical second request with a 422 overlap, not with the first request.
- Push and pull keys differ. `DeviceController.php` line 724 and `PullDeviceEventsJob.php` line 479 (row 2). This matters only for a terminal moved between push and pull, or one that does both.
- A second run of the two scan jobs repeats notifications. `ScanMissingPunchesJob.php` and `ScanAttendanceAnomaliesJob.php` keep no record of what they sent.

## 9. Statistics, as hard rules

1. **A confidence bound only for a backtest on real days**, and only for the organisation and period sampled. It says nothing about any other organisation or month.
2. **A generated seeded run reports** cases, seed, disagreements, the input distribution, and how to reproduce it (commit, command, seed). It reports **no interval**.
3. **Designed boundary cases are a count**, never a percentage of accuracy. 79 cases passing is "79 of 79", not "100% accurate".
4. **No generated figure is called real-world accuracy.** Generated inputs come from ETHR's own reading of the rules, so agreement proves consistency with that reading and nothing more.

## 10. Triage

Classify every disagreement with the four classes of [12, section 6](12-ATTENDANCE-ACCURACY.md#6-classify-a-finding): engine defect, wrong hand value, fixture error, rule undecided. Do not copy that table; use it. A session runs unattended, so these rules decide what happens without anyone asking:

| Situation | What happens |
|---|---|
| The engine disagrees with a hand value | Re-check the fixture first. If it is right, record the case in the engine's findings file as typed, and carry on |
| A rule has no source, or has two readings | It is Undecided in its register. The cases that depend on it are skipped, not asserted |
| A defect needs a change to application code | **Not fixed in a session.** Recorded with its failing case, then fixed in its own pull request with a failing test first |
| A precondition is missing | That charter is `not run`. Nothing waits for it |
| A case will not pass | **A hand value is never changed to make a case pass** |

## 11. The report and sign-off

The report is `docs/audit/ACCURACY-REPORT-<date>.md`, from the template in [10, section 10](10-TEMPLATES-AND-REFERENCE.md#10-accuracy-report). It covers scope A only, per engine, and never says the product is certified.

**Proposed exit criteria (proposed; the owner may change them after the first numbers).**

- Every Decided rule has a golden case, and the engine, the oracle and the hand value agree, or each disagreement is a filed finding.
- Generated runs show zero unexplained disagreements over at least 100,000 employee-days and 10,000 payslips.
- All eight idempotency and concurrency items are verified, or each gap is filed.
- Every reconciliation property is green.
- The mutation score is measured, and each surviving arithmetic or comparison mutant is reviewed.
- Leave has a signed expected value for every legally significant case.
- Real world: at least 99.5% employee-day agreement with HR's signed sheet, and the accountant's signed payslips matched to the cent.
- No open money defect.

Today none of these is met: the generated runs, the idempotency items, the properties and the mutation score are not built, and no backtest has been done.

## 12. Stop rules and production safety

- **Production is read-only, and only in the QA organisation.** A machine never writes there. The rules are those of [the pack's CLAUDE.md](CLAUDE.md) and [01, section 9](01-TEST-PLAN.md#9-production-safety-rules).
- **Never type a credential.** A person saves a session. An agent uses only a saved one and never opens or prints it.
- **Never start MariaDB hidden.** Section 5 has the visible command.
- **Every wait has a deadline.** A stuck charter is recorded `failed: timed out` and the session moves on. After a deadline, check no `php` process is left before running anything else, and never run two test runs in one worktree at the same time.
- **Stop and tell the owner** before anything that is not in this file, and at once if another organisation's data appears.
