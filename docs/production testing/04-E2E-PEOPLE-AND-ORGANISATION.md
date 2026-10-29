# E2E specification 04: setup, organisation structure, employees and self-service

Columns, personas and modes: see the [README](README.md#how-a-test-case-is-written). Run in QA Audit
unless a case says otherwise. Section 1 also builds the test data the later documents rely on.

---

## 1. Guided setup and test data build

The setup wizard lives at `/setup` (it redirects to `/setup/guided`) and has four steps:
configuration, people, sign-in, go live. Run it on a newly registered `uat-` organisation (ORG-004)
so that QA Audit's standing set-up is not overwritten; build QA Audit's data with sections 2 and 3.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SET-001 | P1 | W | Industries | Step 1: open the industry list | 36 industries from federal government to custom. Some set an employee number format (for example a ministry gives `MIN-{SEQ:4}`) |
| SET-002 | P1 | W | Preview | Choose an industry, workforce profile (standard office, six-day week, flexible hybrid, rotating three shift, extended hours two shift, part time, field distributed, seasonal agricultural, weekend) and policy profile (Ethiopia private, Ethiopia public, custom); employee count 50; preview | A proposed set of branches, departments, positions, grades, shifts, leave types, rotations and allowances. Employee count outside 1 to 1,000,000 is refused |
| SET-003 | P1 | W | Apply | Apply the preview | Everything is created in one go. If anything fails, nothing is created (single transaction). Re-applying does not duplicate |
| SET-004 | P2 | W | Access step | Choose sign-in identifiers (at least one of email, phone, employee code, username) | Saved; with none chosen, refused. Sign-in then accepts exactly those (AUTH-004) |
| SET-005 | P1 | W | People step | Stage 5 rows by hand or CSV; set one row to merge, one to skip, one to defer; commit | Created, merged, skipped and deferred as chosen; at most 2,000 rows per batch |
| SET-005a | P3 | W | People from a device | Only with a test device registered (DEV-002): stage people from the device's user list | Rows staged from the device's users for review before commit; nothing created until commit |
| SET-006 | P1 | R | Readiness | Open the go-live step | A score from the weighted checklist (branch 2, departments 1, positions 1, shifts 2, leave types 1, holidays 1, employees 3, employees in a department 1, device 1, an admin exists 3, an admin is active 2). Ready at 90 or more, needs attention at 60 or more, otherwise not ready |
| SET-007 | P2 | W | Go live | Go live with a low score | Allowed (the score advises, it does not block): "Organization is live.", redirected to `/dashboard`, audited `onboarding.went_live`. Needs `settings.manage` |

**QA Audit data build** (do once, then record the public ids in the execution log):

1. Branches B1 and B2 (ORG-S-001), departments D1 and D2 (ORG-S-003), position P1, grade G1 with
   steps 1 to 3 (ORG-S-005).
2. Shifts S1 and S2 ([05, section 9](05-E2E-ATTENDANCE-SHIFTS-DEVICES.md#9-shifts-assignments-rotations-roster)).
3. Persona employees and logins (EMP-001), golden payslip employees E-PAY-1 to E-PAY-7
   ([07, section 2](07-E2E-PAYROLL.md#2-golden-payslips)).
4. Holidays auto-detected (HOL-001).

## 2. Organisation structure

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| ORG-S-001 | P1 | W | Branch | HR: Organization → Branches → add "QA Head Office", code `QAHQ`, latitude and longitude of the office, geofence 200 m | Saved. Name under 2 characters, code over 20, latitude outside -90 to 90, or radius outside 10 to 10,000 m is refused |
| ORG-S-002 | P2 | W | Branch plan limit | On Starter (QA Isolation), add a second branch | 403 branch limit (PLAN-003) |
| ORG-S-003 | P1 | W | Departments and tree | Add D1 and a child department under it; view the tree | Tree shows the hierarchy |
| ORG-S-004 | P1 | W | Delete a staffed unit | Delete D1 while employees are in it | 409 `org-unit-in-use`, "This department cannot be deleted: N current employees are assigned to it. Move them first, or mark it inactive." Same for a branch or position |
| ORG-S-005 | P1 | W | Grades and steps | Grade G1 min 5,000, max 15,000; steps 1 to 3 at 6,000, 7,000, 8,000 | Saved. A max below min, a step outside the range, a duplicate step number, or a higher step paying less is refused |
| ORG-S-006 | P2 | W | Teams, positions, cost centres | Create one of each | Saved; names 2 to 255 characters |
| ORG-S-007 | P2 | W | Department head | Set a department's head employee | Used by "department head" approval steps ([06](06-E2E-LEAVE-AND-APPROVALS.md)) |
| ORG-S-008 | P2 | R | Reporting tree | Organization → reporting tree | SUP with EMP and EMP2 beneath; people whose supervisor was removed appear at the top level, not lost |
| ORG-S-009 | P3 | R | Permissions | EMP opens the organisation page | Can view (everyone holds `org.view`); cannot create or edit |

## 3. Employees

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| EMP-001 | P1 | W | Create with the minimum | HR: Employees → Add; name and hire date only | Saved with status `hired` and the next number (default format `EMP-0001`). The number shown before saving is a preview: two people adding at once may see the same one, and the second save takes the next |
| EMP-002 | P1 | W | Create in full | Name, Amharic name, email, phone `0911 223344`, gender, date of birth, hire date, department, branch, position, grade, supervisor SUP, salary 9,000.00, TIN, national ID, badge number | Saved. Phone stored as `+251911223344`. The response has no TIN or national ID, ever |
| EMP-003 | P1 | W | Validation | Hire date more than a year ahead; probation end before hire date; employee aged 14 on the hire date; duplicate employee code; badge with a space; phone `12` | Each refused with the field named. Age: "The employee must be at least 15 years old on the hire date." |
| EMP-004 | P1 | W | Create a login | Tick "create a login", role `employee`, email the team controls | Invitation sent (PWD-009); `meta.login_invited` true. If an account with that email already exists and is unlinked, it is linked instead of duplicated |
| EMP-005 | P1 | R | Role clamp | HR creates an employee with login role `tenant_admin` | Clamped: never above HR's own level, never `super_admin` |
| EMP-006 | P1 | R | Salary needs financial rights | SUP or a custom role without `employee.updateFinancial` sets a salary or TIN | Refused; sending the unchanged value back is not refused |
| EMP-007 | P1 | R | Cross-tenant relation ids | Through the API, create an employee with a `department_id` (public id) from QA Isolation | 422 with the same generic message as a non-existent id. Repeat for branch, position, grade, team, cost centre, supervisor |
| EMP-008 | P2 | R | Own supervisor | Set an employee as their own supervisor | "An employee cannot be their own supervisor." |
| EMP-009 | P1 | R | List, search, filter | Employees list: search by name, code, email, phone; filter by department and status; sort; page through | Results correct; 25 per page by default, `per_page` above 100 refused or capped |
| EMP-010 | P1 | R | Employee number format | TA: Settings → employee number format `QA-{SEQ:5}`; add an employee | Number `QA-00001` style. An invalid format (two `{SEQ}` tokens, `{SEQ:9}`) gives "Use one {SEQ:n} token, n from 1 to 8…" |
| EMP-011 | P2 | W | Soft delete | TA deletes a test employee | Removed from lists, still present in history, payroll and audit (never hard-deleted); HR cannot delete (`employee.delete` is tenant admin) |
| EMP-012 | P2 | W | Bulk update | Select 3 employees, change department | Each shows updated, unchanged or failed. More than 200 refused; including someone outside your reach rejects the whole request (422); no field given: "At least one field to update must be provided."; a status change also needs `employee.transition` |
| EMP-013 | P2 | R | Statistics | Employees page summary cards | Counts agree with the list |
| EMP-014 | P1 | W | A login with nothing to send | HR: Add employee, name and hire date only, no email, no phone; tick the login box; save. This is **E-NC** for [03, section 5a](03-E2E-AUTH-TENANCY-ACCESS.md#5a-in-person-activation-no-email-no-phone) | The box reads "Create a login to activate in person", hint "With no email or phone, nothing can be sent. HR activates the login face to face with a one-time code from their profile." 201, `meta.activation_code_pending: true`, `login_invited: false`; no email or SMS. The login is always role `employee`. The profile offers "Activate in person" (ACT-001) |
| EMP-015 | P2 | R | Phone but no email | Type a phone and no email; through the API create one with `create_login: true` | Form: the box is disabled, "Add an email to send a login invitation." API: saved with `login_invited: false` and `activation_code_pending: false`, **no login created**, and an in-person code is refused for them (ACT-008). Record it (K-20) |
| EMP-016 | P2 | R | Records to fix | HR: `GET /employees?filter[missing]=tin`, `bank`, `emergency_contact`, `phone`; export with the same filter; SUP asks for `tin` | Only current staff (hired, probation, confirmed) lacking that item; the count equals the matching Next Steps item (NXT-003) and the export has the same rows. `tin` and `bank` need `employee.updateFinancial` (SUP 403). An unknown value is ignored (full list) |

## 4. Import and export

The import is limited to **5 previews and commits per hour per organisation** (the template does not
count). Plan the cases so that a sixth call is the rate-limit case itself.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| IMP-E-001 | P1 | R | Template | Employees → Import → template | Headers: `name, name_am, employee_code, email, phone, gender, date_of_birth, hire_date, department, branch, position, salary, national_id, tin, badge_number, nationality, nationality_status, marital_status` |
| IMP-E-002 | P1 | W | Preview errors | Upload a file with a missing `hire_date` column; one with headers only; one with a department that does not exist; two rows with the same code; one with a department name used twice | "Missing required columns: name, hire_date"; "CSV file must have a header row and at least one data row."; per row: not found, duplicate in file ("is already used on row N of this file"), ambiguous name |
| IMP-E-003 | P1 | W | Amharic and BOM | Upload the UTF-8 CSV with a byte order mark and Amharic names, plus trailing empty `,,,,` rows | Accepted; Amharic intact; empty rows ignored |
| IMP-E-004 | P1 | W | Commit | Commit 5 valid rows | 201 with `created`, `skipped`, `matched`, `users_created`, `errors`; the 5 people appear |
| IMP-E-005 | P1 | W | Idempotent | Commit the same rows with the same import key | All skipped; no duplicates |
| IMP-E-006 | P1 | W | Identity match | Commit a row whose email or phone or national ID belongs to an existing person, under a new key | Counted `matched`, not created |
| IMP-E-007 | P2 | R | Lost response | `GET /employees/import/status/{key}` for a committed key and an unknown one | 200 `{status: "completed", imported: n}`; 404 for unknown |
| IMP-E-008 | P2 | R | Size | Upload a CSV over the limit | Refused before upload with the limit named (5 MB, or the host's 2 MB if lower) |
| IMP-E-009 | P2 | R | Rate limit | Sixth preview within the hour | 429 with `Retry-After` |
| IMP-E-010 | P1 | R | Export | Export the list with a department filter | CSV with the import columns; department, branch and position by name; **no salary, national ID or TIN**; same filter and sort as the screen |
| IMP-E-011 | P2 | W | Round trip | Import the exported file under a new key | Every row `matched` (or created where missing); nothing duplicated |
| IMP-E-012 | P2 | R | Spreadsheet injection | Create an employee named `=1+1`; export; open in a spreadsheet | The cell shows the text, not a formula |
| IMP-E-013 | P2 | W | Plan limit on import | On Starter with 8 employees, import 5 | Refused before any row is created |

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
| LIFE-002 | P1 | W | Forbidden transitions | Try hired → retired; resigned → confirmed | "Cannot change status from :from to :to." |
| LIFE-003 | P1 | W | Leaving | Resign a test employee with a last day of tomorrow | Exit clearance starts by itself ([06, section 6](06-E2E-LEAVE-AND-APPROVALS.md#6-exit-clearance)). After the last day: their login stops working (401 `account-inactive`), attendance after that day is refused ("This employee has left…"), they still appear in that period's payroll |
| LIFE-004 | P1 | W | Personnel actions | Record a promotion, a transfer, a salary step increment, each effective today or earlier | Applied at once and in the history. Acting assignment, delegation and secondment without an end date: "Acting, delegation and secondment actions must have an end date."; end before start refused; salary step outside 1 to 100 refused. A future date is held instead (LIFE-009) |
| LIFE-005 | P1 | W | Contracts | Add a fixed-term contract with salary 15,000.00 from the first of the month, ending in 20 days | Listed; appears on Employees → Contracts ending (30-day window); renewing and ending work. **Check payroll uses this salary** when the employee record has none ([07, PAY-020](07-E2E-PAYROLL.md)) |
| LIFE-006 | P2 | R | Contracts ending list | `GET /employees/contracts/expiring?days=30` | Active contracts ending within 30 days, plus active contracts already past their end. Intended: the Next Steps item says "end within 30 days or have ended" (NXT-003) |
| LIFE-007 | P2 | X | Expiry reminders | A contract ending in 7 days | Reminders 30, 7 and 1 days before (daily at 06:05 UTC); probation reminders 14 and 1 days before |
| LIFE-008 | P2 | R | Attendance timeline | Employee → attendance timeline | The person's punches by day |
| LIFE-009 | P1 | W | A future promotion is held | HR records a promotion for a test employee to G1 step 2, effective the 1st of next month | 201, `is_scheduled: true`. History shows "Scheduled", "Takes effect on :date. The employee record is unchanged until then." and "Cancel change". Grade, position and salary unchanged; a payroll run this month uses the old salary |
| LIFE-010 | P1 | W | One scheduled change per employee | With LIFE-009 pending: a transfer effective today; another promotion dated next month; an acting assignment with an end date | Transfer and promotion: 422 on `effective_date`, "This employee already has a change scheduled for YYYY-MM-DD. Let it take effect or cancel it before scheduling another." The acting assignment is saved |
| LIFE-011 | P1 | W | Cancel a scheduled change | "Cancel change" ("Cancel this scheduled change?", "Keep it" / "Cancel change"); then cancel an applied action through the API; SUP tries to cancel | 204, "Scheduled change cancelled", row gone, audit `employee.personnel_action_cancelled`. Applied: 422 "Only a scheduled change can be cancelled. This one has already taken effect." SUP: 403. While impersonating: 403 `impersonation-restricted` |
| LIFE-012 | P1 | W | Applied on its date | Schedule a change dated tomorrow; check after 00:05 EAT | The job runs hourly at :05 UTC per organisation (drained by the cron caller). Within about an hour of midnight EAT the new values are on the record, "Scheduled" is gone, audit `employee.personnel_action_applied`. A missed run is caught up by the next |
| LIFE-013 | P2 | W | Today or earlier applies at once | A salary step increment dated today, and one dated last week (different employees) | Both applied immediately; neither shows "Scheduled" |

## 6. Employee records: documents, bank, education, contacts

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| DOC-001 | P1 | W | Upload | HR uploads a 1 MB PDF as `contract` with an expiry date next year | Saved; view opens a link valid 15 minutes; each view is audited |
| DOC-002 | P1 | W | Types and size | Upload `.exe` renamed `.pdf`; a 2.5 MB PDF on production; a `.png`, `.docx`, `.txt` | The disguised file refused (contents checked, not the name). The 2.5 MB file refused with the limit named (host limit). Allowed types: pdf, jpg, jpeg, png, webp, doc, docx, txt up to 10 MB where the host allows |
| DOC-003 | P1 | R | Sensitive types | Upload a `medical` and an `id_copy`; SUP opens the employee | Those two are hidden from users without update rights, and opening them directly gives 404 |
| DOC-004 | P2 | R | Expiry watchlist | `GET /employees/documents/expiring?days=30` | Includes documents already expired; days clamped to 1 to 365; `per_page` up to 100 |
| DOC-005 | P2 | W | Delete | Delete a document | Soft-deleted; gone from the list |
| DOC-006 | P1 | W | Bank details | HR adds two bank accounts, sets the second as primary | Only one primary; the API returns the number masked (all but the last 4 as `*`). Viewing needs `employee.viewFinancial`; writing needs `employee.updateFinancial` |
| DOC-007 | P2 | W | Education | Add a degree; end date before start | Saved; then refused |
| DOC-008 | P2 | W | Emergency contacts | Add one with priority 1; priority 11 | Saved; then refused (1 to 10) |
| DOC-009 | P1 | R | Child records belong to the employee | Through the API, open EMP's bank detail id under EMP2's URL (`/employees/{EMP2}/bank-details/{EMP's id}`) | 404, never EMP's data |
| DOC-010 | P2 | W | Upload rate | More than 30 uploads in a minute | 429 |

## 7. Self-service profile

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PRO-001 | P1 | W | Immediate fields | EMP changes phone, marital status, nationality | Saved at once |
| PRO-002 | P1 | W | Approval-gated fields | EMP changes name, Amharic name, TIN, date of birth, bank name, bank account | Shown "Pending approval"; not applied until HR approves |
| PRO-003 | P1 | W | HR review | HR approves one, rejects another | Applied; rejected one unchanged, EMP sees the outcome |
| PRO-004 | P1 | R | No self-review | HR changes their own bank account and opens the queue | "You cannot review a change to your own profile." HR who filed a change for someone else cannot review it either: "You filed this change, so someone else must review it." |
| PRO-005 | P2 | W | Supersede and no-op | Submit a new bank account twice; submit the value already on record | The first pending request is superseded; the no-change value is dropped |
| PRO-006 | P2 | W | Withdraw | EMP withdraws a pending request | Gone; a reviewed one cannot be withdrawn |
| PRO-007 | P1 | W | Photo | Upload a profile photo (a 3 MB JPEG) | The browser shrinks it and it saves; EXIF (location) is removed from the stored image |
| PRO-008 | P2 | W | Own emergency contacts | Add, edit, delete | Works; same rules as DOC-008 |
| PRO-009 | P2 | R | Own documents | Profile → documents | What HR filed against EMP, read-only |
| PRO-010 | P2 | W | Preferences | Language, calendar (Gregorian, Ethiopian, dual), theme (light, dark, system, high contrast) | Saved and applied on the next sign-in and on another device |

## 8. Directory

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| DIR-001 | P1 | R | Search colleagues | EMP: Directory, search a name, email, phone; filter by department | Hired, probation and confirmed staff only; suspended and leavers not shown; 25 per page |
| DIR-002 | P1 | R | Only own organisation | Search for a QA Isolation employee's name | Not found |
| DIR-003 | P2 | R | No private data | Inspect the directory API response | No salary, date of birth, TIN, national ID or bank data |

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
| REC-001 | P1 | W | Record an applicant | HR: Vacancies → "Applicants" → "Add applicant": name, an email the team controls, phone `0911 223344`, source "In person". Then no name; then source `facebook` (API) | 201, stage `applied`, phone stored `+251911223344`, history "Application recorded". The form warns "Record only what the selection needs. Do not record health, religion, ethnicity or political views." No name refused. Bad source: "The selected source is invalid." (email, walk_in, referral, internal, agency, website, other) |
| REC-002 | P1 | W | Stage flow | applied → screening → interview; then applied → offer, `stage: hired`, reject without a note | Allowed moves saved in history. applied → offer: "An application cannot move from applied to offer." `hired`: "The selected stage is invalid." Reject or withdraw without a note: "The note field is required." Hired, rejected and withdrawn are final |
| REC-003 | P2 | W | Notes and interviews | Add a note; an interview with a time and score 85; score 101; a score on a plain note | Appended to history, never edited. 101 refused. Score on a note: "The score field is prohibited." |
| REC-004 | P1 | W | Make an offer | "Make an offer": start next Monday, salary 12,000.00, terms. Then a start date yesterday | Stage `offer`, "Waiting for approval by someone other than :name". A past start date is refused |
| REC-005 | P1 | R | Offer approval is separated | The HR user who made the offer clicks "Approve offer"; SUP calls approve-offer; approve an application not at the offer stage | Maker: 422 on `approver`, "Someone other than the person who made the offer must approve it." SUP: 403. Not at offer: "Only an application at the offer stage has an offer to approve." |
| REC-006 | P1 | W | Approve | TA approves the offer, then again | "Approved by :name on :date". Second call 200, nothing changes |
| REC-007 | P1 | W | Hire | Hire before approval (another application). On the approved one: status probation with no end date; probation end before start; a plain hire; send the hire twice | Before approval: "Hire from an approved offer. Make the offer and have it approved first." No end date refused. End before start: "The probation end date must be after the start date." Hire: 201, ":name is now an employee": next number, the vacancy's department and branch, the offered salary, status `hired`, hire date = offer start; shown "Hired as :name (:code)". Audit `employee.created` (source recruitment); the `employee.created` webhook fires if registered. No login created. Second call: 200, same employee, `meta.was_duplicate: true` |
| REC-008 | P1 | W | Same person as a current employee | Add an applicant with EMP's email; take them to an approved offer and hire | Detail: "Same e-mail or phone found", "This may or may not be the same person. Nothing is merged." Hire: 422 "A current employee has the same e-mail or phone. Check whether this is the same person before hiring." A former (resigned) employee's details are not refused |
| REC-009 | P1 | W | Positions and plan limit | After 2 hires on the 2-position vacancy, hire a third approved offer. In QA Isolation on Starter at its limit, hire one | "All 2 positions on this vacancy are filled. Raise the number of positions to hire more." Starter: 403 "You have reached the employee limit for your plan." |
| REC-010 | P1 | R | Pay data and isolation | A custom role with `recruitment.manage` but no financial permissions opens an offer and sets a salary; request a QA Isolation application id; EMP opens Vacancies | Salary null; setting one 403. Other organisation's id: 404. EMP: no "Applicants", API 403 |
| REC-011 | P2 | W | Erase personal details | Erase an open application. Reject one with a note → "Erase personal details" → add a note | Open: "Only a rejected or withdrawn application can be erased." Erased: name "Erased applicant", contact details and notes gone, stages and dates kept. A new note: "This application was erased." No longer offered as a possible duplicate |
