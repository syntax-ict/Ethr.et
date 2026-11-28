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

Checked against the files on 2026-11-28, and against one session on commit `1c833bba61` (counts of tests, not of cases: a case can be run by more than one test). Every figure is a count of designed cases; none estimates real-world accuracy.

| Engine | Written rules | Measured today | Not measured | Status |
|---|---|---|---|---|
| Attendance | [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md) | 79 golden cases (unique `GC-` ids under `api/tests/Accuracy/cases/`), and the independent oracle run on the same cases: conformance. Four tests skipped, each a case on an Undecided rule | Generated runs, real-world agreement | Measured for conformance |
| Payroll | [PAYROLL-RULES.md](../payroll/PAYROLL-RULES.md) | 89 hand-valued cases (unique ids under `api/tests/Accuracy/Payroll/cases/`) through `PayrollEngine::preview()`, a blind oracle under `Oracle/Payroll/`, and the three-way comparison. The cases that touch an Undecided rule are no longer skipped as a whole: they run with the fields that depend on that rule withheld, and the sheet's "What was not compared" says how many (13 cases in the session of 2026-11-28). The 8 golden payslips and 15 tax points still run in the backend suite | Real-world agreement, until the accountant signs. Robustness | Measured for conformance on designed cases. Not certified |
| Leave | [LEAVE-RULES.md](../LEAVE-RULES.md) | The cases that need no signature, typed from the register, and four properties with a fixed seed (they print it). The cases that wait for a signature or on an Undecided rule are typed and skipped | The skipped cases, real-world agreement | Measured on the cases that need no signature. Not certified until HR or counsel sign the legally significant cases |
| Ethiopian calendar | None | PHP against a table from a third implementation (`api/tests/Accuracy/Calendar/`), and the TypeScript side, `src/src/test/ethiopian-calendar-table.test.ts` and `ethiopian-clock-roundtrip.test.ts`, run by Vitest | Real-world agreement | Measured against one table |
| Restated figures | None | Nothing | All of it. File 14 plans it | Not measured |

[14](14-LEAVE-CALENDAR-AND-RESTATED-FIGURES.md) says how leave, the calendar and restated figures are checked. A file `13-PAYROLL-ACCURACY.md` is being written for payroll and is not linked here yet.

**What a pass means, and how to quote a session.** A passed test means every comparable field agreed. A test prints a line starting with the engine's name and `TALLY:` for what it did not compare, and the sheet copies those lines unchanged under "What was not compared". For payroll, a **field on the engine-unexposed list** is one the engine's entry and log do not carry (today the two day counts, `days_employed` and `days_in_period`): it is checked by hand value and oracle only, and its count is how many times it was stated and not compared with the engine. A **withheld** field is one the oracle marks as depending on an Undecided rule, in a case the register leaves open. On the session of 2026-11-28 (folder `2026-11-28-1857`) the line read: `PAYROLL TALLY: 295 stated fields not comparable against the engine (days_employed x147, days_in_period x148); 130 fields withheld in 13 undecided cases (GP-LATE-007, GP-LATE-008, GP-LATE-010, GP-LEAVE-005, GP-LEAVE-006, GP-LOAN-005, GP-PAG-003, GP-PAG-007, GP-PAG-008, GP-PEN-007, GP-PRO-009, GP-RUN-003, GP-SICK-005)`. A skip count of zero for payroll therefore does not mean nothing was withheld; read the tally.

