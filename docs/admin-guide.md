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
| 2 | **Workforce** — bring in your people, either from an attendance device's enrolled users or from a pasted list; review each match and commit |
| 3 | **Access** — choose how employees log in (email, phone, employee code, username) |
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
  Approving a replacement retires the old phone at the same moment.
- **One phone, one person.** A phone or browser already registered to a
  colleague cannot be registered again, and a passkey belonging to someone
  else is refused.
- **Computers.** On a computer, the person's phone confirms the punch through
  the phone prompt the browser shows. If your office has its own fixed
  internet address, list it under **Office networks for computer punches**: web
  punches from it need no phone and are marked for review.
- **Exemptions.** A person whose phone cannot hold a passkey, or is broken, can
  be exempted until a date (up to three months) from their profile. Their
  punches are accepted meanwhile and still sent for review.
- **Offline.** A punch made with no signal is signed by the registered phone
  over its own time and place, and checked when it syncs, so it cannot be
  edited or made on another phone.
- **Automatic check-in** cannot use the passkey, because the phone only shows
  its prompt after a tap. With **Only the registered phone**, the person taps
  to check in.
- **Kiosk, biometric devices and manual entries** are never asked for a phone.

**Rolling it out:** choose **Check and review**, let staff register as they
punch at work, watch the count under Attendance → Devices, then choose **Only
the registered phone**. What it cannot stop is a person handing their own
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

Settings → General → **Calendar System** → choose Ethiopian or Gregorian. This controls how dates are displayed across the application; date inputs show the other calendar's date beneath them.

Public holidays are managed at Settings → Holidays, which can auto-detect Ethiopian public holidays (Ethiopian New Year, Timkat, Meskel, etc.). Holidays are skipped when counting leave days.

---

## Payroll

### Configuration

Settings → Payroll Rules (Tenant Admin only):
- **Payroll Schedule** — payroll run day, fiscal-year start month (Ethiopian months, Meskerem to Pagume), Pagume pay (a full month, or its days at the daily rate of annual salary ÷ 365), and retirement age (45–75). The pay period is shown read-only.
- **Tax brackets** — Ethiopian income tax rates (pre-loaded, update when government changes them)
- **Overtime rates** — defaults are the statutory minimums: 1.5× daytime, 1.75× night, 2.0× weekly rest day, 2.5× public holiday
- **Allowances**

**Pension** is fixed at the statutory 7% employee + 11% employer and is not configurable.

### Running Payroll

Finance → Payroll Runs → Process Payroll → select the pay period → confirm.

The engine:
1. Pulls confirmed attendance for each employee
2. Deducts leave days
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

Finance → Reports → Builder:
1. Select data source (Employees, Attendance, Leave, Payroll)
2. Choose columns
3. Add filters (status for employees, a date range for attendance, the year for leave; payroll has none), and optional grouping and sorting
4. **Run Preview**
5. **Download CSV**, or **Save as template**

The API's export endpoint also produces PDF. CSV and PDF are the only formats; there is no Excel export.

### Scheduled Reports

Schedule a saved report from the **Saved** tab for automatic email delivery — daily, weekly, or monthly — to a list of email addresses. The report is emailed as a CSV attachment. A saved report can be run, scheduled or deleted, and a schedule can be deleted; neither can be edited. Saving and scheduling depend on your plan.

### Quick Reports

The **Quick Reports** tab has one report per data source — Employees, Attendance, Leave, Payroll — each run with its default columns and downloadable as CSV.

There are no pre-built statutory templates (tax declaration, pension contribution). For monthly tax and pension totals, build a payroll report and group it; grouped payroll reports show totals per group.

---

## Settings Reference

The **Settings** item in the sidebar is shown to holders of `settings.manage` (Tenant Admin). Inside it, each page appears only to those allowed to use it.

