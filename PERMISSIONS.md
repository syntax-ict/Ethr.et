# ETHR — Permission Matrix (v2.0)

## Permission Architecture

Permissions are **additive**: a user has the union of all permissions from all assigned roles. There are no "deny" permissions.

Permissions are checked via `$user->hasPermission('module.action')` — never by comparing role strings.

Permissions are cached in Redis (`user:{id}:permissions`, 15-min TTL) and invalidated on role/permission changes.

---

## Permission Modules & Actions

### employees

| Permission | Description |
|---|---|
| `employees.view` | View any employee profile |
| `employees.create` | Create new employees |
| `employees.edit` | Edit any employee profile |
| `employees.delete` | Soft-delete employees |
| `employees.transition` | Change employee lifecycle status |
| `employees.import` | Import employees from CSV |
| `employees.export` | Export employee data to CSV |

### self

| Permission | Description |
|---|---|
| `self.view` | View own employee profile |
| `self.edit_basic` | Edit own allowed fields (phone, address, emergency contacts, photo) |

### team

| Permission | Description |
|---|---|
| `team.view` | View direct/indirect report profiles |
| `team.attendance` | View team attendance |
| `team.overtime` | View team overtime |

### attendance

| Permission | Description |
|---|---|
| `attendance.own` | View and record own attendance |
| `attendance.team` | View team attendance |
| `attendance.all` | View all attendance (HR view) |
| `attendance.correct` | Request attendance corrections |
| `attendance.import` | Import attendance from CSV |
| `attendance.settings` | Configure attendance methods and settings |

### corrections

| Permission | Description |
|---|---|
| `corrections.request` | Submit correction requests |
| `corrections.approve` | Approve/reject corrections in chain |

### shifts

| Permission | Description |
|---|---|
| `shifts.view` | View shifts and assignments |
| `shifts.manage` | Create, edit, delete, assign shifts |

### devices

| Permission | Description |
|---|---|
| `devices.view` | View devices and sync status |
| `devices.manage` | CRUD devices, trigger sync |

### leave

| Permission | Description |
|---|---|
| `leave.request` | Submit leave requests |
| `leave.approve` | Approve/reject leave in chain |
| `leave.manage_types` | CRUD leave types |
| `leave.manage_balances` | View/adjust any employee balance |

### payroll

| Permission | Description |
|---|---|
| `payroll.view` | View payroll runs and entries |
| `payroll.process` | Run payroll |
| `payroll.approve` | Approve payroll runs |
| `payroll.void` | Void approved payroll runs |
| `payroll.configure` | Edit tax brackets, pension, OT rates, allowances |
| `payroll.export` | Export bank/tax/pension files |

### payslips

| Permission | Description |
|---|---|
| `payslips.own` | View own payslips |
| `payslips.all` | View any employee's payslips |

### organization

| Permission | Description |
|---|---|
| `organization.view` | View org structure |
| `organization.manage_branches` | CRUD branches |
| `organization.manage_departments` | CRUD departments |
| `organization.manage_positions` | CRUD positions, grades, cost centers, teams |

### reports

| Permission | Description |
|---|---|
| `reports.view` | Run pre-built reports |
| `reports.build` | Create custom reports |
| `reports.schedule` | Schedule recurring reports |
| `reports.department` | Reports scoped to own department only |

### dashboard

| Permission | Description |
|---|---|
| `dashboard.employee` | View employee dashboard |
| `dashboard.manager` | View manager dashboard |
| `dashboard.executive` | View executive dashboard |

### notifications

| Permission | Description |
|---|---|
| `notifications.manage_templates` | Edit notification email templates |
| `notifications.manage_announcements` | Create and publish announcements |

### settings

| Permission | Description |
|---|---|
| `settings.view` | View tenant settings |
| `settings.edit` | Edit settings, manage roles, manage holidays, branding |
| `settings.billing` | View and manage billing/subscription |

### audit

| Permission | Description |
|---|---|
| `audit.view` | View audit logs |
| `audit.export` | Export audit logs |

