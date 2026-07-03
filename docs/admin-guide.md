# ETHR Admin Guide

**For Tenant Admins and HR Admins**

---

## Getting Started

### First Login

After your organization registers at `https://ethr.et`, you receive an email with your login link. Your admin account is pre-created as **Tenant Admin**.

> URL format: `https://yourcompany.ethr.et`

### Setup Wizard

On first login you are guided through 7 steps:

| Step | What to configure |
|------|-------------------|
| 1 | Organization name, industry, logo |
| 2 | Branches and departments |
| 3 | Work schedules and shifts |
| 4 | Leave policies |
| 5 | Payroll rules (tax, pension) |
| 6 | Employee import (CSV) |
| 7 | Invite team members |

You can skip steps and return later. All settings are editable after setup.

---

## Organization Structure

Navigate to **Settings → Organization**.

### Branches

Each branch has:
- Name and address
- Geofence (latitude, longitude, radius) — used for mobile attendance validation

Set one branch as **Headquarters**.

### Departments

Departments can be hierarchical (parent → child). Assign a department head from your employee list. This determines approval chains.

### Positions & Grades

Create positions (job titles) and grades (seniority levels). Positions can have salary ranges. Grades control pension and allowance tiers.

---

## Employee Management

### Adding Employees

**Individual:** Employees → New Employee → fill all profile fields.

**Bulk import:** Employees → Import → download the CSV template, fill it in, upload and preview before committing.

### Employee Lifecycle

Every status change is tracked:

```
Hired → Probation → Confirmed → Active
                              ↓
             Transfer / Promotion / Suspension
                              ↓
             Resignation / Retirement / Termination → Exited
```

To transition: open the employee record → Actions → Transition.

### Self-Service Approvals

When an employee updates sensitive fields (name, bank details), the change is **held pending HR approval**. You'll see it in **Approvals → Profile Updates**.

---

## Attendance Configuration

### Shift Setup

Settings → Shifts → create shifts with:
- Start and end time (supports overnight shifts)
- Grace period (minutes before marking late)
- Working days

Assign shifts to individual employees, departments, or branches.

### Attendance Methods

Settings → Attendance → enable/disable sources per your organization:

| Method | Best for |
|--------|---------|
| Biometric device | Factories, banks |
| Mobile (GPS) | Field workers |
| QR code | Shared entrances |
| Kiosk | Reception desks |
| Web | Office workers |
| Manual | Manual correction by HR |

### Biometric Devices

Devices → Register Device → enter model (Hikvision / ZKTeco), IP address, and credentials. The system polls every 5 minutes and processes events in real time.

### Attendance Correction Workflow

Employees submit correction requests with reason and evidence. The approval chain is:

1. Employee submits
2. Supervisor approves
3. Department Head approves
4. HR final approval

Payroll is automatically flagged for recalculation if the corrected date falls in the current open pay period.

---

## Leave Management

### Leave Types

Settings → Leave Types → configure all types. Ethiopian defaults are pre-loaded:

| Type | Days | Accrual |
|------|------|---------|
| Annual | 16 (grows with tenure) | Monthly |
| Sick | 6 | Immediate |
| Maternity | 90 (30 pre + 60 post) | One-time |
| Paternity | 5 | One-time |
| Emergency | 3 | Immediate |

All values are configurable. Create custom types (study leave, compensatory, etc.).

### Approval Chain

Configure in Settings → Leave. Default: employee → supervisor → HR. You can skip levels or require only HR approval.

### Ethiopian Calendar

Settings → Calendar → enable Ethiopian calendar. The system will:
- Display dates in both Gregorian and Ethiopian (ዓ.ም.)
- Auto-detect public holidays (Ethiopian New Year, Timkat, Meskel, etc.)
- Skip holidays when counting leave days

---

## Payroll

### Configuration

Settings → Payroll:
- **Tax brackets** — Ethiopian income tax rates (pre-loaded, update when government changes them)
- **Pension** — 7% employee + 11% employer (configurable)
- **Overtime rates** — 1.25× normal, 1.5× night, 2.0× holiday, 2.5× holiday night

### Running Payroll

Payroll → Process Payroll → select the pay period → confirm.

The engine:
1. Pulls confirmed attendance for each employee
2. Deducts leave days
3. Calculates basic salary (with proration for new hires/exits)
4. Adds overtime and allowances
5. Deducts income tax, pension, loans
6. Generates payslips (PDF, bilingual EN/AM)
7. Produces bank transfer file in Ethiopian bank format

### Approval Flow

After processing, a Finance Admin reviews the run → Approve. Payslips become visible to employees immediately after approval.

### Payslip Access

Employees see their own payslips at **Payslips** in the sidebar. Finance Admins can view any employee's payslips.

---

## Reports

### Report Builder

Reports → New Report:
1. Select data source (Employees, Attendance, Leave, Payroll)
2. Choose columns
3. Set filters and date range
4. Preview results
5. Export as PDF, Excel, or CSV

### Scheduled Reports

Save a report and schedule it for automatic email delivery — daily, weekly, or monthly.

### Pre-built Reports

| Report | Use |
|--------|-----|
| Monthly Attendance Summary | Punctuality and absences |
| Payroll Register | Salary disbursement |
| Leave Balance Report | Remaining leave per employee |
| Tax Declaration | Income tax filing |
| Pension Contribution | EPFO submissions |
| New Hires / Exits | Headcount movements |

---

## Settings Reference

| Section | What it controls |
|---------|-----------------|
| General | Organization name, timezone, language |
| Branding | Logo, primary/secondary colors |
| Calendar | Ethiopian calendar on/off, fiscal year start |
| Attendance | Grace period, OT caps, confidence threshold |
| Leave | Working days, default approval chain |
| Payroll | Pay period, payroll run day |
| Security | MFA policy (optional/required), session timeout |
| Notifications | Email template customization (EN + AM) |
| Data | Full data export, retention settings |
| Audit Log | View all actions with before/after comparison |

---

## User Management

Settings → Users → Invite:
- Enter email, assign role
- User receives email and sets their own password

### Roles

| Role | Access |
|------|--------|
| Tenant Admin | Everything |
| HR Admin | Employees, attendance, leave, reports |
| Finance Admin | Payroll, reports |
| Department Head | Own department — attendance, leave approval |
| Supervisor | Direct reports — attendance, leave approval |
| Employee | Own records only |

---

## Notifications

Every approval action, payroll event, and anomaly triggers a notification. Employees control their own preferences at **Notifications → Preferences**.

Admins customize email template wording at **Settings → Notifications**.

---

## API Integration

For integrating with accounting software or custom systems:

Settings → API Keys → Generate. Keys are scoped (read-only, or per-module write) and can be revoked.

Full API documentation: `https://yourcompany.ethr.et/api/docs`

For event-driven integration, use **Webhooks** (Settings → Webhooks) — HMAC-signed payloads with automatic retry.
