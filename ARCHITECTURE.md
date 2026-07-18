# ETHR — System Architecture (v2.0)

## System Overview

```
                         Internet
                            |
                      [Nginx Reverse Proxy]
                      SSL/TLS + Rate Limiting
                      Security Headers + Gzip
                       /    |    \
                      /     |     \
           [Next.js]   [Laravel API]   [Reverb WS]
            (SSR/SSG)    (PHP-FPM)     (WebSocket)
                \          |          /
                 \         |         /
                  [Redis]  |  [MinIO]
                     \     |     /
                      [MariaDB]
                         |
                    [Queue Workers]
                    (Horizon/Supervisor)
                         |
                  [Failed Job Recovery]
```

---

## Multi-Tenant Architecture

### Isolation Model: Shared Database, Tenant-Scoped

All tenants share one MariaDB instance. Isolation enforced at application layer with database-level safety net.

```
Request → Nginx → Laravel
                      |
              Subdomain extraction (acme.ethr.et → "acme")
                      |
              Tenant resolution (tenants table lookup, cached 1hr)
                      |
              CurrentTenant singleton bound to container
                      |
              BelongsToTenant global scope applied to all scoped models
                      |
              All queries automatically filtered by tenant_id
                      |
              [DEBUG/STAGING] QueryAuditMiddleware validates all queries include tenant_id
```

### Isolation Guarantees

| Layer | Mechanism | Verification |
|---|---|---|
| Database queries | `BelongsToTenant` trait adds `WHERE tenant_id = ?` to every query | `TenantIsolationTest` in CI |
| File storage | MinIO bucket per tenant: `tenant-{public_id}/` | Bucket policy validation |
| Cache keys | Redis key prefix: `tenant:{id}:` | Key pattern enforcement |
| Queue jobs | `tenant_id` serialized with every job; re-resolved on execution | Job middleware assertion |
| API responses | Resources filter by tenant; no cross-tenant data in any response | Integration tests |
| Audit logs | Tenant-scoped; super admin sees across tenants with explicit scope override | Policy enforcement |
| Encryption | Tenant-specific encryption key for sensitive fields (bank details, TIN) | Key isolation test |
| Backups | Per-tenant export capability | Export scoping test |
| Permissions | Permission checks always include tenant context | Permission test suite |

### Tenant Isolation Safety Net (CI-Enforced)

```php
// TenantIsolationTest.php — runs on every commit
class TenantIsolationTest extends TestCase
{
    /** @test */
    public function all_tenant_scoped_models_have_tenant_id_column(): void
    {
        // Dynamically discover all Eloquent models
        // Assert each non-global model has tenant_id
        // Assert each has BelongsToTenant trait
    }

    /** @test */
    public function cross_tenant_queries_return_empty(): void
    {
        // Create data for Tenant A
        // Authenticate as Tenant B
        // Assert all model queries return empty
    }

    /** @test */
    public function no_query_leaks_without_tenant_scope(): void
    {
        // Use DB::getQueryLog() to verify all queries
        // include tenant_id WHERE clause
    }
}
```

### Models Without Tenant Scope (Global Models)

These models are global (not tenant-scoped):

- `Tenant` — the tenant itself
- `Plan` — subscription plans (global catalog)
- `FeatureFlag` — when `tenant_id` is null (global flag)
- `SuperAdmin` — platform-level administrators

All other models MUST have `tenant_id` and use `BelongsToTenant`. The `TenantIsolationTest` enforces this automatically.

### PHPStan Rule: Tenant Scope Enforcement

Custom PHPStan rule that errors if:
- Any migration creates a table with `tenant_id` but the corresponding model lacks `BelongsToTenant`
- Any query uses `withoutGlobalScopes()` without an explicit `where('tenant_id', ...)` clause
- Any model with `tenant_id` column is missing from the isolation test

---

## Authentication Architecture

### Token Strategy (Sanctum)

```
Login (email+password or phone+OTP)
  |
  +→ Access Token (15 min, JSON body, scoped abilities)
  +→ Refresh Token (7 day, httpOnly cookie, path=/api/v1/auth)
  |
  Token refresh (POST /api/v1/auth/refresh)
  |
  +→ New access token (15 min)
  +→ Rotate refresh token (old token revoked)
  |
  Logout (POST /api/v1/auth/logout)
  |
  +→ Revoke all tokens for session
  +→ Clear refresh cookie
  +→ Audit log entry
```

### MFA Flow (TOTP — Phase 0)

```
Login with credentials
  |
  +→ If MFA enabled:
  |     → Return mfa_required: true, mfa_token (5-min expiry)
  |     → Client shows TOTP input
  |     → POST /auth/mfa/verify { mfa_token, totp_code }
  |     → If trusted device cookie present and valid: skip TOTP
  |     → Success: issue access + refresh tokens
  |
  +→ If MFA not enabled:
        → Issue access + refresh tokens directly
```

### Session Management

