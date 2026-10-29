# E2E specification 06: leave, approvals, letters, exit clearance and cases

Columns, personas and modes: see the [README](README.md#how-a-test-case-is-written). Run in QA Audit.

**Ground rules:**

- Leave is counted in whole working days: the organisation's working week (Monday to Friday by
  default) minus active holidays (organisation-wide or the employee's branch). Dates are Gregorian
  underneath; the Ethiopian calendar is a display choice. **There is no half-day leave.**
- Remaining = entitled + carried − used − pending. **Pending days are subtracted at once** when a
  request is submitted.
- Annual entitlement = the type's default days + 1 day for every 2 completed years of service
  (Labour Proclamation 1156/2019, Art. 77(1)).
- No backdating: a request must start today or later.
- Every decision is one step unless the organisation configured an approval chain.

---

## 1. Leave types

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LT-001 | P1 | R | Defaults | HR: Settings → Leave types (organisation set up by the wizard) | Annual 16 days, monthly accrual, carry forward capped at 16, +1 per 2 years, 7 days' notice; Sick 30 (attachment required); Maternity 120 (women); Paternity 3 (men); Bereavement 3; Marriage 3; Study 10 (14 days' notice); Sabbatical 180 (90 days' notice); Injury 30; R&R 5; Unpaid 0 (unpaid, 14 days' notice) |
| LT-002 | P2 | W | Create and duplicate | Create "QA Compassionate" 2 days; duplicate it | Saved; the copy is separate |
| LT-003 | P2 | W | Deactivate | Deactivate QA Compassionate; EMP tries to request it | Not offered; through the API refused (type must be active) |
| LT-004 | P2 | R | Rights | SUP opens leave type settings | View only (everyone holds `leave.viewTypes`); `leave.manageTypes` is HR |
| LT-005 | P1 | R | Kinds that settle absences | HR: Settings → Leave types in QA Audit and in a new `uat-` organisation | Both have "Official Duty / Business Trip" (duty) and "Excused Absence" (excused), both paid, plus an unpaid type. Duty and excused days use no balance and are absent from balance cards and the leave balance report |

## 2. Balances

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LB-001 | P1 | R | Balance cards | EMP: Leave | One card per type: remaining (large) and used of entitled |
| LB-002 | P1 | R | Service increment | An employee hired 4 years and 2 months ago | Annual entitlement 18 (16 + 2) |
| LB-003 | P2 | R | Monthly accrual | Check an annual balance before and after the 1st of the month (accrual at 00:30 UTC) | Raised towards entitlement × months employed this year ÷ 12, rounded to one decimal; never lowered |
| LB-004 | P2 | R | Carry forward | After 1 January (01:00 UTC) | Carried = min(remaining, cap); it overwrites the previous carried figure |
| LB-005 | P1 | W | Opening balance import | HR: import leave balances; template headers `employee_code, leave_type_code, year, entitled_days, used_days, carried_days`; preview 3 rows, one with used greater than entitled + carried, one duplicate | Bad rows reported with reasons; **commit writes nothing while any row is invalid**; after fixing, balances replaced |
| LB-006 | P2 | W | Year bounds | Import year 1999 | Refused (2000 to 2100) |
| LB-007 | P2 | R | Rights | SUP opens the balance import | Refused (`leave.adjustBalance` is HR) |
| LB-008 | P2 | R | Another employee's balance | SUP: `/leave/balance/{EMP}`; `/leave/balance/{EMP3}` | EMP shown; EMP3 refused |

