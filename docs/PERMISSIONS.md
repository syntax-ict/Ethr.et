# ETHR — Permission Matrix (v2.0)

> Generated from `api/database/seeders/PermissionSeeder.php` (permissions and `roleGrants()`),
> `api/app/Models/User.php` (`hasPermission()`, `permissionNames()`), `api/app/Enums/OrgScope.php`
> and `api/app/Traits/ScopesEmployeeAccess.php`. When this file and the seeder disagree, the
> seeder is what runs.

## Permission Architecture

Each user has **one role** (`users.role`) and, optionally, **one custom role** (`users.custom_role_id`). There are no "deny" permissions.

- Without a custom role, the user holds the permissions granted to their role in `role_permissions`.
- With a custom role, the user holds **only** the custom role's permissions. The custom role **replaces** the role's set; it is not added to it.
- `super_admin` is a platform role, not a tenant role. It has no `role_permissions` rows: `hasPermission()` returns true for every ability.

Permissions are checked via `$user->hasPermission('module.action')` — never by comparing role strings. A `Gate::before` hook routes every known permission name through `hasPermission()`, so `Gate::authorize('leave.approve')` and `$user->hasPermission('leave.approve')` give the same answer.

Permissions are cached through the Laravel cache for 1 hour — `role_permissions:{role}` per role and `custom_role_permissions:{id}` per custom role — and the cache is cleared when the seeder runs or a custom role is created, changed or deleted.

---

## Permission Modules & Actions

79 permissions in 22 modules.

### org

| Permission | Description |
|---|---|
| `org.viewAny` | View organization structure |
| `org.view` | View organization unit details |
| `org.create` | Create organization units |
| `org.update` | Update organization units |
| `org.delete` | Delete organization units |

### attendance

| Permission | Description |
|---|---|
| `attendance.checkIn` | Check in/out attendance |
| `attendance.viewOwn` | View own attendance records |
| `attendance.viewTeam` | View team attendance records |
| `attendance.viewAll` | View all attendance records |
| `attendance.view` | View attendance records |
| `attendance.manage` | Manage attendance settings and imports |
| `attendance.viewConflicts` | View attendance conflicts for HR review |
| `attendance.resolveConflicts` | Resolve attendance conflicts |

### shift

| Permission | Description |
|---|---|
| `shift.viewAny` | View shifts list |
| `shift.view` | View shift details |
| `shift.create` | Create shifts |
| `shift.update` | Update shifts |
| `shift.delete` | Delete shifts |

### device

| Permission | Description |
|---|---|
| `device.viewAny` | View devices list |
| `device.view` | View device details |
| `device.create` | Register devices |
| `device.update` | Update device configuration |
| `device.delete` | Remove devices |

### correction

| Permission | Description |
|---|---|
| `correction.create` | Submit attendance corrections |
| `correction.viewOwn` | View own correction requests |
| `correction.viewPending` | View pending corrections for approval |
| `correction.viewAll` | View all correction requests |
| `correction.approve` | Approve or reject corrections |

### holiday

| Permission | Description |
|---|---|
| `holiday.viewAny` | View holidays list |
| `holiday.view` | View holiday details |
| `holiday.create` | Create holidays |
| `holiday.update` | Update holidays |
| `holiday.delete` | Delete holidays |

### payroll

| Permission | Description |
|---|---|
| `payroll.viewAll` | View all payroll data |
| `payroll.process` | Process payroll runs |
| `payroll.approve` | Approve payroll runs |
| `payroll.void` | Void an approved payroll run |
| `payroll.reprocess` | Reprocess a voided payroll run |
| `payroll.manageLoan` | Manage employee loans |
| `payroll.manageCostSharing` | Manage employee cost-sharing obligations |
| `payroll.viewOwnPayslip` | View own payslip |
| `payroll.viewConfig` | View payroll configuration (allowances, tax brackets, overtime rates) |
| `payroll.manageConfig` | Manage payroll configuration (allowances, tax brackets, overtime rates) |

### leave

| Permission | Description |
|---|---|
| `leave.viewTypes` | View leave types |
| `leave.manageTypes` | Manage leave types |
| `leave.request` | Submit leave requests |
| `leave.viewTeam` | View team leave requests |
| `leave.viewAll` | View all leave requests |
| `leave.approve` | Approve or reject leave requests |
| `leave.adjustBalance` | Manually adjust leave balances |