- Active sessions list (device fingerprint, IP, last active, approximate location)
- Revoke individual sessions
- Revoke all sessions except current
- Device trust (skip MFA for trusted devices, 30-day expiry, stored as signed cookie)
- Login history (last 50 logins with IP, device, status, geo)
- Login rate limiting: 5 attempts/min per email per IP, lockout after 10 (15-min cooldown)
- Account lockout: notify tenant admin, allow admin unlock

---

## Permission Architecture

### Permission-Based Authorization (Not Role-Based)

```
User → hasPermission('employees.edit') → Policy check → Allow/Deny

Permissions stored in:
  permissions table:       id, name, module, action, description
  roles table:             id, tenant_id, name, is_system, description
  role_permissions pivot:  role_id, permission_id
  user_roles pivot:        user_id, role_id
```

### Default System Roles (Seeded Per Tenant)

| Role | Key Permissions |
|---|---|
| `tenant_admin` | All permissions |
| `hr_admin` | employees.*, leave.*, attendance.*, organization.*, reports.view |
| `finance_admin` | payroll.*, reports.*, employees.view, attendance.view |
| `department_head` | team.*, leave.approve, attendance.team, corrections.approve, reports.department |
| `supervisor` | team.view, leave.approve, attendance.team, corrections.approve |
| `employee` | self.view, self.edit_basic, attendance.own, leave.request, payslips.own |

### Permission Modules

```
employees:      view, create, edit, delete, transition, import, export
attendance:     own, team, all, correct, import, settings
leave:          request, approve, reject, manage_types, manage_balances
payroll:        view, process, approve, void, configure, export
organization:   view, manage_branches, manage_departments, manage_positions
reports:        view, build, schedule, department (scoped to own dept)
devices:        view, manage, sync
settings:       view, edit, billing
admin:          tenants, revenue, impersonate, health
notifications:  manage_templates, manage_announcements
audit:          view, export
```

### Permission Resolution

```php
// In Policy classes — always use hasPermission(), never check role strings
public function update(User $user, Employee $employee): bool
{
    // Can edit any employee
    if ($user->hasPermission('employees.edit')) {
        return true;
    }

    // Can edit own basic fields
    if ($user->hasPermission('self.edit_basic') && $user->employee_id === $employee->id) {
        return true;
    }

    return false;
}
```

### Custom Roles

Tenant admins can create custom roles via the role editor UI:
- Select permissions from a categorized checklist
- Assign role to users
- System roles (is_system=true) cannot be deleted or have permissions removed
- Custom roles can be duplicated, edited, and deleted

### Permission Caching

- Permissions cached per user in Redis: `user:{id}:permissions` (15-min TTL)
- Invalidated on role change, permission change, or manual flush
- `$user->hasPermission()` reads from cache, falls back to DB

---

## Attendance Architecture (Flagship)

### Unified Attendance Engine

All sources feed into one engine:

```
Sources:
  Biometric → [Device Adapter] ──┐
  Mobile (online) ────────────────┤
  Mobile (offline) → [Sync] ─────┤
  QR scan ────────────────────────┤→ [Attendance Engine] → [DB]
  Web clock ──────────────────────┤         |
  Kiosk ──────────────────────────┤         +→ Idempotency Check
  Manual entry ───────────────────┤         +→ Confidence Score
  CSV import ─────────────────────┤         +→ Conflict Resolution (State Machine)
  REST API ───────────────────────┘         +→ Shift Matching
                                            +→ Intelligence Detection
                                            +→ Audit Log
```

### Device Adapter Pattern

```php
interface DeviceAdapter
{
    public function connect(DeviceConfig $config): void;
    public function pullEvents(Carbon $since): Collection;
    public function pushEventUrl(): string;
    public function getDeviceInfo(): DeviceInfo;
    public function getStatus(): DeviceStatus;
    public function enrollEmployee(Employee $employee): EnrollmentResult;
    public function getCapabilities(): DeviceCapabilities;
}
```

v1.0 implementations:
- `HikvisionAdapter` — ISAPI HTTP protocol (pull + push webhook)
- `ZktecoAdapter` — Push/Pull SDK protocol
- `MockAdapter` — testing

v2.0+: Suprema, Anviz, FingerTec, Dahua via same interface.

### Offline Sync Architecture

```
                     Phone (PWA)
                         |
              ┌──────────┴──────────┐
              |                     |
          [Online]              [Offline]
              |                     |
        Direct API POST      IndexedDB queue
              |                     |
              |              Encrypted record:
              |                - employee_public_id
              |                - tenant_public_id
              |                - timestamps (UTC)
              |                - GPS coordinates
              |                - geofence result
              |                - device_id + signature
              |                - offline_token (HMAC-SHA256)
              |                - optional selfie (compressed JPEG, max 500KB)
              |                     |
              |              Network restored
              |                     |
              |              Service Worker background sync
              |                     |
              └──────────┬──────────┘
                         |
                   [Server API]
                         |
              ┌──────────┴──────────┐
              |                     |
        [Idempotency Check]   [Conflict Resolver]
              |                     |
              └──────────┬──────────┘
                         |
                   [Attendance Engine]
                         |
                   [Database + Audit Log]
```

### Conflict Resolution State Machine

Formal decision table — every case must have a deterministic outcome:

