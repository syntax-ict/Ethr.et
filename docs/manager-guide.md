# ETHR Manager Guide

**For Supervisors and Managers**

---

## Who This Guide Is For

This guide covers the day-to-day tools available to people who lead a team:
**supervisors**, **department/branch managers**, and **HR admins** acting in a
line-management capacity. Manager tools appear automatically once your account
has a supervisory role — you do not need to switch modes.

If a screen described here is not visible in your sidebar, your account does not
yet have the required role. Ask your Tenant Admin to review your role
assignment (see the Admin Guide → *User Management → Roles*).

| Tool | Sidebar location | Required capability |
|------|------------------|---------------------|
| Approvals | **My Work → Approvals** | Supervisor |
| Team Attendance | **Operations → Attendance → Team Attendance** | Supervisor (`attendance.viewTeam`) |
| Corrections | **Operations → Attendance → Corrections** | Supervisor |
| Overtime | **Operations → Attendance → Overtime** | HR Admin (`attendance.viewAll`) |
| Reports | **Finance → Reports** | HR Admin, Finance Admin or Tenant Admin (`report.generate`) |

Supervisors and department admins do not have Reports.

> The **Approvals** item shows a red badge with the number of items currently
> waiting for you. It refreshes every minute while the tab is visible, and
> immediately after you approve or reject something — you do not need to reload
> the page.

---

## Your Dashboard

When you log in with a supervisory role, your dashboard includes manager widgets
in addition to your personal ones:

- **Team attendance today** — a present / absent / late / on-leave summary for
  your direct reports.
- **Pending approvals** — a count of items awaiting your review, linking to the
  Approval Center.
- **On leave this week** — who on your team is out this week.

These widgets refresh every minute while the tab is visible, and immediately
after any approve or reject decision.

These give you a single glance at whether your team is covered before you open
any of the detail screens below.

---

## Approval Center

**Sidebar:** My Work → Approvals

The Approval Center is a single queue for everything that needs your decision:

- **Leave requests** submitted by your team
- **Attendance corrections** (a request to fix a missed or wrong punch)
- **Profile update requests** for approval-controlled fields (bank details,
  legal name, TIN) — these appear only for people who can update employee
  records (HR Admin, Finance Admin, Tenant Admin)

All three types appear in one list; there are no sub-tabs. Each row shows the
employee's name, a short summary of the request in your language, and when it
was submitted.

### Approving or rejecting

1. Open **Approvals**.
2. Review the request summary. The employee name is plain text; to see their
   record, open it from **People → Employees** if your role allows.
3. Click **Approve** or **Reject**.
4. When rejecting, you must give a reason. It is recorded and shown to the
   employee.

Approval is a **single step**: your approval finalises the request. There is no
second approver after you. You can decide a request when you hold the approval
permission and the employee is within your reach — your direct reports as a
supervisor, your department as a department admin, everyone as an HR, Finance or
Tenant Admin.

Approvals are **not** applied optimistically — the screen waits for the server to
confirm the decision before updating. This is deliberate: approving leave changes
the employee's leave balance, and approving a correction changes an official
attendance record, so ETHR confirms the write succeeded before showing it as
done.

> **Approval integrity:** you cannot approve your own request, and you cannot
> decide a request for someone outside your reach. These rules are enforced on
> the server, not just in the interface.

When the queue is clear you will see an **"All caught up!"** message.

---

## Team Attendance Monitoring

**Sidebar:** Operations → Attendance → Team Attendance

This screen shows your team's attendance for a single day.

- Use the **date picker** or the **‹ ›** arrows to move between days. The **Today**
  button jumps back to the current day.
- Each row lists the employee, date, **check-in** and **check-out** times, the
  **source** of the record (web, mobile, kiosk, biometric device, or manual), and
  a colour-coded **status** badge (present, late, absent, on leave, etc.).
- Times are shown in Ethiopian time (EAT, UTC+3).

Status is conveyed by both an icon and a colour, so it remains readable for
colour-blind users and in high-contrast mode.

If a punch is wrong or missing, the employee (or you, if permitted) can raise a
**correction**, which then arrives in your Approval Center.

---

## Attendance Corrections

**Sidebar:** Operations → Attendance → Corrections

Corrections are how a bad or missing attendance record gets fixed without
editing the record silently. An employee requests a change with a reason (there
is no evidence attachment); you review it in the Approval Center.

Approving writes the corrected times onto the attendance record and marks it as
corrected. Nothing is lost: the original punch times stay on the record, and
the audit log shows the times before and after along with the correction
request and your decision. Approving does not flag payroll for recalculation.

---

## Team Leave Calendar

**Sidebar:** My Work → Leave → **Team** tab → calendar view (the grid icon)

The team leave calendar is a month grid. Each day shows badges with the first
names of team members on leave that day, coloured by status: **approved** or
**pending**. Up to three names fit in a day; more show as "+N more". Hover a
badge for the full name and leave type. Use the arrows to change month.

Use it alongside the Approval Center: check the calendar for coverage clashes
before approving overlapping leave.

---

## Overtime Monitoring

**Sidebar:** Operations → Attendance → Overtime

This screen summarises overtime worked by your team so you can keep labour cost
and fatigue under control.

1. Choose **This week** or **This month** from the period selector.
2. Review the three summary cards:
   - **Employees with OT** — how many people logged any overtime in the period.
   - **Total OT hours** — the combined total across the team.
   - **Over threshold** — how many employees exceeded **10 hours** of overtime in
     the month. This card turns red when anyone is over.
3. The per-employee table lists days with overtime and total hours, sorted from
   most to least. Employees over the threshold are flagged with an **"Over
   threshold"** badge; everyone else shows **OK**.

Use the flagged list to plan schedules, redistribute workload, or confirm that
overtime is authorised before it reaches payroll.

---

## Reports

**Sidebar:** Finance → Reports

Reports are available to HR Admins, Finance Admins and Tenant Admins only
(`report.generate`). Supervisors and department admins do not see this screen.

The report builder produces ad-hoc and recurring reports across employees,
attendance, leave, and payroll data.

Typical flow:

1. Pick a **data source** (Employees, Attendance, Leave, Payroll).
2. Choose the **columns** to include.
3. Add **filters** — status for employees, a date range for attendance, the year
   for leave; payroll has none — and optional **grouping** and **sorting**.
4. Click **Run Preview**.
5. **Download CSV**, or **Save as template** for reuse. From the **Saved** tab, a
   saved report can be run again or **Scheduled** to be emailed daily, weekly or
   monthly as a CSV attachment.

Saved and scheduled reports appear in their own tabs. They can be deleted, not
edited. The **Quick Reports** tab runs one report per data source.

---

## Tips

- **Notifications.** You are notified when a new item needs your approval and when
  your own submissions are decided. Check the notification bell (top bar) and the
  Approvals badge.
- **Time zone.** All times display in Ethiopian time (EAT, UTC+3). Ethiopia does
  not observe daylight saving, so the offset never changes.
- **Language.** The interface is available in English and Amharic — switch with
  the globe icon in the top bar. Some newly added Amharic strings are still
  awaiting review by a native speaker.
- **Mobile.** Manager screens are responsive; you can review and clear your
  approval queue from a phone.

---

*See also: the **Admin Guide** for organisation, employee, payroll, and settings
administration, and the **User Guide** for the employee self-service features
your team members use.*