### employee

| Permission | Description |
|---|---|
| `employee.viewAny` | View employees list |
| `employee.view` | View employee details |
| `employee.create` | Create employees |
| `employee.update` | Update employee records |
| `employee.delete` | Delete employees |
| `employee.transition` | Transition employee status |
| `employee.viewFinancial` | View employee financial data |
| `employee.updateFinancial` | Update employee financial data |

### personnel_action, disciplinary_case, retirement_case

| Permission | Description |
|---|---|
| `personnel_action.viewAny` | View employment history / personnel actions |
| `personnel_action.create` | Record personnel actions |
| `disciplinary_case.viewAny` | View disciplinary cases |
| `disciplinary_case.manage` | Open, investigate, decide, sanction and resolve disciplinary cases |
| `retirement_case.viewAny` | View retirement cases |
| `retirement_case.manage` | Initiate, review, decide and finalize retirement cases |

### profile

| Permission | Description |
|---|---|
| `profile.view` | View own profile |
| `profile.update` | Update own profile |

### users

| Permission | Description |
|---|---|
| `users.viewAny` | View login accounts |
| `users.invite` | Invite and provision login accounts |
| `users.update` | Update user roles, status and access |
| `users.delete` | Deactivate login accounts |

### dashboard

| Permission | Description |
|---|---|
| `dashboard.executive` | View executive dashboard (any branch) |
| `dashboard.regional` | View executive dashboard scoped to own branch |

### Single-permission modules

| Permission | Description |
|---|---|
| `announcement.manage` | Manage announcements |
| `report.generate` | Generate reports (also saving and scheduling them) |
| `apikey.manage` | Manage API keys |
| `webhook.manage` | Manage webhooks |
| `billing.manage` | Manage billing and subscriptions |
| `settings.manage` | Manage tenant settings — also custom roles and the tenant audit log |
| `admin.manage` | Platform administration — granted to no tenant role; `super_admin` holds it through the bypass |

---

## Default Role Assignments

Roles: `employee`, `supervisor`, `dept_admin`, `hr_admin`, `finance_admin`, `tenant_admin`, plus `super_admin` (implicit bypass, above). Grants are built from the sets below. Since 2026-10-08 `hr_admin` and `finance_admin` hold **different** sets (audit N95): HR the people side, Finance the payroll side, and both the Director set.

| Role | Holds |
|---|---|
| `employee` | **Everyone** set |
| `supervisor` | Everyone + **Supervisor** set |
| `dept_admin` | Everyone + Supervisor set — **identical to `supervisor`**; only the reach differs (see *Scope Rules*) |
| `hr_admin` | Everyone + Supervisor + **Director** + **HR** sets |
| `finance_admin` | Everyone + Supervisor + **Director** + **Finance** sets |
| `tenant_admin` | Everyone + Supervisor + Director + HR + Finance + **Tenant Admin** sets |

### Everyone

```
org.viewAny, org.view,
attendance.checkIn, attendance.viewOwn, attendance.view,
shift.viewAny, shift.view,
correction.create, correction.viewOwn,
holiday.viewAny, holiday.view,
payroll.viewOwnPayslip,
leave.viewTypes, leave.request,
profile.view, profile.update
```

### Supervisor set

```
attendance.viewTeam,
correction.viewPending, correction.approve,
leave.viewTeam, leave.approve,
employee.viewAny, employee.view,
personnel_action.viewAny,
disciplinary_case.viewAny,
retirement_case.viewAny,
dashboard.regional
```

### Director set (HR and Finance Admin)

```
attendance.viewAll, leave.viewAll,
employee.viewFinancial,
report.generate,
dashboard.executive
```

### HR set

```
org.create, org.update,
attendance.manage,
attendance.viewConflicts, attendance.resolveConflicts,
shift.create, shift.update,
device.viewAny, device.view,
correction.viewAll,
holiday.create, holiday.update,
leave.manageTypes, leave.adjustBalance,
employee.create, employee.update, employee.transition,
employee.updateFinancial,
personnel_action.create,
disciplinary_case.manage,
retirement_case.manage,
announcement.manage,
users.viewAny, users.invite, users.update
```

### Finance set

