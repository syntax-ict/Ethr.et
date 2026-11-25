# ETHR Admin Guide

**For Tenant Admins and HR Admins**

---

## Getting Started

### First Login

You register your organization at `https://ethr.et`. Registration creates your account as **Tenant Admin** with the password you chose, and takes you straight to the guided setup.

> URL format: `https://ethr.et/{your-organization}` — the organization name you chose at registration. If a platform admin has assigned your organization its own domain, that domain is used instead.

### Setup Wizard

The guided setup is at `/setup/guided` (`/setup` redirects there). While setup is incomplete, a progress banner in a Tenant Admin's sidebar links back to it. It has 4 steps:

| Step | What it does |
|------|--------------|
| 1 | **Configure** — pick your industry, approximate employee count and region; ETHR generates a configuration (departments, positions, grades, shifts, leave types, holidays) that you review, trim and apply |
| 2 | **Access** — choose how employees log in (email, phone, employee code, username). It comes before the people are added, so that how they sign in is settled before anyone is invited (2026-11-16) |
| 3 | **Workforce** — bring in your people, either from an attendance device's enrolled users or from a pasted list; review each match and commit. "Invite them to sign in as soon as they are added" starts unticked here, and invitations are sent later from the card that follows or from Employees |
| 4 | **Go live** — a readiness score with the remaining gaps and links to fix them, then go live |

You can skip steps and return later. All settings are editable after setup.

---

## Organization Structure

Navigate to **People → Organization**. The page has tabs for structure, reporting lines, branches, departments, teams, positions, grades and cost centers.

### Branches

Each branch has:
- Name and address
- Geofence (latitude, longitude, radius) — used for mobile attendance validation

### Departments

Departments can be hierarchical (parent → child) and can belong to a branch.

### Positions & Grades

Create positions (job titles) and grades (seniority levels). A grade can carry a salary band (minimum and maximum).

---

## Employee Management

### Adding Employees

**Individual:** Employees → New Employee → fill all profile fields.

**Bulk import:** Employees → Import → download the CSV template, fill it in, upload and preview before committing.

### Employee Lifecycle

Every status change is tracked:

```
Hired → Probation → Confirmed
  └──────────────────→ Confirmed
Probation → Terminated
Confirmed → Suspended / Resigned / Terminated / Retired
Suspended → Confirmed / Terminated
```

Resigned, Terminated and Retired are final. Transfers and promotions are not statuses; they are recorded on the employee's **History** tab as personnel actions.

To transition: open the employee record → **Lifecycle** tab → **Transition Status**.

### Self-Service Approvals

When an employee updates sensitive fields (name, TIN, date of birth, bank details), the change is **held pending HR approval**. It appears in the single **Approvals** list (My Work → Approvals) alongside leave and corrections, for anyone who can update employee records.

---

## Attendance Configuration

### What is set up for you

A new organisation can take attendance from its first punch. At sign-up, and
again every hour for any organisation still missing it, ETHR creates the
**baseline** for the organisation's industry: the attendance settings, a
default schedule, the working week, and this year's and next year's public
holidays. The schedule comes from the industry's usual workforce profile
(see [`INDUSTRY_TEMPLATES.md`](INDUSTRY_TEMPLATES.md)); an organisation with
no industry gets `standard_office`, 08:30–17:30 Monday to Friday.

The baseline only creates what is missing and never changes what you set.
Applying a different profile later replaces the baseline schedule only while
nobody has punched against it and nothing is assigned to it.

**Settings → Attendance Rules** opens with a **setup status** panel that lists
what still needs you, most serious first, each with a link to fix it:

| Check | Severity |
|---|---|
| No default schedule | Blocker |
| Location required while a branch has no coordinates | Blocker |
| A crew rotation nobody is assigned to | Blocker for 24/7 and three-shift profiles, warning otherwise |
| Mobile check-in on with no branch placed on the map | Warning |
| Punches left *pending* (no shift to rate them) in the last 7 days | Warning |
| A device silent for more than 24 hours | Warning |
| Kiosk PIN required while employees have none | Warning |
| Field staff with no work site | Warning |
| Holidays missing for this year or next | Warning |
| Working week differs from the default schedule | Information |

### Shift Setup

Operations → Shifts & Schedules → create shifts with:
- Start and end time. A shift whose end is at or before its start runs past
  midnight, and a punch after midnight belongs to the night it started.
- Grace period (minutes before marking late). Grace lives on the shift; a new
  shift starts with the default schedule's values.
- Working days

There is always exactly one active **default** schedule, used for anyone with
no assignment. It cannot be deleted, deactivated or un-defaulted until another
shift takes its place. For a six-day week the default can be a **rotation**
(five weekdays, a Saturday half day, Sunday off), anchored on a Monday.

Assign shifts or rotations to individual employees, departments, or branches.
Crews on a 24/7 or three-shift rotation each need their own assignment and
start date: the setup status reminds you, it does not guess.

### Attendance Methods

Settings → Attendance Rules → enable/disable sources per your organization:

| Method | Best for |
|--------|---------|
| Biometric device | Factories, banks |
| Mobile (GPS) | Field workers |
| QR code | Shared entrances |
| Kiosk | Reception desks |
| Web | Office workers |
| Manual | Manual correction by HR |

A method you turn off disappears from the employee's menu and is refused by
the server.

### Locations: branches, work sites and the map

