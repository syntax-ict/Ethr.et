# ETHR user acceptance testing (UAT) guide

**For:** business users, HR officers, finance officers, managers, employees and the product owner.
**Goal:** confirm that ETHR, as deployed on production, supports the way an Ethiopian organisation
actually works, before the release is accepted.

UAT is not about finding every bug; the test engineer does that with the E2E specifications. UAT asks
one question per scenario: **"Can I do my job with this, and is the result right?"**

**Brought up to date 2026-11-14 (sixth edition), from `main` at `a2404fd3eb`.** The guide was last
changed on 2026-10-29. Since then it gained scenarios for the kiosk (UAT-E14), asking for help
(UAT-E15), people recorded twice and where an id came from (UAT-H14), giving a whole list a first way
in (UAT-H15), attendance terminals and unrecognised badges (UAT-H16), HR changing approved leave
(UAT-H17), monthly reports and printing (UAT-H18), sick leave pay (UAT-F6), a new organisation's
sign-up and set-up (UAT-A8), and the platform's Features page and help requests (UAT-P1 steps 10 and
11). It corrected UAT-E1, E3, S1, H1, H2, H6, F2, F3, F4, A1, A4, A6 and the sign-off sheet where the
product has moved on (each says "Changed 2026-11-14"). A scenario names the detailed E2E cases that
cover the same ground, as `(see 04 IDN-001)`: the test engineer runs those, you run the scenario.
Some features may not be live on the release you are testing: if a step cannot be done because the
feature is missing, mark it **Not live** and say so. Do not mark it Fail.

---

## 1. Before you start

1. You receive a sign-in for the **QA Audit** organisation from the owner. Use only that. Never
   test in your own company's live organisation.
2. Open the address the owner gives you (for example `https://www.ethr.et/qa-audit`). Bookmark it.
3. Admin roles need an authenticator app (Google Authenticator, Microsoft Authenticator or similar)
   on your phone.
4. Have a phone with a camera and location turned on for the attendance scenarios.
5. Keep this guide open beside the browser. For each step, tick **Pass** or **Fail** and write what
   you saw if it failed.
6. If something looks wrong, take a screenshot (hide any password or code), note the time, and carry
   on with the next step.

**Marks on a scenario.** *Needs a phone* and *Needs a terminal* (a fingerprint, face or card device,
or the kiosk tablet) mean you cannot finish it from a desk: ask the test engineer to arrange it.
*Needs the team's addresses* means an email, text message or printed sheet really goes out: use only
addresses and phones the test team controls.

**Things that are normal on this system, not defects:**

- Some results take a few minutes: payroll calculation, emails, notifications and reports run in the
  background. Wait up to 10 minutes and reload.
- Files larger than about 2 MB are refused on this hosting. The refusal must explain itself.
- Notifications appear when the page refreshes its data (about every 30 seconds), not instantly.

## 2. Personas

| Persona | You are | Scenarios |
|---|---|---|
| **Employee** | Any staff member | Section 3 |
| **Supervisor / department head** | Someone with direct reports | Section 4 |
| **HR officer** | HR administrator | Section 5 |
| **Finance officer** | Payroll and finance | Section 6 |
| **Organisation administrator** | The organisation's owner of ETHR | Section 7 |
| **Platform operator** | Syntax ICT staff running ETHR | Section 8 |

Every scenario has a **"Done when"** line. That is the acceptance criterion.

---

## 3. Employee scenarios

### UAT-E1 Sign in and find my way around

| Step | Do | Expect |
|---|---|---|
| 1 | Open the organisation address | The sign-in page shows your organisation's name and logo, and the ETHR mark (the new e with an amber dot, not a plain letter tile) |
| 2 | Sign in with your email and password | You land on your dashboard |
| 3 | Look at the dashboard | Today's attendance, your leave balance, recent notifications, and a **Next steps** card saying what to finish (for example "Finish your profile") |
| 4 | Click the globe in the header (one menu: Language, calendar and time of day) and choose አማርኛ | The whole screen switches to Amharic, nothing left in English, nothing cut off |
| 5 | In the same menu choose Afaan Oromoo | The screen reads in Afaan Oromoo, with no English left and no raw key names |
| 6 | In the same menu choose the Ethiopian calendar, then **Ethiopian time** for time of day | Dates show in the Ethiopian calendar (for example 14 ጥቅምት 2019); a time such as 07:30 reads 1:30 morning |
| 7 | Switch back to English | Everything returns to English and your choices are remembered after signing out and in |

Changed 2026-11-14: language, calendar and time of day are one menu; Afaan Oromoo and Ethiopian time
are new (see 09 L10N-010, L10N-017, L10N-018).

**Done when:** you can sign in, read your dashboard, and use ETHR in Amharic, Afaan Oromoo and English.

### UAT-E2 Forgot my password

| Step | Do | Expect |
|---|---|---|
| 1 | Sign out. On the sign-in page click **Forgot password** | A form asking for your email |
| 2 | Enter your email | The message says a link was sent *if* the account exists (it says the same for any address) |
| 3 | Open the email (within a few minutes) and click the link | A page to set a new password |
| 4 | Set a new password of at least 8 characters, and sign in with it | Signed in |

**Done when:** you can recover your account without help from HR.

### UAT-E3 Record my attendance

| Step | Do | Expect |
|---|---|---|
| 1 | Go to **My Attendance** and click **Check In** | A confirmation with the time; status shows checked in |
| 2 | Later, click **Check Out** | Hours worked appear for the day |
| 3 | On your phone, open the mobile attendance page and check in at the office | Your location is accepted; if your organisation asks for a selfie, the camera opens |
| 4 | The first time a selfie is asked for, read the photo notice and agree | The notice explains why the photo is taken and how long it is kept (7 days by default) |
| 5 | Check in about 30 minutes after your shift start (if your shift is 08:30, at 09:00 or later) | Your record shows **Late**, timed in Addis Ababa time, and the answer says by how many minutes ("Checked in, 30 min late") |
| 6 | On a public holiday, a weekend or a day of approved leave, open the check-in page | A note names the day: "Today is" and the holiday's name, "Happy weekend", "Today is your day off" or "You are on approved leave today", with "No shift is expected today." |
| 7 | After checking in on your phone, check in again by another way (web or kiosk) | "Already checked in at" the first time and method, and "Your day has one record, whichever way you punch." No second record appears |