| Scenario | Time Gap | Same Employee? | Resolution | Action |
|---|---|---|---|---|
| Same source, same minute | < 1 min | Yes | Deduplicate | Keep first, discard duplicate |
| Different source, same minute | < 1 min | Yes | Merge | Keep highest confidence score |
| Different source, close time | 1-5 min | Yes | Merge | Earliest check-in, latest check-out |
| Different source, far apart | > 5 min | Yes | Flag | Create `conflict` record for HR review |
| Offline arrives after payroll close | Any | Yes | Retroactive | Create adjustment record, notify HR |
| Confidence scores tied | Any | Yes | Source priority | Biometric > Mobile+GPS > QR > Web > Manual > CSV |
| Check-in without check-out (same day) | End of day | Yes | Auto-flag | Missing punch alert to employee + supervisor |
| Check-out without check-in | Any | Yes | Flag | Create `conflict` record for HR review |
| Duplicate idempotency key | Any | Yes | Skip | Return `was_duplicate: true` |

Implementation: `ConflictResolver` service with explicit test cases for every row in this table.

### Confidence Scoring

| Source | Base Score | GPS Match | Geofence Match | Selfie Present | Final Range |
|---|---|---|---|---|---|
| Biometric (online) | 100 | N/A | N/A | N/A | 100 |
| Biometric (offline device) | 95 | N/A | N/A | N/A | 95 |
| Mobile + GPS + selfie | 90 | +5 | +5 | +0 | 90-100 |
| Mobile + GPS (no selfie) | 85 | +5 | +5 | -5 | 80-90 |
| Offline mobile + GPS | 83 | +5 | +5 | -2 | 81-91 |
| QR + GPS | 80 | +5 | +5 | N/A | 80-90 |
| Kiosk (fixed location) | 80 | N/A | +5 | N/A | 80-85 |
| Web attendance | 70 | N/A | N/A | N/A | 70 |
| Manual HR entry | 60 | N/A | N/A | N/A | 60 |
| CSV import | 50 | N/A | N/A | N/A | 50 |

Configurable threshold per tenant: records below threshold require manager approval (default: 70).

### Shift Matching Cascade

```
1. Check ShiftAssignment for specific employee + date
2. If none → check department default shift
3. If none → check branch default shift
4. If none → check tenant default shift
5. If none → mark attendance as PENDING (requires shift assignment)
```

All shifts must have `is_active = true` to be matched. Factories must set this by default.

---

## Payroll Architecture

### Payroll Engine Pipeline

```
PayrollEngine::process(tenantId, periodStart, periodEnd)
  |
  For each active employee:
  |
  Step 1:  Gather confirmed attendance (worked days, OT hours by type)
  Step 2:  Gather approved leave for period
  Step 3:  Calculate basic salary (prorate if mid-month hire/termination/Pagumen)
  Step 4:  Calculate overtime (OvertimeCalculator: normal/night/holiday/holiday-night)
  Step 5:  Calculate allowances (AllowanceService: fixed + percentage)
  Step 6:  Sum gross salary (basic + OT + allowances)
  Step 7:  Calculate taxable income (gross - non-taxable allowances)
  Step 8:  Calculate income tax (TaxCalculator: Ethiopian brackets)
  Step 9:  Calculate pension (PensionCalculator: 7% employee + 11% employer)
  Step 10: Deduct loans (LoanService: monthly installment)
  Step 11: Deduct other deductions
  Step 12: Calculate net salary (gross - tax - pension - loans - deductions)
  Step 13: Create PayrollEntry with calculation_log JSON
  |
  All amounts in integer cents (BIGINT)
  |
  PayrollRun status: draft → approved → (voided if error found)
  |
  Idempotency: unique constraint on tenant_id + period_key
```

### Calculation Log (Mandatory Per Entry)

Every `PayrollEntry` stores a `calculation_log` JSON column:

```json
{
  "version": "1.0",
  "calculated_at": "2026-07-15T10:30:00Z",
  "employee_public_id": "01HXYZ...",
  "steps": [
    {
      "step": 1,
      "name": "attendance",
      "inputs": { "period_start": "2026-07-01", "period_end": "2026-07-31", "working_days": 22 },
      "output": { "worked_days": 20, "ot_normal_minutes": 480, "ot_night_minutes": 0, "ot_holiday_minutes": 120 }
    },
    {
      "step": 3,
      "name": "basic_salary",
      "inputs": { "monthly_salary_cents": 1500000, "working_days": 22, "worked_days": 20 },
      "rule": "full_month",
      "output": { "basic_salary_cents": 1500000 }
    },
    {
      "step": 8,
      "name": "income_tax",
      "inputs": { "gross_taxable_cents": 1750000 },
      "bracket": { "min": 1650100, "max": 3200000, "rate": 15, "deduction": 14250 },
      "output": { "tax_cents": 248250 }
    }
  ],
  "summary": {
    "gross_cents": 1850000,
    "tax_cents": 248250,
    "pension_employee_cents": 105000,
    "pension_employer_cents": 165000,
    "loan_deduction_cents": 50000,
    "other_deductions_cents": 0,
    "net_cents": 1446750
  }
}
```

