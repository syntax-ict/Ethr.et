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

### Shift Setup

Settings → Shift Rules (or Operations → Shifts & Schedules) → create shifts with:
- Start and end time (supports overnight shifts)
- Grace period (minutes before marking late)
- Working days

Assign shifts to individual employees, departments, or branches.

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
| Users & Access | Login accounts and their roles |
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

The interactive API documentation at `/api/docs` is available to platform administrators only. Ask your ETHR provider for the API reference.

For event-driven integration, use **Webhooks** (Settings → Webhooks) — HMAC-signed payloads with automatic retry.
