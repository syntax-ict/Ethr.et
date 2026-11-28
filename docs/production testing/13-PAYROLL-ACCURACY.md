# 13. Payroll accuracy

**At a glance.** This file says how ETHR measures whether the payroll engine gives the answer the written rules give: what is measured, which layers exist today, how to run them, how to read a result, how a case is built and added, and what the work found. It states only what was built and run. Everything else is listed as not built yet. It gives no accuracy percentage and does not say payroll is signed off: the accountant has signed none of the statutory readings.

Companion documents: [12-ATTENDANCE-ACCURACY.md](12-ATTENDANCE-ACCURACY.md) (the same method for attendance, which this file does not repeat), the rule register [PAYROLL-RULES.md](../payroll/PAYROLL-RULES.md) (every rule, with an id, a source and a state), [ACCOUNTANT-SIGN-OFF.md](../payroll/ACCOUNTANT-SIGN-OFF.md) (the readings the accountant has yet to sign), [07-E2E-PAYROLL.md](07-E2E-PAYROLL.md) (the QA cases, with the eight golden payslips) and [15-ACCURACY-SESSION.md](15-ACCURACY-SESSION.md) (the session that runs all of this).

## 1. What is measured

The three measures are the ones in [12, section 1](12-ATTENDANCE-ACCURACY.md#1-what-is-measured): conformance, robustness and real-world agreement. Payroll has one of them today.

| Measure | Question | Payroll today |
|---|---|---|
| Conformance | Does the engine pay what ETHR's written rules say? | Built: sections 2 to 6 |
| Robustness | Does the answer stay the same whichever order, retry or channel the inputs arrive by? | Not built yet |
| Real-world agreement | Does the engine's payslip agree with one the accountant signed for the same person and month? | Not built yet. No signed payslip has been compared |

A conformance result says the engine follows the register. It does not say the register is what the law or a customer's accountant requires. Section 6 says what that leaves open.

## 2. The layers and their status

| Layer | What it does | Status |
|---|---|---|
| Rule register | Every payroll rule with an id, a source and a state: Decided, Implemented or Undecided | Built: [PAYROLL-RULES.md](../payroll/PAYROLL-RULES.md) |
| Hand-valued cases | Payslips and run figures worked by hand from the register, run through the engine | Built: 89 case entries, section 8 |
| Independent oracle | A second implementation of the register in whole cents, run on the same cases | Built: section 5 |
| Three-way vote | When the engine and a hand value disagree, what the oracle says | Built: section 3 |
| Integer-only scan | A token scan that holds the oracle to whole cents and keeps it from reading the answers | Built: section 5 |
| Statutory fixture check | The values the hand work assumed against what the application resolves | Built: section 5 |
| Generated run | Seeded payslips compared with the oracle | Not built yet |
| Properties | Order, retry and channel do not change a payslip; the parts add up to the whole | Not built yet |
| Mutation testing | Whether the tests notice a deliberately wrong engine | Not built yet |
| Restated figures | The bank file, the payslip PDF and the exports against the engine's figures | Not built yet |
| Displayed figures | The numbers on each screen compared with the expected ones | Not built yet |
| Backtest | A real month replayed and compared with the accountant's signed payslips | Not built yet |

The cases are a fixed list of designed cases, not a sample, so a pass count over them does not estimate how often the engine is right on real payslips.

## 3. Run it

Payroll alone, from `api/`:

```bash
php -d memory_limit=-1 vendor/bin/pest -c phpunit.accuracy.xml tests/Accuracy/Payroll
PAYROLL_CASE=GP-TAX-001,GP-NET-001 php -d memory_limit=-1 vendor/bin/pest -c phpunit.accuracy.xml tests/Accuracy/Payroll
```

The second form runs the chosen cases only, through both the engine and the oracle (PHPUnit's `--filter` cannot see a dataset key). The scan that keeps the oracle to whole cents is in `tests/Accuracy/OracleIndependenceTest.php`, one level up, so it is not in the folder run above.

The whole gate, which is attendance, payroll, leave, the calendar and the register consistency test:

```bash
./scripts/gates.sh accuracy
```

It needs no database server: the suite uses SQLite in memory and the array cache. It is opt-in and is not part of the full sweep. The suite sits in `api/tests/Accuracy`, outside the Unit and Feature testsuites on purpose, so the collection count in the root [CLAUDE.md](../../CLAUDE.md) is unchanged.

### What a result means

| Result | Meaning |
|---|---|
| Passed | The engine's figure equals every hand value in the case. |
| Skipped, "rule undecided" | The case depends on a rule the register marks Undecided. It is typed and kept so it is not forgotten, and it is never asserted. |
| Skipped, "NOT COVERABLE" | The fixture cannot build the input through public code. The case stays as typed, with its reason in `api/tests/Accuracy/Payroll/not-coverable.php`. A case that cannot be built and is not listed there fails. |
| Incomplete, "RECORDED FINDING" | The engine disagrees with the hand value and the case is listed in `api/tests/Accuracy/Payroll/findings.php` with what the engine answered. It needs triage (section 9). |
| Failed | The engine disagrees and the case is not listed. Either the engine changed or the case is new. A failure also names a fixture error, which is the engine not taking an input as stated, and a consistency failure, which is the preview and the stored entry of an approved run differing. |

Every disagreement line names the employee, the field, both figures and the rule ids that govern the field, for example `G7 net expected 749000, engine said 750000 (PYR-NET-01, PYR-NET-02)` after the case id and its rules. When the engine disagrees, the message ends with a `THREE-WAY` line saying what the oracle answers for the same case:

- "the oracle agrees with every hand value": the engine is outvoted two to one. Suspect the engine.
- "the oracle also disagrees": suspect the hand value or the register first.
- "the oracle has no decided answer": a field depends on an Undecided rule, and the register must be ruled on first.

Two against one names the suspect. It does not prove it.

### The last run

From the session sheet of 2026-11-28 18:21 UTC, commit `1c833bba61`, `./scripts/gates.sh accuracy` run by `./scripts/accuracy-session.sh`. The worktree was not clean: it held uncommitted edits to the session script and to documents. The conformance charter took 3 minutes 5 seconds.

| Part of the suite | Passed | Failed | Incomplete | Skipped, rule undecided |
|---|---|---|---|---|
| Payroll (`tests/Accuracy/Payroll`) | 190 | 0 | 0 | 20 |
| Shared guards (`OracleIndependenceTest`, `RegisterConsistencyTest`) | 21 | 1 | 0 | 0 |

The 20 skips are the 10 undecided cases of section 8, skipped once against the engine and once against the oracle. No payroll case failed and none is recorded as a finding. The one failure is in the shared guards, and it is not a payroll figure: **at this commit the register consistency check does not pass for payroll.** `RegisterConsistencyTest` ("has a case, or a reasoned exemption, for every Decided rule") names five Decided rules that no case cites and no exemption explains: PYR-SICK-03, PYR-PEN-12, PYR-CS-04, PYR-RUN-01 and PYR-RST-04. `./scripts/gates.sh accuracy` therefore exits 1 until each gets a case, or an entry with a reason in `api/tests/Accuracy/register-exemptions.php`. The count of passes above is a count of designed cases and tests, not a rate.


## 4. What the engine is asked

`PayrollGoldenCasesTest.php` is the only test that holds a case against the engine, and it implements no payroll rule. `PayrollFixture.php` builds, from the case, the organisation, the people, their salary history, contracts, allowances, loans, cost sharing, unpaid and sick leave, overtime and lateness. It states inputs and writes no figure of its own. `EngineReader.php` then asks the engine in one of two ways:

- `run` is `preview` (the default): `PayrollEngine::preview()`, and the calculation-log steps of each entry are read by their `step` key.
- `run` is `approve`: the same preview, then `process()` and `approve()`, and the stored entries are read back too. The run figures (register total, journal lines, bank file total) are read from the services that write them. The preview and the stored entry of each person must be equal, or the case fails as a consistency failure naming PYR-RUN-01.

After the run, `EngineReader` checks that the engine took each input as stated: the number of Pagume days, the Pagume strategy, the unpaid leave days, the half-pay and unpaid sick days and the overtime minutes of each kind. A case whose input the engine did not take fails as a fixture error, not as a payroll disagreement.

`PayrollComparer.php` is the only code that reads `expect`. Each field is compared with `===`.

## 5. The oracle and the checks around it

`api/tests/Accuracy/Oracle/Payroll/` is a second implementation of the register, in four files: `PayslipOracle.php` (the answer for a case), `PayslipCalculator.php` (the arithmetic of each register row), `PayrollReadings.php` (the alternative readings of the Undecided rules) and `Exact.php` (the one place where division and rounding happen, in integers). It is given a case without its `expect` and `working` keys (`PayrollCases::question()`), so it never reads a hand value.

**It does not guess an Undecided rule.** It computes every field under the register's plain reading and again under each alternative reading alone. A field that comes out the same under all of them is answered. A field that differs is returned as undecided, naming the rule that moved it. `PayrollReadings::alternatives()` holds ten alternative readings of nine Undecided rules (PYR-LATE-06 has two). PYR-NET-02 and PYR-BANK-04 to PYR-BANK-06 have no second figure to compute, so the oracle blanks the fields they touch (the figures built on a net pay below zero, and the bank file when a person is deleted after approval) and answers them as undecided with the rule.

| File in `api/tests/Accuracy` | What it checks |
|---|---|
| `Payroll/PayrollOracleCasesTest.php` | The oracle against every case's hand values. A disagreement is listed in `Payroll/oracle-findings.php` or fails. |
| `Payroll/PayrollOracleReadingsTest.php` | Each alternative reading blanks the field it should on an input at its boundary (11 inputs), and does not blank it one step away (11 controls). |
| `Payroll/ExactArithmeticTest.php` | The division helper rounds half away from zero within half a unit, divides toward zero, throws before a product leaves the integer range, and refuses a denominator that is not positive. |
| `OracleIndependenceTest.php` and `IntegerOnlyScan.php` | The oracle uses nothing from the application, no float literal, no `/`, no `%` outside `Exact.php`, no rounding function, and neither of the strings `expect` and `working`. A self-test proves the scan finds each kind of bad snippet. |
| `Payroll/PayrollStatutoryFixtureTest.php` | After the migrations and the tax bracket seeder, the application resolves exactly the statutory values the hand work assumed (`Payroll/fixtures/statutory.php`): working days, hours per day, overtime minimum rates, the one-third ceiling, the Art. 85 tiers, the pension scheme of every organisation kind, the pension rates and the two income tax ladders. A wrong fixture fails here, not as a puzzling mismatch in forty cases. |
| `Payroll/PayrollCaseFormatTest.php` | Every case file is as the format says, every id is unique, and a malformed case is refused. |

`ORACLE_REPORT` is an attendance switch and does nothing for payroll. The oracle's answer for a case also carries a trace of the Decided, Implemented and Undecided rules it relied on, for a generated run that does not exist yet.

## 6. How blindness was kept, and what it proves

The hand values and the oracle were written from the rule register alone, outside the repository, by builders who could not read the engine or each other. Their files were committed unchanged, as written, in `24f608821f`, before the harness had ever run them. The harness (`054d63c1ce`) was built by someone who did read the engine. Every later edit to a case or to the oracle is therefore visible in the history, and the history shows which edits there were (section 7). The commit messages do not record how many people took part, so this file does not state it.

What this proves: the hand values, the oracle and the engine were reached by separate readings of the register, and where all three agree on a field, that field's reading is the register's plain one.

What it does not prove:

- **A shared misreading of the register passes.** The three were given the same text. If the text is wrong, or says what nobody meant, all three can be wrong together.
- **The register was written by someone who read the code.** Its Implemented rows copy what the code does and no document states. Agreement on them shows the behaviour is stable, not that it was chosen. The register's own table says so, and the traceability table in section 10 shows each rule's state so that nobody reads an Implemented row as a decision.
- **No payslip signed by the accountant has been compared with the engine.** Every SIGN row in [ACCOUNTANT-SIGN-OFF.md](../payroll/ACCOUNTANT-SIGN-OFF.md) is unsigned. That layer is the backtest in section 2, and it is not built.
- **The oracle models the register, not the engine's stored results.** It cannot catch a defect in how a figure is written to the payslip PDF or the bank file, because restated figures are not built.

## 7. What the two runs found

Told in the order it happened. The commit ids are from `git log` on branch `test/accuracy-conformance`.

1. **The cases and the oracle were committed blind** (`24f608821f`): 88 hand-valued cases and the oracle, "as written blind (before any run)". The harness had been committed first (`054d63c1ce`) together with a register completed for blind work: it now states the arithmetic of every payslip and run figure, each reading of every Undecided rule and the Pagume dates.
2. **First three-way run** (`be8a5f1fea`). The commit message records 79 asserted cases, 78 of them agreeing on every field across hand value, engine and oracle. One case, GP-PRO-009, disagreed. A person in a payable status whose termination date is before the period had 0 days employed by hand and by the oracle, and the full period in the engine. A second case, GP-SICK-003, stated a sick-leave shape the Art. 85 tiers cannot produce in one period, and was listed in `not-coverable.php`. No blind file was changed.
3. **The register read two ways** (`2926c27c6e`). The disagreement was not an engine defect. PYR-PRO-01 contained a sentence that gave nothing for a termination date before the period, while the code pays the whole period, and no document decides it. The rule was rewritten to say one thing, and the open question became **PYR-PRO-05, Undecided**. No product path was found that leaves a person in that state, so the question is for the owner, not urgent. The same commit added PYR-SAL-08, PYR-LOAN-06, the contract types pension reads, the scope of PYR-PEN-11 and PYR-PEN-14, and **PYR-SICK-05**, which states that within one twelve-month window the tier of a sick day only rises with its date, so a period cannot hold 2 full, 1 half and 1 unpaid day.
4. **The blind work was redone** (`3006672e0f`). GP-PRO-009 became a case on the Undecided rule with both readings written out, and GP-SICK-003 was replaced by a shape the tiers can produce. The typist and the oracle author re-derived from the corrected register, and the files were committed unchanged before the second run.
5. **Second three-way run** (`3dbe312114`). The commit message records that hand value, engine and oracle agree on every field of all 79 asserted cases, that 10 cases wait on Undecided rules, and that `findings.php`, `oracle-findings.php` and `not-coverable.php` are empty. GP-SICK-003 now builds: 63 earlier sick days put the period's first sick day at place 64. Four blind case files were then reformatted by Pint, with proof that no value changed: the sha256 of each file's returned array is the same before and after.

The one disagreement the two runs found was in the register's wording, and no engine change followed. That is a finding about the register and the readings of it, not evidence that the engine is right on real payslips.

## 8. How a case is built

A case is a PHP array in `api/tests/Accuracy/Payroll/cases/*.php`. The files hold data only. `PayrollCases.php` loads them and refuses a case that is not as the format says, so a mistyped field fails instead of passing unseen. Every figure is an integer: money in cents, rates as an integer with the scale in the key (`percent_x100`, `rate_pct_x1000`, `overtime_rates_x100`), unpaid leave in thousandths of a day. A float anywhere fails the format test.

| Key | Meaning |
|---|---|
| `id` | `GP-`, a theme, a dash and three digits, for example `GP-TAX-001`. Unique |
| `rule` | One or more ids from the register |
| `source` | Where the case came from: a register example, a QA pack case in [07](07-E2E-PAYROLL.md), or `register` |
| `undecided` | `true` when the case depends on an Undecided rule. It is skipped, never asserted |
| `run` | `preview` (default) or `approve`. Run-level fields (`expect.run`) and `after_approval` need `approve` |
| `period` | `start`, `end`, `pagume_days` (required, 0 when no Pagume day), `label`. The start is not before 2022-03-18, the first pension rule |
| `org` | The organisation: `organization_type` (default `private`), `pension_scheme`, `working_days_per_month` (default 22), `pagume` (`full_month` or `daily_rate`), `lateness_deductions`, `overtime_rates_x100`, `allowances` (each with `type` fixed or percentage, `amount_cents` or `percent_x100`, and `taxable`) |
| `employees` | People by a key of capitals. Each has `status`, `hire_date`, `termination_date`, `salary_cents`, and optionally `deleted`, `nationality`, `domestic_worker`, `salary_history`, `contracts`, `loans`, `cost_sharing` and `inputs` (`unpaid_leave_thousandths`, `sick_days` by tier, `overtime_minutes` by kind, `lateness_minutes`) |
| `after_approval` | Only `delete`: people deleted after the run is approved, for the bank-file rules |
| `expect` | The hand values: per person a map of field name to integer, and for an approved run a `run` map. `in_run` 0 means no other field may be stated |
| `working` | The working lines, per person (and `run`). Every expected field needs a line that starts `<field> = `, with the arithmetic and the rule ids in brackets |

The field names are the contract of `PayrollFields.php`: `monthly_basic`, `days_in_period`, `days_employed`, `period_basic`, `prorated_basic`, `daily_rate`, the unpaid-leave, sick-leave and lateness steps, `basic`, the five overtime kinds, `allowance.<name>`, `allowances_total`, `gross`, `taxable`, `income_tax`, `pensionable`, the two pension shares, loans, cost sharing, `total_deductions` and `net`, and the run figures. The register's table "Figures on a payslip, in order" gives each one's rule ids.

The worked example is `GP-NET-001` in `cases/format.php`. It is the format's own proof and is counted with the cases.

On an Undecided case, `expect` holds the register's plain reading (the code's), and the other reading is written in `working` as lines that start `undecided: <rule>:`. Nothing asserts the other reading. The oracle answers such a field as undecided, with the rule.

### The cases, counted

Counted in `api/tests/Accuracy/Payroll/cases/` on 2026-11-28.

| Count | Number |
|---|---|
| Case entries, including the worked example `GP-NET-001` | 89 |
| Asserted | 79 |
| Typed and skipped as undecided | 10: GP-LATE-007, GP-LATE-008, GP-LEAVE-005, GP-LOAN-005, GP-PAG-003, GP-PAG-007, GP-PAG-008, GP-PEN-007, GP-PRO-009, GP-RUN-003 |
| Run level (`run` is `approve`) | 7 |
| Stated expected values, over 175 employee entries | 2,923 |
| Register rules, and rules cited by at least one case | 117 and 86 |

By file: `alw` 5, `cs` 5, `format` 1, `late` 8, `leave` 5, `loan` 5, `ot` 6, `pag` 8, `pen` 7, `pro` 10, `run` 3, `sal` 8, `sick` 4, `tax` 14. The file names are the themes of the rule ids: allowances, cost sharing, lateness, unpaid leave, loans, overtime, Pagume, pension, proration, run and bank file, salary, sick leave, income tax.

## 9. Add a case

1. Find the rule in the register. If there is none, the register gets the rule first, then the case. When a rule changes, change the register first, then the code, then the case.
2. **Type the expected values by hand, from the register alone, before any run.** Write the working line for each field with its arithmetic and its rule ids. A person typing a case should not read the engine.
3. Put the case in the file for its theme. Use the next free number.
4. Run it with `PAYROLL_CASE=<its id>` (section 3).
5. If the engine disagrees, **never change the expected value to make it pass.** Check the fixture first: a fixture error says so. If the fixture is right, leave the case as typed and add one line to `api/tests/Accuracy/Payroll/findings.php`: the case id and what the engine answered. The case then shows as incomplete, with that text. A listed case that the engine later agrees with fails until the entry is removed. A case the oracle disagrees with goes to `oracle-findings.php` in the same way.
6. If the case cannot be built through public code, leave it as typed and add its id and the reason to `not-coverable.php`.
7. If the rule is Undecided, add `'undecided' => true` and write the other reading in `working`.
8. Triage a finding as in [12, section 6](12-ATTENDANCE-ACCURACY.md#6-classify-a-finding): an engine defect (a failing-test-first fix in its own pull request), a wrong hand value or a wrong register (the register first, then the case, in a pull request that says why), a fixture error, or a rule undecided.
9. A new global helper in a test file is global in Pest. Prefix it, or make it a static method, and run `vendor/bin/pest --list-tests` from `api/`.

## 10. Traceability

Built from the case files and the register on 2026-11-28, in the register's order (117 rules, which is under the size where grouping would help). The State column is the rule's state in the register: a case on an Implemented rule shows the behaviour is stable, not that it was chosen. "Not asserted (undecided)" cases are typed and skipped. The last column is the file of the cases, or why a rule has none. "Exempt" means `api/tests/Accuracy/register-exemptions.php` holds a reason, as [14, section 4](14-LEAVE-CALENDAR-AND-RESTATED-FIGURES.md#4-register-consistency) describes. `GP-NET-001` is the worked example.

| Rule | State | Cases | File, or why none |
|---|---|---|---|
| PYR-PER-01 | Implemented | GP-PAG-001, GP-PAG-002, GP-PAG-006, GP-PRO-001, GP-PRO-002, GP-PRO-003, GP-PRO-004, GP-PRO-005, GP-PRO-010 | `cases/pag.php`, `cases/pro.php` |
| PYR-PER-02 | Decided | none | exempt: a refusal, not a figure |
| PYR-PER-03 | Decided | GP-TAX-007, GP-TAX-008, GP-TAX-009, GP-TAX-010, GP-TAX-011, GP-TAX-012, GP-TAX-014 | `cases/tax.php` |
| PYR-PER-06 | Implemented | none | no case yet |
| PYR-PER-04 | Implemented | none | no case yet |
| PYR-PER-05 | Decided | none | exempt: a stored label, not a figure |
| PYR-SAL-01 | Implemented | GP-NET-001, GP-SAL-001, GP-SAL-002, GP-SAL-003, GP-SAL-004, GP-SAL-005, GP-SAL-006 | `cases/format.php`, `cases/sal.php` |
| PYR-SAL-02 | Decided | GP-SAL-004, GP-SAL-005 | `cases/sal.php` |
| PYR-SAL-03 | Implemented | GP-SAL-001, GP-SAL-002, GP-SAL-003, GP-SAL-006 | `cases/sal.php` |
| PYR-SAL-04 | Decided | GP-RUN-001, GP-SAL-007 | `cases/run.php`, `cases/sal.php` |
| PYR-SAL-05 | Decided | GP-PRO-003, GP-PRO-006, GP-RUN-001, GP-SAL-007, GP-SAL-008; not asserted (undecided): GP-PRO-009 | `cases/pro.php`, `cases/run.php`, `cases/sal.php` |
| PYR-SAL-06 | Implemented | GP-RUN-001, GP-SAL-008 | `cases/run.php`, `cases/sal.php` |
| PYR-SAL-07 | Implemented | GP-LOAN-004, GP-PRO-007, GP-RUN-001, GP-SAL-007, GP-SAL-008 | `cases/loan.php`, `cases/pro.php`, `cases/run.php`, `cases/sal.php` |
| PYR-SAL-08 | Implemented | none | no case yet |
| PYR-PRO-01 | Decided | GP-ALW-003, GP-NET-001, GP-LEAVE-004, GP-OT-004, GP-PEN-006, GP-PRO-001, GP-PRO-002, GP-PRO-003, GP-PRO-004, GP-PRO-005, GP-PRO-006, GP-PRO-007, GP-PRO-008, GP-PRO-010, GP-SAL-001, GP-SAL-008; not asserted (undecided): GP-PAG-007, GP-PRO-009 | `cases/alw.php`, `cases/format.php`, `cases/leave.php`, `cases/ot.php`, `cases/pag.php`, `cases/pen.php`, `cases/pro.php`, `cases/sal.php` |
| PYR-PRO-02 | Decided | GP-ALW-003, GP-LEAVE-004, GP-OT-004, GP-PEN-006, GP-PRO-001, GP-PRO-002, GP-PRO-003, GP-PRO-004, GP-PRO-005, GP-PRO-007, GP-PRO-008, GP-PRO-010; not asserted (undecided): GP-LATE-007, GP-PAG-007, GP-PRO-009 | `cases/alw.php`, `cases/late.php`, `cases/leave.php`, `cases/ot.php`, `cases/pag.php`, `cases/pen.php`, `cases/pro.php` |
| PYR-PRO-03 | Implemented | GP-PRO-003, GP-PRO-005, GP-PRO-010 | `cases/pro.php` |
| PYR-PRO-04 | Implemented | GP-PRO-007; not asserted (undecided): GP-LOAN-005 | `cases/loan.php`, `cases/pro.php` |
| PYR-PRO-05 | Undecided | not asserted (undecided): GP-PRO-009 | `cases/pro.php` |
| PYR-PAG-01 | Implemented | GP-PAG-001, GP-PAG-002, GP-PAG-004, GP-PAG-005, GP-PAG-006 | `cases/pag.php` |
| PYR-PAG-02 | Implemented | GP-NET-001, GP-PAG-001, GP-PAG-002, GP-PRO-003, GP-PRO-004, GP-SAL-001; not asserted (undecided): GP-PAG-003 | `cases/format.php`, `cases/pag.php`, `cases/pro.php`, `cases/sal.php` |
| PYR-PAG-06 | Undecided | not asserted (undecided): GP-PAG-003 | `cases/pag.php` |
| PYR-PAG-03 | Implemented | GP-PAG-004, GP-PAG-005, GP-PAG-006; not asserted (undecided): GP-PAG-007, GP-PAG-008 | `cases/pag.php` |
| PYR-PAG-04 | Implemented | GP-PAG-005 | `cases/pag.php` |
| PYR-PAG-05 | Undecided | not asserted (undecided): GP-PAG-007 | `cases/pag.php` |
| PYR-RATE-01 | Implemented | GP-ALW-004, GP-NET-001, GP-LEAVE-001, GP-LEAVE-002, GP-LEAVE-003, GP-LEAVE-004, GP-PAG-001, GP-PAG-002, GP-PAG-006, GP-PRO-008, GP-SICK-001, GP-SICK-003, GP-SICK-004; not asserted (undecided): GP-LEAVE-005 | `cases/alw.php`, `cases/format.php`, `cases/leave.php`, `cases/pag.php`, `cases/pro.php`, `cases/sick.php` |
| PYR-RATE-03 | Implemented | none | no case yet |
| PYR-LEAVE-01 | Decided | GP-ALW-004, GP-NET-001, GP-LEAVE-001 | `cases/alw.php`, `cases/format.php`, `cases/leave.php` |
| PYR-LEAVE-06 | Implemented | GP-LEAVE-002 | `cases/leave.php` |
| PYR-LEAVE-02 | Implemented | GP-ALW-004, GP-NET-001, GP-LEAVE-001, GP-LEAVE-002, GP-LEAVE-003, GP-LEAVE-004, GP-SICK-003, GP-SICK-004; not asserted (undecided): GP-LATE-007, GP-LEAVE-005 | `cases/alw.php`, `cases/format.php`, `cases/late.php`, `cases/leave.php`, `cases/sick.php` |
| PYR-LEAVE-03 | Implemented | GP-LEAVE-001, GP-LEAVE-003 | `cases/leave.php` |
| PYR-LEAVE-05 | Implemented | GP-LEAVE-004 | `cases/leave.php` |
| PYR-LEAVE-04 | Decided | none | exempt: a refusal, not a figure |
| PYR-SICK-01 | Implemented | GP-SICK-001, GP-SICK-002, GP-SICK-003 | `cases/sick.php` |
| PYR-SICK-02 | Implemented | GP-SICK-001, GP-SICK-002, GP-SICK-003, GP-SICK-004 | `cases/sick.php` |
| PYR-SICK-03 | Decided | none | no case yet |
| PYR-SICK-04 | Decided | none | exempt: a refusal, not a figure |
| PYR-SICK-05 | Implemented | GP-SICK-003 | `cases/sick.php` |
| PYR-LATE-01 | Decided | GP-ALW-005, GP-LATE-001, GP-LATE-002, GP-LATE-006 | `cases/alw.php`, `cases/late.php` |
| PYR-LATE-02 | Implemented | GP-ALW-005, GP-LATE-002, GP-LATE-003, GP-LATE-006; not asserted (undecided): GP-LATE-008 | `cases/alw.php`, `cases/late.php` |
| PYR-LATE-03 | Implemented | GP-ALW-005, GP-LATE-002, GP-LATE-003, GP-LATE-004, GP-LATE-005, GP-LATE-006; not asserted (undecided): GP-LATE-007 | `cases/alw.php`, `cases/late.php` |
| PYR-LATE-06 | Undecided | not asserted (undecided): GP-LATE-007 | `cases/late.php` |
| PYR-LATE-04 | Decided | GP-ALW-005, GP-LATE-002, GP-LATE-003, GP-LATE-004, GP-LATE-005, GP-LATE-006; not asserted (undecided): GP-LATE-007 | `cases/alw.php`, `cases/late.php` |
| PYR-LATE-05 | Decided | GP-ALW-005, GP-LATE-001, GP-LATE-002, GP-LATE-003, GP-LATE-005, GP-LATE-006; not asserted (undecided): GP-LATE-008 | `cases/alw.php`, `cases/late.php` |
| PYR-ABS-01 | Decided | none | exempt: absences are listed only and no money moves |
| PYR-OT-01 | Implemented | GP-OT-001, GP-OT-002, GP-OT-003, GP-OT-004, GP-OT-005, GP-OT-006 | `cases/ot.php` |
| PYR-OT-02 | Decided | GP-OT-001, GP-OT-002, GP-OT-003, GP-OT-005, GP-OT-006 | `cases/ot.php` |
| PYR-OT-03 | Implemented | GP-OT-002 | `cases/ot.php` |
| PYR-OT-04 | Decided | GP-OT-004 | `cases/ot.php` |
| PYR-OT-05 | Decided | none | exempt: flags only, no figure |
| PYR-ALW-01 | Decided | GP-ALW-001, GP-ALW-002 | `cases/alw.php` |
| PYR-ALW-02 | Decided | GP-ALW-002, GP-ALW-003, GP-ALW-004, GP-ALW-005, GP-NET-001, GP-PAG-004 | `cases/alw.php`, `cases/format.php`, `cases/pag.php` |
| PYR-ALW-03 | Decided | GP-ALW-001, GP-ALW-003, GP-ALW-004, GP-ALW-005, GP-NET-001, GP-LATE-005; not asserted (undecided): GP-PAG-008 | `cases/alw.php`, `cases/format.php`, `cases/late.php`, `cases/pag.php` |
| PYR-ALW-04 | Decided | GP-ALW-001, GP-ALW-002, GP-ALW-003, GP-ALW-004, GP-ALW-005, GP-NET-001, GP-PAG-004; not asserted (undecided): GP-PAG-008 | `cases/alw.php`, `cases/format.php`, `cases/pag.php` |
| PYR-ALW-05 | Implemented | GP-ALW-001, GP-ALW-002 | `cases/alw.php` |
| PYR-ALW-06 | Implemented | none | no case yet |
| PYR-ALW-07 | Undecided | not asserted (undecided): GP-PAG-008 | `cases/pag.php` |
| PYR-GROSS-01 | Decided | GP-ALW-001, GP-ALW-002, GP-ALW-003, GP-ALW-004, GP-ALW-005, GP-NET-001, GP-LATE-001, GP-LATE-005, GP-OT-001, GP-OT-002, GP-OT-003, GP-OT-004, GP-OT-005, GP-OT-006, GP-PAG-004; not asserted (undecided): GP-PAG-008 | `cases/alw.php`, `cases/format.php`, `cases/late.php`, `cases/ot.php`, `cases/pag.php` |
| PYR-GROSS-02 | Decided | GP-ALW-001, GP-ALW-002, GP-ALW-003, GP-ALW-004, GP-ALW-005, GP-NET-001, GP-OT-001, GP-PAG-004; not asserted (undecided): GP-PAG-008 | `cases/alw.php`, `cases/format.php`, `cases/ot.php`, `cases/pag.php` |
| PYR-TAX-01 | Implemented | GP-ALW-001, GP-ALW-002, GP-ALW-003, GP-ALW-004, GP-ALW-005, GP-CS-001, GP-NET-001, GP-LATE-001, GP-LATE-006, GP-LEAVE-001, GP-LEAVE-002, GP-LEAVE-003, GP-LEAVE-004, GP-LOAN-001, GP-OT-001, GP-OT-002, GP-OT-003, GP-OT-004, GP-OT-005, GP-OT-006, GP-PAG-001, GP-PAG-002, GP-PAG-004, GP-PAG-005, GP-PAG-006, GP-PEN-001, GP-PEN-002, GP-PEN-003, GP-PEN-004, GP-PEN-005, GP-PEN-006, GP-PRO-001, GP-PRO-002, GP-PRO-003, GP-PRO-004, GP-PRO-005, GP-PRO-006, GP-PRO-007, GP-PRO-008, GP-PRO-010, GP-RUN-001, GP-SAL-001, GP-SAL-002, GP-SAL-003, GP-SAL-004, GP-SAL-005, GP-SAL-006, GP-SICK-001, GP-SICK-002, GP-SICK-003, GP-SICK-004, GP-TAX-001, GP-TAX-002, GP-TAX-003, GP-TAX-004, GP-TAX-005, GP-TAX-006, GP-TAX-011, GP-TAX-013; not asserted (undecided): GP-LATE-008, GP-LEAVE-005, GP-LOAN-005, GP-PAG-003, GP-PAG-007, GP-PAG-008, GP-PEN-007, GP-PRO-009 | `cases/alw.php`, `cases/cs.php`, `cases/format.php`, `cases/late.php`, `cases/leave.php`, `cases/loan.php`, `cases/ot.php`, `cases/pag.php`, `cases/pen.php`, `cases/pro.php`, `cases/run.php`, `cases/sal.php`, `cases/sick.php`, `cases/tax.php` |
| PYR-TAX-02 | Implemented | GP-TAX-007, GP-TAX-008, GP-TAX-009, GP-TAX-010, GP-TAX-012, GP-TAX-014 | `cases/tax.php` |
| PYR-TAX-03 | Decided | GP-ALW-001, GP-ALW-002, GP-ALW-003, GP-ALW-004, GP-ALW-005, GP-NET-001, GP-OT-001, GP-OT-002, GP-OT-003, GP-OT-004, GP-OT-005, GP-OT-006, GP-PAG-006, GP-PEN-001, GP-PEN-002, GP-PEN-003, GP-PEN-004, GP-PEN-005, GP-PEN-006, GP-PRO-008, GP-TAX-001, GP-TAX-002, GP-TAX-003, GP-TAX-004, GP-TAX-005, GP-TAX-006, GP-TAX-007, GP-TAX-008, GP-TAX-009, GP-TAX-010, GP-TAX-011, GP-TAX-012, GP-TAX-013, GP-TAX-014; not asserted (undecided): GP-PEN-007 | `cases/alw.php`, `cases/format.php`, `cases/ot.php`, `cases/pag.php`, `cases/pen.php`, `cases/pro.php`, `cases/tax.php` |
| PYR-TAX-04 | Implemented | GP-TAX-009, GP-TAX-010, GP-TAX-011 | `cases/tax.php` |
| PYR-TAX-05 | Decided | none | exempt: an organisation's own tax ladder, covered by TaxLadderSourcesTest |
| PYR-TAX-06 | Implemented | none | no case yet |
| PYR-PEN-01 | Decided | GP-PEN-001, GP-PEN-002, GP-PEN-003; not asserted (undecided): GP-PEN-007 | `cases/pen.php` |
| PYR-PEN-02 | Decided | GP-NET-001, GP-PEN-001, GP-PEN-002, GP-PEN-003, GP-PEN-004, GP-PEN-005, GP-TAX-001, GP-TAX-002, GP-TAX-003, GP-TAX-004, GP-TAX-005, GP-TAX-006, GP-TAX-007, GP-TAX-008, GP-TAX-009, GP-TAX-010, GP-TAX-011, GP-TAX-012, GP-TAX-013, GP-TAX-014; not asserted (undecided): GP-PEN-007 | `cases/format.php`, `cases/pen.php`, `cases/tax.php` |
| PYR-PEN-03 | Decided | GP-ALW-004, GP-ALW-005, GP-NET-001, GP-LATE-001, GP-LATE-006, GP-LEAVE-001, GP-LEAVE-002, GP-LEAVE-003, GP-LEAVE-004, GP-PEN-006, GP-SICK-001, GP-SICK-003, GP-SICK-004; not asserted (undecided): GP-LEAVE-005 | `cases/alw.php`, `cases/format.php`, `cases/late.php`, `cases/leave.php`, `cases/pen.php`, `cases/sick.php` |
| PYR-PEN-04 | Implemented | none | no case yet |
| PYR-PEN-05 | Decided | GP-ALW-001, GP-ALW-002, GP-ALW-003, GP-ALW-004, GP-ALW-005, GP-CS-001, GP-NET-001, GP-LATE-006, GP-LEAVE-001, GP-LEAVE-002, GP-LEAVE-003, GP-LEAVE-004, GP-LOAN-001, GP-OT-001, GP-OT-002, GP-OT-003, GP-OT-004, GP-OT-005, GP-OT-006, GP-PAG-001, GP-PAG-002, GP-PAG-004, GP-PAG-005, GP-PAG-006, GP-PEN-001, GP-PEN-002, GP-PEN-003, GP-PEN-004, GP-PEN-005, GP-PEN-006, GP-PRO-001, GP-PRO-002, GP-PRO-003, GP-PRO-004, GP-PRO-005, GP-PRO-006, GP-PRO-007, GP-PRO-008, GP-PRO-010, GP-SAL-001, GP-SAL-002, GP-SAL-003, GP-SAL-004, GP-SAL-005, GP-SAL-006, GP-SICK-001, GP-SICK-002, GP-SICK-003, GP-SICK-004, GP-TAX-001, GP-TAX-002, GP-TAX-003, GP-TAX-004, GP-TAX-005, GP-TAX-006, GP-TAX-007, GP-TAX-008, GP-TAX-009, GP-TAX-010, GP-TAX-011, GP-TAX-012, GP-TAX-013, GP-TAX-014; not asserted (undecided): GP-LATE-008, GP-LEAVE-005, GP-LOAN-005, GP-PAG-003, GP-PAG-007, GP-PAG-008, GP-PEN-007, GP-PRO-009 | `cases/alw.php`, `cases/cs.php`, `cases/format.php`, `cases/late.php`, `cases/leave.php`, `cases/loan.php`, `cases/ot.php`, `cases/pag.php`, `cases/pen.php`, `cases/pro.php`, `cases/sal.php`, `cases/sick.php`, `cases/tax.php` |
| PYR-PEN-06 | Implemented | none | no case yet |
| PYR-PEN-07 | Decided | GP-PEN-004, GP-RUN-001 | `cases/pen.php`, `cases/run.php` |
| PYR-PEN-08 | Implemented | GP-PEN-004 | `cases/pen.php` |
| PYR-PEN-09 | Implemented | GP-PEN-005 | `cases/pen.php` |
| PYR-PEN-10 | Implemented | GP-PEN-006 | `cases/pen.php` |
| PYR-PEN-11 | Undecided | not asserted (undecided): GP-PEN-007 | `cases/pen.php` |
| PYR-PEN-13 | Implemented | none | no case yet |
| PYR-PEN-14 | Undecided | none | no case yet |
| PYR-PEN-12 | Decided | none | no case yet |
| PYR-PEN-15 | Implemented | GP-PEN-004, GP-RUN-001 | `cases/pen.php`, `cases/run.php` |
| PYR-LOAN-01 | Decided | GP-LATE-002, GP-LATE-003, GP-LATE-004, GP-LATE-005, GP-LOAN-001, GP-LOAN-002, GP-LOAN-003, GP-LOAN-004, GP-RUN-002; not asserted (undecided): GP-LOAN-005 | `cases/late.php`, `cases/loan.php`, `cases/run.php` |
| PYR-LOAN-02 | Decided | GP-LOAN-001 | `cases/loan.php` |
| PYR-LOAN-03 | Decided | GP-LOAN-004, GP-RUN-002 | `cases/loan.php`, `cases/run.php` |
| PYR-LOAN-04 | Decided | GP-LATE-002, GP-LATE-003, GP-LATE-004 | `cases/late.php` |
| PYR-LOAN-05 | Implemented | GP-LOAN-001, GP-LOAN-003 | `cases/loan.php` |
| PYR-LOAN-06 | Implemented | none | no case yet |
| PYR-CS-01 | Decided | GP-CS-001, GP-CS-002, GP-CS-003, GP-CS-004, GP-CS-005, GP-LATE-005, GP-RUN-002 | `cases/cs.php`, `cases/late.php`, `cases/run.php` |
| PYR-CS-02 | Decided | none | exempt: a refusal, not a figure |
| PYR-CS-05 | Implemented | GP-CS-003, GP-CS-004 | `cases/cs.php` |
| PYR-CS-06 | Implemented | GP-CS-001, GP-CS-002, GP-LATE-005, GP-RUN-002 | `cases/cs.php`, `cases/late.php`, `cases/run.php` |
| PYR-CS-07 | Implemented | GP-CS-005, GP-RUN-002 | `cases/cs.php`, `cases/run.php` |
| PYR-CS-03 | Implemented | GP-CS-003 | `cases/cs.php` |
| PYR-CS-04 | Decided | none | no case yet |
| PYR-NET-01 | Decided | GP-ALW-001, GP-ALW-002, GP-ALW-003, GP-ALW-004, GP-ALW-005, GP-CS-001, GP-CS-002, GP-CS-003, GP-CS-004, GP-CS-005, GP-NET-001, GP-LATE-001, GP-LATE-003, GP-LATE-004, GP-LATE-005, GP-LATE-006, GP-LEAVE-001, GP-LEAVE-002, GP-LEAVE-003, GP-LEAVE-004, GP-LOAN-001, GP-LOAN-002, GP-LOAN-003, GP-OT-001, GP-OT-002, GP-OT-003, GP-OT-004, GP-OT-005, GP-OT-006, GP-PAG-001, GP-PAG-002, GP-PAG-004, GP-PAG-005, GP-PAG-006, GP-PRO-001, GP-PRO-002, GP-PRO-003, GP-PRO-004, GP-PRO-005, GP-PRO-006, GP-PRO-007, GP-PRO-008, GP-PRO-010, GP-RUN-002, GP-SAL-001, GP-SAL-002, GP-SAL-003, GP-SAL-004, GP-SAL-005, GP-SAL-006, GP-SICK-001, GP-SICK-002, GP-SICK-003, GP-SICK-004; not asserted (undecided): GP-LEAVE-005, GP-LOAN-005, GP-PAG-003, GP-PAG-007, GP-PAG-008, GP-PRO-009 | `cases/alw.php`, `cases/cs.php`, `cases/format.php`, `cases/late.php`, `cases/leave.php`, `cases/loan.php`, `cases/ot.php`, `cases/pag.php`, `cases/pro.php`, `cases/run.php`, `cases/sal.php`, `cases/sick.php` |
| PYR-NET-02 | Undecided | not asserted (undecided): GP-LOAN-005 | `cases/loan.php` |
| PYR-ROUND-01 | Decided | GP-TAX-006 | `cases/tax.php` |
| PYR-ROUND-02 | Decided | GP-CS-001 | `cases/cs.php` |
| PYR-ROUND-03 | Implemented | GP-OT-005 | `cases/ot.php` |
| PYR-ROUND-04 | Undecided | not asserted (undecided): GP-LATE-007, GP-LEAVE-005 | `cases/late.php`, `cases/leave.php` |
| PYR-ROUND-05 | Undecided | not asserted (undecided): GP-LATE-007, GP-LATE-008 | `cases/late.php` |
| PYR-RUN-01 | Decided | none | no case yet |
| PYR-RUN-02 | Decided | none | exempt: a refusal, not a figure |
| PYR-RUN-03 | Implemented | none | no case yet |
| PYR-RUN-04 | Decided | none | exempt: run lifecycle, not a figure |
| PYR-RUN-05 | Decided | none | exempt: run lifecycle, not a figure |
| PYR-RUN-06 | Decided | none | exempt: run lifecycle, not a figure |
| PYR-RUN-07 | Implemented | none | no case yet |
| PYR-RST-01 | Decided | GP-CS-005, GP-LOAN-004, GP-RUN-001, GP-RUN-002, GP-SAL-007, GP-SAL-008; not asserted (undecided): GP-RUN-003 | `cases/cs.php`, `cases/loan.php`, `cases/run.php`, `cases/sal.php` |
| PYR-RST-02 | Decided | GP-CS-005, GP-LOAN-004, GP-RUN-001, GP-RUN-002; not asserted (undecided): GP-RUN-003 | `cases/cs.php`, `cases/loan.php`, `cases/run.php` |
| PYR-RST-03 | Decided | GP-CS-005, GP-LOAN-004, GP-RUN-001, GP-RUN-002; not asserted (undecided): GP-RUN-003 | `cases/cs.php`, `cases/loan.php`, `cases/run.php` |
| PYR-RST-04 | Decided | none | no case yet |
| PYR-BANK-01 | Decided | none | exempt: a refusal, not a figure |
| PYR-BANK-02 | Decided | GP-CS-005, GP-LOAN-004, GP-RUN-001, GP-RUN-002 | `cases/cs.php`, `cases/loan.php`, `cases/run.php` |
| PYR-BANK-03 | Decided | GP-CS-005, GP-LOAN-004, GP-RUN-001, GP-RUN-002; not asserted (undecided): GP-RUN-003 | `cases/cs.php`, `cases/loan.php`, `cases/run.php` |
| PYR-BANK-04 | Undecided | not asserted (undecided): GP-RUN-003 | `cases/run.php` |
| PYR-BANK-05 | Undecided | not asserted (undecided): GP-RUN-003 | `cases/run.php` |
| PYR-BANK-06 | Undecided | not asserted (undecided): GP-RUN-003 | `cases/run.php` |

Rules with no case, from the table: 31 of 117. The register consistency check fails on five of them, as the last run in section 3 says. Thirteen are exempt as Decided rules that are not figures: refusals (PYR-PER-02, PYR-LEAVE-04, PYR-SICK-04, PYR-CS-02, PYR-RUN-02, PYR-BANK-01), run lifecycle (PYR-RUN-04 to PYR-RUN-06), a stored label (PYR-PER-05), an organisation's own tax ladder (PYR-TAX-05), absences that move no money (PYR-ABS-01) and flags (PYR-OT-05). Five Decided rules have neither a case nor an exemption at the time of writing: PYR-SICK-03, PYR-PEN-12, PYR-CS-04, PYR-RUN-01 and PYR-RST-04. None is in the `rule` list of a case. Thirteen are Implemented or Undecided with no case yet: PYR-PER-04, PYR-PER-06, PYR-SAL-08, PYR-RATE-03, PYR-ALW-06, PYR-TAX-06, PYR-PEN-04, PYR-PEN-06, PYR-PEN-13, PYR-PEN-14, PYR-LOAN-06, PYR-RUN-03 and PYR-RUN-07.

## 11. What the cases do not cover

- **Anything a Decided refusal or a run state decides.** Whether a run may be processed, approved, voided or paid, and the refusals that protect it, are not figures. Feature tests cover them (07 sections 4, 5 and 10); a golden case does not.
- **Rules no case cites** (the list under the table).
- **Ten cases are typed and not asserted.** They wait for the owner's ruling, and the accountant's where money moves, on the items in the register's "Waiting for a decision": a period that is all Pagume under `full_month` (PYR-PAG-06), a termination date before the period (PYR-PRO-05), staff outside the private pension scheme (PYR-PEN-11, PYR-PEN-14), a person deleted while a released run holds their entry (PYR-BANK-04 to PYR-BANK-06), negative net pay (PYR-NET-02), a fixed allowance in a Pagume period (PYR-ALW-07), the base of the one-third lateness ceiling (PYR-LATE-06), when the daily rate is rounded (PYR-ROUND-04), Pagume before or after proration (PYR-PAG-05) and how lateness is rounded (PYR-ROUND-05). Until then a field that depends on one is reported as undecided and never as a pass.
- **Implemented rows.** The code does it and no document states it, or it follows a statutory value or a reading the accountant has not signed. A case on one shows the behaviour is stable, not that it was chosen. The register lists the ones that move a figure under "Waiting for a decision".
- **What the register itself leaves out** (its "What this register does not cover"): attendance minutes (that is [ATTENDANCE-RULES.md](../ATTENDANCE-RULES.md)); civil servants under the Federal Civil Servants Proclamation 1353/2024 and government salary scales; legal limits on non-taxable allowances; arrears and recoveries for a salary change back-dated into a month already paid; severance and final pay, and any deduction other than loans, cost sharing and lateness; remittance schedule layouts and bank-specific transfer formats; and who may process, approve, void and pay.
- **The cases that could not be built.** `not-coverable.php` is empty today: after the second run every case builds. A new input the fixture cannot create through public code goes there with its reason.
- **Robustness and real-world agreement**, as in section 1. No generated run, property, mutation test, displayed-figure check or backtest exists.

## 12. Companion files

- [12-ATTENDANCE-ACCURACY.md](12-ATTENDANCE-ACCURACY.md) for the attendance method and its cases.
- [14-LEAVE-CALENDAR-AND-RESTATED-FIGURES.md](14-LEAVE-CALENDAR-AND-RESTATED-FIGURES.md) for the leave cases, the calendar cross-check and the register consistency test.
- [15-ACCURACY-SESSION.md](15-ACCURACY-SESSION.md) for the session that runs it all and the report it feeds.