This log is immutable after payroll approval. It provides the audit trail required by Ethiopian labor law.

### Payroll Voiding and Reprocessing

```
Void:      POST /api/v1/payroll/runs/{id}/void
           → Marks run as "voided"
           → Creates reversal entries
           → Audit log with reason
           → Only tenant_admin can void
           → Voided payslips show "VOIDED" watermark

Reprocess: POST /api/v1/payroll/runs/{id}/reprocess
           → Creates new run for same period
           → Links to voided run via voided_run_id
           → Full pipeline re-execution
           → New calculation_logs generated
```

### Ethiopian Tax Brackets (Configurable)

| Bracket | Min (ETB) | Max (ETB) | Rate | Deduction |
|---|---|---|---|---|
| 1 | 0 | 600 | 0% | 0 |
| 2 | 601 | 1,650 | 10% | 60 |
| 3 | 1,651 | 3,200 | 15% | 142.50 |
| 4 | 3,201 | 5,250 | 20% | 302.50 |
| 5 | 5,251 | 7,800 | 25% | 565 |
| 6 | 7,801 | 10,900 | 30% | 955 |
| 7 | 10,901+ | — | 35% | 1,500 |

Stored in `tax_brackets` table. Configurable when government changes rates.

### Pagumen Proration Strategy

For the 13th Ethiopian month (5 or 6 days):

| Strategy | Calculation | Use Case |
|---|---|---|
| `full_month` | Treat as full monthly salary | Government organizations |
| `daily_rate` | (annual salary / 365) × actual Pagumen days | Private sector |

Configurable per tenant in settings: `pagumen_proration_strategy`.

---

## Employee Lifecycle State Machine

```
                    ┌─────────────┐
                    │   DRAFT     │ (created, not yet active)
                    └──────┬──────┘
                           │ activate
                    ┌──────▼──────┐
              ┌─────│  PROBATION  │─────┐
              │     └──────┬──────┘     │
              │            │ confirm    │ terminate
              │     ┌──────▼──────┐     │
              │     │   ACTIVE    │─────┤
              │     └──┬───┬───┬──┘     │
              │        │   │   │        │
              │  suspend│  │   │transfer│
              │        │   │   │        │
              │  ┌─────▼┐  │  ┌▼────┐   │
              │  │SUSP'D│  │  │TRANS│   │
              │  └──┬───┘  │  └──┬──┘   │
              │     │react.│     │done  │
              │     └──┬───┘     │      │
              │        │         │      │
              │        └────┬────┘      │
              │             │           │
              │      ┌──────▼──────┐    │
              │      │   ACTIVE    │    │
              │      └──────┬──────┘    │
              │             │           │
              │      ┌──────┴──────┐    │
              │      │  resign /   │    │
              │      │  retire /   │◄───┘
              │      │  terminate  │
              │      └──────┬──────┘
              │      ┌──────▼──────┐
              └─────►│  DEPARTED   │
                     └──────┬──────┘
                            │ rehire
                     ┌──────▼──────┐
                     │  PROBATION  │ (new employment record)
                     └─────────────┘
```

Valid transitions enforced by `EmployeeStatus::canTransitionTo()`. Every transition creates an `employee_transitions` record with reason, effective date, and metadata.

---

## API Architecture

### Response Envelope

Every list/paginated endpoint must return a `JsonResource::collection()` over a
paginator (never a raw `response()->json($paginator)`), so it serializes as
the standard envelope — see CLAUDE.md's "API Conventions" section for the
exact single-resource vs. paginated-collection shapes and the matching
`PaginatedResponse<T>` TypeScript type in `src/api/types.ts`. Returning a raw
paginator instead produces a different, incompatible shape (pagination fields
top-level instead of nested under `meta`) that silently breaks any frontend
code written against the standard envelope — this exact bug shipped on the
admin tenants list and the tenant audit log page before being caught.

### Contract Testing (Generated Types + MSW)

`npm run generate:api` exports the Laravel OpenAPI spec (via Scramble) and
runs it through `openapi-typescript` into `src/api/generated.ts`
(`components["schemas"][...]`, `paths[...]`). Feature-scoped types
(`src/features/{feature}/types.ts`) should `Pick<>` from these generated
schemas rather than hand-declaring parallel interfaces — a hand-rolled type
drifts silently from the real API shape. This exact drift shipped once:
`features/employees/types.ts` declared `position: { name: string }` while
the real `PositionResource` field is `title`, so the employee list and
detail pages always rendered "—" for position in production.

Request mocking in Vitest uses MSW (`src/test/msw/handlers.ts` +
`src/test/msw/server.ts`, wired into `src/test/setup.ts` with
`onUnhandledRequest: "error"`) rather than mocking `apiClient` directly,
so tests exercise the real axios request/response pipeline (interceptors,
error handling) against fixtures typed off the same generated schemas —
see `src/test/employees-api.test.tsx` for the reference pattern. Prefer this
over `vi.mock("@/api/client")` for new feature tests; the existing
`vi.mock`-based tests (e.g. `notifications-optimistic.test.tsx`) predate
this convention and don't need to be migrated on sight.

