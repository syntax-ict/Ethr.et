# ETHR user acceptance testing (UAT) guide

**For:** business users, HR officers, finance officers, managers, employees and the product owner.
**Goal:** confirm that ETHR, as deployed on production, supports the way an Ethiopian organisation
actually works, before the release is accepted.

UAT is not about finding every bug; the test engineer does that with the E2E specifications. UAT asks
one question per scenario: **"Can I do my job with this, and is the result right?"**

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
| 1 | Open the organisation address | The sign-in page shows your organisation's name and logo |
| 2 | Sign in with your email and password | You land on your dashboard |
| 3 | Look at the dashboard | Today's attendance, your leave balance and recent notifications |
| 4 | Click the globe icon and choose አማርኛ | The whole screen switches to Amharic, nothing left in English, nothing cut off |
| 5 | Switch the calendar to Ethiopian in the header | Dates show in the Ethiopian calendar (for example 14 ጥቅምት 2019) |
| 6 | Switch back to English | Everything returns to English and your choice is remembered after signing out and in |

**Done when:** you can sign in, read your dashboard, and use ETHR in Amharic and English.

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
| 5 | Check in about 30 minutes after your shift start (if your shift is 08:30, at 09:00 or later) | Your record shows **Late**, timed in Addis Ababa time |

**Done when:** your check-in and check-out are recorded with the right time and status.

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
| 1 | **Employees → Add employee**. Fill name, hire date, department, branch, position, supervisor | The employee number is filled in for you (for example EMP-0012) |
| 2 | Tick "create a login" and give an email the test team controls | Saved; the person receives an invitation email valid for 72 hours |
| 3 | The new person opens the invitation and sets a password | Their account becomes active and they can sign in |
| 4 | Try a date of birth that makes the person 14 on the hire date | Refused: "The employee must be at least 15 years old on the hire date." |

**Done when:** a new hire can be registered and signed in within minutes.

### UAT-H2 Bring in staff from a spreadsheet

| Step | Do | Expect |
|---|---|---|
| 1 | **Employees → Import**, download the template | A CSV with the expected column headings |
| 2 | Fill 5 rows (one with Amharic names) and upload | A preview showing every row and any problem per row (for example a department that does not exist) |
| 3 | Fix the problem rows and commit | The summary says how many were created, matched (already existed) and skipped |
| 4 | Commit the same file again | Nothing is duplicated |
| 5 | Export the employee list | A CSV with the same columns (salary, TIN and national ID are left out on purpose) |

**Done when:** a spreadsheet of staff can be brought in without duplicates or silent losses.

### UAT-H3 Manage a person's employment

| Step | Do | Expect |
|---|---|---|
| 1 | Confirm a probation employee | Status confirmed, history recorded |
| 2 | Record a promotion (personnel action) | Shown in the employment history |
| 3 | Add a fixed-term contract ending in 20 days | It appears on the contracts-ending list |
| 4 | Upload a contract document (a PDF under 2 MB) | Saved; a larger file is refused with a clear message naming the limit |
| 5 | Record a resignation with a last day | An exit clearance checklist starts on its own (ID card, property, handover, obligations, final pay); it never blocks the exit |

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
| 6 | Review the **Integrity** queue | Suspicious punches (outside the geofence, no location, impossible travel) are listed for review; none was refused |

**Done when:** every way your staff will record attendance works for your organisation.

### UAT-H6 Leave set-up and public holidays

| Step | Do | Expect |
|---|---|---|
| 1 | **Settings → Leave types**: check Annual is 16 days with 1 more day for every 2 years of service | Matches the Labour Proclamation 1156/2019 |
| 2 | **Settings → Holidays → Auto-detect** | Ethiopian public holidays for the year (Enkutatash, Meskel, Genna, Timkat, Adwa, Siklet, Fasika, Labour Day, Patriots, Derg, and the Muslim holidays marked as estimated) |
| 3 | Bring in opening leave balances from the previous system with the balance import | Balances show on each person's leave page |

**Done when:** leave rules and holidays match your organisation's policy.

### UAT-H7 Tell everyone something