Changed 2026-11-14: steps 5 to 7 (see 05 PUNCH-007, PUNCH-008, PUNCH-010, MOB-013).

**Done when:** your check-in and check-out are recorded with the right time and status, whichever way you punch.

### UAT-E4 Fix a wrong attendance record

| Step | Do | Expect |
|---|---|---|
| 1 | Go to **My Attendance → Corrections → Request Correction** | A form for the record |
| 2 | Choose a day, enter the correct times and a reason | Submitted, shown as pending |
| 3 | Wait for your supervisor to approve (they do UAT-S2) | You are notified; the record shows the new times, and the original times are kept |

**Done when:** you can fix a mistake through your supervisor, and you cannot approve it yourself.

### UAT-E5 Ask for leave

| Step | Do | Expect |
|---|---|---|
| 1 | Go to **Leave** and read your balance cards | Annual leave shows days remaining and days used of entitled |
| 2 | Click **Apply for Leave**, pick Annual, choose next Monday to Friday, submit | Shown as pending. The balance goes down by 5 days at once (pending days are counted as taken) |
| 3 | Pick dates that include a public holiday | The holiday is not counted against your balance |
| 4 | Try to ask for more days than you have | Refused: "You do not have enough leave balance for this request." |
| 5 | Cancel a pending request | It disappears from pending and the days come back |
| 6 | After your supervisor approves one (UAT-S1), check the request | Status approved, balance shows the days as used, you were notified |

**Done when:** you can ask for leave, see a correct balance, and cancel while it is pending.

### UAT-E6 Read my payslip

| Step | Do | Expect |
|---|---|---|
| 1 | After finance approves a payroll run (UAT-F2), open **My Payslips** | The month's payslip appears. Payslips of runs that are not approved never appear |
| 2 | Open it | Basic salary, overtime, allowances, income tax, pension (7 percent), loan deductions, net pay |
| 3 | Click **Download** | A PDF that matches the screen, names printed correctly in Amharic, and shows the employer's pension contribution |

**Done when:** the payslip figures are the ones finance expects (see section 6) and the PDF is usable.

### UAT-E7 Keep my profile up to date

| Step | Do | Expect |
|---|---|---|
| 1 | Go to **Profile** and change your phone number | Saved at once |
| 2 | Change your bank account number | Shown as "Pending approval"; HR must approve |
| 3 | Add an emergency contact | Saved |
| 4 | Ask for an employment letter (Profile → requests), purpose "Bank loan" | Shown as pending; when HR issues it you can download the PDF with a reference like EL-2026-0001 |

**Done when:** you can update what you are allowed to, and sensitive changes wait for HR.

### UAT-E8 Have a say

| Step | Do | Expect |
|---|---|---|
| 1 | Open **Voice** and answer the weekly pulse | Thanked; a second answer the same week is refused politely |
| 2 | Raise a concern anonymously | You get a tracking code once. Write it down: it is the only way to follow the concern |
| 3 | Track the concern with the code and add a reply | Your reply appears in the conversation |
| 4 | Recognise a colleague | The recognition is posted. You cannot recognise yourself |

**Done when:** you can give feedback, and the anonymous route really does not show your name to HR.

### UAT-E9 Use ETHR as a phone app

| Step | Do | Expect |
|---|---|---|
| 1 | On Android Chrome: menu → **Add to Home screen**. On iPhone Safari: Share → **Add to Home Screen** | An ETHR icon on the home screen |
| 2 | Open it, turn on airplane mode, and check in | The check-in is saved on the phone with a "waiting to sync" indicator |
| 3 | Turn airplane mode off | The record syncs on its own; it appears in your attendance with the time you checked in, not the time it synced |

**Done when:** you can record attendance without internet and it arrives correctly later.

### UAT-E10 Tell payroll I was at work

| Step | Do | Expect |
|---|---|---|
| 1 | On **My Attendance**, find a day you worked that shows under "Needs your attention" as "absent, nothing on record", and click **I was at work** | The correction form opens on that day |
| 2 | Enter your times and a reason, and send | Pending, marked "Missed punch" |
| 3 | Your supervisor approves | The day is recorded and leaves "Needs your attention" |

**Done when:** a day you worked is never paid as an absence.

### UAT-E11 Activate my account without email or phone

For a staff member with no email and no phone.

| Step | Do | Expect |
|---|---|---|
| 1 | HR shows you a QR code; scan it with your phone | Your employee code and the code are filled in for you |
| 2 | Choose your own password (HR must not see it) | "Your account is ready" |
| 3 | Sign in with your employee code and that password | Your dashboard |

**Done when:** you can get into ETHR without an email address or phone number.

### UAT-E12 Punch only from my own phone

When your organisation checks phones.

| Step | Do | Expect |
|---|---|---|
| 1 | At work, open the attendance page on your phone and tap **Register** | The phone asks for your fingerprint, face or PIN; "Phone registered." and "This is your registered phone" |
| 2 | Check in | The phone asks you to unlock again; the check-in is recorded |
| 3 | Sign in on a colleague's phone or a different browser and try to check in | Under "Only the registered phone" nothing is recorded: you are asked to use your registered phone |
| 4 | On a new phone, open the attendance page and tap **Register this phone** | "Sent for approval. Your old phone works until HR approves this one." Once HR approves: "Your attendance phone was approved. Punch with it from now on." and the old phone stops working |

**Done when:** only your own phone can record your attendance, and a new phone takes over after HR approves it.

### UAT-E13 The right punch on every screen

| Step | Do | Expect |
|---|---|---|
| 1 | Check in on your phone, then open Attendance on a computer | Only **Check Out** is offered |
| 2 | At the kiosk, type your code without choosing anything | "Welcome" in the morning; in the evening, "Goodbye", and the day is closed |

**Done when:** you never have to think about which button to press.

### UAT-E14 Use the kiosk

*Needs a terminal* (the kiosk tablet, set up by HR in UAT-H5).

