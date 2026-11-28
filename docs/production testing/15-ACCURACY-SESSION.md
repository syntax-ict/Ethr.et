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
| Attendance | [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md) | 79 golden cases (unique `GC-` ids under `api/tests/Accuracy/cases/`), and the independent oracle run on the same cases: conformance. Four tests skipped, each a case on an Undecided rule. Robustness: seeded generated months compared with the oracle, and property tests (replay, zone, late arrival, method), in `GeneratedMonthsTest` and `PropertiesTest`, with known findings listed | Real-world agreement | Measured for conformance and, on generated months, robustness |
| Payroll | [PAYROLL-RULES.md](../payroll/PAYROLL-RULES.md) | 89 hand-valued cases (unique ids under `api/tests/Accuracy/Payroll/cases/`) through `PayrollEngine::preview()`, a blind oracle under `Oracle/Payroll/`, and the three-way comparison. The cases that touch an Undecided rule are no longer skipped as a whole: they run with the fields that depend on that rule withheld, and the sheet's "What was not compared" says how many (13 cases in the session of 2026-11-28). The 8 golden payslips and 15 tax points still run in the backend suite | Real-world agreement, until the accountant signs. Robustness | Measured for conformance on designed cases. Not certified |
| Leave | [LEAVE-RULES.md](../LEAVE-RULES.md) | The cases that need no signature, typed from the register, and four properties with a fixed seed (they print it). The cases that wait for a signature or on an Undecided rule are typed and skipped | The skipped cases, real-world agreement | Measured on the cases that need no signature. Not certified until HR or counsel sign the legally significant cases |
| Ethiopian calendar | None | PHP against a table from a third implementation (`api/tests/Accuracy/Calendar/`), and the TypeScript side, `src/src/test/ethiopian-calendar-table.test.ts` and `ethiopian-clock-roundtrip.test.ts`, run by Vitest | Real-world agreement | Measured against one table |
| Restated figures | None | The printed payslip, the timesheet (grid, workbook and PDF), the bank file and the schedules read back and compared with what the entries hold, in `api/tests/Accuracy/Restated/` | The displayed-figures browser journeys | Measured for conformance. Its recorded findings are in the sheet |

[14](14-LEAVE-CALENDAR-AND-RESTATED-FIGURES.md) says how leave, the calendar and restated figures are checked. A file `13-PAYROLL-ACCURACY.md` is being written for payroll and is not linked here yet.

**What a pass means, and how to quote a session.** A passed test means every comparable field agreed. A test prints a line starting with the engine's name and `TALLY:` for what it did not compare, and the sheet copies those lines unchanged under "What was not compared". For payroll, a **field on the engine-unexposed list** is one the engine's entry and log do not carry (today the two day counts, `days_employed` and `days_in_period`): it is checked by hand value and oracle only, and its count is how many times it was stated and not compared with the engine. A **withheld** field is one the oracle marks as depending on an Undecided rule, in a case the register leaves open. On the session of 2026-11-28 (folder `2026-11-28-1857`) the line read: `PAYROLL TALLY: 295 stated fields not comparable against the engine (days_employed x147, days_in_period x148); 130 fields withheld in 13 undecided cases (GP-LATE-007, GP-LATE-008, GP-LATE-010, GP-LEAVE-005, GP-LEAVE-006, GP-LOAN-005, GP-PAG-003, GP-PAG-007, GP-PAG-008, GP-PEN-007, GP-PRO-009, GP-RUN-003, GP-SICK-005)`. A skip count of zero for payroll therefore does not mean nothing was withheld; read the tally.