**Wording rule for anyone quoting a session:** "conforms to the register on N designed cases", with N counted, never a percentage and never "accurate". Rules marked Implemented in a register were written from the code, so agreement on them shows consistency, not independent proof ([how to read a row](../payroll/PAYROLL-RULES.md#how-to-read-a-row)).

**Three kinds of skip.** A skipped test is never a pass and never a failure. Each has its own reason in the test's message, and the sheet counts them separately.

| Kind | Meaning | Where it comes from | List them |
|---|---|---|---|
| Rule undecided | The case depends on a rule the register marks Undecided, so nothing is asserted. Expected while rules wait for a ruling | The register's "Waiting for a decision" section | `grep "rule undecided"` in `02-conformance.log` |
| Waiting for a signed expected value | A leave case whose expected value needs a signature from HR or counsel before it may be asserted | `api/tests/Accuracy/Leave/`; the item number is in the message | `grep "waiting for a signed"` in `02-conformance.log` |
| Other | A test that says why it cannot run, for example a payroll case the fixture cannot build ("NOT COVERABLE") | The message | The same log, the lines that are neither of the above |

The log lists them because the charter runs Pest with `--display-skipped` and `--display-incomplete`. To list them without a session, run the same command from `api/`: `php -d memory_limit=-1 vendor/bin/pest -c phpunit.accuracy.xml --display-skipped --display-incomplete`. An incomplete test is a recorded finding and is listed after a `…` marker rather than `-`.

## 5. Before you start

The script checks most of this in `preflight` and records what it found.

| Needs | For | Notes |
|---|---|---|
| A worktree at the release commit | Everything | The sheet records the commit and whether tracked files are clean. See "Which commit" below |
| `api/vendor` and `api/.env` with `BROADCAST_CONNECTION=null` | Every PHP charter | A fresh checkout: [CONTRIBUTING.md](../../CONTRIBUTING.md) |
| GNU `timeout` | Every charter | Git Bash has it. Windows' own `timeout.exe` is not it, and the script refuses it |
| MariaDB on `127.0.0.1:3306` | `races` only | See below |
| The local stack, and a queue worker | The browser journeys in `restated` only | See below |
| A saved QA session, made just before | `production` only | See below |
| `gh` signed in | `strength` only | Not signed in is a skip, not a failure |

**Which commit.** A session measures a commit, so say which. After a release, the commit under test is the one the live site reports in `release.json` on the site (every release carries it), which is also the commit of the release's tag `vYYYY.MM.DD.N`. [PLESK-GO-LIVE.md](../deployment/PLESK-GO-LIVE.md) describes how `scripts/release-status.sh https://<site>` compares the live `release.json` with the `production` branch and its tag. Check out that tag in the worktree. A session run before a release runs on the commit about to be released. The sheet's Commit line is what the report quotes.

**MariaDB on the owner's Windows machine.** Start it in a visible, minimised window, never hidden:

```powershell
Start-Process cmd.exe -ArgumentList '/c','C:\xampp\mysql_start.bat' -WindowStyle Minimized
```

A hidden start has corrupted its system tables before. The script never starts a database server.

**What `races` needs, as read from `concurrency_gate` in `scripts/gates.sh`, `api/phpunit.concurrency.xml` and `api/tests/Concurrency/AttendancePunchRaceTest.php`.** A MySQL or MariaDB server that answers a TCP connection at `DB_HOST:DB_PORT` (defaults `127.0.0.1` and `3306`), the database cache store, and a scratch database. The settings that locate the server come from the environment and default to host `127.0.0.1`, port `3306`, database `ethr_concurrency`, user `root` and an empty password, so on the owner's XAMPP machine nothing needs exporting; export `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` if yours differ, and never write the password into this file or a log. **Nothing creates the database.** The suite's header gives the one statement to run once: `CREATE DATABASE ethr_concurrency CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`. The suite then migrates it itself on its first test and leaves its rows behind (each run under a tenant of its own). The test refuses any database whose name does not contain `concurrency`, `scratch` or `suite`. I read no code that handles a missing database and did not try it, so what a missing `ethr_concurrency` looks like is not stated here: expect `races` to be recorded `failed`, and read its log. The gate itself fails rather than skips when no server answers; the script records `not run: no database server` first.

**Every `not run` the script can print.** The reason is the text in the sheet's Status column.

| Reason as printed | What is missing | How to provide it |
|---|---|---|
| `not run: no api/vendor or no php` | PHP on the PATH, or `api/vendor` | Install PHP 8.2 or newer (XAMPP's `C:\xampp\php` on the owner's machine) and run `composer install` in `api/` ([CONTRIBUTING.md](../../CONTRIBUTING.md); a cold install needs a GitHub token with no scopes) |
| `not run: no database server` | Nothing answers at `DB_HOST:DB_PORT` (default `127.0.0.1:3306`) | Start MariaDB in a visible window (command above), and create `ethr_concurrency` once |
| `not run: gh not signed in` | `gh` missing, or `gh auth status` fails | Install the GitHub CLI and sign in with `gh auth login`. The script only asks whether `gh` is signed in and never reads or logs the token |
| `not run: no run of accuracy-mutation yet` | The job exists but has never run | Someone starts the manual job in GitHub Actions. The script starts nothing |
| `not run: no completed run of accuracy-mutation in the last 10 runs` | The last 10 runs of its workflow all skipped the job | The same |
| `not run: local stack not running; restated-figures tests not built yet` | Nothing answers at the `e2e-att` address of `E2E_ORIGIN`, and the tests do not exist | Bring up the local stack (below), and set `E2E_ORIGIN` if it is not on port 3000. The tests wait for a later merge |
| `not run: no src/node_modules` | The frontend dependencies | `npm ci` in `src/` |
| `not run: the session check could not be read (needs Node 22.6 or newer)` | A Node that can read `src/e2e-production/env.ts` | Node 22.6 or newer |
| `not run: no saved session` | No saved sign-in is fresh | A person saves one just before (below). An agent never does this |
| `not run: preflight failed` | Every charter, after a failed preflight | Fix what `01-preflight.log` names, and run again |

`not built yet` is a different thing: the files do not exist in this checkout, and nothing a tester does provides them.

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

**Exit 0 means every charter that ran passed. It does not mean every charter ran.** Read the sheet's Status column, not the exit code, to see what was measured. A charter that is not built or not run writes no log. The number at the start of a log's name is the charter's number in section 7, so a session with logs 01, 02 and 04 and gaps between them is normal.

The sheet's header lines "Database at 127.0.0.1:3306" and "Local stack at ..." are the same probes as the table in section 5: a bounded TCP connection to the database port, and a bounded HTTP request to the stack. The address `restated` uses is in `01-preflight.log`.

A charter whose precondition is missing is recorded `not run: <reason>`. One whose files do not exist yet in this checkout is recorded `not built yet`, and starts running by itself the day the directory, gate mode or workflow job appears.

## 7. The charters

| # | Charter | What it runs | Needs | Timebox | Status today |
|---|---|---|---|---|---|
| 1 | `preflight` | Commit, branch, whether tracked files are clean, PHP, Composer and Node versions, `api/vendor`, the count of test files on disk, probes for the database, the stack and `gh` | Nothing | 2 min | Runs today |
| 2 | `conformance` | One run of the whole PHP accuracy suite (the command `./scripts/gates.sh accuracy` runs, see below), split by engine; the TypeScript calendar and clock tests under Vitest when `src/node_modules` exists; and `PayrollGoldenPayslipsTest` with `TaxCalculatorTest` only while `api/tests/Accuracy/Payroll` does not exist | `api/vendor`; `src/node_modules` for the TypeScript row | 15 + 5 min | Runs today |
| 3 | `robustness` | The generated seeded run and properties, and `api/tests/Feature/Idempotency`. The leave properties already run inside `conformance`, and the sheet copies their seed, cases and disagreements | `api/vendor` | 45 min | Generated runs and idempotency tests not built yet |
| 4 | `races` | `./scripts/gates.sh concurrency` | A database server | 20 min | Runs today when a server answers |
| 5 | `strength` | Reads the latest completed run of the CI job `accuracy-mutation` with `gh` | `gh` signed in | 5 min | The job exists (below); the reader is not built yet |
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
- **conformance.** One row per engine, with passed, failed, incomplete and the three kinds of skip (section 4). The PHP rows come from one Pest run, not one run per folder. The charter runs the command `accuracy_gate` in `scripts/gates.sh` runs, `php -d memory_limit=-1 vendor/bin/pest -c phpunit.accuracy.xml` from `api/`, with three flags that only add output: `--display-skipped`, `--display-incomplete` and `--log-junit`, so the charter and the gate run the same tests. The JUnit file gives tests, failures and skips per test file, and the log gives the reason of each skip; the rows are counted from those, then **checked against Pest's own last `Tests:` line**. If they do not add up, the sheet says so and shows no split rather than a wrong one. JUnit counts an incomplete test as skipped; the split takes it out using the log. A failure is new. **Incomplete** is a recorded finding (the engine disagrees with a hand value and the case is listed in a `findings.php`). The attendance row holds more tests than the 79 cases, because the oracle tests run the same cases again. Lines starting `<ENGINE> TALLY:` are copied unchanged into "What was not compared", directly under the table (section 4 says what they mean); if none is printed the sheet says "none printed". The shared guards row holds `OracleIndependenceTest`, the register consistency test and the `IntegerOnlyScan` self-tests. Read a failing case as [12, section 3](12-ATTENDANCE-ACCURACY.md#read-a-failing-case) says. If Pest prints no summary line, the sheet says so; it never writes zeros. The old extra run of the payroll golden payslips and tax points happens only while `api/tests/Accuracy/Payroll` is absent.
- **robustness.** The generated runs and the idempotency tests are not built yet, so the charter is `not built yet`. The leave properties run inside `conformance` and print their seed, cases and disagreements; the sheet copies those lines, in its Robustness output section, unchanged, and computes nothing (section 9).
- **races.** Needs a real server: it is the only proof of attendance above a single process ([BASELINE](../audit/BASELINE.md) section 25j). Without one it is `not run`, and the report says attendance concurrency was not measured.
- **strength.** Reads the conclusion of the latest completed run that did not skip the manual job. It starts no CI run. It also notes when that run was on another commit than this checkout.

  **The mutation job.** `.github/workflows/mutation.yml`, job `accuracy-mutation`, started only by hand: `gh workflow run mutation.yml` (add `-f classes='App\Support\Cents'` to try one class). It runs `./scripts/gates.sh mutation`, which makes the calculation core deliberately wrong one change at a time (a `<=` turned into `<`, a `+` into `-`, a rounding call removed) and reruns the accuracy suite, and it needs PCOV or Xdebug. It is never a required check and never runs on push or pull request. Its score and surviving mutants are in the job summary and in the artifact `accuracy-mutation`. A surviving mutant is a missing test: the engine was made wrong and every golden case still passed. Answer it with a new golden case whose hand value is typed from the rule register, never by weakening the mutation or loosening a case. No score is recorded in this repository until a run has measured one.
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

**Read first, then fill.** Before touching the sheet, read [12, section 6](12-ATTENDANCE-ACCURACY.md#6-classify-a-finding) for the four classes, then the findings files (`api/tests/Accuracy/findings.php` and `oracle-findings.php` for attendance), so a disagreement already recorded is not filed twice. Then fill the sheet in this order: first the Triage table, one row per failure, incomplete case or surprise, with its class; then the Debrief; then the report, from the sheet.

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