| Step | Do | Expect |
|---|---|---|
| 1 | Where your code is like EMP-0007, type only 7 and punch | "Your code: EMP-0007" is shown and the punch is recorded |
| 2 | Read the result | Your photo, name and position show, then the screen resets |
| 3 | Ask a colleague to punch with your code by mistake, then tap **Not you? Tell HR** on that result | "HR will check this punch. Now punch with your own code." The wrong punch stays, HR is told, and your own punch then records normally |
| 4 | Tap **My attendance and leave**, enter your code and kiosk PIN | Your last 7 days and leave balances, and no pay figure of any kind |
| 5 | Try a wrong PIN | "That code and PIN do not match. Check both and try again." |
| 6 | Leave the screen untouched for 30 seconds | Back to the punch screen |
| 7 | **Change my PIN**: current PIN, then a new 4 to 6 digit PIN twice | "Your PIN is changed. Use the new one from now on." The old PIN stops working |
| 8 | Where the kiosk shows a QR code, scan it with **Attendance → Scan** on your phone | Check-in recorded; the code works once and changes every few seconds. A photograph of it taken a minute ago is refused |

(See 05 KIO-013 to KIO-028.)

**Done when:** you can punch, correct a wrong punch, and read your own record at the kiosk without
seeing anyone else's.

### UAT-E15 Ask for help

| Step | Do | Expect |
|---|---|---|
| 1 | On any page click **Get help** in the header. Choose a topic (for example Leave), say what happened, and leave "Include technical details" on | The dialog says your organisation's support team answers first; **See what is included** lists the page, language, screen and recent failed requests, never anything you typed |
| 2 | Send | "Request sent". It appears under **My requests**, "Waiting for an answer" |
| 3 | After HR answers (UAT-H18 step 6) | You are told "Your help request has an answer." and read it under your requests |
| 4 | Reply, and later reply again after HR marks it resolved | Your reply reaches HR; replying to a resolved request opens it again |

(See 08 HELP-001 to HELP-004.)

**Done when:** you can ask for help, get an answer, and keep the conversation in one place.

---

## 4. Supervisor scenarios

### UAT-S1 Approve or reject leave

| Step | Do | Expect |
|---|---|---|
| 1 | Open **Approvals** | Requests from your direct reports, with a count in the sidebar |
| 2 | Approve one leave request | It leaves the list; the employee is notified |
| 3 | Reject another, with a reason | The reason is required; the employee sees it and gets the days back |
| 4 | Select several requests and approve them together | Each one shows its own result |
| 5 | Submit a leave request of your own, then look for it in your own Approvals | You cannot approve your own request |
| 6 | On the dashboard, find **Pending Approvals** | The three newest requests, each with Approve and Reject, and "See more". Reject asks for a reason. A decision there is the same as one on Approvals |

Changed 2026-11-14: step 6 (see 08 DASH-009).

**Done when:** you can decide your team's requests quickly, and only theirs.

### UAT-S2 Approve an attendance correction

| Step | Do | Expect |
|---|---|---|
| 1 | Open **Attendance → Corrections → Pending** | The employee's request from UAT-E4 |
| 2 | Approve it | The record shows the corrected times and the right status (present or late, recalculated) |

**Done when:** corrections from your team reach you and fix the record.

### UAT-S3 Watch my team's attendance

| Step | Do | Expect |
|---|---|---|
| 1 | Open the dashboard and **Team Attendance** | Who is present, late, absent, on leave, or on a rest day today |
| 2 | Look at the weekly summary | Per day: present, absent, late and an attendance rate |
| 3 | Open the team leave calendar | Approved leave of your reports for the month |

**Done when:** the picture matches what you know about your team today. Note any person shown absent
who was at work.

### UAT-S4 Overtime

| Step | Do | Expect |
|---|---|---|
| 1 | Ask an employee to request overtime for tomorrow (2 hours, reason) | It reaches your approvals |
| 2 | Approve it | The employee is notified; the request shows approved |

**Done when:** overtime is agreed before it is worked, through ETHR.

---

## 5. HR officer scenarios

### UAT-H1 Hire someone

| Step | Do | Expect |
|---|---|---|
| 1 | **Employees → Add employee** (the main half opens the form; the arrow beside it lists the other ways: One person, From a file or pasted rows, From an attendance terminal, Unrecognised badges). Fill name, hire date, department, branch, position, supervisor | The employee number is filled in for you (for example EMP-0012). The terminal and badge choices appear only when a terminal can be read and you manage attendance |
| 2 | Tick "create a login" and give an email the test team controls | Saved; the person receives an invitation email valid for 72 hours |
| 3 | The new person opens the invitation and sets a password | Their account becomes active and they can sign in |
| 4 | Try a date of birth that makes the person 14 on the hire date | Refused: "The employee must be at least 15 years old on the hire date." |
| 5 | Add someone who is already an employee (same email and national ID, another spelling of the name) | Not created: "is already an employee here. Open their record to make changes." The only choices are Close and Open their record |

Changed 2026-11-14: the arrow beside Add employee and step 5 (see 04 EMP-017 to EMP-021, EMP-024 to
EMP-027, IDN-001 to IDN-004; more in UAT-H14).

**Done when:** a new hire can be registered and signed in within minutes, and nobody is added twice.

### UAT-H2 Bring in staff from a spreadsheet

| Step | Do | Expect |
|---|---|---|
| 1 | **Employees → Import**, download the template | An Excel workbook with the sheets Employees and Instructions, dropdowns for gender, status, department, branch and position, and no example row |
| 2 | Fill 5 rows (one with Amharic names, one phone starting with 0, one 16 digit national ID) and choose the file | "5 people ready", with the columns named. The leading zero and all 16 digits are kept. An old .xls file is refused with a message telling you to save as .xlsx or CSV |
| 3 | **Stage for review** | A table with one row a person and its match: new, matched, or to review, and any problem per row (for example a department that does not exist). **Nobody is added yet** |
| 4 | Choose what the new people arrive as (newly hired, on probation, or already work here, confirmed). Untick "Invite them to sign in as soon as they are added" unless the addresses are the team's. Commit with **Bring them in** | The summary says how many were created, matched and skipped. People arrive with the status you chose |
| 5 | Stage the same list again, and commit | Every row is matched to the person it made; nothing is duplicated |
| 6 | Stage a line for someone already here, with their employee number, a phone they lack and a different department | "Changes to their record": the phone is offered as a fill, the department as a difference with the box "Use this list's value". Salary, national ID and TIN differences are flagged without showing either value |
| 7 | Paste a list from another system whose first line is column names ETHR does not know (for example Emp Nm, Pers Ref, Cell) | The page says so and offers **Match columns**; choose which column is which, name the system and save it. The next list from it is read without asking |
| 8 | Export the employee list | A CSV with the same columns (salary, TIN and national ID are left out on purpose) |