### admin (Super Admin Only)

| Permission | Description |
|---|---|
| `admin.tenants` | View and manage all tenants |
| `admin.revenue` | View platform revenue metrics |
| `admin.impersonate` | Impersonate tenant admins |
| `admin.health` | View system health |
| `admin.plans` | Manage subscription plans |
| `admin.billing` | Manage invoices across tenants |

---

## Default Role Assignments

### tenant_admin — Full Control

All permissions except `admin.*`.

### hr_admin

```
employees.view, employees.create, employees.edit, employees.delete,
employees.transition, employees.import, employees.export,
self.view, self.edit_basic,
team.view, team.attendance, team.overtime,
attendance.own, attendance.all, attendance.correct, attendance.import, attendance.settings,
corrections.request, corrections.approve,
shifts.view, shifts.manage,
devices.view, devices.manage,
leave.request, leave.approve, leave.manage_types, leave.manage_balances,
payslips.own,
organization.view, organization.manage_branches, organization.manage_departments, organization.manage_positions,
reports.view, reports.build,
dashboard.employee, dashboard.manager,
notifications.manage_announcements,
settings.view,
audit.view
```

### finance_admin

```
employees.view,
self.view, self.edit_basic,
attendance.own, attendance.all,
corrections.request,
leave.request,
payroll.view, payroll.process, payroll.approve, payroll.configure, payroll.export,
payslips.own, payslips.all,
reports.view, reports.build, reports.schedule,
dashboard.employee, dashboard.executive,
settings.view,
audit.view
```

### department_head

```
employees.view,
self.view, self.edit_basic,
team.view, team.attendance, team.overtime,
attendance.own, attendance.team, attendance.correct,
corrections.request, corrections.approve,
shifts.view,
leave.request, leave.approve,
payslips.own,
reports.view, reports.department,
dashboard.employee, dashboard.manager,
audit.view
```

### supervisor

```
self.view, self.edit_basic,
team.view, team.attendance,
attendance.own, attendance.team, attendance.correct,
corrections.request, corrections.approve,
leave.request, leave.approve,
payslips.own,
dashboard.employee, dashboard.manager
```

### employee

```
self.view, self.edit_basic,
attendance.own, attendance.correct,
corrections.request,
leave.request,
payslips.own,
dashboard.employee
```

---

## Custom Role Rules

1. Tenant admins can create custom roles via the role editor UI.
2. Custom roles are assigned any subset of available permissions.
3. System roles (`is_system = true`) cannot be deleted or have permissions removed.
4. System role permissions are viewable but not editable in the UI.
5. Custom roles can be duplicated (as a starting point), edited, and deleted.
6. Deleting a custom role: users with only that role are reassigned to `employee` role.
7. A user can have multiple roles — permissions are the union of all assigned roles.
8. Permission changes take effect within 15 minutes (cache TTL) or immediately if cache is flushed.

---

## Scope Rules

| Scope | Rule |
|---|---|
| `employees.view` | Can view any employee in the tenant |
| `team.view` | Can view only direct/indirect reports (supervisor chain) |
| `self.view` | Can view only own profile |
| `reports.department` | Reports filtered to own department only |
| `attendance.team` | Attendance filtered to direct/indirect reports |
| `attendance.all` | Attendance for all employees in tenant |
| `payslips.own` | Only own payslips |
| `payslips.all` | Any employee's payslips |

Scoping is enforced in Policies, not in permission checks. `hasPermission()` checks if the user has the permission; Policies check if the user can perform the action on the specific resource.

---

## Permission Count

| Module | Permissions |
|---|---|
| employees | 7 |
| self | 2 |
| team | 3 |
| attendance | 5 |
| corrections | 2 |
| shifts | 2 |
| devices | 2 |
| leave | 4 |
| payroll | 6 |
| payslips | 2 |
| organization | 4 |
| reports | 4 |
| dashboard | 3 |
| notifications | 2 |
| settings | 3 |
| audit | 2 |
| admin | 6 |
| **Total** | **59** |