### Route Structure

```
/api/v1/
  /auth/
    POST   /login                          Rate: 5/min/IP
    POST   /logout
    POST   /refresh
    POST   /otp/request                    Rate: 3/min/phone
    POST   /otp/verify
    POST   /mfa/verify
    POST   /mfa/setup
    POST   /mfa/enable
    POST   /mfa/disable
    GET    /sessions
    DELETE /sessions/{id}
    POST   /sessions/revoke-all

  /tenant/
    GET    /current

  /onboarding/
    GET    /progress
    PUT    /progress/{step}
    POST   /apply-template
    POST   /employees/preview
    POST   /employees/commit

  /organization/
    GET    /tree
    GET    /chart
    CRUD   /branches
    CRUD   /departments
    CRUD   /teams
    CRUD   /positions
    CRUD   /grades
    CRUD   /cost-centers

  /employees/
    GET    /                    Paginated, filterable, searchable (fulltext)
    POST   /                   Create
    GET    /stats               Count by status, department, branch
    GET    /{id}                Show with relationships
    PUT    /{id}                Update (audit logged)
    DELETE /{id}                Soft delete (tenant_admin only)
    POST   /{id}/transition     Lifecycle transition
    CRUD   /{id}/documents
    CRUD   /{id}/emergency-contacts
    CRUD   /{id}/education
    CRUD   /{id}/bank-details   Encrypted
    POST   /import/template     Download CSV template
    POST   /import/preview      Parse + validate
    POST   /import/commit       Create employees (idempotent)
    GET    /import/status/{key} Import progress
    POST   /bulk-update         Batch department/branch/status update
    GET    /export              CSV with current filters

  /attendance/
    POST   /check-in            Idempotency-Key required
    POST   /check-out           Idempotency-Key required
    GET    /my                  Employee's own records
    GET    /team                Manager's team
    GET    /today               Today's overview (HR)
    GET    /                    All, filterable (HR)
    GET    /{id}                Single record detail
    POST   /corrections         Request correction
    GET    /corrections         List corrections
    PUT    /corrections/{id}/approve
    PUT    /corrections/{id}/reject
    POST   /sync                Offline batch upload (HMAC validated)

  /mobile-attendance/
    POST   /check-in            GPS + geofence + optional selfie
    POST   /check-out

  /qr-attendance/
    POST   /generate            Time-limited QR token
    POST   /scan                Validate QR + record attendance

  /kiosk/
    POST   /sessions            Create kiosk session (admin)
    GET    /sessions            List sessions
    DELETE /sessions/{id}       End session
    POST   /check-in            Kiosk check-in (employee code/PIN)

  /devices/
    CRUD   /                    Biometric device management
    GET    /{id}/status
    POST   /{id}/pull           Trigger event pull
    GET    /dashboard           Real-time device health
    POST   /webhook/{adapter}   Device push endpoint

  /shifts/
    CRUD   /
    POST   /assign              Assign shift to employee/dept/branch
    GET    /assignments         List assignments
    GET    /roster              Week/month roster view
    GET    /schedule            Employee's schedule

  /leave/
    POST   /request             Submit leave request
    GET    /my                  Employee's own requests
    GET    /team                Manager's team requests
    GET    /balance             Current user's balances
    GET    /balance/{employee}  Specific employee (HR only)
    PUT    /{id}/approve
    PUT    /{id}/reject
    PUT    /{id}/cancel         Employee cancels pending
    GET    /calendar            Team leave calendar

  /holidays/
    CRUD   /
    POST   /auto-detect         Auto-detect Ethiopian holidays

  /payroll/
    POST   /process             Run payroll (Idempotency-Key required)   Rate: 2/hr/tenant
    GET    /runs                Payroll run history
    GET    /runs/{id}           Run detail with entries
    GET    /runs/{id}/summary   Summary by department/cost center
    PUT    /runs/{id}/approve   Approve draft run
    POST   /runs/{id}/void     Void approved run (tenant_admin)
    POST   /runs/{id}/reprocess Reprocess voided run
    GET    /payslips/my         Employee's own payslips
    GET    /payslips/{employee} Specific employee (finance_admin)
    GET    /runs/{id}/export/bank     Bank transfer CSV
    GET    /runs/{id}/export/tax      Tax report
    GET    /runs/{id}/export/pension  Pension report
    CRUD   /loans               Employee loans
    CRUD   /rules               Payroll rules
    GET    /tax-brackets        Current tax brackets

  /notifications/
    GET    /                    Paginated, filterable (read/unread)
    PUT    /{id}/read
    PUT    /read-all
    GET    /unread-count
    GET    /preferences
    PUT    /preferences

  /announcements/
    CRUD   /                    (hr_admin+ only)

  /approvals/
    GET    /pending             Unified approval center
    POST   /batch               Batch approve/reject

  /reports/
    GET    /sources             Available data sources
    POST   /generate            Run report
    POST   /export              Export as PDF/Excel/CSV
    POST   /save                Save report template
    GET    /saved               List saved templates
    DELETE /saved/{id}
    POST   /schedule            Schedule recurring report
    GET    /scheduled
    DELETE /scheduled/{id}

  /dashboard/
    GET    /executive           Executive overview
    GET    /executive/attendance
    GET    /executive/payroll
    GET    /executive/workforce
    GET    /manager             Manager overview
    GET    /employee            Employee overview

  /analytics/
    GET    /departments         Department comparison
    GET    /departments/{id}    Department detail
    GET    /branches            Branch comparison
    GET    /branches/{id}       Branch detail

  /directory/
    GET    /                    Searchable employee directory (limited fields)

  /profile/
    GET    /                    Own profile
    PUT    /                    Self-update (limited fields, some need approval)

  /files/
    POST   /presign             Get presigned upload URL
    POST   /confirm             Confirm upload (verify magic bytes)
    GET    /{id}                Download via signed URL

  /webhooks/
    CRUD   /                    (tenant_admin only)
    POST   /{id}/test           Send test event
    GET    /{id}/deliveries     Delivery log

  /api-keys/
    CRUD   /                    (tenant_admin only)

  /settings/
    GET    /                    Tenant settings (all categories)
    PUT    /                    Update settings
    GET    /notification-templates
    PUT    /notification-templates/{type}
    POST   /export              Full tenant data export
    GET    /export/status
    GET    /roles               List roles
    POST   /roles               Create custom role
    PUT    /roles/{id}          Update role permissions
    DELETE /roles/{id}          Delete custom role (not system roles)

  /accounting/
    GET    /chart-of-accounts
    PUT    /chart-of-accounts
    GET    /journal/{payroll_run_id}
    GET    /export/{payroll_run_id}

  /audit-logs/
    GET    /                    Tenant-scoped, paginated, filterable
    GET    /export              CSV export

  /billing/
    GET    /dashboard           Current plan + billing info
    POST   /change-plan         Upgrade/downgrade
    GET    /invoices            Invoice history
    GET    /invoices/{id}/receipt   PDF receipt
    POST   /invoices/{id}/mark-paid

  /admin/                       Super admin only (super_admin role)
    GET    /tenants
    GET    /tenants/{id}
    PUT    /tenants/{id}/status
    POST   /tenants/{id}/impersonate   MFA required, 30-min expiry
    POST   /tenants/{id}/extend-trial
    POST   /tenants/{id}/backup
    GET    /revenue
    GET    /health
    GET    /audit               Cross-tenant audit log
    GET    /billing/invoices    All invoices
    PUT    /billing/invoices/{id}/mark-paid
    CRUD   /plans

  /register/                    Public (no auth)
    POST   /                    Create tenant + admin user
    GET    /check-subdomain     Subdomain availability

  /contact/                     Public (no auth)
    POST   /                    Contact form submission    Rate: 3/min/IP

  /plans/                       Public (no auth)
    GET    /                    Plan listing (cacheable)

  /templates/                   Public (no auth)
    GET    /                    Organization templates (cacheable)
```