Changed 2026-11-14: the template is a workbook, and preview and commit are one staged review in which
nothing is added until you commit (see 04 IDN-005 to IDN-011, XLS-001 to XLS-003, MAP-001 to MAP-003,
MASS-001). Staged lists are kept 30 days. At most 2,000 people are added at a time.

**Done when:** a spreadsheet of staff can be brought in without duplicates or silent losses.

### UAT-H3 Manage a person's employment

| Step | Do | Expect |
|---|---|---|
| 1 | Confirm a probation employee | Status confirmed, history recorded |
| 2 | Record a promotion (personnel action) | Shown in the employment history |
| 3 | Add a fixed-term contract ending in 20 days | It appears on the contracts-ending list |
| 4 | Upload a contract document (a PDF under 2 MB) | Saved; a larger file is refused with a clear message naming the limit |
| 5 | Record a resignation with a last day | An exit clearance checklist starts on its own (ID card, property, handover, obligations, final pay); it never blocks the exit |
| 6 | Record a promotion dated next month for another employee | Shown as **Scheduled**, "Takes effect on …"; grade and salary unchanged until then; **Cancel change** removes it |

**Done when:** the employee file tells the true story of the person's employment.

### UAT-H4 Approve profile changes and letters

| Step | Do | Expect |
|---|---|---|
| 1 | Open the profile change queue | The bank change from UAT-E7 |
| 2 | Approve it | Applied to the employee record |
| 3 | Open **Employees → Letters**, issue the letter from UAT-E7 | The employee can now download it |
| 4 | Try to approve a change to your own profile | Refused: "You cannot review a change to your own profile." |

**Done when:** sensitive changes go through HR, and no one approves their own.

### UAT-H5 Set up attendance

| Step | Do | Expect |
|---|---|---|
| 1 | Create a shift 08:30 to 17:30, grace 15 minutes, Monday to Friday | Saved |
| 2 | Assign it to a department | The roster shows it |
| 3 | Show a QR code for branch B1 on a screen; an employee scans it with **Attendance → Scan** | Check-in recorded at that branch |
| 4 | Register a kiosk for B1 and open it on a tablet | Employees check in with their employee code (and PIN if required) |
| 5 | Enter a forgotten day by hand for an employee, with a reason | Recorded and rated present or late |
| 6 | Review the **Integrity** queue | Suspicious punches (outside the geofence, no location, impossible travel, a high risk score) are listed for review with the reasons; none was refused unless "Only the registered phone" is on (UAT-H11) |

**Done when:** every way your staff will record attendance works for your organisation.

### UAT-H6 Leave set-up and public holidays

| Step | Do | Expect |
|---|---|---|
| 1 | **Settings → Leave types**: check Annual is 16 days with 1 more day for every 2 years of service | Matches the Labour Proclamation 1156/2019 |
| 2 | **Settings → Holidays → Auto-detect** | Ethiopian public holidays for the year (Enkutatash, Meskel, Genna, Timkat, Adwa, Siklet, Fasika, Labour Day, Patriots, Derg, and the Muslim holidays marked as estimated) |
| 3 | Bring in opening leave balances from the previous system with the balance import | Balances show on each person's leave page |
| 4 | Read the **Leave year** card (an organisation admin changes it; HR only reads) | Ethiopian year (Meskerem to Pagume) or Gregorian year. Once leave is recorded it cannot be changed: "Leave has already been used, requested or carried over in this year, so the leave year can no longer change." |
| 5 | Try to set Annual Leave to 15 days, then 17 | 15 refused: "The law grants at least 16 days of this leave (Labour Proclamation No. 1156/2019, Art. 77(1)). A leave type may give more, never less." 17 saved. Put it back to 16 |

Changed 2026-11-14: steps 4 and 5 (see 06 LB-009, LT-006).

**Done when:** leave rules and holidays match your organisation's policy.

### UAT-H7 Tell everyone something

| Step | Do | Expect |
|---|---|---|
| 1 | Publish an announcement to everyone, with a cover picture at least 960 px wide | Staff see it and are notified |
| 2 | Tick "show on our public page" | It appears on the organisation's public sign-in page and news page |
| 3 | Publish a vacancy as public with a closing date | It appears on the public page until the closing date |

**Done when:** staff and the public see what you meant them to see, and nothing else.

### UAT-H8 Settle absences for payroll

| Step | Do | Expect |
|---|---|---|
| 1 | **Approvals**: approve leave the administrator filed for an employee (unpaid, excused or business trip) for a past day | Approved; the day leaves the Reconcile list |
| 2 | Record a missed punch for an employee on **Attendance → Corrections** | Pending, then recorded once approved |
| 3 | Try to approve a lateness decision | Refused: "A lateness deduction changes pay, so it must be approved by someone who processes payroll." |

**Done when:** every absence the month's payroll sees has a decision behind it.

### UAT-H9 Activate a staff member who has no email or phone

Needs employee code sign-in on.

| Step | Do | Expect |
|---|---|---|
| 1 | Add the employee with no email and no phone; tick "Create a login to activate in person" | Saved; nothing is sent |
| 2 | With the person in front of you, check their ID and click **Activate in person**: choose the document, tick the box, give your password (and authenticator code) | A QR and a typed code, valid 24 hours |
| 3 | Let them scan the QR (do not photograph the screen) | They choose a password and sign in with their employee code |
| 4 | Try the same code again | Refused |

**Done when:** a worker without email or phone can sign in, and the code cannot be used twice.

### UAT-H10 Recruit and hire

With the organisation administrator.

| Step | Do | Expect |
|---|---|---|
| 1 | **Vacancies → Applicants**: add two applicants | Listed as applied |
| 2 | Move one through screening and interview to **Make an offer** | Waiting for approval by someone else |
| 3 | Try to approve your own offer | Refused |
| 4 | The administrator approves it; you **Hire** | An employee record appears with a number, department and branch |
| 5 | Reject the other applicant with a reason and erase their details | "Erased applicant"; stages kept |

**Done when:** a vacancy can be filled from ETHR, with a second person signing off the offer.

### UAT-H11 Make sure staff punch from their own phone