## 3. Requests

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LR-001 | P1 | W | Request | EMP: Apply → Annual, next Monday to Friday (no holiday) | Pending; 5 days; remaining down by 5 at once; SUP notified; `leave.requested` webhook (if subscribed) |
| LR-002 | P1 | W | Weekend and holiday excluded | A request from Friday to the next Tuesday with Monday a public holiday | 3 days counted |
| LR-003 | P1 | W | Only non-working days | A request covering only Saturday and Sunday | "The selected dates do not include any working days." |
| LR-004 | P1 | W | Backdating | Start yesterday | Refused (`start_date` must be today or later) |
| LR-005 | P1 | W | End before start | End before start | Refused |
| LR-006 | P1 | W | Insufficient balance | Annual for more days than remain | "You do not have enough leave balance for this request." |
| LR-007 | P1 | W | Overlap | A second request overlapping a pending one | Refused. **Known wording defect:** the message says "approved" even when the clash is with a pending request |
| LR-008 | P1 | W | Gender | A male employee requests Maternity | "This leave type is not available for your gender." |
| LR-009 | P2 | W | Notice | Annual starting in 3 days (7 days' notice) | Refused. **Known defect:** the message may show a raw key (`leave.min_notice`); record it |
| LR-010 | P2 | W | Maximum consecutive | If a type has a maximum, exceed it | Refused. **Known defect:** may show `leave.max_consecutive` |
| LR-011 | P1 | W | Cancel pending | EMP cancels LR-001 | Cancelled; the 5 days return |
| LR-012 | P1 | W | Cancel decided | EMP cancels an approved request | Refused: "This leave request is no longer pending." An approved leave cannot be cancelled by the employee (HR handles it) |
| LR-013 | P1 | R | Cancel someone else's | SUP cancels EMP's request | 403 "You can only modify your own leave requests." |
| LR-014 | P2 | W | Ethiopian calendar | With the Ethiopian calendar on, request across Pagume (the 13th month, early September) | Days counted correctly across Pagume and into Meskerem |
| LR-015 | P2 | W | Amharic reason | A reason in Amharic | Stored and shown intact |
| LR-016 | P1 | W | Unpaid leave and balance | EMP requests Unpaid Leave (0 days left) 15 days ahead | Pending. No longer refused with "You do not have enough leave balance for this request." |
| LR-017 | P1 | W | HR files for an employee | HR: `POST /leave/request` with `employee_public_id` EMP3, yesterday, Excused Absence; HR approves it; SUP and FIN file for EMP3; an unknown `employee_public_id` | 201 pending; backdating and notice rules not applied; `filed_for` in its history. HR approving: "You filed this request, so someone else must approve it." SUP, FIN: 403. Unknown: "The selected employee public id is invalid." |

## 4. Decisions and the approval inbox

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LD-001 | P1 | W | Approve | SUP: Approvals → approve EMP's request | Approved; pending moves to used; EMP notified; `leave.approved` webhook |
| LD-002 | P1 | W | Reject | SUP rejects without, then with, a reason (up to 1,000) | Reason required; pending released; EMP notified |
| LD-003 | P1 | R | Self-approval | SUP requests leave and tries to approve it | "You cannot approve your own leave request." (403 `self-approval`) |
| LD-004 | P1 | R | Outside reach | SUP tries to approve EMP3's request (not a report) | Refused |
| LD-005 | P2 | W | Already decided | Approve twice | Second refused (not pending) |
| LD-006 | P1 | W | Batch | SUP selects 3 requests (2 leave, 1 correction) and approves; one item is SUP's own | Per-item results: approved, approved, error with the reason. A batch reject without a reason uses "Batch rejected" |
| LD-007 | P1 | R | Pending count | Sidebar Approvals badge | Equals the number of items waiting for this person |
| LD-008 | P2 | R | Team calendar | SUP: team leave calendar for this month | Approved leave of direct reports overlapping the month |
| LD-009 | P2 | R | Mobile tab | On a phone, an approver's bottom bar | Approvals with its count replaces Payslips |

## 5. Approval chains (engine)

Configured at Settings → Approval chains (needs `settings.manage`). Applies to leave, attendance
corrections, profile updates and overtime. Payroll does not use it (it has maker-checker); loans do
not use it. **Delegation and return-for-correction are not built.**

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| CH-001 | P1 | W | Two-step chain | Leave chain: step 1 supervisor, step 2 permission `leave.approve` (HR); SLA 48 h | Saved. More than 5 steps, SLA 0 or 721 refused |
| CH-002 | P1 | W | Walk the chain | EMP requests leave; SUP approves | Still pending; HR notified as next approver; EMP sees it at step 2 |
| CH-003 | P1 | W | Final step | HR approves | Approved; balance moved; history shows both steps |
| CH-004 | P1 | R | Wrong step | HR tries to approve at step 1 before SUP | "This request is waiting for another approver at its current step." |
| CH-005 | P1 | R | Same person twice | A person who approved step 1 is also eligible for step 2 | Not allowed to approve step 2 |
| CH-006 | P2 | W | Conditions and priority | A second chain for D2 only, higher priority | EMP2 (D2) follows it; EMP (D1) follows the first |
| CH-007 | P2 | W | Edit in flight | Change the chain while a request is pending | The pending request keeps the chain it started with |
| CH-008 | P2 | W | Department head step | Step kind "department head" with D1's head set | The head is asked; if no head is set, any permission holder may decide and the history records a fallback |
| CH-009 | P2 | W | Delete chain | Delete all chains | New requests use one step decided by any permission holder who reaches the employee |
| CH-010 | P2 | X | Reminder and escalation | Leave a step waiting over 48 h | Daily 06:00 UTC reminder to the named approver; past the SLA, once: the approver's supervisor (or every HR admin) is told and the step opens to all eligible approvers. **Never auto-approved** |
| CH-011 | P2 | W | Other request types | Chains for correction, profile update, overtime | Each follows its chain like leave |

## 6. Exit clearance

Records the leaving checklist. **It never blocks the exit, the final pay or the login closing.**

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| EXC-001 | P1 | W | Starts by itself | Resign a test employee (LIFE-003) | Clearance with lines ID card (HR), property (HR), handover (supervisor), obligations (finance), final pay (finance); listed at Employees → Exit clearances |
| EXC-002 | P1 | W | Who ticks what | SUP ticks handover; FIN ticks obligations and final pay; HR ticks anything; SUP tries final pay | Allowed as listed; SUP on a finance line refused |
| EXC-003 | P1 | W | Balance owed | The employee has an active loan; FIN ticks obligations without a note | "A balance is still owed. Add a note to say how it is being settled." |
| EXC-004 | P1 | W | Complete | Tick every line | Status complete |
| EXC-005 | P2 | W | Template | TA: Settings → Exit clearance, add a line (max 20 lines) | New clearances include it |
| EXC-006 | P2 | W | Manual start | Start a clearance for an employee who already has an open one | The existing one is returned; no duplicate |

## 7. Employment letters

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| LET-001 | P1 | W | Ask | EMP: Profile → letters, purpose "QA bank loan", addressed to, include salary, language English; another in Amharic | Pending; purpose under 3 characters refused; more than 10 requests an hour refused |
| LET-002 | P1 | W | Issue | HR: Employees → Letters → issue | Reference `EL-YYYY-NNNN`; EMP can download the PDF; the PDF shows the current record and the salary only if asked |
| LET-003 | P1 | W | Reject | HR rejects with a reason (3 to 500) | EMP sees the reason; PDF not available (404) |
| LET-004 | P1 | R | Own letter | HR decides a letter about HR | 403 "You cannot decide a letter about yourself." |
| LET-005 | P2 | W | Decided twice | Issue an issued letter | 422 "This request has already been decided." |
| LET-006 | P2 | R | Amharic letter | Open the Amharic PDF | Amharic renders correctly, nothing missing |

## 8. Disciplinary cases

States: reported → investigating (on the first note) → decided → appealed → closed. Needs
`disciplinary_case.manage` (HR) and reach; the subject cannot decide their own case.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| DIS-001 | P1 | W | Open and investigate | HR opens a case on EMP2; adds a note | Reported, then investigating |
| DIS-002 | P1 | W | Decide | Decision guilty, sanction written warning | Decided |
| DIS-003 | P1 | W | Appeal | Appeal; resolve upheld | Sanction cleared. With denied: sanction kept |
| DIS-004 | P1 | W | Close with appeal pending | Close while appealed | "This case has a pending appeal — resolve it before closing the case." |
| DIS-005 | P1 | W | Termination sanction | Decide termination effective tomorrow; close | The employee moves to terminated on that date (exit clearance starts) |
| DIS-006 | P2 | W | Bad transition | Decide a closed case | "Cannot move a case from closed to decided." |
| DIS-007 | P1 | R | Visibility | SUP views cases (view only); EMP2 views own case | SUP sees within reach, cannot manage; EMP2 cannot see the case list |

## 9. Retirement cases

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| RET-001 | P1 | W | Initiate | HR initiates for an employee aged 59 | Case with eligibility date = date of birth + retirement age (default 60) |
| RET-002 | P1 | W | One open case | Initiate a second | "This employee already has an open retirement case." |
| RET-003 | P1 | W | Through to finalise | Review, approve, finalise | Employee status retired; exit clearance starts. No pension benefit is calculated (by design) |
| RET-004 | P2 | W | Cancel | Cancel an approved case | Cancelled; employee unchanged |
