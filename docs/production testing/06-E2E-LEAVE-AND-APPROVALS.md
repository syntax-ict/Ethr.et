# E2E specification 06: leave, approvals, letters, exit clearance and cases

Columns, personas and modes: see the [README](README.md#how-a-test-case-is-written). Run in QA Audit.

**Third edition (2026-11-08).** Cases were added for the leave year (#521, and the Ethiopian year by
default since #536: LB-009 to LB-011), HR cancelling approved leave or ending it early (#634:
LD-010 to LD-015), the leave side of Art. 85 sick leave (#637: LR-018, LR-019, LB-012), and the
leave items of the A-Z review train #624: leave counted on the person's own working days (#593,
LR-020), maternity leave in calendar days (#596, LR-021), minimum notice in whole days with a
readable message (#585, LR-009, LR-010), a request decided once (#567, LD-005) and approval
reminders in the reader's language (#605, CH-010). Approval behaviour on the dashboard's Pending
Approvals panel (#578) that file 08's DASH-009 does not test is LD-016 and LD-017. Cases these
changed were corrected in place: the ground rules, LT-001, LB-008, LR-006, LR-009, LR-010, LR-012,
LR-014, LD-005, LD-006, LD-008, CH-010 and LET-001. The pay side of sick leave is file 07, section
12 (SICK-001 to SICK-004); unpaid leave refused in an approved payroll period is 07's PAY-038; date
fields and the reader's calendar in general are 09's L10N-019. Priorities were re-tiered: **P1** is
kept for what would be an S1 or block a role's main flow: a wrong balance, an approval that bypasses
the chain or lets someone approve their own request, exposure beyond a person's reach, data loss,
and the one happy path (request, approve, balance moves). Other flows, lesser refusals and messages
are **P2**; cosmetic and secondary checks are **P3**.

**Ground rules:**

- Leave is counted in whole days the person is scheduled to work: their rotation, else their shift,
  else the organisation's working week (Monday to Friday by default), minus active holidays
  (organisation-wide or the employee's branch). A type counted in calendar days (Maternity, Art. 88)
  takes every day of the span. Dates are Gregorian underneath; the Ethiopian calendar is a display
  choice. **There is no half-day leave.**
- Balances run on the organisation's **leave year**: Ethiopian (Meskerem 1 to Pagume 5 or 6) by
  default, or Gregorian (1 January to 31 December). A request is charged to the leave year it starts
  in, even when it ends in the next (A-Z review E-04, not fixed).
- Remaining = entitled + carried − used − pending. **Pending days are subtracted at once** when a
  request is submitted.
- Annual entitlement = the type's default days + 1 day for every 2 completed years of service
  (Labour Proclamation 1156/2019, Art. 77(1)).
- Sick Leave follows Art. 85: up to six months within twelve, counted from the first sick day, and
  not held to its 30-day balance (section 3 note).
- No backdating: a request must start today or later. HR filing on an employee's behalf is exempt
  (LR-017).
- An employee cancels only a pending request. HR (whoever holds `leave.adjustBalance`) cancels
  approved leave or ends it early, never their own (section 4).
- Every decision is one step unless the organisation configured an approval chain.

---

## 1. Leave types

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LT-001 | P2 | R | Defaults | HR: Settings → Leave types (organisation set up by the wizard) | Annual 16 days, monthly accrual, carry forward capped at 16, +1 per 2 years, 7 days' notice; Sick 30 (attachment flag set, but nothing asks for one, A-Z review E-09; held to Art. 85, not to the 30 days, LR-018); Maternity 120 consecutive calendar days (women, LR-021); Paternity 3 (men); Bereavement 3; Marriage 3; Study 10 (14 days' notice); Sabbatical 180 (90 days' notice); Injury 30; R&R 5; Unpaid 0 (unpaid, 14 days' notice) |
| LT-002 | P2 | W | Create and duplicate | Create "QA Compassionate" 2 days; duplicate it | Saved; the copy is separate |
| LT-003 | P2 | W | Deactivate | Deactivate QA Compassionate; EMP tries to request it | Not offered; through the API refused (type must be active) |
| LT-004 | P2 | R | Rights | SUP opens leave type settings | View only (everyone holds `leave.viewTypes`); `leave.manageTypes` is HR |
| LT-005 | P2 | R | Kinds that settle absences | HR: Settings → Leave types in QA Audit and in a new `uat-` organisation | Both have "Official Duty / Business Trip" (duty) and "Excused Absence" (excused), both paid, plus an unpaid type. Duty and excused days use no balance and are absent from balance cards and the leave balance report |

## 2. Balances

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LB-001 | P2 | R | Balance cards | EMP: Leave | One card per type: remaining (large) and used of entitled |
| LB-002 | P1 | R | Service increment | An employee hired 4 years and 2 months ago | Annual entitlement 18 (16 + 2) |
| LB-003 | P2 | R | Monthly accrual | Check an annual balance before and after a month of the leave year starts (the 1st, or the 1st of an Ethiopian month on the Ethiopian leave year; accrual runs daily at 00:30 UTC) | Raised towards entitlement × months employed this leave year ÷ 12, rounded to one decimal; never lowered |
| LB-004 | P2 | R | Carry forward | In the first week of the leave year (1 January, or Meskerem 1 on the Ethiopian leave year), 01:00 UTC | Carried = min(remaining, cap); it overwrites the previous carried figure |
| LB-005 | P1 | W | Opening balance import | HR: import leave balances; template headers `employee_code, leave_type_code, year, entitled_days, used_days, carried_days`; preview 3 rows, one with used greater than entitled + carried, one duplicate | Bad rows reported with reasons; **commit writes nothing while any row is invalid**; after fixing, balances replaced |
| LB-006 | P2 | W | Year bounds | Import year 1999 | Refused (2000 to 2100) |
| LB-007 | P2 | R | Rights | SUP opens the balance import | Refused (`leave.adjustBalance` is HR) |
| LB-008 | P1 | R | Another employee's balance | SUP, then FIN, then HR: `GET /leave/balance/{EMP}` and `/leave/balance/{EMP3}` | SUP: 403 for both, EMP included: the endpoint needs `leave.viewAll`, which supervisors do not hold (`LeaveRequestController.php:286`); a supervisor sees their team's requests on the Team tab, not balances. FIN and HR (both hold `leave.viewAll`): both shown. An employee of QA Isolation: 403 or 404, never the balance. If supervisors should see their reports' balances, that is a change request, not a defect |
| LB-009 | P2 | R | Leave year card (#521, #536) | TA in QA Audit: Settings → Leave types, the "Leave year" card. Then HR. `GET /settings` → `leave` | "When leave balances start again and unused days carry over." The choice is "Ethiopian year, Meskerem to Pagume" or "Gregorian year, January to December". Since #536 every organisation has the setting: Ethiopian, unless it had leave used, pending or carried before 2026-11-02, which kept Gregorian. Record which QA Audit runs on; LB-003, LB-004 and LR-014 depend on it. Once leave is recorded the selector is disabled with "Leave has already been used, requested or carried over in this year, so the leave year can no longer change." HR sees "Only an organisation admin can change the leave year." The API returns `leave_year` and `leave_year_changeable` |
| LB-010 | P1 | W | A new organisation runs on the Ethiopian year (#536) | In QA Isolation (registered for this plan): the Leave year card; an employee hired before 11 September 2026 (Meskerem 1, 2019) but less than two years ago, with the wizard's Annual Leave; after one daily accrual (00:30 UTC), `GET /leave/balance` as that employee | Card: "Ethiopian year, Meskerem to Pagume". Balance rows carry `year` 2019 (the Ethiopian year running from 11 September 2026 to 10 September 2027), not 2026. Annual entitled = 16 × the Ethiopian month of the year so far ÷ 12 (Tikimt is month 2: 2.7; Hidar, from 10 November 2026, month 3: 4.0), not the Gregorian month (November, 11: 14.7) |
| LB-011 | P2 | W | The leave year is fixed once leave is recorded (#521) | In QA Isolation before any leave is requested: TA switches to Gregorian, then back to Ethiopian. Then an employee submits a request; TA tries again; `PUT /settings` with `settings.leave_year` `gregorian`. The employee cancels the request; TA reloads the card | Before any request: "Leave year saved" each time. With the request pending: the selector is disabled with the locked note (LB-009), and the API answers 422 on `settings.leave_year`: "The leave year can't change once leave has been used, requested or carried over. Those balances belong to the current leave year." After the cancel (nothing used, pending or carried): changeable again. Leave QA Isolation on the Ethiopian year |
| LB-012 | P3 | R | The Sick card after a long sick leave (#637) | While LR-018's request is pending, HR: `GET /leave/balance/{that employee}?year=` the leave year LR-018 starts in (on the Ethiopian year a June 2026 start is 2018); the employee's Leave page if that is the current leave year | From the code the Sick balance is still the 30-day figure: `pending_days` is the request's days and `remaining_days` goes below zero (`LeaveBalance.php:63`), and the card shows that negative number. Art. 85 sick leave is not limited by it (LR-018). Record a P3 defect for the owner to decide how the Sick card should read |

## 3. Requests

**Sick leave (Art. 85, #637).** Every type with the code `sick` (the wizard's "Sick Leave") follows
Labour Proclamation Art. 85: up to six months within a twelve-month period that starts on the first
sick day not already in one. A month is the payroll rule's working days per month (22 by default), so
six months is 132 charged days. Pending requests count, so two filed together cannot pass the limit
between them. No leave screen shows a day's tier (full, half or unpaid pay); the pay figures are file
07, section 12. 07's SICK-004 (K-46) records that sick leave dated in an approved payroll period is
approved without refusal. Never use E-SICK-1 or E-SICK-2 here.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LR-001 | P1 | W | Request | EMP: Apply → Annual, next Monday to Friday (no holiday) | Pending; 5 days; remaining down by 5 at once; SUP notified; `leave.requested` webhook (if subscribed) |
| LR-002 | P1 | W | Weekend and holiday excluded | A request from Friday to the next Tuesday with Monday a public holiday | 3 days counted |
| LR-003 | P2 | W | Only non-working days | A request covering only Saturday and Sunday | "The selected dates do not include any working days." |
| LR-004 | P2 | W | Backdating | Start yesterday | Refused (`start_date` must be today or later) |
| LR-005 | P2 | W | End before start | End before start | Refused |
| LR-006 | P1 | W | Insufficient balance | Annual for more days than remain | "You do not have enough leave balance for this request." **Known defect (K-03):** the message names neither the days remaining nor the days asked |
| LR-007 | P2 | W | Overlap | A second request overlapping a pending one | Refused. **Known wording defect (K-03):** the message says "approved" even when the clash is with a pending request: "You already have an approved leave request that overlaps with these dates." |
| LR-008 | P2 | W | Gender | A male employee requests Maternity | "This leave type is not available for your gender." |
| LR-009 | P2 | W | Notice (#585 in #624) | Annual (7 days' notice): EMP requests a start 6 days from today, then exactly 7 days from today (the organisation's date, also between 00:00 and 03:00) | 6 days: refused, "This leave type needs 7 days' notice. Choose a start date at least 7 days from today." 7 days: accepted (cancel it afterwards). No raw key `leave.min_notice` (K-02 fixed). HR filing on someone's behalf is not held to notice (LR-017) |
| LR-010 | P2 | W | Maximum consecutive (#585) | HR sets a maximum of 2 consecutive days on QA Compassionate; EMP requests it for 3 working days | Refused: "This leave type allows at most 2 days in one request." No raw key `leave.max_consecutive` |
| LR-011 | P2 | W | Cancel pending | EMP cancels LR-001 | Cancelled; the 5 days return |
| LR-012 | P2 | W | Cancel decided | EMP cancels an approved request | Refused: "This leave request is no longer pending." The employee cannot cancel approved leave; HR can (LD-010) |
| LR-013 | P1 | R | Cancel someone else's | SUP cancels EMP's request | 403 "You can only modify your own leave requests." |
| LR-014 | P2 | W | Ethiopian calendar | With the Ethiopian calendar on, request across Pagume (the 13th month, early September) into Meskerem | Days counted correctly across Pagume and into Meskerem. On the Ethiopian leave year (LB-009) Meskerem 1 starts a new leave year, and the whole request is charged to the year it starts in: the old year's balance moves, the new year's does not (A-Z review E-04, open; record it, no new defect) |
| LR-015 | P3 | W | Amharic reason | A reason in Amharic | Stored and shown intact |
| LR-016 | P2 | W | Unpaid leave and balance | EMP requests Unpaid Leave (0 days left) 15 days ahead | Pending. No longer refused with "You do not have enough leave balance for this request." |
| LR-017 | P1 | W | HR files for an employee | HR: `POST /leave/request` with `employee_public_id` EMP3, yesterday, Excused Absence; HR approves it; SUP and FIN file for EMP3; an unknown `employee_public_id` | 201 pending; backdating and notice rules not applied; `filed_for` in its history. HR approving: "You filed this request, so someone else must approve it." SUP, FIN: 403. Unknown: "The selected employee public id is invalid." |
| LR-018 | P2 | W | Sick leave is not held to 30 days (#637) | HR files Sick Leave on behalf of a `QA-` employee with no sick leave on record, from a Monday about five months ago to the Friday about twenty weeks later (about 100 charged days); read `days` in the response | 201 pending with `days` well over 30; no "You do not have enough leave balance for this request." Keep it pending for LR-019 and LB-012 |
| LR-019 | P2 | W | Past six months is refused (#637) | Same employee, LR-018 still pending: HR files Sick Leave from the Monday after LR-018 ends for ten weeks (about 50 charged days); then the same for one week. Afterwards TA rejects both of them, reason "QA" | Ten weeks: refused, "Sick leave is at most six months within twelve, counted from the first day of sickness (Labour Proclamation Art. 85). These days go past that." (LR-018's pending days count). One week: 201 pending, as long as LR-018's days plus these are 132 or fewer. After the rejections the pending days return |
| LR-020 | P2 | W | Counted on the person's own working days (#593 in #624) | HR assigns a `QA-` employee a `QA-Six` shift working Monday to Saturday (05 owns shifts), then files Annual Leave on their behalf for one Monday to Saturday with no holiday; the same dates for an employee on S1 (Monday to Friday). TA rejects both afterwards | Six-day shift: 6 days. S1: 5 days. A rotation, where assigned, decides before the shift |
| LR-021 | P2 | W | Maternity is counted in calendar days (#596 in #624) | HR files Maternity Leave on behalf of a female `QA-` employee for 14 consecutive days spanning two weekends and, if one falls inside, a holiday. TA rejects it afterwards | 14 days, weekends and holidays included; remaining 120 − 14 = 106 while pending. Other types still skip non-working days (LR-002) |

## 4. Decisions and the approval inbox

The dashboard's Pending Approvals panel decides through the same batch call as the Approvals page
(#578); its layout and rejection reason are 08's DASH-009. Approvals lists only what this person may
decide at its current step: never their own request.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LD-001 | P1 | W | Approve | SUP: Approvals → approve EMP's request | Approved; pending moves to used; EMP notified; `leave.approved` webhook |
| LD-002 | P2 | W | Reject | SUP rejects without, then with, a reason (up to 1,000) | Reason required; pending released; EMP notified |
| LD-003 | P1 | R | Self-approval | SUP requests leave and tries to approve it (`PUT /leave/{id}/approve`) | "You cannot approve your own leave request." (403 `self-approval`). The request is not listed in SUP's own Approvals or dashboard panel |
| LD-004 | P1 | R | Outside reach | SUP tries to approve EMP3's request (not a report) | Refused |
| LD-005 | P2 | W | Decided once (#567 in #624) | Approve a request, then approve it again. With a second request open in two browser tabs as SUP, press Approve in both as close together as possible | The second approval of each is refused: "This leave request is no longer pending." The balance moves once: used up by the request's days, not twice |
| LD-006 | P1 | W | Batch | SUP: Approvals, select 3 items (2 leave, 1 correction) and approve. Then `POST /approvals/batch` with one of those already decided, and SUP's own pending request | Page: all three approved, "Action completed". API: per-item results, `status: "error"` with "This leave request is no longer pending." and "You cannot approve your own leave request."; the page reports such a result as "Action failed" with the reason, not "Action completed". A batch reject without a reason uses "Batch rejected". No limit on the number of items (K-12) |
| LD-007 | P2 | R | Pending count | Sidebar Approvals badge | Equals the number of items waiting for this person |
| LD-008 | P2 | R | Team calendar | SUP: Leave → Team → Calendar view, this month; then with the Ethiopian calendar | Pending and approved requests of everyone SUP reaches on their days; rejected and cancelled not shown. On the Ethiopian calendar the grid stays a Gregorian month and its heading names the Ethiopian dates it covers |
| LD-009 | P3 | R | Mobile tab | On a phone, an approver's bottom bar | Approvals with its count replaces Payslips |

### Cancelling approved leave or ending it early (#634)

HR (whoever holds `leave.adjustBalance`: HR and TA; not FIN, DA or SUP) uses **Change** on an approved
request in Leave → Team: "Cancel the whole leave", or "End it early" with a new last day, and a
reason (up to 1,000 characters) the employee sees. The days it no longer takes go back to the
balance of the leave year it was charged to. API: `PUT /leave/{id}/recall` with `reason` and, to end
early, `end_date`.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LD-010 | P1 | W | Cancel approved leave | EMP has an approved 5-day Annual request (LD-001). HR: Leave → Team → Change on it → "Cancel the whole leave", reason "QA cancelled" → "Cancel leave". EMP: Leave, notifications | "Leave cancelled". Status cancelled; EMP's Annual used down by 5 and remaining up by 5. EMP's in-app notification: "Your approved leave was changed by HR." Audit: "Leave changed by HR" |
| LD-011 | P1 | W | End it early | EMP: another approved Annual request, Monday to Friday. HR: Change → "End it early"; the date picker; new last day Wednesday; reason "QA back early" → "Save new end date" | The picker offers the first day to Thursday only. "Leave now ends earlier". The request ends Wednesday and counts 3 days; used down by 2. The 2 freed days are bookable again |
| LD-012 | P1 | R | Who may not change it | HR's own approved leave (HR requests, TA approves): HR looks for Change, then `PUT /leave/{id}/recall` with a reason. SUP and FIN: the same call on EMP's approved leave. HR: the call on a pending request, and with no reason | HR's own row has no Change; the call: 403 "You cannot change your own leave. Another administrator has to." SUP, FIN: no Change button; 403. Pending: 422 "Only approved leave can be cancelled or ended early." No reason: 422 on `reason` |
| LD-013 | P2 | W | Dates the change refuses | HR, by API on an approved request: `end_date` equal to its current last day, then before its first day. On an approved request from a Saturday to a Wednesday: `end_date` the Sunday | 422 on `end_date`: "The new end date must be on or after the first day and before the current last day." Sunday: "Ending the leave on that date leaves no working days. Cancel the leave instead." Nothing changes |
| LD-014 | P1 | W | Not inside an approved payroll period | With 07's run A approved and not yet voided, the Annual leave day approved for EMP3 in P1 by PAY-038: HR → Change → "Cancel the whole leave" | 422 `payroll-period-closed`: "Some of the days given back fall in a payroll period that is already approved. Void and reprocess that run first, so pay follows the change." The request stays approved and the balance is unchanged |
| LD-015 | P2 | X | Email and webhook | After LD-011, with a webhook subscribed to `leave.changed` (08). EMP's inbox. Then EMP turns off "Leave Changed" ("When HR cancels or shortens your approved leave") in notification preferences and HR changes another request | Email "Your leave has been changed": "Your Annual Leave leave starting {start date} was changed by HR and now counts 3 days. Days it no longer takes are back in your balance." with the reason. Webhook `leave.changed` with `public_id`, `employee_name`, `status`, `end_date`, `days` and `reason`. With the preference off: no email |
| LD-016 | P1 | W | The dashboard refuses what the engine refuses (#578) | HR files Annual Leave on EMP3's behalf (LR-017). HR: dashboard → Pending Approvals → Approve on it | Listed (on Approvals if it is not among the panel's three newest), but refused: "Action failed" with "You filed this request, so someone else must approve it." The row stays; the request stays pending. TA approves it from TA's own panel |
| LD-017 | P2 | W | The dashboard follows the chain step (#578) | With CH-001's chain: EMP requests leave. HR's and SUP's dashboards; SUP approves in the panel; both dashboards again; HR approves | Before SUP: listed on SUP's panel only. After SUP: "Action completed", gone from SUP's panel, listed on HR's (and TA's). HR's approval: approved, balance moved, as CH-003 |

## 5. Approval chains (engine)

Configured at Settings → Approval chains (needs `settings.manage`). Applies to leave, attendance
corrections, profile updates and overtime. Payroll does not use it (it has maker-checker); loans do
not use it. **Delegation and return-for-correction are not built.**

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| CH-001 | P2 | W | Two-step chain | Leave chain: step 1 supervisor, step 2 permission `leave.approve` (HR); SLA 48 h | Saved. More than 5 steps, SLA 0 or 721 refused |
| CH-002 | P2 | W | Walk the chain | EMP requests leave; SUP approves | Still pending; HR notified as next approver; EMP sees it at step 2 |
| CH-003 | P2 | W | Final step | HR approves | Approved; balance moved; history shows both steps |
| CH-004 | P1 | R | Wrong step | HR tries to approve at step 1 before SUP | "This request is waiting for another approver at its current step." |
| CH-005 | P1 | R | Same person twice | A person who approved step 1 is also eligible for step 2 | Not allowed to approve step 2 |
| CH-006 | P2 | W | Conditions and priority | A second chain for D2 only, higher priority | EMP2 (D2) follows it; EMP (D1) follows the first |
| CH-007 | P2 | W | Edit in flight | Change the chain while a request is pending | The pending request keeps the chain it started with |
| CH-008 | P2 | W | Department head step | Step kind "department head" with D1's head set | The head is asked; if no head is set, any permission holder may decide and the history records a fallback |
| CH-009 | P2 | W | Delete chain | Delete all chains | New requests use one step decided by any permission holder who reaches the employee |
| CH-010 | P2 | X | Reminder and escalation (#605 in #624) | Leave a step waiting over 48 h; the approver reads ETHR in Amharic | Daily 06:00 UTC reminder to the named approver, written wholly in their language: in English "You have :count approval requests waiting for you.", "The oldest has been waiting :hours hours." and the button "Review approvals"; in Amharic subject, body and button all Amharic. Past the SLA, once: the approver's supervisor (or every HR admin) is told and the step opens to all eligible approvers. **Never auto-approved** |
| CH-011 | P2 | W | Other request types | Chains for correction, profile update, overtime | Each follows its chain like leave |

## 6. Exit clearance

Records the leaving checklist. **It never blocks the exit, the final pay or the login closing.**

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| EXC-001 | P2 | W | Starts by itself | Resign a test employee (LIFE-003) | Clearance with lines ID card (HR), property (HR), handover (supervisor), obligations (finance), final pay (finance); listed at Employees → Exit clearances |
| EXC-002 | P2 | W | Who ticks what | SUP ticks handover; FIN ticks obligations and final pay; HR ticks anything; SUP tries final pay | Allowed as listed; SUP on a finance line refused |
| EXC-003 | P2 | W | Balance owed | The employee has an active loan; FIN ticks obligations without a note | "A balance is still owed. Add a note to say how it is being settled." |
| EXC-004 | P2 | W | Complete | Tick every line | Status complete |
| EXC-005 | P2 | W | Template | TA: Settings → Exit clearance, add a line (max 20 lines) | New clearances include it |
| EXC-006 | P2 | W | Manual start | Start a clearance for an employee who already has an open one | The existing one is returned; no duplicate |

## 7. Employment letters

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LET-001 | P2 | W | Ask | EMP: Profile → letters, purpose "QA bank loan", addressed to, include salary, language English; another in Amharic, a third in Afaan Oromoo | Pending; the languages offered are English, Amharic and Afaan Oromoo; purpose under 3 characters refused; more than 10 requests an hour refused |
| LET-002 | P2 | W | Issue | HR: Employees → Letters → issue | Reference `EL-YYYY-NNNN`; EMP can download the PDF; the PDF shows the current record and the salary only if asked |
| LET-003 | P2 | W | Reject | HR rejects with a reason (3 to 500) | EMP sees the reason; PDF not available (404) |
| LET-004 | P1 | R | Own letter | HR decides a letter about HR | 403 "You cannot decide a letter about yourself." |
| LET-005 | P2 | W | Decided twice | Issue an issued letter | 422 "This request has already been decided." |
| LET-006 | P2 | R | Amharic letter | Open the Amharic PDF | Amharic renders correctly, nothing missing |

## 8. Disciplinary cases

States: reported → investigating (on the first note) → decided → appealed → closed. Needs
`disciplinary_case.manage` (HR) and reach; the subject cannot decide their own case.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| DIS-001 | P2 | W | Open and investigate | HR opens a case on EMP2; adds a note | Reported, then investigating |
| DIS-002 | P2 | W | Decide | Decision guilty, sanction written warning | Decided |
| DIS-003 | P2 | W | Appeal | Appeal; resolve upheld | Sanction cleared. With denied: sanction kept |
| DIS-004 | P2 | W | Close with appeal pending | Close while appealed | "This case has a pending appeal — resolve it before closing the case." |
| DIS-005 | P2 | W | Termination sanction | Decide termination effective tomorrow; close | The employee moves to terminated on that date (exit clearance starts) |
| DIS-006 | P3 | W | Bad transition | Decide a closed case | "Cannot move a case from closed to decided." |
| DIS-007 | P1 | R | Visibility | SUP views cases (view only); EMP2 views own case | SUP sees within reach, cannot manage; EMP2 cannot see the case list |

## 9. Retirement cases

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| RET-001 | P2 | W | Initiate | HR initiates for an employee aged 59 | Case with eligibility date = date of birth + retirement age (default 60) |
| RET-002 | P2 | W | One open case | Initiate a second | "This employee already has an open retirement case." |
| RET-003 | P2 | W | Through to finalise | Review, approve, finalise | Employee status retired; exit clearance starts. No pension benefit is calculated (by design) |
| RET-004 | P3 | W | Cancel | Cancel an approved case | Cancelled; employee unchanged |