**Wording rule for anyone quoting a session:** "conforms to the register on N designed cases", with N counted, never a percentage and never "accurate". Rules marked Implemented in a register were written from the code, so agreement on them shows consistency, not independent proof ([how to read a row](../payroll/PAYROLL-RULES.md#how-to-read-a-row)).

**Robustness today.** The generated attendance months (`GeneratedMonthsTest`, seed and month count printed as `ACCURACY_SEED=... ACCURACY_MONTHS=...`, every month replayable alone) and the property tests (`PropertiesTest`, replay, zone, late arrival and method) run inside the conformance run and are shown in their own rows; the leave properties print `LEAVE PROPERTY ... seed= cases= disagreements=`. Their known findings (today the late and early minutes counted on a rest day or holiday) are listed in the sheet's Robustness output, and an engine disagreement that is not a known finding fails the run. `tests/Feature/Idempotency` and the race tests are in section 8. Restated figures (`tests/Accuracy/Restated`: the payslip, the timesheet, the bank file and the schedules read back from what they print) now run too.

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
| 3 | `robustness` | The generated months and the property tests (counted once, from the conformance run, and run alone only when `conformance` was not selected), and `api/tests/Feature/Idempotency` | `api/vendor` | 45 min | Runs today |
| 4 | `races` | `php -d memory_limit=-1 vendor/bin/pest -c phpunit.concurrency.xml`, the command `./scripts/gates.sh concurrency` runs, plus two flags that only add output (`--display-skipped`, `--display-incomplete`), so an incomplete race test shows its message. Covers `AttendancePunchRaceTest` and `IdempotencyRaceTest` | A database server and the scratch database | 20 min | Runs today when a server answers |
| 5 | `strength` | Reads the latest completed run of the CI job `accuracy-mutation` with `gh` | `gh` signed in, and the workflow on GitHub | 5 min | Runs today. Not run until the workflow is on GitHub |
| 6 | `restated` | The restated-figures tests (counted from the conformance run, not run a second time; run alone only when `conformance` was not selected), and `./scripts/gates.sh e2e-attendance` for the browser journeys | The local stack for the journeys | 40 min | Both run today; the journeys need the stack |
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
- **robustness.** The generated months and the property tests run once, inside `conformance`, and appear in their own rows there and in the sheet's "Robustness runs" table; the charter reads their counts from the conformance JUnit file and runs them itself only when `conformance` was not selected. It also runs `tests/Feature/Idempotency` and lists its counts. The sheet's Robustness output copies, unchanged, the lines the tests print for the purpose: `LEAVE PROPERTY ... seed= cases= disagreements=`, `ACCURACY_SEED=... ACCURACY_MONTHS=...` and `ACCURACY_SEED=... ACCURACY_PROPERTY_MONTHS=...`, and from the two report files the tests write (`api/storage/framework/testing/accuracy/summary.md` and `properties.md`) only the seed and counts, the known findings and the input distribution. Those reports also hold a Wilson interval; the script drops every line that mentions one, and no interval or percentage is ever computed or copied (section 9). A known finding is a disagreement the test lists as understood; an unexplained one fails the run.
- **races.** Needs a real server: it is the only proof of attendance above a single process ([BASELINE](../audit/BASELINE.md) section 25j). Without one it is `not run`, and the report says concurrency was not measured. The charter runs `AttendancePunchRaceTest` and `IdempotencyRaceTest`. A race that comes out wrong is marked **incomplete** with the figures it saw, so the charter passes and the figures are in the sheet's "Recorded findings": read them first, they are the most important result of the session (section 8).
- **strength.** Reads the latest completed run of the manual workflow `mutation.yml` with `gh`. It starts no CI run. Four outcomes, none of which can fail the session except the last: `not run: GitHub not reachable` (`gh` could not read the run list); `not run: workflow not on GitHub` (the file exists here and GitHub has no workflow of that name, because it is not merged to the default branch yet); `not measured: never run`; and a real result, the run's conclusion with its date and commit (and a note when that commit is not this checkout's). Only a completed run whose conclusion is **failure** fails the charter. **What failure means:** the job broke (no coverage driver, a plugin error, a timeout inside the job), because the gate has no pass mark. `MUTATION_MIN` is empty, so a low score does not make the job fail, and there is no floor today. The score and the surviving mutants are on the run's summary page and in the artifact `accuracy-mutation`, not in the conclusion; read them there.

  **The mutation job.** `.github/workflows/mutation.yml`, job `accuracy-mutation`, started only by hand: `gh workflow run mutation.yml` (add `-f classes='App\Support\Cents'` to try one class). It runs `./scripts/gates.sh mutation`, which makes the calculation core deliberately wrong one change at a time (a `<=` turned into `<`, a `+` into `-`, a rounding call removed) and reruns the accuracy suite, and it needs PCOV or Xdebug. It is never a required check and never runs on push or pull request. Its score and surviving mutants are in the job summary and in the artifact `accuracy-mutation`. A surviving mutant is a missing test: the engine was made wrong and every golden case still passed. Answer it with a new golden case whose hand value is typed from the rule register, never by weakening the mutation or loosening a case. No score is recorded in this repository until a run has measured one.
- **restated.** The restated-figures tests (the payslip, the timesheet, the bank file and the schedules) are part of the conformance run, so the charter takes their counts from its JUnit file and does not run them a second time; it runs them alone only when `conformance` was not selected. The browser journeys read back what the API stored, not a toast, and never retry; they seed the local database and need the queue worker for the payroll journey. An incomplete restated test is a recorded finding and is in the sheet.
- **production.** Read-only by construction ([11, section 4](11-CLAUDE-CODE-AND-PLAYWRIGHT.md#4-running-the-scripted-checks-playwright-cli)): every spec imports its `test` from `src/e2e-production/guard.ts`, which aborts any `/api/v1/` call that is not GET, HEAD or OPTIONS, and service workers are blocked. The script switches off the secret-path probe (S-05). A persona whose session is stale is recorded Blocked by the spec, not Failed.

## 8. Idempotency and concurrency

Each item is checked against the behaviour the product has today, read from the tests and the code on 2026-11-28 and run in the session. No API contract changes in this work. A guard that is missing is a recorded finding, and each finding is fixed in its own pull request, never inside the accuracy session. The tests mark a finding by being **incomplete**, with the figures they saw in the message, so the gate stays green and the sheet's "Recorded findings" section lists them. The findings are written down in `api/tests/Feature/Idempotency/findings.php` (IDEM-01 to IDEM-09); the process-level races are in `api/tests/Concurrency/IdempotencyRaceTest.php`, run by the `races` charter.

| # | Item | Verified by | Recorded finding, or what stays unverified |
|---|---|---|---|
| 1 | Duplicate attendance submissions | `api/tests/Feature/Attendance/IdempotencyKeyHeaderTest.php`: "treats a replayed header key as a duplicate rather than a second record", "accepts the documented Idempotency-Key header in place of the body field", "still rejects a write that supplies no idempotency key at all". `KioskSecurityTest`: "treats a retried punch with the same idempotency key as a duplicate, not a second punch". `AutoCheckoutReplacementTest`: "answers a replay of the same check-out as a duplicate, and changes nothing twice". `RejoinSplitDayTest`: "answers a resent morning check-out as a duplicate after the rejoin, and does not close the day". Golden cases in `api/tests/Accuracy/cases/day.php`: GC-DAY-001, 002, 007, 010, 011 (duplicate outcomes) and GC-DAY-005, 006, 009 (two methods within 5 minutes merge into one live record). `EngineCorrectnessTest.php` has no duplicate test: it covers shifts and geofences Now also: `tests/Feature/Idempotency/AttendanceRepeatTest.php`: a manual entry submitted twice with the same key (one record, the second answered 200 `was_duplicate`), the same entry re-sent under a fresh key (the day rule voids the second as a twin merged into the first), and a CSV import committed twice (one record, nothing created the second time). | Recorded finding IDEM-09 (noise): the second commit is answered 201 with created 0 and skipped 1 and writes a second `attendance.import.committed` audit row. Safe; the audit log says the import was committed twice. |
| 2 | Repeated biometric device events | A push resend: `Device/ZktecoAdmsTest` "records pushed punches in the device zone, acknowledges every line, and dedupes a resend"; `Device/DevicePushInboxTest` "puts a ZKTeco punch in the inbox once, however often it is sent" (and the Suprema and ADMS twins); `Device/HikvisionInboxTest` "keeps one row and queues one job when the terminal sends the same event again"; `Device/DevicePunchKeyTest` "records the in and the out of the longest badge an employee holds, and a resend changes nothing"; `Device/DeviceHealthTruthTest` "moves with a push, and counts a resend as a duplicate". Browser: `src/e2e/attendance-payroll/device-health-counts.spec.ts`, `one-arrival-many-methods.spec.ts` Now also: `AttendanceRepeatTest.php`: a terminal event that arrives by push and then by pull, and `PullDeviceEventsJob` run twice over the same events. | Recorded finding IDEM-08 (noise): live records stay at one, but the pulled check-in is kept as a voided twin and every further pull appends another duplicate entry to the live record's punch log, because the push and pull keys differ. Matters only for a terminal that both pushes and is pulled. |
| 3 | Concurrent attendance submissions | `api/tests/Concurrency/AttendancePunchRaceTest.php`, run by `./scripts/gates.sh concurrency`: "keeps one live record when two methods check a person in at the same instant", "answers a retry that races its original with the one record", "closes the day once when two methods check a person out at the same instant", "records the punch when the lock cannot be had in time", "reopens the day once when two check-ins race the return from a break". Each punch is a process of its own (`punch-child.php`), released together Now also: run by the races charter, and passed in the session of 2026-11-28. | The children call `AttendanceEngine::record()` directly, so the HTTP layer, middleware and a device push racing a phone are not in the race. Nothing found fails. |
| 4 | Duplicate payroll processing or approval requests | Processing: `PayrollQueuedProcessingTest` "does not queue a second job when an idempotency key is replayed", "refuses to recompute a run that is no longer processing" (the job handled twice, entry count unchanged); `ContractSalaryAndRunOverlapTest` "replaying the same idempotency key still answers with the existing run", "a second run over the same period is refused"; `LeaverAndReprocessRunTest` "a voided run reprocessed a second time with a new key is refused". Balances: `PayrollBalanceMovementTest` "calculating a run moves no balance until it is approved", "void before approval and reprocess draws the balances down once", "void after approval gives back exactly what the run took, and reprocess takes it once". Approval rules: `PayrollReleaseControlsTest` (who may approve). The engine re-reads the run under `lockForUpdate` in `approve`, `markPaid` and `void` (`PayrollEngine.php` lines 398, 480, 511) and locks each balance row (`PayrollBalanceService.php` line 210), and `reserve` takes a cache lock per organisation Now also: `PayrollRepeatTest.php`: approve, mark paid and void each sent twice (money and balances move once; the first approver, `approved_at`, `paid_on` and reference stand), and `ProcessPayrollJob` handled twice one after the other (one set of entries). `LostResponseResendTest.php`: a re-sent process with the same key is answered with the first run. `tests/Concurrency/IdempotencyRaceTest.php`: "releases pay once when two approvals of the same payroll run reach the server together" (passes). | **Recorded finding IDEM-01 (money or balance), not fixed here:** two `ProcessPayrollJob` handles released together on one processing run of 40 employees wrote 80 `payroll_entries` (40 distinct employees, each twice), status completed, `employee_count` 80 and `gross_total_cents` 64,000,000 where one set is 32,000,000. The status check passes for both and no unique index on `(payroll_run_id, employee_id)` stops the second row. Whether approving such a run draws the balances twice was not tested. Recorded finding IDEM-03 (client): a repeat approve, mark paid or void is answered 422, not the first answer. |
| 5 | Repeated leave requests | `LeaveManagementTest` "leave request detects overlap" (a pending request exists, a second over the same days is refused). The controller takes a lock per person (`LeaveRequestController.php` lines 136 and 137) and refuses an overlap with a pending or approved request (lines 173 to 186) Now also: `LeaveRepeatTest.php`: the identical request sent twice (one row, `pending_days` raised once). `IdempotencyRaceTest.php`: "keeps one request and raises pending days once when identical leave requests reach the server together" (passes). | Recorded finding IDEM-04 (client): the second request is answered 422 overlap, not the first request, and an `Idempotency-Key` header changes nothing. |
| 6 | Concurrent leave balance updates | `Leave/LeaveDecidedOnceTest`: "takes the days once when two approvals race", "does not reject a request another approver has just approved", "does not cancel a request approved since the employee opened it". Each case decides a stale copy after the first decision, in one process. `LeaveDecisionService.php` and `LeaveRecallService.php` re-read the request under `lockForUpdate` and check its status Now also: `LeaveRepeatTest.php`: approve, reject, recall (and an early return recalled twice) and cancel each sent twice, with balances moving once. `IdempotencyRaceTest.php`: "takes the days once when two decisions on the same leave request reach the server together" (passes, accepts 200, 403 or 422 for the loser and says why). | **Recorded finding IDEM-02 (money or balance), not fixed here:** two accrual runs released together over 30 people on a 24-day monthly type left `entitled_days` summing to 1232 where one run credits 660; 26 of 30 balances were credited twice. Each run reads `entitled_days`, works out the gap and adds it with `increment()`. Recorded finding IDEM-06 (client): the loser of two racing decisions is not always refused (422, 403, or 200 with the winner's outcome). Recorded finding IDEM-05 (client): a repeat leave decision is answered 422. |
| 7 | Retry after a network interruption | Attendance: `MobileOfflineAttendanceTest` "offline sync is idempotent" (the same batch twice: first created, second duplicate), "a punch outside the offline window is a final refusal" and the other final and not-final cases; `KioskOfflineSyncTest` "records a sealed punch made during an outage, at the moment it was made" (it sends the same punch again after a retry); `KioskSecurityTest` as in row 1. Browser: `src/e2e/attendance-payroll/offline-punch.spec.ts` (a punch saved offline is sent and read back). Payroll: processing carries an idempotency key and a replay is answered with the same run (row 4) Now also: `LostResponseResendTest.php`: a re-sent payroll approve, mark paid, void and process; a re-sent leave request; a re-sent leave decision, each with an `Idempotency-Key` header. | Recorded findings IDEM-03, IDEM-04 and IDEM-05 (client): every re-send except a payroll process is answered with an error, whatever key it carries, so a client that lost the first response must read the record to find out. Data is right in every case. |
| 8 | Repeated queue execution | Payroll: `ProcessPayrollJob` is not unique and has one try; it skips a run that is not `processing` (`ProcessPayrollJob.php` line 120), tested as in row 4. Leave: `LeaveBalanceJobsTest` "the accrual job is safe to re-run in the same month" and "the carry-forward job is safe to re-run" (each run three times, one result; the accrual sets a target and the carry-forward assigns). Personnel changes that move salary: `ScheduledPersonnelActionTest` ("Running again changes nothing and logs nothing"). Devices: `ProcessDeviceInboundPunchesJob` and `RecordHeldPunchesJob` are `ShouldBeUniqueUntilProcessing`; `HikvisionInboxTest` "sweeps a pending row older than two minutes and processes it" reads back one record; `HeldBadgeAutoRecordTest` "leaves quietly while a manual assign holds the badge, and records nothing twice". Pay differences: `ComputePayDifferencesJob` retries rows still pending or failed; `PayDifferenceTest` "fails the job so the queue retries, and the retry works the failed rows again" Now also: `ScheduledJobRepeatTest.php`: `RollForwardHolidaysJob` handled twice (holidays created once), `ScanMissingPunchesJob` and `ScanAttendanceAnomaliesJob` handled twice. `PayrollRepeatTest.php`: `ProcessPayrollJob` handled twice in turn. | Recorded finding IDEM-07 (noise): each scan job handled twice leaves every recipient two in-app notifications of the same kind for the same day; no record, pay or balance changes. For two jobs at the same moment see IDEM-01 and IDEM-02 above. |

**The findings.** Two are doubled records and neither is fixed here:

- **IDEM-01, payroll entries doubled.** Two `ProcessPayrollJob` handles on one run of 40 employees wrote 80 entries and a gross of 64,000,000 cents where one set is 32,000,000. A run processed twice at once pays twice at approval.
- **IDEM-02, leave credited twice.** Two accrual runs over 30 people left `entitled_days` at 1232 where one run credits 660; 26 of 30 balances were credited twice.

| Id | Severity | Where | What happens today |
|---|---|---|---|
| IDEM-01 | money or balance | `ProcessPayrollJob`, two handles of one run | Entries doubled (above) |
| IDEM-02 | money or balance | `LeaveBalanceService::accrueMonthly`, two runs | Balances credited twice (above) |
| IDEM-03 | client | payroll approve, mark paid, void | A repeat is answered 422, not the first answer |
| IDEM-04 | client | leave request | The identical request is answered 422 overlap |
| IDEM-05 | client | leave approve, reject, cancel, recall | A repeat is answered 422; balances move once |
| IDEM-06 | client | leave approve and reject racing | Balance moves once; the loser is not always refused |
| IDEM-07 | noise | the two scan jobs | Two in-app notifications for the same day |
| IDEM-08 | noise | pull after push of one terminal | A voided twin stays; the punch log grows on each pull |
| IDEM-09 | noise | attendance import committed twice | 201 with created 0, and a second audit row |

Not tested: whether approving a doubled payroll run draws loans and balances twice; the Hikvision and ADMS paths beyond the tests named in rows 2 and 7.

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