---

## Frontend Form Architecture

CLAUDE.md's "Form Pattern Library" section defines the six form patterns
(inline, dialog, page-tabbed, wizard, inline-edit, drawer) and the rules that
apply to all of them. There is no shared wrapper component per pattern (e.g.
no `<DrawerForm>`/`<WizardForm>`) — each page hand-builds its form using the
shadcn primitives directly. Status of the cross-cutting rules as of this
audit:

- **Submit/cancel placement, primary action on the right** — followed by
  convention across existing forms; not structurally enforced.
- **Escape to cancel dialogs** — satisfied for free: `components/ui/dialog.tsx`
  wraps Radix UI's `Dialog` primitive, which handles Escape-to-close and focus
  trapping out of the box.
- **Enter to submit dialogs** — satisfied for free when a dialog's fields are
  wrapped in `<form onSubmit={...}>` (the established convention in this
  codebase) — that's native `<form>` behavior, not custom code. Any dialog
  that renders fields *outside* a `<form>` element loses this for free.
- **Unsaved changes warning on navigation (page forms/wizards)** — was
  entirely missing. Added `useUnsavedChangesWarning(hasUnsavedChanges)` in
  `src/lib/hooks/useUnsavedChangesWarning.ts`, wired into the employee
  create page (`src/app/(dashboard)/employees/new/page.tsx`) as the
  reference implementation. It only covers browser-level navigation
  (tab close, refresh, external link) via the `beforeunload` event — the
  Next.js App Router has no supported hook for intercepting client-side
  route changes (`router.push` to another page), so in-app navigation away
  from a dirty form is not currently guarded. Other page forms and the
  onboarding wizard should adopt the same hook.
- **Auto-save every 30s (page forms/wizards)** — not implemented anywhere.
  Would need a draft-persistence design (where drafts live, conflict
  handling on reconnect) before it's worth building; out of scope here.
- **Forms use React Hook Form + Zod** (per the Stack table) — inconsistently
  followed; several forms (e.g. the employee create page) use plain
  `useState` instead. Not changed here — migrating an existing working form
  to a different form library is a larger, separate refactor.

---

## Event Architecture

### Domain Events (Laravel Events + Listeners)

