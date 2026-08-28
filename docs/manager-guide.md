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
assignment (see the Admin Guide → *Role and permission management*).

| Tool | Sidebar location | Required capability |
|------|------------------|---------------------|
| Approvals | **My Work → Approvals** | Supervisor |
| Team Attendance | **Operations → Attendance → Team Attendance** | Supervisor |
| Corrections | **Operations → Attendance → Corrections** | Supervisor |
| Overtime | **Operations → Attendance → Overtime** | HR Admin |
| Reports | **Finance → Reports** | `reports.view` |

> The **Approvals** item shows a live red badge with the number of items
> currently waiting for you. The count refreshes on its own — you do not need to
> reload the page.

---

## Your Dashboard

When you log in with a supervisory role, your dashboard includes manager widgets
in addition to your personal ones:

- **Team attendance today** — a live present / absent / late / on-leave summary
  for your team, refreshed every minute.
- **Pending approvals** — a count of items awaiting your review, linking to the
  Approval Center.
- **Team overtime** — accumulated overtime for the current month.
- **Team on leave** — who is out today and in the days ahead.

These give you a single glance at whether your team is covered before you open
any of the detail screens below.

---

## Approval Center

**Sidebar:** My Work → Approvals

The Approval Center is a single queue for everything that needs your decision:

- **Leave requests** submitted by your team
- **Attendance corrections** (a request to fix a missed or wrong punch)
- **Profile update requests** for approval-controlled fields (bank details,
  legal name, TIN)

Each row shows the employee, a short summary of the request, and when it was
submitted.

### Approving or rejecting

1. Open **Approvals**.
2. Review the request summary. Click the employee name to open their record if
   you need more context.
3. Click **Approve** or **Reject**.
4. When rejecting, the request is returned to the employee so they can see it was
   declined.

Approvals are **not** applied optimistically — the screen waits for the server to
confirm the decision before updating. This is deliberate: approving leave changes
the employee's leave balance, and approving a correction changes an official
attendance record, so ETHR confirms the write succeeded before showing it as
done.

> **Approval integrity:** you cannot approve your own request, and a request
> cannot skip a required step in its approval chain. These rules are enforced on
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
editing the record silently. An employee requests a change; you review it in the
Approval Center. Because the original record and the correction are both kept,
attendance stays fully auditable — nothing is overwritten in place.

---

## Team Leave Calendar

The **team leave calendar** shows, for a chosen month, who on your team is on
leave on each day. It is available from your manager dashboard.

- Each employee has a row; each day is a cell.
- Days on leave are colour-coded by **leave type** (annual, sick, etc.).
- A **daily summary** row shows how many people are out on each day, so you can
  spot days when too much of the team is away before you approve another request.

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

The report builder lets you produce ad-hoc and recurring reports across
employees, attendance, leave, and payroll data — scoped to what your role is
allowed to see.

Typical flow:

1. Pick a **data source** (e.g. Attendance, Leave).
2. Choose the **columns** to include.
3. Add **filters** (date range, department, status) and optional **grouping** and
   **sorting**.
4. Click **Preview** to check the shape of the result, then **Generate**.
5. **Export** the result, **Save** the report definition for reuse, or
   **Schedule** it to be generated and emailed on a recurring basis.

Saved and scheduled reports appear in their own tabs so you can re-run or edit
them later.

---

## Tips

- **Notifications.** You are notified when a new item needs your approval and when
  your own submissions are decided. Check the notification bell (top bar) and the
  Approvals badge.
- **Time zone.** All times display in Ethiopian time (EAT, UTC+3). Ethiopia does
  not observe daylight saving, so the offset never changes.
- **Language.** Every screen is available in English and Amharic — switch with the
  language selector in the user menu.
- **Mobile.** Manager screens are responsive; you can review and clear your
  approval queue from a phone.

---

*See also: the **Admin Guide** for organisation, employee, payroll, and settings
administration, and the **User Guide** for the employee self-service features
your team members use.*