Each branch has a location and a radius. Set it on the map in the branch form
(Organization → Branches): drag the pin, search an address, press **Use my
current location** while standing there, or paste a Google Maps link or a
`9.0108, 38.7613` pair. The map is OpenStreetMap; it switches to Google Maps
once a platform administrator adds a Google Maps key in the platform console.

**Work sites** (Settings → Attendance Rules → Work sites) are extra places
people may punch at: a project site, a client's premises, a warehouse. Assign a
site to employees or to a whole department.

A phone's GPS is rarely exact, so a punch counts as inside when it is within
the radius after allowing for the phone's reported accuracy, up to 50 m.
With **location required** on, a punch outside every allowed place is
refused; with it off, the punch is recorded and flagged. A branch with no
coordinates never refuses anyone: the punch is recorded and flagged *location
not configured*, and the setup status asks you to place the branch.

### How each person works

On the employee's profile, **Attendance mode** is one of:

| Mode | Where they may punch |
|---|---|
| Office (default) | Their branch, and any site assigned to them |
| Field | Any site assigned to them or their department. Outside every site is flagged, not refused |
| Remote | Anywhere. The location is recorded; no geofence applies |

### Branch rules

Settings → Attendance Rules → **Branch rules** lets one branch differ from the
organisation: which methods are on, location required, selfie required, kiosk
PIN, the GPS accuracy limit, automatic check-out, arrival check-in and the
review threshold. A branch shows how many rules it changes; everything else is
inherited. Photo retention, the confidence threshold and overtime rules stay
organisation-wide, because they are legal and payroll matters.

The order is always organisation, then branch, then the employee's mode.

### Field visits

Field staff log each visit from the attendance page on their phone: the place,
an optional note and, with consent, a photo. A visit records where someone
was; it never changes worked hours. Managers see them under Attendance →
Visits. Visits taken offline are queued and sent when the phone reconnects.

### Reminders on the phone

Once the platform administrator has set the push keys (see
[`deployment/PLESK-GO-LIVE.md`](deployment/PLESK-GO-LIVE.md)), employees can
turn on **Even when ETHR is closed** on the mobile attendance page. ETHR then sends a
notification when a shift is about to start, when a check-in is missed after
the grace period, and when an approval is waiting, even with the app closed.
Reminders can arrive up to about five minutes late, because the host has no
background worker. On iPhone the app must be installed to the home screen
(iOS 16.4 or later).

### Presence checks

Punches show who arrived and who left. A presence check looks at the middle of
the day: a few times a shift, at a moment nobody can predict, ETHR sends
checked-in staff a notification asking them to confirm they are at work, and
they answer from where they stand.

Settings → Attendance Rules → **Presence checks**: choose **Off** (the
default) or **Up to 1, 2 or 3 a shift**. A branch can set its own under Rules
by branch. **Tell your staff in writing before you switch it on.**

- Who is asked: people who are checked in at a workplace on a working day, who
  have a place on the map to be measured against, and who allowed
  notifications. Remote and field staff, people on leave and people on a break
  are not asked. On iPhone, notifications need ETHR added to the Home Screen.
- When: at an unpredictable time inside the shift, never around its middle
  (where lunch usually falls), never in the first or last half hour. It can
  arrive a few minutes late because the scheduler runs when it is called.
- What staff see: the notification opens the attendance page, which shows
  **Confirm you are at work** with the time left and one button, **I am here**.
  They have 15 minutes. A phone registered for punching signs the answer like a
  punch. Offline it cannot be confirmed; an answer after the 15 minutes is
  still recorded, marked late.
- What a reviewer sees: an answer from outside the workplace opens a high item
  in Attendance → Integrity, "Outside the workplace at a presence check", with
  the distance, the nearest place and the times. Two or more checks missed or
  unverified in 14 days, and at least half of those sent, open "Presence checks
  missed or unverified". **One missed check files nothing.**
- The reviewer's choices are **Excused** and **Not excused**. Either way the
  day's record is kept and the decision is recorded. Rejecting a flag about a
  punch (check-in, check-out, day) still voids it.

The attendance setup list on the settings page shows how many people the
checks cannot reach: no notifications on their phone, or no place set for
their branch.

### Registered phones

Settings → Attendance Rules → Cheating protection → **Registered phone** stops
buddy punching: one employee punching for another from a friend's phone, a
second phone or another browser.

Each person registers the one phone they punch with. The phone keeps a
**passkey**: a key held in its secure hardware that cannot be copied, unlocked
by the phone's own fingerprint, face or PIN. Every punch from the phone, the
web page, a QR scan or a field visit carries a fresh signature from it, which
the server checks before recording anything. A different phone, a second
browser or a private window holds no passkey for that person.

| Setting | What happens to a punch from a phone that is not the person's registered one |
|---|---|
| Off | Nothing is checked |
| Check and review | It is recorded and scored `device_unverified` for review |
| Only the registered phone | It is refused, and the person is told why |

A branch can choose differently from the organisation (Branch rules).

- **Registering.** The first phone registers by itself only when the person is
  at work, inside their branch or a work site. Registering anywhere else, or
  replacing a phone, waits for approval under Attendance → Devices.
  HR admins and organisation admins are told in the app when one is waiting.
  A waiting replacement shows the phone it replaces and when that phone last
  punched; approving it retires the old phone at the same moment.
- **A new or lost phone.** The person opens the punch screen on the new phone.
  It names their registered phone and offers **Register this phone**, which
  sends the new one for approval. The old phone keeps working until then.
- **Refusing and revoking.** Both ask for a reason. The person is told what
  happened and why, and the reason is kept in the audit log.