| Event | Listeners |
|---|---|
| `TenantCreated` | Provision MinIO bucket, seed defaults, seed system roles, create default shifts |
| `EmployeeCreated` | Send welcome notification, create user account, initialize leave balances |
| `EmployeeTransitioned` | Update payroll status, send notification, audit log, update reporting |
| `AttendanceRecorded` | Run intelligence checks (late/early/OT), update dashboard cache, check conflicts |
| `AttendanceCorrectionRequested` | Notify approver chain |
| `AttendanceCorrectionApproved` | Update record, recalculate affected payroll if needed |
| `LeaveRequested` | Notify approver, check team minimum staffing |
| `LeaveApproved` | Update balance, notify employee, update calendar cache |
| `LeaveRejected` | Clear pending_days, notify employee |
| `PayrollProcessed` | Generate payslips (PDF), notify employees, create journal entries |
| `PayrollApproved` | Lock calculation_logs, enable payslip downloads |
| `PayrollVoided` | Create reversal entries, notify tenant admin, audit log |
| `DeviceOffline` | Alert admin, log event, update device dashboard |
| `DeviceOnline` | Clear offline alert, trigger event pull for missed period |
| `OfflineSyncCompleted` | Process conflicts via state machine, update dashboard |
| `SubscriptionChanged` | Update feature access, invalidate permission cache, notify admin |
| `TrialExpiring` | Notify tenant admin at 30 days, 7 days, 1 day before expiry |
| `MissingPunchDetected` | Notify employee + supervisor |
| `AnnouncementPublished` | Notify target audience via Reverb + in-app |

### Queue Architecture (Horizon)

| Queue | Purpose | Workers | Retry | On Final Failure |
|---|---|---|---|---|
| `default` | General tasks | 2 | 3 | Log to failed_jobs, surface in admin dashboard |
| `attendance` | Attendance processing, sync | 4 | 5 | Preserve payload in failed_syncs, notify admin |
| `payroll` | Payroll calculations | 2 | 1 | Mark run as failed, notify tenant_admin |
| `notifications` | Email, SMS, push | 3 | 3 | Log failure, do not retry (stale) |
| `exports` | PDF, Excel, bank files | 2 | 2 | Mark export as failed, notify user |
| `devices` | Biometric device polling | 2 | 5 | Trigger DeviceOffline event |
| `sync` | Offline data sync | 3 | 5 | Preserve payload in failed_syncs, notify admin |

Failed jobs surface in Super Admin dashboard with retry/dismiss actions.

---

## Caching Strategy (Redis)

| Key Pattern | TTL | Invalidation | Purpose |
|---|---|---|---|
| `tenant:{id}:config` | 1 hour | On settings update | Tenant configuration |
| `tenant:{id}:holidays:{year}` | 24 hours | On holiday change | Holiday lookup |
| `tenant:{id}:shifts` | 1 hour | On shift change | Shift matching |
| `tenant:{id}:org:structure` | 30 min | On org change | Org tree/chart |
| `user:{id}:permissions` | 15 min | On role/permission change | Permission checks |
| `dashboard:{tenant}:{type}:{date}` | 5 min | On new attendance/payroll | Dashboard KPIs |
| `employee:{id}:leave:balance` | 1 hour | On leave change | Leave balance display |
| `tenant:{id}:plans` | 24 hours | On plan change | Plan catalog |
| `tenant:{id}:templates` | 24 hours | On template change | Org templates |
| `search:{tenant}:{query_hash}` | 5 min | On employee change | Search results |

---

## File Storage (MinIO)

### Bucket Structure
```
ethr/
  tenant-{public_id}/
    employees/
      {employee_public_id}/
        profile/          Profile photos (max 2MB, EXIF stripped)
        documents/        Contracts, certificates (max 10MB)
        attendance/       Selfie captures (max 500KB, EXIF stripped)
    payroll/
      {year}/{month}/     Payslip PDFs
    reports/              Generated reports
    imports/              Upload staging (TTL: 7 days)
    branding/             Logo, theme assets
    exports/              Generated exports (TTL: 30 days)
```

### Upload Flow (With Content Verification)
```
Client → POST /api/v1/files/presign { filename, content_type }
  → Server validates type/size against whitelist
    → Server generates presigned MinIO URL (5-min expiry)
      → Client uploads directly to MinIO
        → Client confirms: POST /api/v1/files/confirm { key }
          → Server downloads from MinIO temporarily
            → Verify magic bytes match declared content_type:
              JPEG: FF D8 FF
              PNG:  89 50 4E 47
              PDF:  25 50 44 46
              CSV:  valid UTF-8, no embedded scripts
            → Strip EXIF data from images (privacy)
            → Re-upload cleaned file to MinIO
            → Record in files table, link to entity
            → Return file metadata
```

### File Size Limits

| Type | Max Size |
|---|---|
| Profile photo | 2 MB |
| Attendance selfie | 500 KB |
| Document (PDF, contract) | 10 MB |
| CSV import | 50 MB |
| Logo/branding | 2 MB |

---

## Real-time (Reverb WebSocket)

### Channels