| Section | What it controls |
|---------|-----------------|
| General | Organization name, organization type, timezone, default language; calendar system (Ethiopian or Gregorian) |
| Branding | Logo URL, primary/secondary/accent colors |
| Security | MFA policy — *required*: anyone without two-factor is sent to set it up and can use nothing else until they do; *disabled*: no new set-ups, existing ones kept. Session timeout (5–480 minutes without activity; background refreshes do not count) |
| SSO | SAML single sign-on |
| Attendance Rules | Enabled attendance methods, geofence, GPS accuracy, photo/PIN requirements, QR and offline sync options |
| Shift Rules | Shifts and their working days, grace and break minutes |
| Leave Types | Leave types, and the organization's working week |
| Holidays | Public holidays, including auto-detection |
| Payroll Rules | Payroll schedule, fiscal year, Pagume pay, retirement age, allowances, tax brackets, overtime rates (Tenant Admin) |
| Users & Access | Login accounts and their roles. A tenant admin can **reset two-factor authentication** for someone who lost their authenticator (the shield icon on a user with **2FA**). That person signs in with their password and sets it up again, is emailed that it happened, and their trusted browsers are forgotten. It cannot be used on your own account or on a higher role. For a platform super admin, or an organisation's only tenant admin, run `ethr:reset-mfa <email>` (add `--tenant=<slug>` for a tenant's user) from the Laravel Toolkit's Artisan tab |
| Roles & Permissions | Custom roles (Tenant Admin) |
| API Keys, Webhooks, Accounting, Notification Templates, SCIM Provisioning | Integrations |
| Audit Log | View all actions (Tenant Admin) |

There is no self-service export of your organization's whole data set. Employee lists, reports and payroll bank files can be exported individually; full backups are taken by the platform administrator.

---

## User Management

Settings → Users & Access → Invite:
- Enter email, assign role
- User receives email and sets their own password

### Roles

Each user has one role. The built-in roles and what they hold today:

| Role | Access |
|------|--------|
| Tenant Admin | Everything in the organization. Only this role can approve, void and reprocess payroll; change payroll rules; change organization settings, roles, API keys, webhooks and billing; register, edit and remove devices; delete employees, shifts, holidays and organization units; deactivate logins |
| HR Admin | The people side. All employees: create, update, status transitions, bank and salary details, personnel actions, disciplinary and retirement cases. Organization structure, shifts and holidays (create and update). Attendance for everyone, attendance rules and conflicts, viewing devices. Leave types, all leave, balance adjustments. Leave and correction approval for everyone. Reports, announcements, the executive dashboard. Inviting users and changing their access. **No payroll** |
| Finance Admin | The payroll side. Running payroll, loans and cost sharing; viewing payroll rules, every payroll run and payslip, and the accounting export. Reading every employee, their attendance, leave and pay details. Leave and correction approval for everyone. Reports and the executive dashboard. **Cannot add or change employees, or their bank details** |
| Department Admin | Same permissions as Supervisor, reaching everyone in their own department |
| Supervisor | Their direct reports: leave and correction approval, team attendance and leave, employee records (read), employment history, disciplinary and retirement cases (read); a dashboard limited to their own branch |
| Employee | Own attendance, corrections, leave, payslips and profile |

**Separation of duties.** The person who runs payroll (Finance Admin) cannot change the bank accounts it pays into (HR Admin), and nobody but the Tenant Admin approves a run. For any other split — an HR role for one branch, a payroll clerk without loans — create custom roles at **Settings → Roles & Permissions** and assign them. A custom role replaces the permissions of the user's built-in role. Each custom role also sets **whose records** its holders reach — their own, their direct reports, team, department, branch, or everyone — so an HR role for one branch is possible. A user can only be given a custom role by someone who already holds everything it grants. See [`PERMISSIONS.md`](PERMISSIONS.md).

A custom role cannot be deleted while any user holds it; reassign those users first.

---

## Notifications

Every approval action, payroll event, and anomaly triggers a notification. Employees control their own preferences at **Notifications → Preferences**.

Tenant Admins customize email template wording (English and Amharic subject and body) at **Settings → Notification Templates**.

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