```
payroll.viewAll, payroll.process, payroll.manageLoan,
payroll.manageCostSharing,
payroll.viewConfig
```

### Tenant Admin set

```
org.delete,
shift.delete,
device.create, device.update, device.delete,
holiday.delete,
payroll.approve, payroll.void, payroll.reprocess,
payroll.manageConfig,
employee.delete,
users.delete,
apikey.manage,
webhook.manage,
billing.manage,
settings.manage
```

Consequences worth knowing:

- **Separation of duties, pinned by `PermissionSystemTest`:** below tenant admin, nobody both changes bank details (`employee.updateFinancial`, HR) and processes payroll (`payroll.process`, Finance), and nobody both processes and approves a run (`payroll.approve` is tenant admin's).
- **Only `tenant_admin` can approve, void or reprocess payroll**, change payroll configuration, or register, edit and remove devices.
- **`supervisor` and `dept_admin` do not hold `report.generate`**, so they have no reports.
- No role is granted `admin.manage`, and none can be: it is a **platform-only** ability (`Permission::PLATFORM_ONLY`). The catalogue offered to tenants leaves it out, custom-role validation refuses it, and permission resolution never returns it for anyone but `super_admin`, whatever a role row says (audit N86).

---

## Custom Role Rules

1. Custom roles are managed at **Settings → Roles & Permissions** (`/settings/roles`). Every custom-role endpoint requires `settings.manage`, which only `tenant_admin` holds by default.
2. A custom role has a name (unique within the tenant), an optional description, an active flag, a reach (rule 8) and at least one permission from the catalogue above, platform-only abilities excepted.
3. A user is assigned at most one custom role (`custom_role_id` on the user). The custom role **replaces** the permissions of the user's role; the two are never combined.
4. The built-in roles are not rows in `custom_roles` and are not edited from this screen. Their grants come from `PermissionSeeder`.
5. Custom roles can be created, edited and deleted. There is no "duplicate role" action.
6. **Deleting a custom role is refused (422) while any user holds it.** Reassign those users first; nobody is reassigned automatically.
7. Changing or deleting a custom role clears its permission cache, so the change applies on the next request.
8. **Reach:** a custom role carries its own organisation scope (`org_scope`: `self`, `direct_reports`, `team`, `department`, `branch` or `all`), chosen on the roles screen as "Whose records" and replacing the built-in role's scope. New roles default to `self`.
9. **Assigning a custom role** (Settings → Users) is refused unless the role belongs to your organisation, you already hold every permission it grants, and its reach is no wider than yours. A tenant admin can therefore assign any role; an HR admin cannot give anyone, themselves included, a role carrying `settings.manage` or `payroll.approve` (audit N87, N89).

---

## Scope Rules

A permission says *what* a user may do; the organisation scope says *to whom*. Policies check both: `hasPermission()` first, then `canAccessEmployee()` for actions on a specific employee.

| Role | Scope | Reaches |
|---|---|---|
| `super_admin`, `tenant_admin`, `hr_admin`, `finance_admin` | `all` | Every employee in the tenant |
| `dept_admin` | `department` | Employees in the user's own department |
| `supervisor` | `direct_reports` | Employees whose supervisor is the user, and the user themselves |
| `employee` | `self` | Only the user's own employee record |

The scope is anchored on the user's linked employee record; a user with no employee record and a scope other than `all` reaches no one. Scoped checks include viewing, updating and transitioning employees, approving leave and corrections, team attendance, attendance conflicts and disciplinary cases.

Two further rules sit on top of scope for approvals: a user cannot approve their own leave request or their own attendance correction, and every approval is a single step — one approval finalises the request.

---

## Permission Count

| Module | Permissions |
|---|---|
| org | 5 |
| attendance | 8 |
| shift | 5 |
| device | 5 |
| correction | 5 |
| holiday | 5 |
| payroll | 10 |
| leave | 7 |
| employee | 8 |
| personnel_action | 2 |
| disciplinary_case | 2 |
| retirement_case | 2 |
| profile | 2 |
| users | 4 |
| announcement | 1 |
| dashboard | 2 |
| report | 1 |
| apikey | 1 |
| webhook | 1 |
| admin | 1 |
| billing | 1 |
| settings | 1 |
| **Total** | **79** |
