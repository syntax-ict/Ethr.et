# Production QA execution log, 2026-11-17: UAT continued in Chrome

Pack: [`docs/production testing/`](<../production testing/README.md>), sixth edition plus the phone punch cases of #774. Format: [10, section 2](<../production testing/10-TEMPLATES-AND-REFERENCE.md#2-execution-log>). The scenarios are those of the [UAT guide](<../production testing/02-UAT-GUIDE.md>); a row names the detailed case where one was run with it.

| | |
|---|---|
| Release under test | `v2026.11.16.22`, `release.json` commit `467a3cb606ba` (#779), built 2026-11-16 21:51:40Z. `main` is at `d993487e1f` (#781); #780 and #781 are merged and not deployed (`production` carries `c126f02ddf11`, #780) |
| Organisation | Public pages of the marketing site and QA Audit (`qa-audit`); then QA Audit signed in, writes agreed by the owner in chat for this session |
| Persona | TA in QA Audit, signed in by the owner at about 15:28. The account has no employee record, so no employee, supervisor, HR-only or finance-only persona was available. The platform console showed its sign-in form at 15:20 and was never opened signed in |
| Browser | Chrome, unguarded (the Claude in Chrome extension), the owner's connected Chrome, not the dedicated ETHR QA profile of 11, section 6.1. Other organisations' hosts were not checked for a session. No guarded Playwright run today |
| Tester | Claude Code; to be signed by the owner |

Times are approximate, within about five minutes. Nothing was typed into a password, code or token field. No file was downloaded, no email, SMS or invitation was sent, and nothing was deleted.

## Does the pack cover the whole product?

**The UAT guide covers the six personas** (Employee E1 to E15, Supervisor S1 to S4, HR H1 to H18, Finance F1 to F6, Organisation administrator A1 to A8, Platform operator P1) and names the detailed cases behind each scenario. Local `main` was ten commits behind the remote and was fast-forwarded; the remote copy adds #774 (the phone punch screen) and the Quiet state of #780 in UAT-H16.

**It does not cover everything that is live.** `scripts/qa-pack-coverage.sh` lists 32 user-visible pull requests since the baseline (`c8cc3a4b2d`) that the pack cites nowhere. Thirty of them are live in this release and are **Not covered**; #780 and #781 are merged and not deployed.

| PRs | Area | Result |
|---|---|---|
| #731, #745, #747, #750, #751, #752, #753, #759, #764, #778 | Employee import: slices, the workbook, completing people already here, problems by column, lists in progress, the Sign in column | Not covered. #764, #753 and #778 were seen working in passing (below) |
| #735, #742, #748, #754, #765, #769 | Devices: retry of a failed pull, the actions menu, held punches in slices, the health dashboard | Not covered |
| #738, #766 | Backups: OBS checksums, Google Drive fallback | Not covered (platform console) |
| #739, #746 | Console: failed job buttons, kind in law on the list | Not covered (platform console) |
| #736, #737, #741, #744 | Release without a manual optimize, receipts and PDF page numbers, report totals, impersonation limits | Not covered |
| #767, #768 | Brand tokens and Manrope, tall menus | Not covered. Manrope was seen on the `/en` heading |
| #772, #776, #777, #779 | First setup order, the daily invitation ceiling, the sign-in form before hydration, who cannot sign in yet | Not covered. #772 and #779 were seen working in passing; the guide mentions #772 only in UAT-H15 step 1 |
| #780, #781 | Quiet terminals, a tap on a button that cannot punch | Not live |

Of the guide's 52 scenarios, one had been run before today (UAT-H1, steps 1, 4 and 5, on 2026-11-14). Today 20 were run in part (16 in the first session, 4 more in the second); the table at the end says what is left and why.

## Release smoke (10, section 3)

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| S-01 | Pass | Claude Code | 15:20 | | | Chrome, unguarded. `release.json`: `467a3cb606ba468076b0e6a1f7ffa13a8774221b`, built 2026-11-16T21:51:40Z, the commit of tag `v2026.11.16.22`. `scripts/release-status.sh` was not run: it timed out from this machine |
| S-02 | Pass | Claude Code | 15:20 | | | Chrome, unguarded. `GET /api/v1/health`: healthy; scheduler 288 s old; four queues at depth 0; `failed_jobs` 0; `pending_migrations` 0. One reading, so growth not compared |
| S-03 | Not run | | | | | No route named for this release |
| S-04 | Pass (two legs) | Claude Code | 15:20 | | | Chrome, unguarded. `https://www.ethr.et/qa-audit` lands on `https://qa-audit.ethr.et/login`; `https://admin.ethr.et` signed out lands on its `/login` ("Sign in to ETHR Platform"). The `http://` and apex legs were not repeated |
| S-05 | Not run | | | | | Secret probes are a person's (01 rule 10) |
| S-06 | Pass | Claude Code | 15:21 | | en, am | Chrome, unguarded. `/en`, `/am`, `/en/pricing`: `<html lang>` matches, no raw key, console clean. Prices shown: Free, 999 and 8,999 ETB a month. `/am` has no English beyond organisation names |
| S-07 | Pass | Claude Code | 15:22 | | am, en | Chrome, unguarded. QA Audit `/login`: its name, logo (image named "QA Audit"), cover and "Powered by ETHR" with the mark `aria-hidden`; robots `noindex, nofollow` |
| S-08 | Pass (TA) | Claude Code | 15:28 | TA | am | Chrome, unguarded. `/dashboard` as Organisation admin after the owner signed in. HR, FIN, SUP, EMP: no accounts |
| S-10 | Pass (6 of 9) | Claude Code | 15:30 to 15:50 | TA | en | Chrome, unguarded. Employees, attendance, payroll runs, reports, settings and billing each loaded with data and a clean console. Leave, announcements and devices were not opened today |
| S-12 | Pass | Claude Code | 15:29 | TA | am, om, en | Chrome, unguarded. Dashboard in አማርኛ, Afaan Oromoo and English, no raw key. O-1 below |
| S-09, S-11, S-13, S-14, S-15 | Not run | | | | | No PA session in Chrome (never); no EMP account; a resized desktop window is not a phone, and the window would not narrow below 1280 px in this Chrome |

## Signed out: sign-up and sign-in pages

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| UAT-E2 | Pass (step 1) | Claude Code | 15:23 | | en | Chrome, unguarded. "Forgot password?" opens `/login/forgot`: "Forgot your password?", one Email field, "Send reset link". Not submitted: steps 2 to 4 send a real email |
| ORG-009 | Pass (English) | Claude Code | 15:24 | | en | Chrome, unguarded. `/register` step 1: "Kind of organisation in law", "Choose one", the help line, "Next" disabled with no kind. With "General Company": 16 kinds in the four groups, in the case's order for the two groups read name by name. አማርኛ not repeated |
| ORG-015 | Pass | Claude Code | 15:24 | | en | Chrome, unguarded. Industry starts on "Choose one". Government Office 4 kinds; NGO 5; Hospital and University 14; Bank, Manufacturing and Hotel 11; General Company 16 and no link. "Show every kind of organisation in law" shows 16 and becomes "Show only the kinds that match your type of organisation", and back. Nothing submitted |
| ORG-016 | Pass | Claude Code | 15:25 | | en | Chrome, unguarded. Charity or society stays from NGO to Hospital and is cleared on Government Office; PLC chosen through "Show every kind" stays on Bank and is cleared on NGO; each change of industry closes the full list |
| ORG-012 | Pass | Claude Code | 15:26 | | en | Chrome, unguarded. Admin step: "Next" disabled with Phone empty and with `12345`; after leaving the field, "Enter a valid Ethiopian phone number, e.g. 0911223344" as `role="alert"`, the field `aria-invalid` and described by hint and error; `0912 345 678` clears it and "Next" comes alive. No `/api/` call was made; the page was left on the admin step and nothing was created |
| UAT-A8 | Pass (steps 1, 2), partial (3 to 5) | Claude Code | 15:24 to 15:36 | TA | en | Chrome, unguarded. Steps 1 and 2 are the four rows above. Steps 3 to 5 were read on **QA Audit's** guided setup, not on a new `uat-` organisation: no organisation was registered. See the next section. Step 6 not run today (L10N-023 and L10N-024 passed on 2026-11-14 and 11-15) |

## QA Audit, signed in as TA, writes agreed (about 15:28 to 15:52)

The header read "QA Audit" and "Organisation admin" before each step.

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| UAT-E1 | Pass (steps 1, 3 to 7) | Claude Code | 15:28 | TA | am, om, en | Chrome, unguarded. One header menu holds language, calendar and time of day. አማርኛ: nothing left in English. Afaan Oromoo: no raw key (O-1). Ethiopian calendar and Ethiopian time: the dashboard reads "Sadaasa 8, 2019" and Attendance reads "ጥቅምት 2, 2019" and "5:00 ጠዋት" for 11:00. Back to English, Gregorian, Automatic. Step 2 was the owner's sign-in. "Remembered after signing out and in" not run. **Changed:** the TA's own language, calendar and clock, put back to አማርኛ, Gregorian, Automatic as found |
| UAT-A5 | Pass (step 1), Blocked (step 2) | Claude Code | 15:30 | TA | en | Chrome, unguarded. Billing: Starter, trial, "Next Billing 11 Apr 2027, Period ends". The page gives the date the trial ends, not a count of days. No invoice exists, so no receipt |
| UAT-F1 | Pass (steps 1 to 3) | Claude Code | 15:31 | TA | en | Chrome, unguarded. The six brackets as the guide lists them (0, 15, 20, 25, 30, 35 percent, deductions 300, 500, 850, 1,350, 2,050). Pension in force: Private (POESSA), employee 7%, employer 11%; the military scheme reads 7% and 33%. Overtime 1.5, 1.75, 2, 2.5, and 2.5 for a holiday night. Step 4 not run: no allowance was added or switched on |
| PAY-032 | Pass (first half) | Claude Code | 15:32 | TA | en | Chrome, unguarded. "Working days per month" shows 22 with its help. 19 and 32, then Save: "Enter a number of days from 20 to 31" under the field, `aria-invalid`, and no request sent. The page was reloaded without saving. The API half, the run-day half and FIN were not run |
| UAT-A6 | Pass (steps 1, 4, 5 read) | Claude Code | 15:31 to 15:34 | TA | en | Chrome, unguarded. Step 1: kind "Private organisation", pension Private (POESSA), fiscal year Meskerem; **"Confirm" pressed**: "Organisation updated" and the banner went (`PUT /settings/organization` 200). Step 4 is PAY-032; **the guide said 19 is valid and the product refuses it, so the guide is corrected in this change.** Step 5: each rule names its source, 16 read "Set by law", "Change" and "Reset to default" exist; a value below the law was not tried. D-3. Steps 2, 3 and 6 not run |
| UAT-H6 | Pass (steps 1, 5), partial (2, 4) | Claude Code | 15:39 to 15:41 | TA | en | Chrome, unguarded. Run after the guided setup below gave QA Audit its leave types. Step 1: Annual Leave 16 days, "Add days every (years of service)" 2, "Days added each time" 1. Step 5: 15 is refused with the Art. 77(1) message (`PUT /leave-types/…` 422); 17 saves ("Leave type updated"); **put back to 16**. Step 2: 13 holidays for 2026 and 13 for 2027 are listed, with the dates the case asks to check (Enkutatash 11 and 12 September, Meskel 27 and 28 September, Genna 7 January); the button reads "Add Ethiopian public holidays" and was not pressed; no "estimated" mark is shown in the list (O-3). Step 4: the Leave year card reads "Ethiopian year, Meskerem to Pagume"; a change was not tried. Step 3 not run |
| UAT-A8 (3 to 5) | Pass (step 4), partial (3, 5) | Claude Code | 15:33 to 15:36 | TA | en | Chrome, unguarded. Step 3 on QA Audit: the order is Organisation, Sign-in, People, Go live (#772). Technology / Software Company narrows "How your people work" and "Which rules apply" with "Show every option"; headcount is a range; "How you pay" asks the kind, the calendar and how often, **not** a pension registration number (O-4). **Applied** (`POST /onboarding/configuration/apply` 200). Step 4: the dashboard reads "Ready for first payroll: 2 of 9". Step 5: a pasted list is staged and reviewed in the setup; the list's dates were read as Gregorian, the toggle's choice here, so Ethiopian hire dates were not exercised |
| UAT-H2 | Pass (steps 3, 4 by paste), rest not run | Claude Code | 15:35 | TA | en | Chrome, unguarded. Eight people pasted with column names: "8 people ready" and the eleven columns named. Staged: 8 new, nobody added. "already work here, confirmed" chosen; the invite box starts unticked in the setup (#772) and the summary reads "Marked no in the list, so not invited: 8" (#778). "Bring them in" asks first, then every row reads Done. The leading zero of `0900000008` is kept as `+251900000008`; salaries, departments and positions arrived. After the commit: who can sign in ("In person · this list did not ask to invite them") and "8 people have no branch, so they will not reach a terminal" (#753). The template, a file, a re-stage, a difference, Match columns and the export were not run |
| UAT-H15 | Pass (step 1, unticked half) | Claude Code | 15:36 | TA | en | Chrome, unguarded. Nothing sent, and the way in is offered afterwards. Steps 2 to 6 make codes and passwords: not an agent's |
| UAT-F2 | Pass (steps 1 to 3) | Claude Code | 15:38 to 15:44 | TA | en | Chrome, unguarded. Period 11 October to 9 November 2026. Preview: "A dry run: nothing is saved yet.", People 9, Gross 95,747.44, Income tax 18,981.60, Pension 17,154.00, Net 65,460.61; "Process Payroll" disabled until the tick. **Processed** (202); Awaiting Approval within about five minutes with the same totals. **All six golden rows match to the cent** (1,800 / 0 / 126 / 1,674; 3,500 / 225 / 245 / 3,030; 6,000 / 700 / 420 / 4,880; 9,000 / 1,400 / 630 / 6,970; 12,000 / 2,250 / 840 / 8,910; 25,000 / 6,700 / 1,750 / 16,550), on the preview and on the run. The run lists 196 unexplained absences and says pay was not reduced, and "2 employees were skipped because no salary is set for them". Steps 4 to 6 not run: the run was **not approved**, and no file was downloaded. O-5 |
| UAT-H14 | Pass (steps 1, 2) | Claude Code | 15:50 | TA | en | Chrome, unguarded. Step 1: a new name with QA-EMP's phone: "Is this someone new?", "This may be QA-EMP Hana Bekele, who is already an employee here. Add them only if this is a different person.", reason "phone"; Cancel, and the list still holds 11. Step 2: "No possible duplicates" with the five things compared named. Steps 3 to 5 not run |
| UAT-H18 | Pass (steps 1 to 3), D-4 | Claude Code | 15:47 | TA | en | Chrome, unguarded. Quick Reports, October 2026: the twelve columns, 10 rows, late never above present, 22 workdays for a five-day week. The month picker is a Gregorian month on the Gregorian calendar; "Standard reports" and "More reports" are listed. No employee login to compare with My Attendance. **Present plus absent is one more than the workdays for QA Employee A (D-4).** Steps 4 to 7 not run |
| UAT-A1 | Pass (step 5) | Claude Code | 15:45 | TA | en | Chrome, unguarded. The audit log lists each of today's writes with its time: preferences, organisation details, configuration applied, access updated, migration staged and committed, payroll previewed, started and calculated, leave type updated twice. The refused 15 is not there. Steps 1 to 4 and 6 need codes, an invitation or a second administrator |
| UAT-H5 | Pass (step 6 read) | Claude Code | 15:48 | TA | en | Chrome, unguarded. Integrity: 0 to review, "Nothing to review". Steps 1 to 5 not run |
| UAT-H12 | Pass (step 1) | Claude Code | 15:30 | TA | en | Chrome, unguarded. Attendance setup panel lists what to fix: "No default shift", "1 device not heard from yet", "Kiosk check-in is on with no kiosk". Read before the setup was applied |
| UAT-S1 | Pass (step 1, empty) | Claude Code | 15:48 | TA | en | Chrome, unguarded. Approvals: "No pending approvals". Nothing to decide, and no supervisor account |
| CHT-001 | Pass, D-1 | Claude Code | 15:37 | TA | en | Chrome, unguarded. Employees: "Show as table" on Gender Distribution gives a table with headings and "Show as chart". Female 5, Male 3 are right; the third row has no name (D-1) |

## Second session, same day (about 15:58 to 16:16)

The owner signed Chrome in to QA Audit again, said to approve the payroll run and to carry on. The console showed its sign-in form at 15:58. Same browser, same persona, `Chrome, unguarded`.

| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |
|---|---|---|---|---|---|---|
| UAT-F2 | Pass (steps 4 to 6 read) | Claude Code | 15:59 | TA | en | Chrome, unguarded. "Approve Payroll" asks first: "Every employee in the run is notified that their payslip is available. This cannot be undone from here; a wrong run has to be voided and reprocessed." **Approved**, on the owner's word. The processor could approve because QA Audit has no other approver, as step 4 allows. The run reads Approved, with "Confirm payment" and "Void Payroll". Export lists the register, the bank transfer file, journal entries, and the income tax and pension schedules as CSV and PDF; **none was downloaded**, so "totals agree" was not checked |
| UAT-A6 | Pass (step 2) | Claude Code | 16:02 | TA | en | Chrome, unguarded. Reconciliation set to Advisory: "Settings saved", and Reconcile reads "Advisory: runs can be approved with items open." **Put back to Off** at 16:15 |
| UAT-H5 | Pass (step 5) | Claude Code | 16:05 | TA | en | Chrome, unguarded. Manual Entry for QA-EMP, 16 November 2026, 08:30 to 17:30, with a reason: `POST /attendance/manual` 201, listed as Manual, Present |
| UAT-F4 | Pass (steps 1, 2 in part) | Claude Code | 16:03 to 16:08 | TA | en | Chrome, unguarded. With no attendance at all in the period, Reconcile lists nothing: by design (`PayrollEngine::unexplainedAbsencesFor`, "an organisation recording no attendance flags nothing"). After the manual entry: 120 open for 1 to 17 November, each "Absent, nothing on record", Open, with Settle. Settle offers Unpaid Leave, Official Duty / Business Trip and Excused Absence and needs a reason; QA-PAY-1's 12 November filed as Excused Absence: 119 open, 1 waiting. Lateness, missing punches, Settled and step 4 not run. O-6 |
| UAT-H8 | Pass (control), Blocked (step 1) | Claude Code | 16:09 | TA | en | Chrome, unguarded. Approvals shows the request, with 1 in the sidebar. Approve as the person who filed it: "Action failed. You filed this request, so someone else must approve it." Right, and it means the request waits for a second approver QA Audit does not have |
| UAT-S1 | Pass (steps 1, 3 dialog) | Claude Code | 16:09 | TA | en | Chrome, unguarded. Reject opens "Reject request" with a required reason, "Visible to the employee.", and Reject disabled until one is typed. Closed without rejecting |
| UAT-H3 | Pass (step 6) | Claude Code | 16:11 | TA | en | Chrome, unguarded. QA-PAY-4, History, Record action: a Promotion to Senior Engineer dated 17 December 2026 reads **Scheduled**, "Takes effect on 17 Dec 2026. The employee record is unchanged until then."; the position still reads Software Engineer. "Cancel change" asks first, then "Scheduled change cancelled" and the history is empty again. Steps 1 to 5 not run |
| UAT-A2 | Pass (step 1) | Claude Code | 16:13 | TA | en | Chrome, unguarded. Create role: "QA Branch HR", reach "Their branch", three permissions (view employee details, view employees list, update employee records): "Role QA Branch HR created", 3 permissions, 0 users. Steps 2 and 3 need a test user |
| UAT-A3 | Not run (read only) | Claude Code | 16:14 | TA | en | Chrome, unguarded. Approval chains: none; the page explains that without one a request is decided in one step and that nobody approves their own. No chain was made: it needs a supervisor and an HR account to prove |
| UAT-E5, S1 step 2, H17 | Blocked | | | | | "Apply for Leave" is for the signed-in person, and the TA has no employee record. Annual leave needs an employee login to ask and a second account to approve |

## What was created or changed in QA Audit (01, section 12)

Second session: **the payroll run was approved** (below); a **manual attendance record** for QA-EMP on 16 November 2026; a **leave request**, Excused Absence for QA-PAY-1 on 12 November 2026, **still waiting** for an approver other than the TA; the custom role **"QA Branch HR"**, held by nobody. Reconciliation went Off, Advisory, Off. A scheduled promotion for QA-PAY-4 was made and cancelled.

- **The kind of organisation was confirmed** as Private organisation.
- **The guided setup was applied** as Technology / Software Company, Flexible start, Ethiopian private sector: a branch "Headquarters" beside "QA Branch"; departments Engineering, Product, Design, QA, DevOps, Sales, Customer Success, Finance, HR; eight positions; Grades I to VIII; the shift "Flexible day 08:00–17:00"; leave types Annual, Sick, Maternity, Paternity, Marriage and Bereavement (Unpaid was there); three allowances, switched off with no amount. These names do not carry the `QA-` prefix: they are what the setup creates.
- **Sign-in methods:** Employee number switched on beside Email.
- **Eight employees**, batch `01M7MR2A2STAVA3GKEM6GJFQ3K`, confirmed, no email, no login, nobody invited: QA-PAY-1 Almaz Kebede (1,800), QA-PAY-2 Bekele Tadesse (3,500), QA-PAY-3 Chaltu Gemechu (6,000), QA-PAY-4 Dawit Haile (9,000), QA-PAY-5 Eleni Assefa (12,000), QA-PAY-6 Fikru Wolde (25,000), QA-SUP Genet Mekonnen (15,000), QA-EMP Hana Bekele (8,000, phone `0900000008`, a made-up number). They are the guide's E-PAY-1 to E-PAY-6 and two people for the supervisor and employee personas. None has a branch or a supervisor yet.
- **Payroll run** `01M7MR8QNAZE2DNTR7B7XP31X0`, 11 October to 9 November 2026, 9 people, **Approved** at about 15:59 on the owner's word. Payment is not confirmed, so it can still be voided with the reason "QA". Leave dated inside that period is now refused (UAT-F6 step 4).
- **Annual Leave** went 16, 17, 16 days.
- Found and left alone: two lists in progress of 2,000 and 600 lines, started the day before.
- The owner should sign that Chrome out of QA Audit when the pass is over (11, section 6.1).

## Not run, and why

| Scenarios | Why |
|---|---|
| UAT-E3 to E15, S1 to S4 (beyond the empty Approvals) | No employee or supervisor login. QA-EMP and QA-SUP exist as employees from today; each needs a login made by the owner (an activation code or a password is not an agent's) and QA-EMP a supervisor. E3 steps 3 and 4, E9 and E12 need a phone; E14 the kiosk tablet |
| UAT-H1 steps 2, 3; H13; H15 steps 2 to 6; A1 steps 1 to 4, 6; H9; E11 | An invitation email, an authenticator or activation code, a starting password, or a second administrator |
| UAT-H3 steps 1 to 5, H4, H5 steps 1 to 4, H7, H10, H11, H12 steps 2 to 4, H18 steps 4 to 7; A4, A7 | Not reached. All can be run by an agent as TA in QA Audit except where a second account must act |
| UAT-H8 step 1, H17, A2 steps 2 and 3, A3, F4 steps 3 and 4 | **A second approver.** QA Audit has one administrator, and nobody approves what they filed. An HR or second admin login unblocks these |
| UAT-H16 | Needs a terminal |
| UAT-F2 steps 4 to 6, F3, F4, F5, F6 | The run is not approved; F5 cannot be undone and is the owner's; F6 needs E-SICK people |
| UAT-P1 | The platform console is never driven by an agent in Chrome |
| Mode X cases, rate-limit and secret-path probes | A person's, with the owner's go-ahead |

## Defect drafts (10, section 1), for the tester to file

**D-1. People with no gender on file are a row with no name in Gender Distribution.** S4, usability.

- Where: Employees, the Gender Distribution card, as a chart legend and as a table. `src/src/features/employees/components/headcount-charts.tsx:153` and `:190` print `g.gender` as it arrives.
- Seen on production, about 15:37, QA Audit, TA, English: the table reads a blank cell with 3, "Female" 5, "Male" 3. The three are the employees added before today with no gender chosen.
- Expected: a name for the row, for example "Not recorded", in en, am and om.

**D-2. The setup's note says ETHR does not work out the service day or sick pay in steps, and it does.** S4, copy.

- Where: the guided setup's "Confirm before you apply", first note. `api/lang/en/onboarding.php:8`: "Labour Proclamation 1156/2019 also adds an annual-leave day for every two years of service and pays sick leave in steps; ETHR does not calculate either automatically."
- Seen on production, about 15:33. The Annual Leave type the same setup creates carries "Add days every (years of service)" 2 and "Days added each time" 1, and payroll pays sick leave by the Art. 85 tiers since 2026-11-06 (`Services/Leave/SickLeaveTiers`, UAT-F6).
- Expected: the note says what the product does. To check before changing it: that the service day is added to the entitlement, not only stored on the leave type. The same sentence in `am` and `om`.

**D-3. A statutory rule's source names an internal file.** S4, copy.

- Where: Settings, Payroll rules, Statutory rules, "Working days in a month". The text is the row's `legal_reference`, written by `api/database/migrations/2026_11_06_000003_give_each_organization_type_its_own_working_days_row.php:54`.
- Seen on production, about 15:33: "Set for your kind of organisation · ETHR convention, set per organisation type (ACCOUNTANT-SIGN-OFF.md) · version 2026.11.1".
- Expected: a source a customer can read, without the name of a file in ETHR's repository.

**D-4. On the monthly timesheet, present and absent days add up to more than the workdays.** S3, reports. The cause is not established: confirm before filing.

- Where: Reports, Quick Reports, Monthly timesheet, October 2026, QA Audit.
- Seen on production, about 15:47: QA Employee A: Workdays 27, Present 2, Absent 26, which is 28 days against 27. QA Employee B: Workdays 27, Present 1, Absent 26, which adds up.
- What the attendance list holds for QA Employee A in October: two records dated 12 October (biometric 11:00 to 19:15, and kiosk 20:12 to 02:30, which ends on the 13th), and four dated 10 October, three Voided and one Pending. QA Employee B also has two records on 12 October and is counted once, so two records on a day is not by itself the cause. The record that crosses midnight and the Pending one are the two candidates. The payroll run counts 13 October onward as missed for that person.
- Expected (REP-006): each workday is counted once, as present, absent, on leave or a holiday.
- Caveat: these records were made by QA in October, before a day held one record whichever way a person punches (2026-11-06).

## Observations, not filed

- **O-1.** In Afaan Oromoo on the Gregorian calendar the dashboard's date reads "November 17, 2026", in English; the menu beside it reads "17 Nov 2026". In አማርኛ the same line reads "17 ኖቬምበር 2026". Month names for Afaan Oromoo on the Gregorian calendar may be missing.
- **O-2.** The Add Employee form's Code field shows the example "TECH-0001" while Settings reads "Next code: EMP-0002". Not followed up: no employee was added by hand after the setup.
- **O-3.** HOL-001 expects Eid al-Fitr, Arafa and Mawlid "marked estimated". The Holidays list shows no such mark. The button was not pressed, so the mark may be in what it offers.
- **O-4.** UAT-A8 step 3 says "How you pay" asks the pension registration number. On QA Audit (private, Technology) it asks the kind, the calendar and how often; the number is under Settings, Payroll rules, Statutory filing. Either the guide or the screen is out of date for this kind.
- **O-5.** On the payroll run, QA Employee A's missed days are every day from 13 October to 9 November, Sundays included (28), while the timesheet gives that person 27 workdays in October and the new people 21 weekdays in the period. Which days that person is expected was not read.
- **O-6.** Reconcile shows its From and To in the Ethiopian calendar ("Tikimt 22, 2019" to "Hidar 8, 2019") while the table under them, and the rest of the session, is Gregorian, the reader's own choice. The period it opens on is the Gregorian month to date, not the Ethiopian month the guide's step 1 speaks of.
- **Pack corrections made with this log:** UAT-A6 step 4 said 19 working days is a valid choice; the product and PAY-032 refuse anything outside 20 to 31. UAT-H6 step 2 named a button "Auto-detect"; it reads "Add Ethiopian public holidays" (`holidays_page.auto_detect`).

## Summary

| | Pass | Pass in part | Fail | Blocked or not run |
|---|---|---|---|---|
| Release smoke (15 rows) | 5 | 3 | 0 | 7 |
| Detailed cases (ORG-009, 012, 015, 016, PAY-032, CHT-001) | 4 | 2 | 0 | |
| UAT scenarios (52) | 0 in full | 20 | 0 | 32 |

The second session added UAT-F4, H3, H5 step 5, H8, A2 and S1's reject dialog, and took F2 through approval. No S1 or S2 defect was seen. The four drafts are S3 or S4. The exit criteria of 01, section 7.2 are not met and were not expected to be: most of the guide still needs logins for the other personas, a phone and a terminal, and 30 live changes have no case.