- **Leavers.** When someone resigns, is terminated or retires, their phone is
  revoked and any waiting request closed, so the phone can be handed to a
  colleague.
- **One phone, one person.** A phone or browser already registered to a
  colleague cannot be registered again, and a passkey belonging to someone
  else is refused.
- **Computers.** On a computer, the person's phone confirms the punch through
  the phone prompt the browser shows. If your office has its own fixed
  internet address, list it under **Office networks for computer punches**: web
  punches from it need no phone and are marked for review.
- **Exemptions.** A person whose phone cannot hold a passkey, or is broken, can
  be exempted until a date (up to three months) from their profile, or with
  **Punch without a phone** under Attendance → Devices. Their punches are
  accepted meanwhile and still sent for review.
- **Offline.** A punch made with no signal is signed by the registered phone
  over its own time and place, and checked when it syncs, so it cannot be
  edited or made on another phone.
- **Automatic check-in** cannot use the passkey, because the phone only shows
  its prompt after a tap. With **Only the registered phone**, the person taps
  to check in.
- **Kiosk, biometric devices and manual entries** are never asked for a phone.

**Rolling it out:** choose **Check and review**, let staff register as they
punch at work, and watch **Still to register** under Attendance → Devices. It
counts only the people whose phone is checked (their branch checks phones,
lets them punch from a phone or browser, and they are not exempt); click it
for their names. Those are the people **Only the registered phone** would
stop, so choose it once the list is empty or each name is handled. What it cannot stop is a person handing their own
unlocked phone to a friend; the selfie, the live face check and the review
queue cover that.

### Cheating protection

Every punch gets a **risk score** from 0 to 100, with the reasons that make it
up shown to reviewers. It is a fixed, explainable scoring rather than a black
box: each reason has a set weight, and the comparison is against your own
records and each person's own habits. Nothing is sent to an outside service.

