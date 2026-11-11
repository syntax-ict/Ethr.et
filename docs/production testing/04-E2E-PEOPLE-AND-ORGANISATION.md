# E2E specification 04: setup, organisation structure, employees and self-service

Columns, personas and modes: see the [README](README.md#how-a-test-case-is-written). Run in QA Audit
unless a case says otherwise. Section 1 also builds the test data the later documents rely on.

**Third edition (2026-11-08).** Cases were added for the guided setup's People step and readiness
(#481, #499, #501, #505, #506), Ethiopian dates and pay data in the employee import, the People
step's limits, structure deletions and supervisor loops (A-Z review train #624: #558, #559, #560,
#562), a vacancy that cannot be over-filled (#624, #606) and signed file links under `/api/files`
(#625, #612). Priorities were re-tiered: **P1** is kept for cross-organisation or cross-department
exposure, refusals that protect data or money, security controls on files and logins, and the one
happy path of each section's main flow (apply a setup, commit people, add one employee, commit an
import, one lifecycle transition, upload a document, a gated profile change, a hire). Messages,
validation, secondary flows and plan-limit refusals are P2; cosmetic and secondary items are P3.
Covered elsewhere and not repeated here: the organisation owner (03, ORG-004 and ACC-027 to
ACC-030), department reach (03, ACC-017 to ACC-019), HR's "create login" role rule (03, ACC-021),
pay fields on one's own record (03, ACC-020 and ACC-022), employee code sign-in after in-person
activation (03, K-44), contract salaries in payroll (07, PAY-020) and the public vacancy page
(08, VAC-004).

**Since the third edition (2026-11-09).** Every stored file is now named by its detected type and
served as a download (#679: DOC-001, DOC-011, DOC-012), and two writers at the last free seat of a
plan are taken one at a time (#679: IMP-E-017). One login per employee (#670, #678) is file 03's
ACC-031.

**Org-type-aware configuration (#682).** Section 1a (SET-018 to SET-022) covers the configuration
step's new answers (kind of organisation, headcount band, pay frequency, calendar), the payroll
details it needs (pension registration, payout method, paying bank) and "Ready for first payroll".
SET-002, SET-006 and SET-008 and uat-setup's test data were changed for it. Until #682 is deployed
(S-01), record SET-018 to SET-022 as Blocked and run the changed cases' earlier text. The sign-up
question is 03's ORG-009 to ORG-011; the statutory rules themselves are 07's section 13.

**Extra test data for this file** (add it with the rest of [01, section 6](01-TEST-PLAN.md#6-test-data)):

| Item | Used by |
|---|---|
| **uat-setup**: a fresh organisation registered for the setup wizard as ORG-004 does, slug `uat-setup-<date>`, Organization Type "Government Office" and, since #682, "Kind of organisation in law" "Government office (civil service)". Not QA Audit, not QA Isolation | SET-001 to SET-022 |
| A people list of 6 rows with a header row, typed in a spreadsheet: one row with no hire date, one with no employee number | SET-009 to SET-012 |
| A CSV of the same list saved with semicolons, one name written `"Kebede, Abebe"`; the same sheet saved as `.xlsx` | SET-010 |
| A custom role "QA Pay-less HR" with `employee.create`, `employee.update` and `employee.viewAny` but not `employee.updateFinancial`, reach the whole organisation, held by a disposable `QA-` login | IMP-E-016, PRO-011 |

---

## 1. Guided setup and test data build

The setup wizard lives at `/setup` (it redirects to `/setup/guided`) and has four steps:
configuration, people, sign-in, go live. Run it only on **uat-setup** (above), so that neither QA
Audit's standing set-up nor QA Isolation's plan states are disturbed; build QA Audit's data with
sections 2 and 3. The People step is the same component as `/employees/from-device`, so its list
cases (SET-009 to SET-015) can be repeated there.

**Before uat-setup holds 5 people**, switch off Settings → ETHR website (LAND-006). A trial with 5 or
more people is named in public (SET-017), and QA organisations must stay off the public site
(LAND-007).

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SET-001 | P2 | R | Industries of the sign-up's kind (#499) | Step 1 on uat-setup (signed up as "Government Office"): read the industry list; click "Show every type of organisation"; click the link again | Only the nine government types: Federal Government, Federal Ministry, Federal Agency / Authority, Regional Government, Regional Bureau, Zonal Administration, City Administration, Woreda Administration, Government Sector Office. None is preselected, since there are several. "Show every type of organisation" lists all 35, from Federal Government to Custom Organization, and the link becomes "Show only the types that match your sign-up". Federal Ministry proposes the employee number format `MIN-{SEQ:4}` |
| SET-002 | P2 | W | Preview (#682) | Choose an industry, workforce profile (standard office, six-day week, flexible hybrid, rotating three shift, extended hours two shift, part time, field distributed, seasonal agricultural, weekend) and policy profile (Ethiopia private, Ethiopia public, custom); employee count 50 (since #682 the page asks "How many people work here" instead: choose "51-200"); preview | A proposed set of branches, departments, positions, grades, shifts, leave types, rotations and allowances. Employee count outside 1 to 1,000,000 is refused (API `employee_count`; since #682 the page sends `staff_band`, read as its lower bound, 51, and any other band is 422, SET-019). Since #682 the policy profile no longer sets the fiscal year or Pagume proration: they follow the kind of organisation (07, STAT-006) |
| SET-003 | P1 | W | Apply | Apply the preview | Everything is created in one go. If anything fails, nothing is created (single transaction). Re-applying does not duplicate |
| SET-004 | P2 | W | Access step | Choose sign-in identifiers (at least one of email, phone, employee code, username) | Saved; with none chosen, refused. Sign-in then accepts exactly those (AUTH-004) |
| SET-005 | P1 | W | People step | Stage 5 rows by hand or CSV; set one row to merge, one to skip, one to defer; "Commit migration", confirm "Bring them in" | The confirmation reads "Bring these records in?", "Employees are created and merged as the table shows. This cannot be undone from here." Then "Migration complete: :created created, :merged merged"; created, merged, skipped and deferred as chosen; at most 2,000 rows per batch. No row is silently dropped |
| SET-005a | P3 | W | People from a device | Only with a test device registered (DEV-002): stage people from the device's user list | Rows staged from the device's users for review before commit; nothing created until commit. A device user with no name is held for review, and if created is named "Device user :id" (#501). With no device connected, the device card is replaced by one line, "Using a fingerprint or face terminal? Connect a ZKTeco terminal and everyone enrolled on it can be brought in from here." and "Connect a device" (#505) |
| SET-006 | P2 | R | Readiness tells the truth (#481, #682) | Open the go-live step on uat-setup right after registering; again after SET-003 and SET-005 | Groups Configuration, Data, Payroll (only when the plan includes payroll) and Security. Weights: "Organisation setup reviewed and applied" 2, "At least one branch" 2, "Departments defined", "Positions defined", "Working hours and shifts defined", "Leave policies defined", "Public holidays loaded" 1 each; "Employees added" 3, "Every employee is in a department" 1, "An attendance source (device or method) is set" 1; "Employer TIN recorded" 2, "Every employee has a salary" 2 (their own or a contract's), "Every employee has a TIN" 1, "Every employee has a bank account" 1; "At least one administrator is active" 1, "Every administrator uses two-step sign-in" 2. An "Every employee" check fails while there is nobody. Since #682 the Payroll group is "Employer TIN recorded" 2, "Tax centre recorded" 1, "Pension registration number recorded" 2, "How salaries are paid is set" 2 (cash, or bank with the bank named), "Tax and pension follow the law" 2, "Leave types grant at least what the law grants" 1, then the three "Every employee" checks as before; and Data adds "A biometric device is linked" with weight 0 (SET-022). Each gap under "To improve your score" has "Fix". "Ready to launch" at 90 or more, "Needs attention" at 60 or more, otherwise "Not ready". Straight after sign-up the score is low (22% in the #481 review; a little higher since #682, whose two law checks pass on a new organisation), never near ready; it rises after SET-003 and SET-005 |
| SET-007 | P2 | W | Go live | Go live with a low score | Allowed (the score advises, it does not block): "Go live now?", "Setup ends and the organisation opens for daily use. Anything not yet set up can still be done later from Settings.", then "Your organization is live." (API `message` "Organization is live."), redirected to `/dashboard`, audited `onboarding.went_live` with the score. Needs `settings.manage` |
| SET-008 | P3 | R | One industry of its kind is chosen for you (#481, #499, #682) | Only where another fresh registration is planned (QA Isolation, ORG-004): register it as "Bank / Financial" and open step 1 without applying. If a "General Company" registration is planned, open step 1 there too. Since #682 each registration also needs a "Kind of organisation in law" (ORG-009): "Share company (S.C.)" for the bank, "Private limited company (PLC)" for the general company | Bank / Financial: Bank, Insurance Company and Microfinance Institution, with Bank already selected. General Company: #499 intends every type listed with no "Show every type of organisation" link. The code narrows to the types whose base is `general` (the services and other groups); if only those show, record a defect against #499. Once #656 is deployed (K-49): every type, none selected, no link |
| SET-009 | P2 | W | Paste from a spreadsheet (#501) | People step on uat-setup: copy the header row and 3 rows from Excel or Google Sheets with the columns out of order (Department, Name, Hire date (G.C.), Email) and paste. Then replace the header with Amharic names (ስም, ኢሜይል, ክፍል) | While typing: "3 people ready · Columns: Department, Name, Hire date, Email" and "Hire dates are read as Gregorian (G.C.), as the column name says." The Amharic header is recognised the same way. "Stage for review" puts exactly those 3 people in the review table. Without a header row the order is "name, email, phone, employee number, hire date (YYYY-MM-DD), department" (hint below the box) |
| SET-010 | P2 | W | A CSV in place and an Excel file (#501) | "Choose a CSV file": the semicolon CSV with `"Kebede, Abebe"`; then the `.xlsx`; then "Download template" with each calendar chosen | The CSV fills the box: the quoted name stays one person, `Kebede, Abebe`. The `.xlsx`: "Excel files are not read directly. Select the rows in Excel, copy them and paste them here, or save the sheet as CSV." and nothing is read. The template is `ethr-people-template.csv`, headed `Name,Email,Phone,Employee number,Hire date (G.C.),Department` (or `Hire date (E.C.)` under Ethiopian) with one example person |
| SET-011 | P2 | W | Lines to fix before staging (#481, #501) | Paste a list with: a row with no name; an email `abebe@`; a hire date `08/07/2019`; one employee number twice; one email twice; a department that does not exist; then 6 problem lines at once. Through the API, `POST /onboarding/migration/rows` with two rows sharing an employee number | Under the box, with line numbers counted as seen, header included: "Line :line has no name."; "Line :line: abebe@ is not an email address."; "Line :line: write the hire date 08/07/2019 as YYYY-MM-DD, for example 2019-07-08."; "Line :line: employee number :value is also on line :first. Each person needs their own."; "Line :line: :value is also on line :first. Each person needs their own email address."; "Line :line: there is no department :value. Use a department's name or code as it is in ETHR, or leave it empty." The first 5 are listed, then "and :count more". "Stage for review" stays disabled until all are fixed. API: 422, "Two lines have the same employee number. Each person needs their own.", nothing staged |
| SET-012 | P2 | W | Ethiopian hire dates (#505) | Choose "Ethiopian (E.C.)" beside "Hire dates are in"; paste a person with hire date `2011-10-30` and one with `2012-13-06`; then a header naming "Hire date (G.C.)" while Ethiopian is chosen; leave two people without a date; stage and commit the valid one | `2012-13-06`: "Line :line: 2012-13-06 is not an Ethiopian calendar date. Write it as YYYY-MM-DD, for example 2011-10-30." (Pagume 2012 has 5 days.) The header wins over the buttons: "Hire dates are read as Gregorian (G.C.), as the column name says." Missing dates: "2 people have no hire date. Today will be used, which affects leave and length of service." After the commit, the person dated `2011-10-30` E.C. has hire date 7 July 2019 on their profile, not 2011. The calendar chosen at first is the reader's own |
| SET-013 | P3 | R | The example follows the calendar (#506) | Empty box; switch between "Ethiopian (E.C.)" and "Gregorian (G.C.)" | The grey example reads `Hire date (E.C.)` with `2011-10-30` under Ethiopian and `Hire date (G.C.)` with `2019-07-07` under Gregorian, the same day, as the template does |
| SET-014 | P2 | W | People added elsewhere count (#505) | On uat-setup before SET-005: add one employee on Employees → Add employee; reopen `/setup/guided`; open the dashboard as TA | The People step shows done and ":done of :total done" counts it; the dashboard's "Finish the guided setup" item says ":done of :total steps done." with the same number. Before the fix the step stayed undone for good |
| SET-015 | P2 | W | The People step follows the other ways in (#624, #559) | (a) Paste 2 people with no employee number, stage and commit. (b) Paste one with a 31-character employee number and stage. (c) Stage a batch, open it in a second tab, commit in both. (d) In QA Isolation on Starter at its employee limit (REC-009), stage one person on `/employees/from-device` and commit | (a) Both get the next numbers in the organisation's format (`EMP-0001` style by default), never empty. (b) Refused at staging (422 on `rows.0.employee_code`, the server's reason shown), nothing staged. (c) Everyone is created once; the second tab ends with the same totals. (d) 403 "You have reached the employee limit for your plan.", nobody created. A trial has no limit |
| SET-016 | P3 | R | The administrator's own name (#481) | On uat-setup, the account that registered (it has no employee record): open Profile; TA opens Settings → Users | Both show the administrator name typed at sign-up, not the email address or part of it |
| SET-017 | P2 | R | A new trial is not named in public (#481) | Signed out, straight after registering uat-setup (fewer than 5 people): the ethr.et home page "Trusted by", `/sitemap-organisations.xml`, and `curl -I` on its entry page | Absent from "Trusted by" and from the sitemap, and its entry page is `noindex`. A trial is named only once it holds 5 people, an active organisation at once (MKT-007, LAND-005). Switch the showcase off before the fifth person (section note) |

**QA Audit data build** (do once, then record the public ids in the execution log):

1. Branches B1 and B2 (ORG-S-001), departments D1 and D2 (ORG-S-003), position P1, grade G1 with
   steps 1 to 3 (ORG-S-005).
2. Shifts S1 and S2 ([05, section 9](05-E2E-ATTENDANCE-SHIFTS-DEVICES.md#9-shifts-assignments-rotations-roster)).
3. Persona employees and logins (EMP-001), golden payslip employees E-PAY-1 to E-PAY-7
   ([07, section 2](07-E2E-PAYROLL.md#2-golden-payslips)).
4. Holidays auto-detected (HOL-001).

## 1a. Organisation profile and first payroll (#682)

**Run only once #682 is deployed (check S-01); until then record Blocked.**

On uat-setup (kind "Government office (civil service)"), unless a case says otherwise. The
configuration step's apply records the organisation's profile, its kind and its industry as its
sector (`ConfigurationController::apply()`), and writes no statutory value. "Ready for first
payroll" is the Payroll group of the readiness rubric (`ReadinessScorer::payrollChecks()`), returned
by `GET /next-steps` as `payroll {done, total, ready}` to whoever holds `settings.manage`, and only
when the plan includes payroll. Its nine checks: employer TIN, tax centre, pension registration
number, payout method, tax and pension follow the law, leave types at least the law, and a salary, a
TIN and a bank account for every current employee. Each failing check that has a step becomes one on
the Next steps card.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SET-018 | P2 | R | The configuration step asks how you pay (#682) | `/setup/guided`, step 1: choose an industry; read the section that appears; open each control without applying | Beside region: "How many people work here", placeholder "Choose a range", options `1-10`, `11-50`, `51-200`, `201-500`, `501-1000`, `1000+` (no number field "Employees (approx.)" any more). A section "How you pay", "Tax, pension and leave rules follow from the kind of organisation. Nothing here asks you to type a rate.": "Kind of organisation in law", already the sign-up's kind, the sixteen kinds in the groups "Private sector", "Non-profit", "Public sector", "International" (03, ORG-009); "Calendar", "Ethiopian calendar" or "Gregorian calendar", the organisation's own preselected; "How often you pay": "Monthly" chosen, "Every two weeks" and "Weekly" shown and disabled, with "Weekly and two-weekly pay are not supported yet; payroll runs monthly." No field asks for a tax or pension rate |
| SET-019 | P1 | W | Applying records the answers as a profile, not as rates (#682) | Step 1: industry, "51-200", the kind left as it is, "Ethiopian calendar"; "Review proposal", "Apply configuration". Then TA: `GET /payroll/statutory-rules`, `GET /settings`, Settings → Payroll → Statutory rules, the audit log. Then, with the browser's request copied, `POST /onboarding/configuration/apply` again with `pay_period` `weekly`; with `staff_band` `5-9`; with `organization_type` `plc` | "Configuration applied: :count records created" (or "Configuration applied. Already set up."). `statutory-rules`: `organization_type` `government`, `sector` the chosen industry's key. `GET /settings`: `organization_type_needs_review` false; `payroll.pay_period` `monthly`; `display.calendar` `ethiopian`. Statutory rules: "First month of the fiscal year" 11, "Set for your kind of organisation", and "How Pagume is paid" `full_month`, "The law, for every organisation" (an ETHR default labelled as the law's layer: note it, cosmetic), both with "Default", never "Your value" (before #682 the wizard wrote both into settings, and they would now be the organisation's own). Audit `organization_profile.assigned` with `organization_type`, `sector`, `effective_from`. The three API variants: 422 on `pay_period`, `staff_band` and `organization_type` respectively, nothing applied |
| SET-020 | P1 | R | "Ready for first payroll" (#682) | TA on uat-setup after SET-019, before SET-021: the dashboard's Next steps card; `GET /next-steps`. HR: the same. QA Isolation on Starter: TA's `GET /next-steps` | Under the card's hint: "Ready for first payroll: :done of :total" with a bar named "First payroll readiness". API `payroll`: `total` 9, `done` the passing checks (on a new organisation the two law checks pass), `ready` false. The failing checks as steps, each with its button: "Record your employer TIN", "The Ministry of Revenues return needs it.", "Add TIN"; "Record your tax centre", "The Ministry of Revenues branch your employment tax is declared at.", "Add tax centre"; "Record your pension registration number", "Contributions are declared under it to POESSA or PSSSA.", "Add number"; "Choose how salaries are paid", "Bank transfer needs the bank you pay from; or choose cash.", "Set payout"; "Give everyone a salary", "Payroll cannot pay someone without one.", "Review employees" (TIN and bank gaps keep their own steps). "Link a biometric device", "Optional: a terminal records attendance without phones.", "Add a device" is information only and is not one of the nine. HR: no readiness block (`payroll` null, no `settings.manage`). Starter without payroll: `payroll` null |
| SET-021 | P1 | W | The payroll details first payroll needs (#682) | TA: Settings → Payroll → Payroll setup → "Statutory filing" with the Network tab open: read the hint under "Pension registration number"; type 41 characters; set "QA-PEN-1"; "How salaries are paid": "Bank transfer", read; "Cash"; save. Employer TIN and tax centre `QA` values. One `QA-` employee with a salary, TIN and bank account (EMP-001). API: `PUT /settings` with `settings.payout_method` `mobile`; with a 41-character `settings.pension_registration_number`. Reopen the dashboard | Hint: "Your registration with Public Servants' Social Security Agency (PSSSA) — Civil Service Pension Fund." (a private kind names "Private Organization Employees' Social Security Agency (POESSA)"). The field stops at 40 characters. Bank transfer shows "Bank you pay from" (up to 120); Cash hides it and saves it empty. "Statutory details saved"; the request carries `pension_registration_number`, `payout_method`, `employer_bank_name`, and `pension_scheme` only if the scheme was changed. `GET /settings` → `payroll.pension_registration_number`, `payout_method`, `employer_bank_name`, `pension_agency`. API: 422 on each. "Bank transfer" with no bank named leaves "Choose how salaries are paid" failing. With everything set: "Ready for first payroll" and `payroll` `{done: 9, total: 9, ready: true}` |
| SET-022 | P3 | R | The new checks on the go-live step (#682) | Go-live step on uat-setup after SET-021, in English and then in አማርኛ; leave the device unlinked | Payroll lists the six checks of SET-006 by their labels; "A biometric device is linked" appears under Data and under "To improve your score" with "Fix", though at weight 0 it cannot change the score. Expected: an optional check is not offered as a way to improve the score; record it if it is (cosmetic). In አማርኛ the six new labels have no `setup2.check_*` key in any locale and show the API's English (09, L10N-021) |

## 2. Organisation structure

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| ORG-S-001 | P1 | W | Branch | HR: Organization → Branches → add "QA Head Office", code `QAHQ`, latitude and longitude of the office, geofence 200 m | Saved. Name under 2 characters, code over 20, latitude outside -90 to 90, or radius outside 10 to 10,000 m is refused |
| ORG-S-002 | P2 | W | Branch plan limit | On Starter (QA Isolation), add a second branch | 403 branch limit (PLAN-003) |
| ORG-S-003 | P2 | W | Departments and tree | Add D1 and a child department under it; view the tree | Tree shows the hierarchy |
| ORG-S-004 | P2 | W | Delete a staffed unit (#624, #562) | Delete D1 while employees are in it; then a branch, a position, a team, a grade and a cost centre, each with a current `QA-` employee | 409 `org-unit-in-use`, "This department cannot be deleted: N current employees are assigned to it. Move them first, or mark it inactive." The same for each, naming it ("This cost centre cannot be deleted: 1 current employee is assigned to it. …"). Before #562 a team, grade or cost centre was deleted and its staff lost the link. People who have left do not block |
| ORG-S-005 | P2 | W | Grades and steps | Grade G1 min 5,000, max 15,000; steps 1 to 3 at 6,000, 7,000, 8,000 | Saved. A max below min, a step outside the range, a duplicate step number, or a higher step paying less is refused. Who sees the amounts: 03 |
| ORG-S-006 | P3 | W | Teams, positions, cost centres | Create one of each | Saved; names 2 to 255 characters |
| ORG-S-007 | P2 | W | Department head | Set a department's head employee | Used by "department head" approval steps ([06](06-E2E-LEAVE-AND-APPROVALS.md)) |
| ORG-S-008 | P2 | R | Reporting tree | Organization → reporting tree | SUP with EMP and EMP2 beneath; people whose supervisor was removed appear at the top level, not lost |
| ORG-S-009 | P3 | R | Permissions | EMP opens the organisation page | Can view (everyone holds `org.view`); cannot create or edit |

## 3. Employees

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| EMP-001 | P2 | W | Create with the minimum | HR: Employees → Add; name and hire date only | Saved with status `hired` and the next number (default format `EMP-0001`). The number shown before saving is a preview: two people adding at once may see the same one, and the second save takes the next |
| EMP-002 | P1 | W | Create in full | Name, Amharic name, email, phone `0911 223344`, gender, date of birth, hire date, department, branch, position, grade, supervisor SUP, salary 9,000.00, TIN, national ID, badge number | Saved. Phone stored as `+251911223344`. The response has no TIN or national ID, ever |
| EMP-003 | P2 | W | Validation | Hire date more than a year ahead; probation end before hire date; employee aged 14 on the hire date; duplicate employee code; badge with a space; phone `12` | Each refused with the field named. Age: "The employee must be at least 15 years old on the hire date." |
| EMP-004 | P2 | X | Create a login | Tick "Send a login invitation to this email", email the team controls | Invitation sent (PWD-009); `meta.login_invited` true. If an account with that email already exists and is unlinked, it is linked instead of duplicated |
| EMP-005 | P1 | R | Role clamp (#624, #557) | HR creates an employee with login role `tenant_admin`, then `finance_admin` (API `role`) | The login is made as `employee` in both cases, never a role HR may not assign and never `super_admin`. TA asking for `finance_admin` gets it. The user-management side of the rule is 03, ACC-020 and ACC-021 |
| EMP-006 | P1 | R | Salary needs financial rights | SUP or a custom role without `employee.updateFinancial` sets a salary or TIN | Refused; sending the unchanged value back is not refused |
| EMP-007 | P1 | R | Cross-tenant relation ids | Through the API, create an employee with a `department_id` (public id) from QA Isolation | 422 with the same generic message as a non-existent id. Repeat for branch, position, grade, team, cost centre, supervisor |
| EMP-008 | P2 | R | Own supervisor and loops (#624, #562) | Set an employee as their own supervisor. Then set SUP's supervisor to EMP (EMP reports to SUP) | "An employee cannot be their own supervisor." The loop: 422 on `supervisor_id`, "That would make a loop: this person already supervises, directly or further down, the one chosen." Nothing changes, and both stay in the reporting tree |
| EMP-009 | P2 | R | List, search, filter | Employees list: search by name, code, email, phone; filter by department and status; sort; page through | Results correct; 25 per page by default, `per_page` above 100 refused or capped |
| EMP-010 | P2 | R | Employee number format | TA: Settings → employee number format `QA-{SEQ:5}`; add an employee | Number `QA-00001` style. An invalid format (two `{SEQ}` tokens, `{SEQ:9}`) gives "Use one {SEQ:n} token, n from 1 to 8…" |
| EMP-011 | P2 | W | Soft delete | TA deletes a test employee | Removed from lists, still present in history, payroll and audit (never hard-deleted); HR cannot delete (`employee.delete` is tenant admin) |
| EMP-012 | P2 | W | Bulk update | Select 3 employees, change department | Each shows updated, unchanged or failed. More than 200 refused; including someone outside your reach rejects the whole request (422); no field given: "At least one field to update must be provided."; a status change also needs `employee.transition` |
| EMP-013 | P3 | R | Statistics | Employees page summary cards | Counts agree with the list |
| EMP-014 | P2 | W | A login with nothing to send | HR: Add employee, name and hire date only, no email, no phone; tick the login box; save. This is **E-NC** for [03, section 5a](03-E2E-AUTH-TENANCY-ACCESS.md#5a-in-person-activation-no-email-no-phone) | The box reads "Create a login to activate in person", hint "With no email or phone, nothing can be sent. HR activates the login face to face with a one-time code from their profile." 201, `meta.activation_code_pending: true`, `login_invited: false`; no email or SMS. The login is always role `employee`. The profile offers "Activate in person" (ACT-001) |
| EMP-015 | P2 | R | Phone but no email | Type a phone and no email; then through the API create one with `create_login: true`. This is **E-PH** | Form: the box is still disabled, "Add an email to send a login invitation." (K-42, still open). API, while text invitations are off: 201, a login made for in-person activation, `activation_code_pending: true`, `login_invited: false`, no SMS. The profile offers "Activate in person" and ACT-001 works (K-20, fixed by #458) |
| EMP-016 | P2 | R | Records to fix | HR: `GET /employees?filter[missing]=tin`, `bank`, `emergency_contact`, `phone`; export with the same filter; SUP asks for `tin` | Only current staff (hired, probation, confirmed) lacking that item; the count equals the matching Next Steps item (NXT-003) and the export has the same rows. `tin` and `bank` need `employee.updateFinancial` (SUP 403). An unknown value is ignored (full list) |

## 4. Import and export

The import is limited to **5 previews and commits per hour per organisation** (the template does not
count). Plan the cases so that a sixth call is the rate-limit case itself.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| IMP-E-001 | P2 | R | Template | Employees → Import → template | Headers: `name, name_am, employee_code, email, phone, gender, date_of_birth, hire_date, department, branch, position, salary, national_id, tin, badge_number, nationality, nationality_status, marital_status` |
| IMP-E-002 | P2 | W | Preview errors | Upload a file with a missing `hire_date` column; one with headers only; one with a department that does not exist; two rows with the same code; one with a department name used twice | "Missing required columns: name, hire_date"; "CSV file must have a header row and at least one data row."; per row: not found, duplicate in file ("is already used on row N of this file"), ambiguous name |
| IMP-E-003 | P2 | W | Amharic and BOM | Upload the UTF-8 CSV with a byte order mark and Amharic names, plus trailing empty `,,,,` rows | Accepted; Amharic intact; empty rows ignored |
| IMP-E-004 | P1 | W | Commit | Commit 5 valid rows | 201 with `created`, `skipped`, `matched`, `users_created`, `invitations_batch`, `invitations_queued`, `in_person`, `errors`; the 5 people appear, none missing |
| IMP-E-005 | P2 | W | Idempotent | Commit the same rows with the same import key | All skipped; no duplicates |
| IMP-E-006 | P2 | W | Identity match | Commit a row whose email or phone or national ID belongs to an existing person, under a new key | Counted `matched`, not created |
| IMP-E-007 | P3 | R | Lost response | `GET /employees/import/status/{key}` for a committed key and an unknown one | 200 `{status: "completed", imported: n}`; 404 for unknown |
| IMP-E-008 | P2 | R | Size | Upload a CSV over the limit | Refused before upload with the limit named (5 MB, or the host's 2 MB if lower) |
| IMP-E-009 | P2 | R | Rate limit | Sixth preview within the hour | 429 with `Retry-After` |
| IMP-E-010 | P1 | R | Export | Export the list with a department filter | CSV with the import columns; department, branch and position by name; **no salary, national ID or TIN**; same filter and sort as the screen |
| IMP-E-011 | P2 | W | Round trip | Import the exported file under a new key | Every row `matched` (or created where missing); nothing duplicated |
| IMP-E-012 | P2 | R | Spreadsheet injection | Create an employee named `=1+1`; export; open in a spreadsheet | The cell shows the text, not a formula |
| IMP-E-013 | P2 | W | Plan limit on import | On Starter with 8 employees, import 5 | Refused before any row is created |
| IMP-E-014 | P2 | X | Import with logins | Import 3 rows with team addresses and 1 with no email or phone, "create logins" ticked | The hint reads "People with an email get an invitation, sent a few a minute. People without one are activated in person. People already in ETHR are not invited again." The commit returns in seconds with `invitations_queued: 3`, `in_person: 1`; the result shows the INV-002 progress card and "1 person has no email. Activate them in person from their employee page." The 3 emails arrive after the next drain (within 10 minutes). Counts toward the 5 an hour |
| IMP-E-015 | P2 | W | Ethiopian dates in the file (#624, #558) | On the upload step read "Dates in the file are in"; choose "Ethiopian (E.C.)" and preview a 1-row file with `hire_date` `15/04/2016`; preview the same file with "Gregorian (G.C.)" (the default) | Ethiopian: the preview shows the hire date as 25 December 2023 (Tahsas 15, 2016), and that is what a commit stores. Gregorian: 15 April 2016. The column hint reads "2024-03-15 or 15/03/2024, day first, in the calendar chosen above". Each preview counts toward the 5 an hour |
| IMP-E-016 | P2 | R | Pay data in a file needs pay rights (#624, #560) | As the "QA Pay-less HR" login: preview and commit a 1-row file that sets `salary` (or `tin`); then the same row without either | With salary or TIN: the commit is refused with 403 and nobody is created. Without them: created. Before #560 the file wrote salary and TIN with only the create permission |
| IMP-E-017 | P2 | W | Two commits at the last free seats (#679) | QA Isolation on Starter (10 employees) holding 9. Preview two different 1-row files, then commit both at the same moment (two tabs, or `curl … & curl …`, with different import keys). Afterwards delete what was created | One commit creates its person; the other: 403 "You have reached the employee limit for your plan.", nobody created. QA Isolation holds 10, never 11. A writer that waits more than 20 seconds for the other gets 409 "Another change to your headcount is being saved. Try again in a moment." instead. The same seat lock covers Add employee, SCIM and SSO sign-up, the device migration and a hire (REC-009). Two previews and two commits count toward the 5 an hour |

## 5. Employee lifecycle

Status transitions allowed: hired → probation or confirmed; probation → confirmed or terminated;
confirmed → suspended, resigned, terminated or retired; suspended → confirmed or terminated.
Resigned, terminated and retired are final.

A permanent personnel action (appointment, promotion, demotion, transfer, re-designation, salary
step increment, reassignment) dated **after today, Addis Ababa time**, is held until that date.
Acting assignment, delegation and secondment are never held. `DELETE
/employees/{employee}/personnel-actions/{personnelAction}` cancels a held action
(`personnel_action.create`).

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LIFE-001 | P1 | W | Allowed transitions | Walk one test employee through hired → probation → confirmed → suspended → confirmed | Each saved with an effective date, shown in the history |
| LIFE-002 | P2 | W | Forbidden transitions | Try hired → retired; resigned → confirmed | "Cannot change status from :from to :to." |
| LIFE-003 | P1 | W | Leaving | Resign a test employee with a last day of tomorrow | Exit clearance starts by itself ([06, section 6](06-E2E-LEAVE-AND-APPROVALS.md#6-exit-clearance)). After the last day: their login stops working (401 `account-inactive`), attendance after that day is refused ("This employee has left…"), they still appear in that period's payroll |
| LIFE-004 | P2 | W | Personnel actions | Record a promotion, a transfer, a salary step increment, each effective today or earlier | Applied at once and in the history. Acting assignment, delegation and secondment without an end date: "Acting, delegation and secondment actions must have an end date."; end before start refused; salary step outside 1 to 100 refused. A future date is held instead (LIFE-009) |
| LIFE-005 | P2 | W | Contracts | Add a fixed-term contract with salary 15,000.00 from the first of the month, ending in 20 days | Listed; appears on Employees → Contracts ending (30-day window); renewing and ending work. **Check payroll uses this salary** when the employee record has none ([07, PAY-020](07-E2E-PAYROLL.md)). A contract salary that differs from the record needs the pay permission (03, ACC-022) |
| LIFE-006 | P2 | R | Contracts ending list | `GET /employees/contracts/expiring?days=30` | Active contracts ending within 30 days, plus active contracts already past their end (K-11, by design). The Next Steps item says ":count contracts end within 30 days or have ended" (NXT-003) |
| LIFE-007 | P2 | X | Expiry reminders | A contract ending in 7 days | Reminders 30, 7 and 1 days before (daily at 06:05 UTC); probation reminders 14 and 1 days before |
| LIFE-008 | P3 | R | Attendance timeline | Employee → attendance timeline | The person's punches by day |
| LIFE-009 | P2 | W | A future promotion is held | HR records a promotion for a test employee to G1 step 2, effective the 1st of next month | 201, `is_scheduled: true`. History shows "Scheduled", "Takes effect on :date. The employee record is unchanged until then." and "Cancel change". Grade, position and salary unchanged; a payroll run this month uses the old salary |
| LIFE-010 | P2 | W | One scheduled change per employee | With LIFE-009 pending: a transfer effective today; another promotion dated next month; an acting assignment with an end date | Transfer and promotion: 422 on `effective_date`, "This employee already has a change scheduled for YYYY-MM-DD. Let it take effect or cancel it before scheduling another." The acting assignment is saved |
| LIFE-011 | P1 | W | Cancel a scheduled change | "Cancel change" ("Cancel this scheduled change?", "Keep it" / "Cancel change"); then cancel an applied action through the API; SUP tries to cancel | 204, "Scheduled change cancelled", row gone, audit `employee.personnel_action_cancelled`. Applied: 422 "Only a scheduled change can be cancelled. This one has already taken effect." SUP: 403. While impersonating: 403 `impersonation-restricted` |
| LIFE-012 | P2 | W | Applied on its date | Schedule a change dated tomorrow; check after 00:05 EAT | The job runs hourly at :05 UTC per organisation (drained by the cron caller). Within about an hour of midnight EAT the new values are on the record, "Scheduled" is gone, audit `employee.personnel_action_applied`. A missed run is caught up by the next |
| LIFE-013 | P3 | W | Today or earlier applies at once | A salary step increment dated today, and one dated last week (different employees) | Both applied immediately; neither shows "Scheduled" |

## 6. Employee records: documents, bank, education, contacts

Every stored file (documents, profile and employee photos, thumbnails) is opened through a signed
link under `/api/files/`, valid 15 minutes (#625, #612). Anyone holding the link can open it until it
expires, so never paste one into a defect or log (01, section 9, rule 3).

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| DOC-001 | P1 | W | Upload | HR uploads a 1 MB PDF as `contract` with an expiry date next year; views it | Saved; view opens a link under `/api/files/` valid 15 minutes, and the file is saved as a download (200, `Content-Disposition: attachment`, since #679), never a 403 and never shown as a page in the tab; each view is audited (`employee.document.viewed`) |
| DOC-002 | P1 | W | Types and size | Upload `.exe` renamed `.pdf`; a 2.5 MB PDF on production; a `.png`, `.docx`, `.txt` | The disguised file refused (contents checked, not the name). The 2.5 MB file refused with the limit named (host limit, 2 MB, K-16). Allowed types: pdf, jpg, jpeg, png, webp, doc, docx, txt up to 10 MB where the host allows |
| DOC-003 | P1 | R | Sensitive types | Upload a `medical` and an `id_copy`; SUP opens the employee | Those two are hidden from users without update rights, and opening them directly gives 404 |
| DOC-004 | P2 | R | Expiry watchlist | `GET /employees/documents/expiring?days=30` | Includes documents already expired; days clamped to 1 to 365; `per_page` up to 100 |
| DOC-005 | P3 | W | Delete | Delete a document | Soft-deleted; gone from the list |
| DOC-006 | P1 | W | Bank details | HR adds two bank accounts, sets the second as primary | Only one primary; the API returns the number masked (all but the last 4 as `*`). Viewing needs `employee.viewFinancial`; writing needs `employee.updateFinancial` |
| DOC-007 | P3 | W | Education | Add a degree; end date before start | Saved; then refused |
| DOC-008 | P3 | W | Emergency contacts | Add one with priority 1; priority 11 | Saved; then refused (1 to 10) |
| DOC-009 | P1 | R | Child records belong to the employee | Through the API, open EMP's bank detail id under EMP2's URL (`/employees/{EMP2}/bank-details/{EMP's id}`) | 404, never EMP's data |
| DOC-010 | P3 | W | Upload rate | More than 30 uploads in a minute | 429 |
| DOC-011 | P2 | R | A signed link and its limits (#625, #612) | From DOC-001, copy the document's link (developer tools, network tab); open it in a private window; change one character of its `signature` and open it; open the original again after 20 minutes. On the Employees list, check a photo and its thumbnail. At most three altered requests in ten minutes (firewall, 01 section 9) | The path starts `/api/files/`. Private window: the file downloads without signing in, while the link is valid (as an attachment, DOC-012). Altered signature: 404. After 20 minutes: 404. Photos and thumbnails load; none answers 403 (I-06 in the A-Z review was every one refused on production before #612) |
| DOC-012 | P1 | W | A stored file keeps its real type, and downloads (#679) | Save a plain-text file containing only `QA plain text, not a page` as `qa-note.html`; HR uploads it to a `QA-` employee as a document of an allowed type (it passes as `.txt` content). View it with the network tab open; `curl -sI` the link. Then do the same for the DOC-001 PDF, a document uploaded before the release, and a profile photo's link | The upload is accepted (plain text) but stored as `<id>.txt`, never `.html`: the link's response is `Content-Type: text/plain`, `Content-Disposition: attachment; filename=…txt`, `X-Content-Type-Options: nosniff`, and the browser saves it, never renders it. Every other stored file answers `attachment` and `nosniff` too, including one stored before the release; photos and thumbnails still show in the page (an `<img>` ignores `attachment`). A file whose type is not on the list is stored as `.bin`. Before #679 the name the browser sent decided the extension, so this file was served back as `text/html` on the API's address |

## 7. Self-service profile

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PRO-001 | P2 | W | Immediate fields | EMP changes phone, marital status, nationality | Saved at once |
| PRO-002 | P1 | W | Approval-gated fields | EMP changes name, Amharic name, TIN, date of birth, bank name, bank account | Shown "Pending approval"; not applied until HR approves |
| PRO-003 | P2 | W | HR review | HR approves one, rejects another | Applied; rejected one unchanged, EMP sees the outcome |
| PRO-004 | P1 | R | No self-review | HR changes their own bank account and opens the queue | "You cannot review a change to your own profile." HR who filed a change for someone else cannot review it either: "You filed this change, so someone else must review it." |
| PRO-005 | P2 | W | Supersede and no-op | Submit a new bank account twice; submit the value already on record | The first pending request is superseded; the no-change value is dropped |
| PRO-006 | P3 | W | Withdraw | EMP withdraws a pending request | Gone; a reviewed one cannot be withdrawn |
| PRO-007 | P1 | W | Photo | Upload a profile photo (a 3 MB JPEG) | The browser shrinks it and it saves; EXIF (location) is removed from the stored image; the photo shows through a `/api/files/` link (DOC-011) |
| PRO-008 | P3 | W | Own emergency contacts | Add, edit, delete | Works; same rules as DOC-008 |
| PRO-009 | P2 | R | Own documents | Profile → documents | What HR filed against EMP, read-only |
| PRO-010 | P2 | W | Preferences | Profile → preferences: interface language, calendar, time of day, theme | Languages English, Amharic and Afaan Oromoo can be chosen; Tigrinya, Somali and Sidama are shown as coming soon and cannot. Calendar Ethiopian or Gregorian; time of day as in 09 (L10N-017); theme system, light, dark, high contrast. "Preference saved.", applied at once, and kept on the next sign-in and on another device |
| PRO-011 | P2 | R | Deciding a pay field needs pay rights (#624, #560) | EMP submits a new bank account; the "QA Pay-less HR" login opens the review queue and tries to approve it; then a name change from EMP | Bank account: refused, "Deciding a change to a TIN or bank account needs permission to edit pay data.", still pending. The name change can be decided. HR, who holds the pay permission, can approve the bank change |

## 8. Directory

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| DIR-001 | P2 | R | Search colleagues | EMP: Directory, search a name, email, phone; filter by department | Hired, probation and confirmed staff only; suspended and leavers not shown; 25 per page |
| DIR-002 | P1 | R | Only own organisation | Search for a QA Isolation employee's name | Not found |
| DIR-003 | P1 | R | No private data | Inspect the directory API response | No salary, date of birth, TIN, national ID or bank data |

## 9. Recruitment: applicants to hire

`GET|POST /vacancies/{vacancy}/applications`, `GET /job-applications/{application}`, `POST
/job-applications/{application}/stage|events|approve-offer|hire|erase`. Every route needs
`recruitment.manage` (HR, TA). Approving an offer also needs `recruitment.approve`, by someone other
than the person who made it. Hiring also needs `employee.create`. An offered salary needs
`employee.updateFinancial` to set and `employee.viewFinancial` to see. No plan feature gates
recruitment, but a hire counts against the employee limit. There is no public application form: HR
records each applicant. Use the VAC-001 vacancy (2 positions).

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| REC-001 | P2 | W | Record an applicant | HR: Vacancies → "Applicants" → "Add applicant": name, an email the team controls, phone `0911 223344`, source "In person". Then no name; then source `facebook` (API) | 201, stage `applied`, phone stored `+251911223344`, history "Application recorded". The form warns "Record only what the selection needs. Do not record health, religion, ethnicity or political views." No name refused. Bad source: "The selected source is invalid." (email, walk_in, referral, internal, agency, website, other) |
| REC-002 | P2 | W | Stage flow | applied → screening → interview; then applied → offer, `stage: hired`, reject without a note | Allowed moves saved in history. applied → offer: "An application cannot move from applied to offer." `hired`: "The selected stage is invalid." Reject or withdraw without a note: "The note field is required." Hired, rejected and withdrawn are final |
| REC-003 | P3 | W | Notes and interviews | Add a note; an interview with a time and score 85; score 101; a score on a plain note | Appended to history, never edited. 101 refused. Score on a note: "The score field is prohibited." |
| REC-004 | P2 | W | Make an offer | "Make an offer": start next Monday, salary 12,000.00, terms. Then a start date yesterday | Stage `offer`, "Waiting for approval by someone other than :name". A past start date is refused |
| REC-005 | P1 | R | Offer approval is separated | The HR user who made the offer clicks "Approve offer"; SUP calls approve-offer; approve an application not at the offer stage | Maker: 422 on `approver`, "Someone other than the person who made the offer must approve it." SUP: 403. Not at offer: "Only an application at the offer stage has an offer to approve." |
| REC-006 | P2 | W | Approve | TA approves the offer, then again | "Approved by :name on :date". Second call 200, nothing changes |
| REC-007 | P1 | W | Hire | Hire before approval (another application). On the approved one: status probation with no end date; probation end before start; a plain hire; send the hire twice | Before approval: "Hire from an approved offer. Make the offer and have it approved first." No end date refused. End before start: "The probation end date must be after the start date." Hire: 201, ":name is now an employee": next number, the vacancy's department and branch, the offered salary, status `hired`, hire date = offer start; shown "Hired as :name (:code)". Audit `employee.created` (source recruitment); the `employee.created` webhook fires if registered. No login created. Second call: 200, same employee, `meta.was_duplicate: true` |
| REC-008 | P2 | W | Same person as a current employee | Add an applicant with EMP's email; take them to an approved offer and hire | Detail: "Same e-mail or phone found", "This may or may not be the same person. Nothing is merged." Hire: 422 "A current employee has the same e-mail or phone. Check whether this is the same person before hiring." A former (resigned) employee's details are not refused |
| REC-009 | P2 | W | Positions and plan limit (#624, #606) | After 2 hires on the 2-position vacancy, hire a third approved offer. In QA Isolation on Starter at its limit, hire one | "All 2 positions on this vacancy are filled. Raise the number of positions to hire more." The count is now taken under a lock, so two hires at the same instant cannot over-fill it (08, VAC-004). Starter: 403 "You have reached the employee limit for your plan." |
| REC-010 | P1 | R | Pay data and isolation | A custom role with `recruitment.manage` but no financial permissions opens an offer and sets a salary; request a QA Isolation application id; EMP opens Vacancies | Salary null; setting one 403. Other organisation's id: 404. EMP: no "Applicants", API 403 |
| REC-011 | P2 | W | Erase personal details | Erase an open application. Reject one with a note → "Erase personal details" → add a note | Open: "Only a rejected or withdrawn application can be erased." Erased: name "Erased applicant", contact details and notes gone, stages and dates kept. A new note: "This application was erased." No longer offered as a possible duplicate |

## 10. Logins for employees brought in from a device

A device migration (the onboarding step, or Devices → a device's enrolments → "Bring these people into
ETHR", which opens `/employees/from-device?device=…`) creates employees and no logins. After the commit,
"Give them a way to sign in" lists who still cannot sign in. MIG-001 to MIG-004 need the owner's test
device (05, section 14); without one, mark them Blocked and run MIG-005 to MIG-009 on employees added
without a login. `POST /employees/logins` needs both `users.invite` and `employee.update`.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| MIG-001 | P2 | W | The step after a commit | Commit a device migration; read the next step | "Give them a way to sign in", "Check the names first. People with an email get an invitation by email. People without one are listed for an in-person code, which you issue from their profile." Columns Name, Contact ("None on file" when empty) and Invitation ("Email", "Text message", "In person"); "invited, not yet activated" beside anyone already invited. "Continue" moves on. "Everyone brought in can already sign in." when nobody is left |
| MIG-002 | P2 | R | Hire dates given today | Same batch | "Check the hire dates": ":count people were given today as their hire date, because the source did not have one. Leave and severance are counted from it, so correct it on each profile." A device gives none, so every person created counts; merged people do not. No date is changed |
| MIG-003 | P2 | X | Send invitations | "Send invitations" (team addresses only) | "Logins created: :created. Invitations queued: :queued. To activate in person: :in_person.", then the INV-002 progress card. Nothing is sent in the request. "Email already used by another login: :count." when an address belongs to another login. The button is absent for a role without `users.invite` |
| MIG-004 | P3 | R | Opened from a device | Devices → enrolments of the test device → "Bring these people into ETHR"; then "Devices". `GET /onboarding/migration/batches/{batch}/logins` | The migration opens with that device chosen; "Devices" returns. The API gives `without_login` and `hire_dates_defaulted`, and needs `employee.viewAny`. SUP and FIN cannot open the page |
| MIG-005 | P2 | X | Logins for several people | HR: `POST /employees/logins` with the ids of an employee with a team address and no login, one with no email or phone, one active (EMP), one invited more than 10 minutes ago | 202 "Invitations are being sent.", `created: 1`, `active: 1`, `in_person: 1`, `email_in_use: 0`, `queued: 2` (the new login and the re-invited one), a `batch`. Audit "Logins created for employees". The no-contact person gets no login yet: the in-person code makes it |
| MIG-006 | P3 | R | Address already taken | An employee with no login whose email belongs to another login of the organisation | `email_in_use: 1`; no login made for them |
| MIG-007 | P1 | R | Another organisation's employee | Include a QA Isolation employee id; separately, a made-up 26-character id | 422 on `employee_ids`, "The selected employee ids is invalid.", identical for both; nothing created |
| MIG-008 | P3 | R | Request rules | An empty list; the same id twice; 1,001 ids | 422 each: at least 1, distinct, at most 1,000 |
| MIG-009 | P1 | R | Who may | SUP, FIN, EMP: `POST /employees/logins` | 403 |