| Channel | Events | Subscribers |
|---|---|---|
| `tenant.{id}` | Announcements, system alerts | All tenant users |
| `user.{id}` | Personal notifications, approval requests | Individual user |
| `attendance.{tenant_id}` | New check-in/out, anomaly alerts | HR, managers |
| `device.{device_id}` | Status changes (online/offline) | Admins |
| `dashboard.{tenant_id}` | KPI updates | Executives |
| `kiosk.{session_id}` | Kiosk session updates | Kiosk display |

### Connection Management

- Connect on authentication
- Listen to `user.{id}` + `tenant.{id}` channels
- Reconnect with exponential backoff (1s, 2s, 4s, 8s, max 30s)
- Heartbeat every 30s
- Clean disconnect on logout

---

## Deployment Architecture

### Docker Compose Services

```yaml
services:
  nginx:          Reverse proxy, SSL termination, rate limiting, security headers
  api:            Laravel PHP-FPM (8.2)
  frontend:       Next.js (SSR, Node 20 LTS)
  worker:         Queue workers (Horizon, all queues)
  scheduler:      Laravel scheduler (cron, 1-min intervals)
  reverb:         WebSocket server (Reverb)
  mariadb:        Database (10.11, persistent volume)
  redis:          Cache + queues + sessions (7+, persistent volume)
  minio:          File storage (persistent volume)
```

### Nginx Routing

```
*.ethr.et           → frontend (Next.js)
*.ethr.et/api/*     → api (Laravel)
*.ethr.et/ws        → reverb (WebSocket)
ethr.et             → frontend (marketing pages)
admin.ethr.et       → frontend (super admin)
```

### Health Checks

| Service | Endpoint | Interval |
|---|---|---|
| API | `GET /api/health` (checks DB, Redis, MinIO, queue) | 30s |
| Frontend | `GET /api/healthz` | 30s |
| MariaDB | TCP 3306 | 10s |
| Redis | `PING` | 10s |
| MinIO | `GET /minio/health/live` | 30s |
| Reverb | WebSocket ping | 30s |
| Queue | Horizon dashboard + queue depth check | 60s |

---

## Security Architecture

### Defense Layers

1. **Network:** Nginx rate limiting, IP blocking, TLS 1.2+, security headers (HSTS, CSP, X-Frame-Options)
2. **Application:** CORS per-tenant subdomain, CSRF, input validation (FormRequest), SQL injection prevention (Eloquent only)
3. **Authentication:** Sanctum tokens (scoped abilities), MFA/TOTP, device trust, session management, login rate limiting
4. **Authorization:** Permission-based RBAC, Policy per endpoint, `hasPermission()` checks, custom roles
5. **Data:** Tenant isolation (BelongsToTenant + TenantIsolationTest), field-level encryption, immutable audit logging
6. **Storage:** MinIO bucket policies, signed URLs (5-min expiry), content-type verification (magic bytes), EXIF stripping
7. **Monitoring:** Failed login tracking, anomaly detection, admin alerts, queue failure recovery
8. **Webhooks:** HMAC-SHA256 signatures, SSRF prevention (block private IPs/metadata endpoints)
9. **Impersonation:** MFA required, 30-min expiry, restricted actions, full audit trail with `impersonated_by`

### Security Headers (Nginx)

```
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000; includeSubDomains
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self' wss:; font-src 'self';
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(self), microphone=(), geolocation=(self)
```

### Sensitive Data Handling

| Field | Storage | Access | Verification |
|---|---|---|---|
| Passwords | bcrypt hash | Never returned in API | Login comparison only |
| Bank account numbers | AES-256 encrypted | finance_admin only | Decrypted on payslip generation |
| TIN (tax ID) | AES-256 encrypted | finance_admin only | Decrypted on tax report |
| OTP codes | Argon2id hash | Single-use, 5-min expiry | Comparison only |
| TOTP secrets | AES-256 encrypted | User only (during setup) | Decrypted for verification |
| Sanctum tokens | SHA-256 hash | Never stored in plain text | Comparison only |
| Attendance GPS | Plain (tenant-scoped) | HR + managers | Displayed in attendance detail |
| Employee photos | MinIO (signed URLs) | Tenant users | EXIF stripped on upload |
| Payroll calculation_log | Plain JSON (tenant-scoped) | finance_admin + employee (own) | Immutable after approval |

### Webhook SSRF Prevention

Before delivering webhooks, validate target URL:
1. Resolve hostname to IP
2. Reject private ranges: `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `127.0.0.0/8`, `169.254.0.0/16`
3. Reject IPv6 private: `::1`, `fc00::/7`, `fe80::/10`
4. Reject cloud metadata: `169.254.169.254`
5. Reject known internal hostnames: `localhost`, `*.ethr.et`, `*.internal`
6. Re-validate on every delivery attempt (DNS can change)

### Impersonation Guardrails

While impersonating a tenant admin:
- Blocked: password changes, MFA changes, API key creation, subscription changes, data deletion
- Logged: every action with `impersonated_by` field in audit log
- Expiry: 30-minute session timeout, non-renewable
- Requires: MFA verification before starting impersonation