The reasons include: outside the allowed place, a location that looks faked,
impossible travel between two punches, an offline punch that cannot be
verified, a phone clock far from the server's, **one phone used by two
employees** (buddy punching), several employees from one address within two
minutes (your office's usual addresses are learned and excluded), a
**reused photo** (the same selfie again, or a photo of someone else's),
automation, and a place or arrival time far outside the person's usual
pattern.

- At or above the **review threshold** (40 by default, adjustable per branch)
  the punch opens a *high risk* item in Attendance → Integrity and reviewers
  are notified. **The punch is still recorded**: nobody is refused for a score.
- A payroll run lists any high-risk items still open before it is approved.
- Each night ETHR looks for pairs of employees who repeatedly punch within a
  minute of each other on the same phone or address, and for people who
  always arrive on the last minute of grace, and opens items for them.
- In Integrity, a reviewer sees the score broken down, the selfie, and the
  punch on a map with the allowed place and the GPS accuracy.

Settings → Attendance Rules → **Cheating protection**:

| Setting | What it does |
|---|---|
| Review threshold | The score at which a punch is sent for review |
| Adaptive selfies | With selfies not otherwise required: someone with 90 clean days is asked on about one day in five, chosen unpredictably; anyone newer, or with an open or rejected serious finding, every time |
| Live face check | Before a selfie, the phone asks for a blink or a head turn. It runs entirely on the phone and only the result is sent. A failed check is one reason among many, a phone that cannot run it counts for nothing, and nobody is refused for it |

Photos, their fingerprints and device identifiers follow the organisation's
photo retention period. See
[`operations/ATTENDANCE-PHOTO-CONSENT.md`](operations/ATTENDANCE-PHOTO-CONSENT.md).

### Biometric Devices

Devices → Register Device → choose the adapter (Hikvision, ZKTeco, Suprema, or a generic HTTP device) and enter its connection details. Registering, editing and removing devices needs a Tenant Admin; HR Admins can view them.

ETHR can receive punches two ways:

- **Pull** — the server connects to the device's address and reads new events; the scheduler checks every 5 minutes. The address must be reachable from the internet. Private and LAN addresses (for example `192.168.x.x` or `10.x.x.x`) are refused unless the deployment has `devices.allow_private_hosts` turned on, and it is off on shared hosting, where the server could not reach your office network anyway.
- **Push (webhook)** — for a device on your office LAN, configure it to send events to ETHR at `/api/v1/devices/webhook/hikvision` (or `/zkteco`, `/suprema`). The device proves who it is with the webhook token shown on the device's page to a Tenant Admin, or by sending from an address on the device's IP allowlist. A device with neither is refused.

See [`DEVICE_INTEGRATION.md`](DEVICE_INTEGRATION.md) for the adapters and their configuration.

### Attendance Correction Workflow

Employees submit correction requests with a reason and the corrected times. Approval is a **single step**: one approval finalises the request.

Who can approve: anyone holding the `correction.approve` permission whose reach covers the employee — a supervisor for their direct reports, a department admin for their department, an HR Admin, Finance Admin or Tenant Admin for everyone. Nobody can approve a correction to their own attendance.

Approving writes the corrected times onto the attendance record and marks it as corrected. The original punch times are kept on the record, and the audit-log entry for the decision shows the times before and after, along with the correction request (proposed times, reason, decision). Approving does not flag payroll for recalculation — if the date falls in a pay period that has already been processed, re-run that period yourself.

---

## Leave Management

### Leave Types

Settings → Leave Types → configure all types. When you apply an industry configuration during setup, leave types are created from ETHR's Ethiopian defaults (the industry decides which ones):

| Type | Days | Accrual |
|------|------|---------|
| Annual | 16 | Monthly |
| Sick | 30 | Annual |
| Maternity | 120 | One-time |
| Paternity | 3 | One-time |
| Bereavement | 3 | Annual |

Other defaults include marriage, study, sabbatical, work injury, rest & recuperation and unpaid leave. All values are configurable. Create custom types (study leave, compensatory, etc.).

### Working Week

The **Working week** card on Settings → Leave Types sets which days (Monday to Sunday) your organization works. Only an organization admin (`settings.manage`) can change it; others see it read-only. Leave requests count only days in the working week, and public holidays are excluded.

### Approval

Leave approval is a **single step**: one approval finalises the request and updates the balance. There is no configurable approval chain.

Who can approve: anyone holding the `leave.approve` permission whose reach covers the employee — a supervisor for their direct reports, a department admin for their department, an HR Admin, Finance Admin or Tenant Admin for everyone. Nobody can approve their own request.

### Ethiopian Calendar

Settings → General → **Display** → **Calendar system** → choose Ethiopian or Gregorian. This is the default for everyone in the organisation; each person can choose their own under Profile → Preferences. It controls how dates are displayed across the application, and date inputs show the other calendar's date beneath them. **Time of day** on the same card sets the default clock the same way.

Public holidays are managed at Settings → Holidays, which can add the Ethiopian public holidays in one step (Ethiopian New Year, Timkat, Meskel, etc.). Holidays are skipped when counting leave days. The [Settings Reference](#settings-reference) explains the Estimated mark and the next-year warning.

---

## Payroll

### Configuration

Settings → Payroll Rules. The page and its sidebar row follow the **View payroll configuration** permission (`payroll.viewConfig`), not a role name. The Finance Admin and the Tenant Admin hold it, and so does any custom role you give it. A viewer sees the same page with no Add, Edit, Delete or Save, and a note saying that changing the rules needs the **Manage payroll configuration** permission (`payroll.manageConfig`), which only the Tenant Admin holds by default. A platform operator signed in as a super admin does not see the row.

The page has two tabs, **Payroll setup** and **Statutory rules**, and `?tab=rules` opens the second. A plan without payroll shows the plan notice at the top.

Four cards on **Payroll setup** read and save the organisation's own settings, so they need **settings.manage** (the Tenant Admin) as well. Without it they are left out of the page rather than shown and failing to load:
- **Payroll schedule**: payroll run day, fiscal-year start month (Ethiopian months, Meskerem to Pagume), Pagume pay (a full month, or its days at the daily rate of annual salary ÷ 365), working days per month, and retirement age (45 to 75). The pay period is shown read-only.
- **Statutory filing**: the employer TIN, the tax centre and the pension scheme, printed at the top of the monthly income tax and pension schedules each run exports.
- **On printed documents**: the lines shown under the organisation name and logo on payslips, employment letters, schedules and report exports. Leave a line empty to leave it out.
- **Checks before approval**: a run lists each employee whose net pay moved by at least the percentage (1 to 100) and at least the amount (0 to 1,000,000 ETB) set here since their last released payslip. Approval is never blocked by it.

The cards below are open to every holder of `payroll.viewConfig`, and change only with `payroll.manageConfig`:
- **Allowances**
- **Income tax brackets**: the ladder the law sets, read-only for everyone. Payroll applies it as it stands and an organisation cannot change it.
- **Pension rules**: the contribution rates the law sets for each scheme (7% employee and 11% employer for the private and public schemes), read-only for everyone.
- **Overtime rates**: defaults are the statutory minimums: 1.5× daytime, 1.75× night, 2.0× weekly rest day, 2.5× public holiday. Lower values are refused.

**Statutory rules** lists what payroll applies today and where each rule comes from: the law for every organisation, your kind of organisation, or your own. **Change** leads back to Payroll setup, where the saving cards need `settings.manage`, so it is for the Tenant Admin.

**Accounting** (Settings → Accounting) follows the same split: anyone who can view all payroll data can read the chart of accounts and download the journal, and saving the chart takes `payroll.manageConfig`. Without it the page is read-only. A Finance Admin therefore reads the mapping and cannot rewrite it.

### Running Payroll

Finance → Payroll Runs → Process Payroll → select the pay period → confirm.

The engine:
1. Reads each employee's attendance and leave for the period: the overtime worked by rate, unpaid leave days, the Art. 85 sick-leave days and, when lateness deductions are on, the lateness minutes an approved correction decided. Absences are listed on the run and never deducted
2. Deducts unpaid leave days
3. Calculates basic salary (with proration for new hires/exits)
4. Adds overtime and allowances
5. Deducts income tax, pension, loans
6. Prepares payslips
7. Produces a bank transfer file (CSV)

### Approval Flow

After processing, a **Tenant Admin** reviews the run → Approve. Only Tenant Admins can approve, void or reprocess a run. Payslips become visible to employees immediately after approval.

### Payslip Access

Employees see their own payslips at **My Payslips** in the sidebar once the run is approved. **Download** gives a PDF with earnings (including each allowance), deductions, net pay, employer pension and the organization name. Amharic names print correctly; the payslip labels are in English only.

Finance Admins and Tenant Admins can view any employee's payslips.

---

## Reports

Reports are available to holders of `report.generate` — HR Admin, Finance Admin and Tenant Admin. Supervisors and department admins do not have them.

### Report Builder

Insights → Reports → Builder:
1. Select data source (Employees, Attendance, Monthly timesheet, Absences, Leave, Payroll). Payroll is offered only to holders of `payroll.viewAll`
2. Choose columns
3. Add filters (status and unit for employees; a period or a date range, and unit, for attendance, timesheet, absences and payroll; the year for leave), and optional grouping and sorting
4. **Run Preview**
5. **Download CSV**, or **Save as template**

A report shows at most 1,000 rows on screen and in a file, the newest first, and says so when it has cut any. Group totals are counted over every matching row, not only the rows shown. If a very large report cannot be totalled in time, the result says its totals are partial.

Changing the settings after a preview marks the preview out of date; run it again before saving or downloading, so what is saved is what was seen.

**Period.** Instead of fixed dates a report can use a rolling period: yesterday, the last 7 days, this month, last month, the last 3 or 12 months, this fiscal year or last fiscal year. Months are months of the organization's calendar, and the fiscal year starts in the month set under Payroll Rules. A rolling period is worked out each time the report runs. The monthly timesheet covers at most 31 days, so it takes only yesterday, the last 7 days, this month and last month; the absences report covers at most the newest 92 days of the period chosen.

The API's export endpoint also produces PDF. CSV and PDF are the only formats; there is no Excel export.

### Scheduled Reports

Schedule a saved report from the **Saved** tab for automatic email delivery, daily, weekly or monthly, to a list of email addresses. The report is emailed as a CSV attachment, and the email states the period it covers and whether rows were cut. Monthly schedules run on the first day of the month in the organization's calendar. On the Ethiopian calendar that is thirteen runs a year: the run on Pagume 1 covers Nehase, and the run on Meskerem 1 covers Pagume.

- Only the person who saved a report can schedule it. A schedule runs with that person's reach, and stops if they leave or lose the permission.
- Give a scheduled report a rolling period. A report saved with fixed dates sends the same dates every time.
- Pay figures are sent only to addresses that belong to someone in the organization allowed to see them.
- If a run fails, the recipients are told and the **Scheduled** tab marks the schedule as failed, saying whether the person who saved the report can no longer run it or the report could not be produced.
- A schedule set up before 15 November 2026 on a report somebody else saved keeps running with that person's reach. Delete it and have the report's owner schedule it again if that is not intended.

A saved report can be run, scheduled or deleted, and a schedule can be deleted; neither can be edited. Saving and scheduling depend on your plan.

### Monthly timesheet

The monthly timesheet reads the same figures payroll reads, so the two cannot disagree. Each row is one person for a month in your calendar (an Ethiopian month, Pagume included, or a Gregorian one):

- **Days**: workdays, present (late and early days are kinds of present), absent, leave, holidays, rest days and missing-punch days. An absence is a workday with no record, up to yesterday, in an organization that records attendance at all. Payroll flags absences on the run and never deducts them, so they are not taken off payable days.
- **Late and early**: the count of days and the minutes observed. The minutes payroll will deduct are the ones an approved lateness correction decided, and are shown apart.
- **Hours**: scheduled, worked, and short. Short hours are counted day by day, so a long day does not make up for a short one.
- **Overtime by rate**: one column for each rate the organization pays (1.5× day, 1.75× night, 2.0× rest day, 2.5× holiday are the legal minimums). Overtime worked is paid whether or not it was approved in advance; the timesheet shows how much was unapproved, and the days over the legal limits of 4 hours a day and 12 hours a week, from the rules in Payroll rules.
- **Status**: shown, never set by hand. Open, exceptions (a punch is still open or pending), reconciled, part-paid or paid (payroll has released the days). A day payroll has paid cannot be edited; a later fix goes into the next run.

**The grid.** On the **Quick reports** tab, the monthly timesheet card has **Open as a day-by-day grid**, which opens the month chosen above it. The page is `/reports/timesheet?from=…&to=…`, so a link or a reload keeps the month and the filters (department, branch, shift, employment status).

- Each row is a person, with one cell a day and a letter in it: **P** present, **L** late, **E** left early, **M** missing punch, **A** absent, **AL** annual, **SL** sick, **ML** maternity, **OL** other leave, **FD** field duty, **½** half day leave, **H** public holiday, **R** weekly rest day. A dot is a day that is not over. Weekends are shaded, and the legend under the sheet names every code.
- **Click a cell** to see what was scheduled and worked that day, the late and early minutes, the overtime by rate, and the punches behind it. A day payroll has paid is marked and cannot be corrected from here; any other day links to **Request a correction**. Seeing the punches needs `attendance.viewAll`; without it the drawer says the day has records.
- A warning mark at the end of a row means the row does not add up: its workdays, present days, overtime or hours disagree with the records. It should never show; if it does, tell support.
- Summary columns follow the days, then one overtime column for each rate the organization pays. **Payable days** is shown only to someone who may see payroll.
- Under the sheet: totals for everyone listed and for each department, the legend, and **How this is counted** (each shift's grace and early-departure minutes and break, how punches pair, how a night shift is dated, the overtime window and legal limits, and that absences are listed and not deducted).
- Totals and the status are shown for up to 200 people. For a longer list the sheet still pages through everyone, says so, and shows no status: choose a department, branch or shift to narrow it.
- On a phone, each person is a card with the month as a strip of days; tapping a day opens the same drawer.
- **Download CSV** saves the page as it reads, a code a day; **Print** prints the sheet from the browser. The A4 landscape PDF with signature blocks comes in a later release.

Through the API, `GET /reports/timesheet` returns a page of people with one cell per day, the totals over everyone the filters match, department subtotals, the rules applied and the status. The Builder's "Monthly timesheet" source carries the same figures as columns.

### Quick Reports

The **Quick Reports** tab lists ready-made reports: the standard ones, and those recommended for the organization's type and working pattern (for example staffing by grade for a government office, overtime by department for a factory on shifts). A month picker in your calendar applies to the timesheet, attendance, absence and payroll reports. Each can be downloaded as CSV.

The statutory outputs are on each payroll run, not in Reports: open the run and use **Export** for the payroll register, the bank file, the journal, and the income tax and pension schedules.

---

## Settings Reference

The **Settings** row in the sidebar is one row, and the settings pages have a navigation of their own on the left. **Each person sees only the pages their permissions allow**, so Settings is not only for the Tenant Admin: the HR Admin and the Finance Admin see it too, with fewer pages. The row opens the first settings page the person may use. Someone who holds none of the permissions below sees no Settings row.

### Who sees which page

The left navigation and the command palette read the same list, so a page that is in one is in the other. A page and the API behind it ask for the same permission.

| Page | Permission it follows | Built-in roles that have it |
|------|-----------------------|-----------------------------|
| General (organisation, address, display, employee numbers) and its Branding, Security and SSO tabs | `settings.manage` | Tenant Admin |
| Attendance rules | `attendance.manage` | HR Admin, Tenant Admin |
| Shift rules (opens Shifts & Schedules, outside the settings pages) | `shift.create` | HR Admin, Tenant Admin |
| Leave types | `leave.manageTypes` | HR Admin, Tenant Admin |
| Holidays | `holiday.create` to add, `holiday.update` to edit, `holiday.delete` to delete | Add and edit: HR Admin, Tenant Admin. Delete: Tenant Admin |
| Approval chains, Exit checklist | `settings.manage` | Tenant Admin |
| Payroll rules | `payroll.viewConfig` to read, `payroll.manageConfig` to change | Read: Finance Admin, Tenant Admin. Change: Tenant Admin |
| Opening balances | `leave.adjustBalance` (leave balances) or `payroll.manageLoan` (loans) | HR Admin, Finance Admin, Tenant Admin |
| Users & access | `users.viewAny` | HR Admin, Tenant Admin |
| Roles & permissions, Notification templates, SCIM provisioning, Audit log, Data export | `settings.manage` | Tenant Admin |
| API keys | `apikey.manage` | Tenant Admin |
| Webhooks | `webhook.manage` | Tenant Admin |
| Accounting | `payroll.viewAll` to read, `payroll.manageConfig` to save the chart | Read: Finance Admin, Tenant Admin. Save: Tenant Admin |

A custom role changes this at once: give a role `payroll.viewConfig` and its holders see Payroll rules, read-only. Roles are built at **Settings → Roles & Permissions**; see [`PERMISSIONS.md`](PERMISSIONS.md) for the full list. The platform operator's console is separate and is not part of Settings.

### General: organisation, address, display and employee numbers

**Settings → General** opens on four tabs: **General**, **Branding**, **Security** and **SSO**. Each card title on the page is a heading.

- **Organisation**: organisation name, address name, kind of organisation in law (with the date a change applies from), industry, timezone and default language. The language is one of the shipped languages: English, Amharic or Afaan Oromoo; any other language code is refused when saved.
- **Address**: how the organisation is reached, and the name in it. Changing the name keeps the old address working and forwards it. If you sign in with SSO, give your identity provider the new addresses after a change.
- **Display**: the default **Calendar system** (Ethiopian or Gregorian) and **Time of day** for everyone. Each person can override both under Profile → Preferences.
- **Employee numbers**: the format a new employee, or an imported row with no code, receives. The number replaces `{SEQ:n}`, padded to at least `n` digits, and the card shows the next code and an example.
- **Branding** tab: logo, colours, **Public website** (what people see before they sign in; everything there is public) and the **ETHR website** listing.

### Security

**Settings → General → Security** (`?tab=security`) holds the organisation's sign-in rules. Each card saves on its own.

- **Sign-in security**: the two-factor policy and the session timeout. *Required*: anyone without two-factor is sent to set it up and can use nothing else until they do. *Disabled*: no new set-ups, existing ones kept. *Optional* leaves it to each person. The session timeout is 5 to 480 minutes without activity; background refreshes do not count.
- **Sign-in methods**: what people may type to sign in: email address, mobile number, employee number or username. Choose at least one. Removing one asks you to confirm, because people who sign in with it must use another. The order is kept: at sign-in the first method that finds exactly one person wins.
- **Password rules**: a minimum length from **8 to 128** characters (8 is the lowest ETHR allows) and four switches: **Require an uppercase letter**, **a lowercase letter**, **a number** and **a symbol**. Saving always sends all five values together, so a partial save cannot reset the rest. **A stricter rule applies to the next password someone sets**; passwords already set are not checked again, so nobody is signed out or forced to change. There is no password-expiry setting, because nothing would act on it.
- **Activation codes**: **Code lifetime (hours)**, from **1 to 72**, for a code you issue for one person (in-person activation). A change applies to codes issued from now on. Printed activation sheets set their own lifetime and ignore this setting.

Changes to the password rules and the code lifetime are recorded in the audit log as "Password and activation code rules updated", with the values before and after, and only when something really changed.

### SSO (SAML)

**Settings → General → SSO** (`?tab=sso`). The badge on the card reads **Enabled**, **Disabled** or **Not ready**.

- **Not ready** means SSO is switched on but cannot work yet. The card lists what is missing: **IdP entity ID**, **IdP SSO URL** and **IdP certificate (PEM)**. All three are needed. The URL must start with `https://`. The certificate is stored encrypted and never shown again; a save that leaves the box empty keeps the stored one, and pasting a new one replaces it.
- SSO can be switched on only when all three are present. A save that would switch it on without them is refused, and so is a save that removes the URL or the certificate while SSO is on. Switch SSO off first to clear them.
- The **sign-in page offers SSO only when it is ready**. An organisation that is switched on but not ready shows no SSO button, so nobody is sent to a sign-in that would fail.
- **Default role for SSO users** (Employee, Supervisor, Department admin or HR admin) and **Auto-provision** (create the account the first time someone signs in through SSO).
- **SP metadata URL**: give it to your identity provider.
- ETHR reads fixed attribute names from the assertion: the email from `email`, `mail` or the standard email-address claim (the NameID when none is sent), and the name from `firstName` and `lastName` or the standard given-name and surname claims. There is **no attribute mapping and no sign-out (single logout) URL**: neither exists in ETHR. The API used to accept settings with those names, nothing read them, and since the 2026 settings review it no longer does.

### Holidays

**Settings → Holidays** lists the organisation's public holidays. **Add Ethiopian public holidays** adds the standard set in one step and can be pressed again without duplicates.

- A date marked **Estimated** follows the moon (the Islamic feasts: Eid al-Fitr, Eid al-Adha and Mawlid) and may move by a day. Edit the date once it is announced.
- When the **last one-time holiday on the list is within 90 days of today, or already past**, a warning reads "Holidays for 2028 have not been added yet" (the year after the last one on the list). Recurring holidays repeat by themselves and are not counted.
- HR can add and edit holidays; only the Tenant Admin can delete one.

### Approval chains and the exit checklist

**Approval chains** set who decides leave requests, attendance corrections, overtime requests and profile changes, in up to five steps. Without a chain, a request is decided in one step by anyone who may approve it for that employee. Each step names the person's supervisor, their department head, anyone with a role, one named person or anyone who may approve; a chain can be limited to some branches, departments or leave types, and when two match the higher priority wins. Nobody approves their own request or two steps of the same one. A change applies to requests submitted afterwards; requests already waiting keep the steps they started with.

**Exit checklist** is the list of lines every exit clearance starts with when someone resigns, is terminated or retires. For each line you choose who ticks it (HR can tick any line, finance its own, the leaver's supervisor theirs). It is a record and does not hold up the exit or the final pay. Changes apply to clearances started afterwards.

### Opening balances

**Settings → Opening balances** brings over leave balances and loans from a previous system by CSV. Download the template, fill it, and check the preview: nothing is imported unless every row is valid. A leave-balance row replaces the existing balance for that employee, leave type and year; a loan row that matches a loan already on file is skipped.

### Users, roles and integrations

| Section | What it controls |
|---------|-----------------|
| Attendance rules | Enabled attendance methods, geofence, GPS accuracy, photo/PIN requirements, QR and offline sync options |
| Leave types | Leave types, the organisation's working week and the leave year |
| Users & access | Login accounts and their roles. A tenant admin can **reset two-factor authentication** for someone who lost their authenticator (the shield icon on a user with **2FA**). That person signs in with their password and sets it up again, is emailed that it happened, and their trusted browsers are forgotten. It cannot be used on your own account or on a higher role. For a platform super admin, or an organisation's only tenant admin, run `ethr:reset-mfa <email>` (add `--tenant=<slug>` for a tenant's user) from the Laravel Toolkit's Artisan tab. The page also names the owner of the organisation. A person with no username shows "Not set" |
| Roles & permissions | Custom roles (Tenant Admin) |
| API keys, Webhooks | Integrations; see [API Integration](#api-integration) |
| Accounting | The chart of accounts and journal export; see [Payroll](#payroll) for who may read and who may save |
| Notification templates | The wording of the emails ETHR sends; see [Notifications](#notifications) |
| SCIM provisioning | See below |
| Audit log | Every recorded action, with filters (Tenant Admin) |

**SCIM provisioning** (Settings → SCIM provisioning) lets your identity provider create, update and deactivate ETHR users. Generate a bearer token (it is shown once), then enter the **Base URL** and the token in your identity provider.

- Under **Tokens in use** the page lists the organisation's own SCIM tokens with name, prefix, created date, last used and expiry. A token you no longer want can be revoked there, after a confirmation; the identity provider stops provisioning until you give it a new token. This cannot be undone.
- Listing and revoking need **API keys** access (`apikey.manage`), because a SCIM token is an API key with the `scim` ability. Someone without it sees a line saying to ask an administrator who has it.
- If the platform has switched SCIM off for your organisation, the page says so and the identity provider's requests are refused until it is back on.

A Tenant Admin can export the organisation's whole data set from **Settings → Data export**: the export is prepared in the background and can be downloaded for 7 days. Employee lists, reports and payroll bank files can also be exported individually; full backups are taken by the platform administrator.

### Finding things in Settings

- **Tabs have addresses.** `?tab=` is written to the address when you choose a tab, so a link can point at Security (`/settings?tab=security`), SSO (`?tab=sso`), Branding (`?tab=branding`) or the Statutory rules tab (`/settings/payroll?tab=rules`). Reloading keeps the tab, and Back leaves the page in one step instead of walking back through every tab you opened. An unknown `?tab=` opens the first tab.
- **Search from anywhere.** Press **Ctrl+K** and type what you want: "sso", "password rules", "tax brackets", "webhooks", "holidays" or "directory sync". Every settings page, and the Branding, Security and SSO tabs, is in the list and filed under Settings; the list shows only what you may open. It searches in English, Amharic and Afaan Oromoo.
- **Unsaved changes.** The settings forms warn before you close the tab, reload or type another address while a change is unsaved. The warning is the browser's own and **does not appear when you click another page inside ETHR**, so save before you move on. Typing a value back to what was saved is not a change.
- **Saves are announced.** A screen reader hears "Settings saved", or the reason a save failed, from one status line on every settings page. The toast on screen is unchanged.
- **Breadcrumbs.** Every settings page other than General shows Admin, then **Settings**, then the page's own name.

## User Management

Settings → Users & Access → Invite:
- Enter email, assign role
- User receives email and sets their own password

#### Finding everyone who cannot sign in yet

The guided setup invites nobody unless you choose to, so people you added may have no way to sign in. To see them:

1. Follow the **people cannot sign in yet** row in Next Steps, which opens **Employees** filtered to them (the address is `/employees?missing=sign_in`). The list shows current employees with no login, or with an invitation they have not finished. A small note beside each name says "No sign-in" or "Invited, not activated".
2. Tick the people you want, or tick the whole page.
3. Choose **Give a way to sign in**. People with an email get an invitation by email; people with only a phone get a text message if texts are on; people with neither are listed so you can print them a code or give them a starting password. People who can already sign in, and people who no longer work here, are left out of the send, and the dialog says how many. A long selection is sent 200 people at a time, and if one part fails the dialog says how many went and lets you send the rest.

You need permission to invite users and to edit employees to see the action.

### Roles

Each user has one role. The built-in roles and what they hold today:

| Role | Access |
|------|--------|
| Tenant Admin | Everything in the organization. Only this role can approve, void and reprocess payroll; change payroll rules; change organization settings, roles, API keys, webhooks and billing; register, edit and remove devices; delete employees, shifts, holidays and organization units; deactivate logins |
| HR Admin | The people side. All employees: create, update, status transitions, bank and salary details, personnel actions, disciplinary and retirement cases. Organization structure, shifts and holidays (create and update). Attendance for everyone, attendance rules and conflicts, viewing devices. Leave types, all leave, balance adjustments. Leave and correction approval for everyone. Reports, announcements, the executive dashboard. Inviting users and changing their access. **No payroll** |
| Finance Admin | The payroll side. Running payroll, loans and cost sharing; viewing (not changing) payroll rules, every payroll run and payslip, and the accounting export and chart of accounts. Reading every employee, their attendance, leave and pay details. Leave and correction approval for everyone. Reports and the executive dashboard. **Cannot add or change employees, or their bank details** |
| Department Admin | Same permissions as Supervisor, reaching everyone in their own department |
| Supervisor | Their direct reports: leave and correction approval, team attendance and leave, employee records (read), employment history, disciplinary and retirement cases (read); a dashboard limited to their own branch |
| Employee | Own attendance, corrections, leave, payslips and profile |

**Separation of duties.** The person who runs payroll (Finance Admin) cannot change the bank accounts it pays into (HR Admin), and nobody but the Tenant Admin approves a run. For any other split — an HR role for one branch, a payroll clerk without loans — create custom roles at **Settings → Roles & Permissions** and assign them. A custom role replaces the permissions of the user's built-in role. Each custom role also sets **whose records** its holders reach — their own, their direct reports, team, department, branch, or everyone — so an HR role for one branch is possible. A user can only be given a custom role by someone who already holds everything it grants. See [`PERMISSIONS.md`](PERMISSIONS.md).

A custom role cannot be deleted while any user holds it; reassign those users first.

---

## Notifications

Every approval action, payroll event, and anomaly triggers a notification. Employees control their own preferences at **Notifications → Preferences**.

Tenant Admins change the wording of the emails ETHR sends at **Settings → Notification Templates**. There are seven: leave requested, approved, rejected and changed, payslip available, missing punch and trial expiring. Each has a subject and a body in **English, Amharic and Afaan Oromoo**, and the page shows the built-in text as the starting point.

- A placeholder such as `{employee_name}` is replaced when the email is sent. A placeholder the template does not provide is refused when you save, so a typo is caught before an employee sees it.
- Subjects are limited to 200 characters and bodies to 2,000.
- **The reader's language decides.** For each of the six fields, ETHR uses your edited text in the reader's language if you wrote one, otherwise the built-in text in the reader's language, and only then English. Editing only the English text therefore changes nothing for someone who reads Amharic or Afaan Oromoo. Edit each language you want to change.
- Leaving a field empty brings the built-in text back for it.

---

## API Integration

For integrating with accounting software or custom systems:

Settings → API Keys → Generate. Keys are scoped (read-only, or per-module write) and can be revoked.

**Settings → API Keys → API reference** is the reference for your developers. It covers:

- how to authenticate with a key;
- what each ability allows;
- pagination, errors, rate limits and idempotency;
- webhook signatures and retries;
- every operation a key can call, with its parameters and the shape of its answer.

It is open to anyone who manages API keys. The full interactive documentation at `/api/docs` also maps the platform console, so it stays with platform administrators.

For event-driven integration, use **Webhooks** (Settings → Webhooks) — HMAC-signed payloads with automatic retry.