| Step | Do | Expect |
|---|---|---|
| 1 | Settings → Attendance → Cheating protection → **Registered phone**: "Check and review" | Saved |
| 2 | Open Attendance → **Phones** | People with a phone, waiting for approval, and still to register |
| 3 | Approve a phone registered away from work; refuse another with a reason | The approved phone punches; the refused person is told the reason |
| 4 | **Still to register**: give one person **Punch without a phone** for a week | They leave the list |
| 5 | Choose "Only the registered phone"; someone without a phone tries to check in | Refused: "This phone is not registered for your attendance. Register it from the attendance page while you are at work, or ask HR." |
| 6 | Record that a person has resigned | Their phone appears under "Revoked or refused" |
| 7 | Set the rule back to Off (QA organisations only) | Saved |

**Done when:** you can roll the rule out, handle new and lost phones, and know who still has to register.

### UAT-H14 Find people recorded twice

| Step | Do | Expect |
|---|---|---|
| 1 | Add an employee with the phone number of someone already here (no email) and a different name | A question: "This may be", the person's name, "who is already an employee here. Add them only if this is a different person." Cancel creates nobody |
| 2 | Open **Employees → Possible duplicates** (or the search palette, "Find employees recorded twice") | Groups of current employees sharing a national ID, email, phone number, badge number, or name with date of birth, each with the reason and a link to every profile. The shared value itself is not shown. Nothing on the page changes anything |
| 3 | Give two test employees the same badge number, reload, then change one back | The pair appears under "Same badge number", then disappears. Someone who has left is never listed |
| 4 | Open a person brought in from a list or a terminal, on the Employment tab, card **Known elsewhere as** | How the record was made ("Brought in from a list", "Added by hand" and so on) and each id they have elsewhere, named by its terminal or saved source, marked Confirmed or Matched by ETHR. A national ID is never shown |
| 5 | On a link marked Matched by ETHR click **Confirm**; on a wrong test link click **Remove** | "Link confirmed"; for Remove, a question first ("Remove this link?"), then "Link removed". The employee's own details are unchanged |

(See 04 IDN-012 to IDN-015, DUP-001 to DUP-003.)

**Done when:** you can see who may be on file twice, and trust that nothing is merged or changed for you.

### UAT-H15 Give a whole list a first way in

*Needs the team's addresses.* Use a list of test people only, some with an email and some with none.
Needs employee code sign-in on.

| Step | Do | Expect |
|---|---|---|
| 1 | Commit a staged list (UAT-H2) with "Invite them to sign in as soon as they are added" ticked | For the people with an email: "Logins created" and "Invitations queued", with a progress card; nothing more to press. Unticked, nothing is sent and **Send invitations** is offered |
| 2 | For the people with no email, on the card that follows, click **Print a code sheet** | A dialog asking how long the codes work (3 or 7 days), a tick for "I will check each person's identity document before I hand them their slip.", and your password |
| 3 | Confirm | One slip a person: name, employee code, a QR and a code like XXXX-XXXX-XXXX, "Each code works once, until" a date. Closing the sheet drops the codes; they cannot be read back |
| 4 | Instead, click **Give a starting password** for the same kind of people and type a password of your own | A warning that until a person first signs in, anyone who knows the password and their employee code could sign in as them. The password is shown once with the people it was given to, and stops working after 72 hours |
| 5 | A person signs in with their employee code and the starting password | They are held at the change-password screen until they choose their own |
| 6 | Try a code for someone invited by email today, and for someone invited three or more days ago who has not answered | Today: refused, they can be reached by the normal invitation. Three days or more: a code is issued |

(See 04 MASS-001 to MASS-005, SPW-001; 03 section 5c, STP-001 to STP-012.)

**Done when:** every person on a list has a way to sign in that fits what you know about them, and
codes and passwords stay with the people they were meant for.

### UAT-H16 Attendance terminals and unrecognised badges

*Needs a terminal.* A Hikvision terminal on the office network, registered to the QA organisation.

| Step | Do | Expect |
|---|---|---|
| 1 | **Devices → Add device**, a Hikvision terminal, tick "The terminal is on our office network" and leave the address empty | Saved with no address. A panel, "Enter these on the terminal", gives the values to type on the terminal itself |
| 2 | Before the terminal sends anything, open the device list and page | The badge reads "Not connected yet", never Offline or Online, with "Nothing received from this terminal yet." and a hint to enter the address shown into the terminal |
| 3 | Punch on the terminal, or wait for its heartbeat | **Last heard** moves and the device reads Online (for 24 hours after it was last heard, then Offline). The punch is recorded once, even if the terminal sends it twice |
| 4 | Have the terminal send a burst of punches at once (for example after being offline) | None is lost and none is recorded twice. Allow up to 10 minutes: they are worked by the queue |
| 5 | Give the terminal a wrong token (the owner arranges this) | The device page says "The terminal called" a time "and ETHR refused it." |
| 6 | In **Employees → Add employee → From an attendance terminal**, pick that terminal | "sends its punches to ETHR and cannot be asked who is enrolled on it", and a pointer to the badges it has sent. A terminal that ETHR calls itself can be read |
| 7 | Punch with a badge no one owns. Open **Devices** (or **Add employee → Unrecognised badges**) and find it under **Unrecognised badges** | The badge, the terminal and how many punches are held. If ETHR knows who it is: "This is" a name, with **Assign and replay** the punches in one click. If it might be someone: "Possibly" a name, confirmed through the picker. Otherwise **Add as a new employee** |
| 8 | Choose **Add as a new employee**, fill the form, save | The form says "Badge" and the number "from" the terminal, "with" a number of "punches held" and that they are recorded when you save. After saving: "held punches were recorded for them." The badge leaves the list |
| 9 | Choose **Download these badges as a list** | A workbook with one row for each badge that still needs a person |