| Step | Do | Expect |
|---|---|---|
| 1 | Publish an announcement to everyone, with a cover picture at least 960 px wide | Staff see it and are notified |
| 2 | Tick "show on our public page" | It appears on the organisation's public sign-in page and news page |
| 3 | Publish a vacancy as public with a closing date | It appears on the public page until the closing date |

**Done when:** staff and the public see what you meant them to see, and nothing else.

---

## 6. Finance officer scenarios

### UAT-F1 Check payroll set-up

| Step | Do | Expect |
|---|---|---|
| 1 | **Settings → Payroll**: tax brackets | The Proclamation 1395/2025 ladder: 0 to 2,000 at 0 percent; 2,000 to 4,000 at 15 percent; 4,000 to 7,000 at 20 percent; 7,000 to 10,000 at 25 percent; 10,000 to 14,000 at 30 percent; over 14,000 at 35 percent |
| 2 | Pension | Employee 7 percent, employer 11 percent (private sector) |
| 3 | Overtime rates | 1.5 normal, 1.75 night, 2.0 rest day, 2.5 public holiday |
| 4 | Add a transport allowance as **not taxable** and a position allowance of 10 percent as **taxable** | Saved |

**Done when:** the rules match the law and your organisation's policy. **Your accountant or tax adviser
should confirm the ladder before the first real payroll.**

### UAT-F2 Run a month's payroll

| Step | Do | Expect |
|---|---|---|
| 1 | **Payroll → Process** for the current Ethiopian month | The run is queued and completes within a few minutes |
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

**Done when:** a month can be paid from ETHR and the figures are ones you would sign.

### UAT-F3 Loans and cost sharing

| Step | Do | Expect |
|---|---|---|
| 1 | Give an employee a loan of 3,000 ETB at 1,000 ETB a month | Active loan |
| 2 | Run and approve payroll | 1,000 ETB deducted; outstanding balance 2,000 |
| 3 | Void that run with a reason | The 1,000 ETB is given back to the loan balance |
| 4 | Record a cost-sharing (education) obligation at 10 percent of gross | Deducted each month until paid |

**Done when:** balances move only when pay is approved, and come back if it is voided.

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

**Done when:** the public face of your organisation is how you want it.

### UAT-A5 Plan and invoices

| Step | Do | Expect |
|---|---|---|
| 1 | **Billing**: read the current plan and trial days remaining | Shown correctly |
| 2 | Open an invoice and download the receipt | A PDF receipt |

**Done when:** you understand what you pay for and what you have paid.

---

## 8. Platform operator scenarios (Syntax ICT)

### UAT-P1 Run the platform

| Step | Do | Expect |
|---|---|---|
| 1 | Sign in at `https://admin.ethr.et` with MFA | The console dashboard: tenants, revenue, health, failed jobs |
| 2 | Find QA Audit in the tenant list and open it | Its plan, status, trial, address and invoices |
| 3 | Extend a `uat-` organisation's trial by 30 days | The trial end moves 30 days |
| 4 | Impersonate QA Audit (MFA code asked) | You work as its administrator; deleting, plan changes and password changes are blocked; leaving returns you to the console |
| 5 | Mark a test invoice paid | Paid; marking again changes nothing |
| 6 | Edit the landing page in **Marketing** (a test row, then delete it) | The public site shows the change, then not |
| 7 | Cancel, then delete, a `uat-` organisation (type its address, give a reason, MFA code) | It is closed and its users signed out; **Restore** brings it back cancelled |

**Done when:** the platform can be operated from the console without database access.

---

## 9. Sign-off

Release under test: `v__________________`   Dates tested: `__________ to __________`

| Persona | Scenarios | Name | Result (Accept / Accept with conditions / Reject) | Conditions or defects | Signature and date |
|---|---|---|---|---|---|
| Employee | E1 to E9 | | | | |
| Supervisor | S1 to S4 | | | | |
| HR officer | H1 to H7 | | | | |
| Finance officer | F1 to F3 | | | | |
| Organisation administrator | A1 to A5 | | | | |
| Platform operator | P1 | | | | |

**Final decision (product owner):**

- [ ] Accepted: the release stays in production.
- [ ] Accepted with conditions: the listed defects are fixed in the next release, by `__________`.
- [ ] Rejected: the release is rolled back (a revert on `main` and a new release; migrations are not undone).

Name: `____________________`  Signature: `____________________`  Date: `____________`
