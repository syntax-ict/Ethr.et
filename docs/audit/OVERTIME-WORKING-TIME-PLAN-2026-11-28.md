# Overtime and working time: gaps and plan

Status: plan, 2026-11-28. **Not built.** Written on Fable 5.2 as the planning step of the owner's model rule ([`../MODEL-ROUTING.md`](../MODEL-ROUTING.md)). Code read at `origin/main` `2e001351bc`. No test was run.

The owner brought a list of gaps that reach payroll, the day the Attendance rules page was reviewed ([`ATTENDANCE-SETTINGS-REVIEW-2026-11-28.md`](ATTENDANCE-SETTINGS-REVIEW-2026-11-28.md)). Most of them are not about that page, so they have their own plan here.

**On the law.** The overtime limits, rates and night window below are the ones already stored as statutory rows in this repository, with the article each row cites. The rules for workers under 18 and the lawful grounds for overtime are **from memory of Proclamation 1156/2019 and were not read for this plan**. Nothing in this plan may be seeded as a rule until step 0 has read the text and entered it in [`../EMPLOYMENT-LAW-REGISTER.md`](../EMPLOYMENT-LAW-REGISTER.md) with a class. That register has no row on overtime or on young workers today.

## The list, checked against the code

| # | Claim | What the code says | Verdict |
|---|---|---|---|
| 1 | A badge number with "/" is refused on create, update and import | All three use letters, digits and hyphen only: `StoreEmployeeRequest.php:67`, `UpdateEmployeeRequest.php:73`, `EmployeeImportRow.php:728`. The page repeats it: `new-employee-validation.ts:94`, `unmatched-badges-card.tsx:45` | True. Not part of this plan: one small PR of its own |
| 2 | Passing 4 hours a day or 12 a week is only flagged when payroll runs | `PayrollEngine::overtimeLimits()` is the only reader of the two legal rows, and their own note says "Used only to flag a run". **One part is already stopped:** a request cannot ask for more than 240 minutes, and a person can hold one pending or approved request a day (`OvertimeRequest::MAX_PLANNED_MINUTES`, `OvertimeRequestController::store`). The week is not checked when a request is made | True, with that correction |
| 3 | The default daily cap is 2 hours and the law says 4 | `ot_daily_cap_minutes` (default 120) is the organisation's own limit. The legal 240 and 720 are separate rows (`overtime.legal_daily_minutes`, `overtime.legal_weekly_minutes`, both marked unconfirmed) and are checked whatever the organisation's limit is | Not a mismatch. The page's wording invites the reading, and that is fixed in the settings plan |
| 4 | Overtime approval is off for new organisations | `AttendanceSetting::defaults()`; neither the provisioner nor the attendance baseline sets it | True |
| 5 | The minimum age of 15 is enforced, and nothing limits a 15 to 17 year old | No rule anywhere for a worker under 18. **And the age of 15 is enforced on one path only:** creating an employee on the form (`StoreEmployeeRequest::oldEnoughToWork`). Updating a date of birth and importing a list do not check it (`UpdateEmployeeRequest.php:78`; `EmployeeImportRow` has no age rule). The 15 is a literal in code | True, and wider than claimed |
| 6 | The overtime reason is free text | `StoreOvertimeRequestRequest.php:33`: 3 to 1,000 characters | True |
| 7 | There is no minimum overtime block | A request must be at least 15 minutes. Worked overtime has no floor at all: every minute past the shift's end is overtime (`AttendanceRecord::overtimeWindow()`), so a check-out three minutes late is three paid minutes | True, and it costs money today |
| 8 | No warnings for repeated unexcused absence | Nothing on `main` detects it. `PreApprovalChecks` lists unexplained absences before a run (#404), which is a different thing | True. It belongs to the long absence follow-up plan of 2026-11-23, whose first step merged as #867. Not repeated here |

## Rules this plan keeps

These are the product's own, and each decision below is shaped by them.

- **A punch is never refused for a working-time rule.** Refusing it loses the record of what happened.
- **Nothing withholds pay by itself.** A person decides, and the run records who.
- **A statutory number is a dated row by organisation kind, never a literal.** A rule not yet confirmed is labelled so on screen.
- **What the product may refuse is a plan:** a request, an approval, an assignment. What it does with something already worked is flag it, where the person who can act will see it.

## Decisions

**W1. One rule for the limits, read by payroll and by the request.** The limit arithmetic in `PayrollEngine::overtimeLimits()` moves to a service that payroll, the overtime request and the overtime screen all call. `MAX_PLANNED_MINUTES` stops being a literal and reads `overtime.legal_daily_minutes`.

**W2. A request respects the week.** A request is refused when the person's approved and pending minutes in that week, plus this one, pass `overtime.legal_weekly_minutes`. The answer says how much is left. The approver sees the week's total beside the request. Overtime already worked in the week counts too.

**W3. The limit is seen when it is passed, not at payroll.** The Overtime page (Attendance, then Overtime) shows who is over a daily or weekly limit this week, computed when the page is read, with nothing stored. HR is told once per person per week. The payroll check stays as the last net.

**W4. Whether approval is on for a new organisation is the owner's decision.** The recommendation is on, for new organisations only, with the setup wizard saying so. Existing organisations are not touched. The switch only flags; it holds back no pay. The cost is flags for an organisation that never uses requests.

**W5. Workers under 18.** After step 0 confirms the articles, the rules become rows in the employment domain (`employment.young_worker.*`: the age, the daily hours, and what is not allowed: overtime, the night window, rest days, public holidays). What the product then does:

- an overtime request for someone under the age on the requested day is refused, with the reason;
- `PreApprovalChecks` lists every such person who has overtime, night, rest-day or holiday minutes in the period, before the run is approved;
- assigning one to a shift that reaches into the night window warns;
- the profile shows "Young worker until <date>";
- the minimum age is checked on update and on import as it is on create, and read from a row.

A date of birth is optional. Without one the product can assert nothing, so the payroll check also says how many people in the run have no date of birth, and silence is not read as a clean result. Whether a date of birth becomes required for new employees is the owner's decision.

**W6. A fixed list of overtime reasons.** An enum, validated with `Rule::enum()` and offered from one module under `src/src/lib/options/`, as every closed list is. The values follow the grounds the law allows (to be confirmed in step 0: accident, force majeure, urgent work, standing in for an absent worker on continuous work) plus "other", which requires a note. The free-text note stays for every reason. Existing requests keep their text and have no code. The payroll check counts overtime requested as "other".

**W7. A floor for worked overtime is the owner's and the accountant's decision.** It moves paid minutes. Three options:

| | Option | Effect |
|---|---|---|
| A | Leave as it is | Every minute past the shift's end is paid |
| B | An organisation setting: overtime shorter than N minutes on a day is not counted. Default 0, which is today | A late check-out of a few minutes stops being overtime. The calculation log records the rule and the minutes dropped |
| C | With approval on, overtime that was not approved is held for a decision at the run instead of being paid | The law treats overtime as work the employer ordered. This changes what a run pays and needs counsel as well |

The recommendation is B, after the accountant agrees, and C only with counsel. Nothing is built for W7 until then.

**W8. Repeated unexcused absence stays with its own plan.** The law there was read and corrected on 2026-11-23 (the register's section *Absence from work, and returning with a reason*), and its detection step was in review at last note. A second design here would disagree with it.

## The pull requests, in order

Sonnet implements. "Opus plan" and "Opus review" are steps 1 and 3 of the model rule.

| PR | What | Migration | Model rule |
|---|---|---|---|
| 0 | Read the overtime articles and the young-worker articles of 1156/2019, and the overtime rule for civil servants in 1353/2024. Enter each in the law register with a class. Documents only | No | A planning session reads; counsel for anything left at class 3 |
| 1 | W1 and W2: the shared limits service, the weekly check on a request, the literal replaced by the row | No | Opus review: it changes which requests are refused, and payroll reads the same code |
| 2 | W3: the Overtime page shows who is over a limit, and HR is told once a week per person | No | Sonnet, then a review of the diff |
| 3 | W5: the young-worker rows, the refusal, the payroll check, the profile line, the age check on update and import. Waits for PR 0 | **Yes** (rows are seeded by a migration) | **Opus plan first**, Opus review after |
| 4 | W6: the reason list. Waits for PR 0 | **Yes** (one column) | Sonnet, then a review of the diff |
| 5 | W7, only after the decision | Depends on the option | Opus plan first: it changes pay |

Each PR carries its tests, its cases in the production testing pack and its lines in the guides. A PR with a migration is run on a scratch MariaDB first, and its deploy is Deploy plus `migrate` as one step. The release that carries PR 1, 3 or 5 runs `./scripts/gates.sh e2e-attendance` first, because its payroll journey reads what these change.

## Decisions this plan waits on

1. **W4**: overtime approval on for new organisations?
2. **W5**: is a date of birth required for new employees?
3. **W7**: a floor for worked overtime (option A, B or C), with the accountant.
4. **Counsel**, for whatever step 0 leaves at class 3.

PR 0, PR 1 and PR 2 need none of these and can start.

## Not in this plan

- **The badge number rule** (row 1). One PR: allow "/", "." and "_" in the three server rules and the two page rules, keep the 32-character limit and the per-organisation uniqueness. Matching is already case-insensitive (`IdentityResolver`), and no address or file name is built from a badge number; it appears in exports as a cell.
- **The daily cap's wording** (row 3). In the settings plan, PR 5.
- **Absence warnings** (row 8). See W8.