(See 05 DEV-007, DEV-008, DEV-018 to DEV-023; DEV-024 to DEV-027 for step 6 (#707); DEV-028 to DEV-032
for steps 2 and 3 (#709); DEV-033 to DEV-038 for steps 7 to 9 (#710); DEV-039 and DEV-040 for step 5
(#712); DEV-041 to DEV-044 for steps 3 and 4 (#720). Those cases are new in this edition: if your copy
of 05 does not have them yet, use DEV-018 to DEV-023.)

**Done when:** a terminal that cannot be heard says so, and every badge ends as a person with their
punches counted.

### UAT-H17 Change leave that was already approved

| Step | Do | Expect |
|---|---|---|
| 1 | **Leave → Team**, open an employee's approved 5 day Annual request, **Change → Cancel the whole leave**, with a reason | "Leave cancelled". The employee's days used go down by 5 and remaining up by 5, and the employee is told "Your approved leave was changed by HR." |
| 2 | On another approved request choose **End it early** and a new last day mid-week | "Leave now ends earlier". Only dates before the current last day are offered. The freed days can be booked again |
| 3 | Try the same on your own approved leave | No Change button; refused: "You cannot change your own leave. Another administrator has to." |
| 4 | Try it on a request that is still pending | Refused: "Only approved leave can be cancelled or ended early." |

(See 06 LD-010 to LD-013.)

**Done when:** a change of plan after approval is recorded, the balance is right, and nobody changes
their own leave.

### UAT-H18 Reports for the month, and answering help

| Step | Do | Expect |
|---|---|---|
| 1 | **Reports → Quick Reports → Monthly timesheet**, pick last month | One row per employee: workdays, present, late, absent, on leave, holidays, worked and overtime hours. Compare one employee with their My Attendance: they agree. Late days never exceed present days |
| 2 | Read the month picker | A month of your own calendar (an Ethiopian month if you use that calendar) |
| 3 | Read the list of recommended reports | They fit the kind of organisation you are (a government body sees different ones from a private company) |
| 4 | Generate the timesheet as PDF and CSV, once with the interface in Amharic | Column headings and values read as words in the language chosen, not codes |
| 5 | Print an employee list or Approvals from the browser | A4, the whole page, no sidebar, header or buttons; a top line with the organisation, the page title and the date in your calendar; table headings repeat on each page |
| 6 | **Support → Organisation requests**: answer the request from UAT-E15, then **Mark resolved** | The employee is told "Your help request has an answer." and later that it was marked resolved. You see their email and role. **Pass to ETHR support** (with a note) sends it on; the employee never sees the note |
| 7 | **Analytics**, choose a period and read "vs previous period"; press **Show as table** on a chart | The months named in your calendar; the figures agree with the dashboard; the table has headings and the chart returns with **Show as chart** |

(See 08 REP-006 to REP-009, PRN-001, PRN-002, CHT-001, ANA-003, HELP-003, HELP-004.)

**Done when:** you can hand the month's attendance to finance and a manager on paper or file, and the
numbers match what staff see.

### UAT-H12 Attendance by branch and work site

| Step | Do | Expect |
|---|---|---|
| 1 | Settings → Attendance: read the **Attendance setup** panel | "Attendance is set up. Nothing needs attention." or a list of what to fix, each with a link |
| 2 | **Rules by branch**: require a selfie at one branch only | Only that branch's staff are asked for a selfie |
| 3 | **Work sites**: add a site on the map | Listed with its radius |
| 4 | Open an employee, set **Where they punch** to Field | Saved; they can punch at the site, are sent for review (not refused) elsewhere, and see a **Visits** card on the phone |

**Done when:** each branch and each kind of worker is held to the rules that fit them.

### UAT-H13 Make sure everyone can sign in

Only with addresses the team controls.

| Step | Do | Expect |
|---|---|---|
| 1 | Settings → Users → **Not activated** | Everyone who has not set a password, each with what became of their last invitation |
| 2 | **Resend to everyone not activated** | "Invitations are being sent", then "Invitations sent" a few minutes later. You can leave the page meanwhile |
| 3 | The person opens the email and sets a password | They leave the Not activated list |
| 4 | Find someone with only a phone | "Activate in person"; activate them with a code (UAT-H9) |
| 5 | After a device migration, read **Give them a way to sign in** and **Check the hire dates** | Who still cannot sign in, and how many people were given today as their hire date; correct those dates on their profiles |

**Done when:** everyone who should use ETHR has been invited, and you can see who has not yet signed in.

---

## 6. Finance officer scenarios

### UAT-F1 Check payroll set-up

| Step | Do | Expect |
|---|---|---|
| 1 | **Settings → Payroll**: tax brackets | The Proclamation 1395/2025 ladder: 0 to 2,000 at 0 percent; 2,000 to 4,000 at 15 percent; 4,000 to 7,000 at 20 percent; 7,000 to 10,000 at 25 percent; 10,000 to 14,000 at 30 percent; over 14,000 at 35 percent |
| 2 | Pension | Employee 7 percent, employer 11 percent (private sector). **Settings → Organisation** shows the kind of organisation: private, charity and international organisation use the private scheme, government the public scheme, defence and police the military scheme (employer 33 percent) |
| 3 | Overtime rates | 1.5 normal, 1.75 night, 2.0 rest day, 2.5 public holiday |
| 4 | Add a transport allowance as **not taxable** and a position allowance of 10 percent as **taxable** | Saved |

**Done when:** the rules match the law and your organisation's policy. **Your accountant or tax adviser
should confirm the ladder before the first real payroll.**

### UAT-F2 Run a month's payroll

| Step | Do | Expect |
|---|---|---|
| 1 | **Payroll → Run Payroll**, choose the current Ethiopian month, **Preview payslips** | A dry run first: "A dry run: nothing is saved yet." with People, Gross, Income tax, Pension and Net pay, and a row for each person. Process Payroll needs a tick. Then the run is queued and completes within a few minutes |
| 2 | Open the run | Each employee with gross, tax, pension, deductions and net. A banner lists absences nobody explained (pay was not reduced for them) |
| 3 | Compare the test employees with the table below | Every figure matches |
| 4 | Try to approve the run you processed | Refused if another approver exists: "You processed this payroll run, so another approver in your organisation must approve it." |
| 5 | The organisation administrator approves it | Status approved; payslips appear for employees |
| 6 | Download the bank file, payroll register, income tax and pension schedules (CSV and PDF) and the accounting journal | All open; totals agree with each other |

Expected figures for the test employees (monthly, ETB, private-sector pension):

| Employee | Gross | Income tax | Employee pension (7%) | Net | Employer pension (11%) |
|---|---|---|---|---|---|
| E-PAY-1 | 1,800.00 | 0.00 | 126.00 | 1,674.00 | 198.00 |
| E-PAY-2 | 3,500.00 | 225.00 | 245.00 | 3,030.00 | 385.00 |
| E-PAY-3 | 6,000.00 | 700.00 | 420.00 | 4,880.00 | 660.00 |
| E-PAY-4 | 9,000.00 | 1,400.00 | 630.00 | 6,970.00 | 990.00 |
| E-PAY-5 | 12,000.00 | 2,250.00 | 840.00 | 8,910.00 | 1,320.00 |
| E-PAY-6 | 25,000.00 | 6,700.00 | 1,750.00 | 16,550.00 | 2,750.00 |

Changed 2026-11-14: step 1 starts with the preview (see 07 STAT-014). A leaver whose last day is after
the period is paid the whole period (07 PAY-039). An organisation can set its own working days per
month (UAT-A6).

**Done when:** a month can be paid from ETHR and the figures are ones you would sign.

### UAT-F3 Loans and cost sharing

| Step | Do | Expect |
|---|---|---|
| 1 | Give an employee a loan of 3,000 ETB at 1,000 ETB a month | Active loan |
| 2 | Run and approve payroll | 1,000 ETB deducted; outstanding balance 2,000 |
| 3 | Void that run with a reason | The 1,000 ETB is given back to the loan balance |
| 4 | Record a cost-sharing (education) obligation at 10 percent of gross | Deducted each month until paid |
| 5 | On your own record, try to create or lower a loan or a cost-sharing obligation | Refused: "Someone else in your organisation must set up or change your own loans and deductions." The organisation administrator, who has no one above them, can; a colleague's record can be changed by you |

Changed 2026-11-14: step 5 (see 07 LOAN-009).

**Done when:** balances move only when pay is approved, and come back if it is voided.

### UAT-F4 Reconcile attendance before payroll

| Step | Do | Expect |
|---|---|---|
| 1 | **Payroll → Reconcile**, set From and To to the Ethiopian month | Each employee's absences, late arrivals and missing punches, with Open, Waiting or Settled |
| 2 | The administrator settles them: absences as Unpaid Leave, Excused Absence or Business Trip; lateness by deducting or waiving the minutes | They move to Waiting, then Settled once approved |
| 3 | **Attendance → Corrections**: approve the lateness decisions | Approved |
| 4 | Process the month | An unpaid day costs basic divided by the organisation's working days per month (22 unless the organisation set another number, UAT-A6). Lateness never takes more than a third of basic together with loans and cost sharing, and a payslip over that third is listed before approval |

**Done when:** the month's pay follows decisions people made, not gaps in the records.

### UAT-F5 Record the payment

| Step | Do | Expect |
|---|---|---|
| 1 | After the administrator approves the run and the bank has paid, click **Confirm payment**; enter the date paid and the bank reference | "Payment recorded" |
| 2 | Look at the run | **Paid**; the Void button is gone; the schedules read "Approved and paid" |

**Done when:** the run says what the bank did, and a mistake goes into next month's run. **This cannot be undone**: use only a run the owner agrees to close for good.

### UAT-F6 Sick leave and pay

Use test employees prepared by the test engineer (see 07 section 12), with no earlier sick leave.

| Step | Do | Expect |
|---|---|---|
| 1 | HR files a long Sick Leave for a test employee, about 100 charged days | Accepted, shown pending: sick leave is not held to a 30 day balance. Approve it |
| 2 | Run payroll for a month where the employee was sick for 5 days in their first month of sick leave | No deduction: the first month is paid in full |
| 3 | Run payroll where the employee was sick for 5 days in the second period of sick leave (from the 23rd day on, on a 22 day month) | Half pay for those days: 5 days at half of the daily rate is deducted, and the calculation log names them as half pay days |
| 4 | Try to file sick leave dated in a month whose payroll is already approved | Refused, naming the closed period; the request stays pending until it is rejected |

Changed 2026-11-14: new. The law (Labour Proclamation Art. 85) pays a month in full, the next two
months at half, and then nothing (see 07 SICK-001 to SICK-004, PAY-038).

**Done when:** sick leave pays as the law sets out and a closed month cannot be reopened by leave.

---

## 7. Organisation administrator scenarios

### UAT-A1 Secure the organisation

| Step | Do | Expect |
|---|---|---|
| 1 | **Profile → Security**: set up the authenticator | Every later sign-in asks for the 6-digit code (unless you trusted the browser, for 30 days) |
| 2 | **Settings**: set MFA to "required" for everyone | A user without MFA is sent to set it up before using anything else |
| 3 | **Settings → Users**: invite a user as HR admin | Invitation email; the user can only do HR things |
| 4 | Reset a test user's MFA | The user can sign in with password only and set MFA up again; they get an email |
| 5 | Check **Settings → Audit logs** (Enterprise plan) | Each of the above is recorded |
| 6 | As the owner, hand ownership of a test organisation to another administrator, first with a wrong password, then the right one (QA organisations only, and hand it back) | Wrong password: "That password is not right." Right: "Ownership handed on". Only the owner can do this, and only to another active administrator |

Changed 2026-11-14: step 6 (see 03 ACC-029).

**Done when:** you control who gets in and can see what they did.

### UAT-A2 Roles that fit the organisation

| Step | Do | Expect |
|---|---|---|
| 1 | **Settings → Roles & Permissions**: create "Branch HR" with employee view and update, reach "branch" | Saved |
| 2 | Give it to a test user | That user sees only their branch's employees |
| 3 | Try to delete the role while assigned | Refused until nobody holds it |

**Done when:** you can give people exactly the access their job needs.

### UAT-A3 Approval chains

| Step | Do | Expect |
|---|---|---|
| 1 | **Settings → Approval chains**: leave needs the supervisor, then HR | Saved |
| 2 | An employee asks for leave | The supervisor approves, then it goes to HR; only after HR approves is it final |

**Done when:** requests follow your organisation's chain of authority.

### UAT-A4 Our public page and brand

| Step | Do | Expect |
|---|---|---|
| 1 | **Settings → Branding**: upload a logo and colours | The sign-in page and app header use them |
| 2 | Fill the landing page (headline, about, services, FAQ) | The public sign-in page shows them, in both languages if given |
| 3 | Open the page signed out on a phone | Looks right; nothing private is shown |
| 4 | **Settings → ETHR website**, and on the landing page "List this page in search engines" | Both on by default. Turning the first off removes your name from Trusted by on ethr.et at once; turning the second off keeps your page out of search results |
| 5 | **Settings → General → Time of day**: choose 24-hour, then back to Automatic | Staff who never chose their own follow it; a person's own choice (UAT-E1) always wins |

**Done when:** the public face of your organisation is how you want it.

### UAT-A5 Plan and invoices

| Step | Do | Expect |
|---|---|---|
| 1 | **Billing**: read the current plan and trial days remaining | Shown correctly |
| 2 | Open an invoice and download the receipt | A PDF receipt |

**Done when:** you understand what you pay for and what you have paid.

### UAT-A6 Payroll rules your organisation follows

| Step | Do | Expect |
|---|---|---|
| 1 | **Settings → Organisation**: confirm the kind of organisation in law | The pension scheme and fiscal year match it |
| 2 | **Settings → Attendance**: choose reconciliation Off, Advisory or Required | Saved |
| 3 | Turn on lateness deductions, first without and then with the work-rules clause as the basis | Refused without a basis; saved with one |
| 4 | **Settings → Payroll → Payroll schedule**: read **Working days per month**; try 19, then 32 | The field shows 22 with its help text. 32 is refused: "Enter a number of days from 20 to 31" (19 is a valid choice only if the organisation really pays on a 19 day month; put it back to 22) |
| 5 | **Settings → Payroll → Statutory rules** | Each rule says where it comes from (the law for everyone, your kind of organisation, or your own). Rules the law sets are locked; there is **Reset to default** for your own. A tax ladder or pension rate below the law is refused with the law cited |
| 6 | If your organisation changes its kind after its first approved run | The change needs an effective date, the first of a month, and the pending change is shown until then |

Changed 2026-11-14: steps 4 to 6 (see 07 PAY-032, STAT-006 to STAT-013).

**Done when:** payroll follows the law that applies to you and the rules you agreed with staff.

### UAT-A7 Rename our address

Only on a `uat-` organisation: the old name stays reserved for good.

| Step | Do | Expect |
|---|---|---|
| 1 | **Settings → Address → Change name**, and confirm | "Address changed to …" |
| 2 | Open the old link | It forwards to the new one |
| 3 | From another organisation, try to take the old name | Refused |

**Done when:** the organisation can change its name without breaking links already sent.

### UAT-A8 Set up a new organisation

Only on a new `uat-` organisation, registered for this test.

| Step | Do | Expect |
|---|---|---|
| 1 | Open the sign-up page, fill the organisation name and an address, and choose an industry first | The list **Kind of organisation in law** follows the industry (a bank suggests the private kinds, a government body the public ones). A link **Show every** type brings back the full list. Next stays disabled until a kind is chosen |
| 2 | On the administrator step, type a phone number that is too short, then correct it | A message under the field: "Enter a valid Ethiopian phone number, e.g. 0911223344". Next is disabled until the number is valid |
| 3 | Finish sign-up and open the guided set-up | The industry's kind, shift patterns and rules lists are narrowed to suggestions, with a way to show every one. A value you already hold stays selectable. Headcount is a range, and the section **How you pay** asks the kind, the calendar and the pension registration number |
| 4 | Read the Next steps card | "Ready for first payroll" with a count out of 9, and each missing item as a step with its button (employer TIN, tax centre, pension registration number and others) |
| 5 | In the **People** step paste a list (UAT-H2) | The staged review in the set-up, with Ethiopian hire dates read correctly, and people already counted as done |
| 6 | Switch to Amharic and then Afaan Oromoo | The kind, rules and preview read in each language |

Changed 2026-11-14: new (see 03 ORG-009 to ORG-017; 04 SET-008 to SET-028; 09 L10N-021).

**Done when:** a new organisation can be registered, set up and shown ready for its first payroll
without being offered choices that make no sense for its kind.

---

## 8. Platform operator scenarios (Syntax ICT)

### UAT-P1 Run the platform

| Step | Do | Expect |
|---|---|---|
| 1 | Sign in at `https://admin.ethr.et` with MFA | You arrive directly on the console dashboard: Operations, tenants, recurring revenue and payments this month, health, failed jobs |
| 2 | Find QA Audit in the tenant list and open it | Its plan, status, trial, address and invoices |
| 3 | Extend a `uat-` organisation's trial by 30 days | The trial end moves 30 days |
| 4 | Impersonate QA Audit (MFA code asked) | You work as its administrator; deleting, plan changes and password changes are blocked; leaving returns you to the console |
| 5 | Mark a test invoice paid | Paid; marking again changes nothing |
| 6 | Edit the landing page in **Marketing** (a test row, then delete it) | The public site shows the change, then not |
| 7 | Cancel, then delete, a `uat-` organisation (give a reason) | No address to type and no authenticator code; it is closed and its users signed out; **Restore** brings it back cancelled |
| 8 | Open **Operations** after a release | Each area Healthy, or Needs a look with a reason you understand; the release shown is the one just deployed |
| 9 | Read **Platform Settings → Sidebar product mark** (change nothing without the owner) | Shown, with the standard wording, in every organisation's sidebar |
| 10 | Open **Features** | Grouped as Modules, Integrations and Public pages; each row shows its state and "Organisation exceptions". Attendance, leave, employee management and notifications read "Always on". Open **Change** on one row and cancel: the dialog states that the new state applies to every organisation on its next request and asks for a reason |
| 11 | Open **Support → Requests passed to ETHR** after HR passes one on (UAT-H18 step 6) | The organisation, who asked, the note and the technical details, "Waiting for ETHR". Your reply reaches the employee and HR as "ETHR support", never your name |

Changed 2026-11-14: steps 10 and 11 (see 08 FC-001, HELP-004).

**Done when:** the platform can be operated from the console without database access.

---

## 9. Sign-off

Release under test: `v__________________`   Dates tested: `__________ to __________`

| Persona | Scenarios | Name | Result (Accept / Accept with conditions / Reject) | Conditions or defects | Signature and date |
|---|---|---|---|---|---|
| Employee | E1 to E15 (E14 needs a terminal) | | | | |
| Supervisor | S1 to S4 | | | | |
| HR officer | H1 to H18 (H15 needs the team's addresses, H16 a terminal) | | | | |
| Finance officer | F1 to F6 | | | | |
| Organisation administrator | A1 to A8 | | | | |
| Platform operator | P1 (steps 1 to 11) | | | | |

**Final decision (product owner):**

- [ ] Accepted: the release stays in production.
- [ ] Accepted with conditions: the listed defects are fixed in the next release, by `__________`.
- [ ] Rejected: the release is rolled back (a revert on `main` and a new release; migrations are not undone).

Name: `____________________`  Signature: `____________________`  Date: `____________`
