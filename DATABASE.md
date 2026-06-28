# ETHR — Data Model

## Engine & Conventions

- **Engine:** MariaDB 10.11 (prod), SQLite in-memory (tests)
- **Charset:** utf8mb4 (for Amharic and other Ethiopian scripts)
- **Collation:** utf8mb4_unicode_ci
- **Naming:** snake_case for tables and columns
- **PKs:** `BIGINT UNSIGNED AUTO_INCREMENT` (internal), `CHAR(26)` ULID `public_id` (API)
- **FKs:** `BIGINT UNSIGNED` with `CONSTRAINT` and `ON DELETE` rules
- **Currency:** `BIGINT` (cents/minor units), never `DECIMAL` or `FLOAT`
- **Dates:** `TIMESTAMP` (UTC) for created_at/updated_at; `DATE` for business dates
- **Enums:** `VARCHAR` columns with PHP backed enums (not MySQL ENUM type)
- **JSON:** `JSON` column type for flexible metadata/payload
- **Soft deletes:** `deleted_at TIMESTAMP NULL` on all business entities
- **Indexes:** composite indexes on `(tenant_id, ...)` for all tenant-scoped queries

---

## Entity Relationship Overview

```
Tenant ──1:N── Branch
Tenant ──1:N── Department
Tenant ──1:N── Employee
Tenant ──1:N── User
Tenant ──1:N── Subscription
Tenant ──1:N── Device

Employee ──1:N── AttendanceRecord
Employee ──1:N── LeaveRequest
Employee ──1:N── PayrollEntry
Employee ──1:N── Document
Employee ──N:1── Department
Employee ──N:1── Branch
Employee ──N:1── Position
Employee ──1:1── User (optional)

Department ──N:1── Branch
Department ──N:1── Department (parent, hierarchical)

Shift ──N:M── Employee (via shift_assignments)
Shift ──1:N── AttendanceRecord (matched)

PayrollRun ──1:N── PayrollEntry
LeaveType ──1:N── LeaveRequest
```

---

## Core Platform Tables

### tenants
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL        -- ULID
name                VARCHAR(255) NOT NULL
subdomain           VARCHAR(63) UNIQUE NOT NULL
custom_domain       VARCHAR(255) UNIQUE NULL
type                VARCHAR(50) NOT NULL             -- government, bank, hospital, etc.
status              VARCHAR(20) NOT NULL DEFAULT 'trial' -- trial, active, suspended, cancelled
logo_path           VARCHAR(500) NULL
theme               JSON NULL                        -- { primary_color, secondary_color }
default_locale      VARCHAR(5) NOT NULL DEFAULT 'en'
timezone            VARCHAR(50) NOT NULL DEFAULT 'Africa/Addis_Ababa'
ethiopian_calendar  BOOLEAN NOT NULL DEFAULT false    -- enable dual calendar
settings            JSON NULL                        -- flexible tenant settings
trial_ends_at       TIMESTAMP NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
deleted_at          TIMESTAMP NULL

INDEX idx_status (status)
INDEX idx_subdomain (subdomain)
```

### plans
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
name                VARCHAR(100) NOT NULL            -- Starter, Professional, Enterprise
slug                VARCHAR(100) UNIQUE NOT NULL
price_cents         BIGINT NOT NULL                  -- monthly price in ETB cents
max_employees       INT NOT NULL
max_branches        INT NOT NULL
max_devices         INT NOT NULL
features            JSON NOT NULL                    -- { "payroll": true, "api": false, ... }
is_active           BOOLEAN NOT NULL DEFAULT true
sort_order          INT NOT NULL DEFAULT 0
created_at          TIMESTAMP
updated_at          TIMESTAMP

-- Global table (no tenant_id)
```

### subscriptions
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
plan_id             BIGINT UNSIGNED NOT NULL FK -> plans
status              VARCHAR(20) NOT NULL             -- trial, active, past_due, paused, cancelled
seats               INT NOT NULL DEFAULT 0           -- employee count for billing
current_period_start TIMESTAMP NOT NULL
current_period_end  TIMESTAMP NOT NULL
paused_at           TIMESTAMP NULL
cancelled_at        TIMESTAMP NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_tenant (tenant_id)
INDEX idx_status (status)
```

### invoices
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
subscription_id     BIGINT UNSIGNED NOT NULL FK -> subscriptions
amount_cents        BIGINT NOT NULL
tax_cents           BIGINT NOT NULL DEFAULT 0
total_cents         BIGINT NOT NULL
status              VARCHAR(20) NOT NULL             -- draft, pending, paid, voided
line_items          JSON NOT NULL                    -- array of { description, amount_cents }
paid_at             TIMESTAMP NULL
due_date            DATE NOT NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_tenant (tenant_id)
INDEX idx_status (status)
```

---

## Authentication Tables

### users
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
employee_id         BIGINT UNSIGNED NULL FK -> employees
email               VARCHAR(255) NOT NULL
phone               VARCHAR(20) NULL
password            VARCHAR(255) NOT NULL
role                VARCHAR(30) NOT NULL             -- UserRole enum
status              VARCHAR(20) NOT NULL DEFAULT 'active' -- active, inactive, locked
mfa_enabled         BOOLEAN NOT NULL DEFAULT false
mfa_secret          TEXT NULL                        -- encrypted TOTP secret
locale              VARCHAR(5) NOT NULL DEFAULT 'en'
last_login_at       TIMESTAMP NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
deleted_at          TIMESTAMP NULL

UNIQUE idx_tenant_email (tenant_id, email)
INDEX idx_tenant_role (tenant_id, role)
```

### personal_access_tokens
```sql
-- Laravel Sanctum default table
id, tokenable_type, tokenable_id, name, token, abilities, last_used_at, expires_at, created_at, updated_at
```

### otp_codes
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
user_id             BIGINT UNSIGNED NOT NULL FK -> users
code_hash           VARCHAR(255) NOT NULL            -- Argon2id hash
purpose             VARCHAR(30) NOT NULL             -- login, mfa_setup
used                BOOLEAN NOT NULL DEFAULT false
expires_at          TIMESTAMP NOT NULL
created_at          TIMESTAMP

INDEX idx_user_purpose (user_id, purpose)
```

### login_histories
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
user_id             BIGINT UNSIGNED NOT NULL FK -> users
ip_address          VARCHAR(45) NOT NULL
user_agent          TEXT NULL
device_name         VARCHAR(255) NULL
status              VARCHAR(20) NOT NULL             -- success, failed, mfa_required, locked
created_at          TIMESTAMP

INDEX idx_user (user_id)
INDEX idx_tenant_created (tenant_id, created_at)
```

### trusted_devices
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
user_id             BIGINT UNSIGNED NOT NULL FK -> users
device_hash         VARCHAR(255) NOT NULL            -- hash of device fingerprint
device_name         VARCHAR(255) NULL
trusted_until       TIMESTAMP NOT NULL
created_at          TIMESTAMP

INDEX idx_user (user_id)
```

### audit_log
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
tenant_id           BIGINT UNSIGNED NULL FK -> tenants  -- null for super-admin actions
user_id             BIGINT UNSIGNED NULL FK -> users
action              VARCHAR(100) NOT NULL            -- employee.created, attendance.corrected, etc.
auditable_type      VARCHAR(100) NULL                -- App\Models\Employee, etc.
auditable_id        BIGINT UNSIGNED NULL
payload             JSON NULL                        -- { before: {}, after: {}, metadata: {} }
ip_address          VARCHAR(45) NULL
user_agent          TEXT NULL
created_at          TIMESTAMP NOT NULL

-- APPEND ONLY: no updates, no deletes
INDEX idx_tenant_action (tenant_id, action)
INDEX idx_auditable (auditable_type, auditable_id)
INDEX idx_tenant_created (tenant_id, created_at)
```

---

## Organization Tables

### branches
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
name                VARCHAR(255) NOT NULL
code                VARCHAR(50) NULL
address             TEXT NULL
city                VARCHAR(100) NULL
phone               VARCHAR(20) NULL
email               VARCHAR(255) NULL
is_headquarters     BOOLEAN NOT NULL DEFAULT false
latitude            DECIMAL(10,7) NULL               -- for geofencing
longitude           DECIMAL(10,7) NULL
geofence_radius_m   INT NULL                         -- meters
is_active           BOOLEAN NOT NULL DEFAULT true
created_at          TIMESTAMP
updated_at          TIMESTAMP
deleted_at          TIMESTAMP NULL

UNIQUE idx_tenant_code (tenant_id, code)
INDEX idx_tenant_active (tenant_id, is_active)
```

### departments
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
branch_id           BIGINT UNSIGNED NULL FK -> branches
parent_id           BIGINT UNSIGNED NULL FK -> departments  -- hierarchical
name                VARCHAR(255) NOT NULL
code                VARCHAR(50) NULL
head_employee_id    BIGINT UNSIGNED NULL FK -> employees
is_active           BOOLEAN NOT NULL DEFAULT true
sort_order          INT NOT NULL DEFAULT 0
created_at          TIMESTAMP
updated_at          TIMESTAMP
deleted_at          TIMESTAMP NULL

UNIQUE idx_tenant_code (tenant_id, code)
INDEX idx_tenant_branch (tenant_id, branch_id)
INDEX idx_parent (parent_id)
```

### teams
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
department_id       BIGINT UNSIGNED NOT NULL FK -> departments
name                VARCHAR(255) NOT NULL
lead_employee_id    BIGINT UNSIGNED NULL FK -> employees
is_active           BOOLEAN NOT NULL DEFAULT true
created_at          TIMESTAMP
updated_at          TIMESTAMP
deleted_at          TIMESTAMP NULL

INDEX idx_tenant_dept (tenant_id, department_id)
```

### positions
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
name                VARCHAR(255) NOT NULL
code                VARCHAR(50) NULL
grade_id            BIGINT UNSIGNED NULL FK -> grades
description         TEXT NULL
min_salary_cents    BIGINT NULL
max_salary_cents    BIGINT NULL
is_active           BOOLEAN NOT NULL DEFAULT true
created_at          TIMESTAMP
updated_at          TIMESTAMP
deleted_at          TIMESTAMP NULL

UNIQUE idx_tenant_code (tenant_id, code)
```

### grades
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
name                VARCHAR(100) NOT NULL
level               INT NOT NULL                     -- numeric level for comparison
description         TEXT NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP

UNIQUE idx_tenant_level (tenant_id, level)
```

### cost_centers
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
name                VARCHAR(255) NOT NULL
code                VARCHAR(50) NOT NULL
is_active           BOOLEAN NOT NULL DEFAULT true
created_at          TIMESTAMP
updated_at          TIMESTAMP

UNIQUE idx_tenant_code (tenant_id, code)
```

---

## Employee Tables

### employees
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
user_id             BIGINT UNSIGNED NULL FK -> users
department_id       BIGINT UNSIGNED NULL FK -> departments
branch_id           BIGINT UNSIGNED NULL FK -> branches
position_id         BIGINT UNSIGNED NULL FK -> positions
grade_id            BIGINT UNSIGNED NULL FK -> grades
team_id             BIGINT UNSIGNED NULL FK -> teams
cost_center_id      BIGINT UNSIGNED NULL FK -> cost_centers
supervisor_id       BIGINT UNSIGNED NULL FK -> employees  -- self-ref
name                VARCHAR(255) NOT NULL
name_am             VARCHAR(255) NULL                -- Amharic name
email               VARCHAR(255) NULL
phone               VARCHAR(20) NULL
employee_code       VARCHAR(50) NOT NULL
gender              VARCHAR(10) NULL                 -- male, female
date_of_birth       DATE NULL
nationality         VARCHAR(100) NULL
marital_status      VARCHAR(20) NULL
religion            VARCHAR(50) NULL
status              VARCHAR(30) NOT NULL DEFAULT 'hired' -- EmployeeStatus enum
hire_date           DATE NOT NULL
probation_end_date  DATE NULL
confirmation_date   DATE NULL
termination_date    DATE NULL
salary_cents        BIGINT NOT NULL DEFAULT 0
photo_path          VARCHAR(500) NULL
tin                 TEXT NULL                         -- encrypted tax ID
import_key          VARCHAR(255) NULL                -- idempotency for imports
created_at          TIMESTAMP
updated_at          TIMESTAMP
deleted_at          TIMESTAMP NULL

UNIQUE idx_tenant_code (tenant_id, employee_code)
UNIQUE idx_tenant_import (tenant_id, import_key)
INDEX idx_tenant_status (tenant_id, status)
INDEX idx_tenant_dept (tenant_id, department_id)
INDEX idx_tenant_branch (tenant_id, branch_id)
INDEX idx_supervisor (supervisor_id)
INDEX idx_name_search (tenant_id, name)
```

### employee_documents
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
employee_id         BIGINT UNSIGNED NOT NULL FK -> employees
category            VARCHAR(50) NOT NULL             -- contract, certificate, id_copy, other
name                VARCHAR(255) NOT NULL
file_path           VARCHAR(500) NOT NULL            -- MinIO path
file_size           INT NOT NULL                     -- bytes
mime_type           VARCHAR(100) NOT NULL
uploaded_by         BIGINT UNSIGNED NOT NULL FK -> users
expires_at          DATE NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
deleted_at          TIMESTAMP NULL

INDEX idx_employee (employee_id)
INDEX idx_tenant_category (tenant_id, category)
```

### employee_emergency_contacts
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
employee_id         BIGINT UNSIGNED NOT NULL FK -> employees
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
name                VARCHAR(255) NOT NULL
relationship        VARCHAR(50) NOT NULL
phone               VARCHAR(20) NOT NULL
email               VARCHAR(255) NULL
address             TEXT NULL
is_primary          BOOLEAN NOT NULL DEFAULT false
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_employee (employee_id)
```

### employee_bank_details
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
employee_id         BIGINT UNSIGNED NOT NULL FK -> employees
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
bank_name           VARCHAR(255) NOT NULL
branch_name         VARCHAR(255) NULL
account_number      TEXT NOT NULL                    -- AES-256 encrypted
account_name        VARCHAR(255) NOT NULL
is_primary          BOOLEAN NOT NULL DEFAULT true
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_employee (employee_id)
```

### employee_education
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
employee_id         BIGINT UNSIGNED NOT NULL FK -> employees
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
institution         VARCHAR(255) NOT NULL
degree              VARCHAR(100) NOT NULL
field_of_study      VARCHAR(255) NULL
start_date          DATE NULL
end_date            DATE NULL
is_current          BOOLEAN NOT NULL DEFAULT false
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_employee (employee_id)
```

### employee_transitions
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
employee_id         BIGINT UNSIGNED NOT NULL FK -> employees
from_status         VARCHAR(30) NOT NULL
to_status           VARCHAR(30) NOT NULL
reason              TEXT NULL
effective_date      DATE NOT NULL
approved_by         BIGINT UNSIGNED NULL FK -> users
metadata            JSON NULL                        -- promotion details, transfer details, etc.
created_at          TIMESTAMP

INDEX idx_employee (employee_id)
INDEX idx_tenant_date (tenant_id, effective_date)
```

---

## Attendance Tables

### attendance_records
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
employee_id         BIGINT UNSIGNED NOT NULL FK -> employees
branch_id           BIGINT UNSIGNED NULL FK -> branches
shift_id            BIGINT UNSIGNED NULL FK -> shifts
source              VARCHAR(30) NOT NULL             -- biometric, mobile, offline_mobile, qr, web, kiosk, manual, csv, api
check_in            TIMESTAMP NULL
check_out           TIMESTAMP NULL
status              VARCHAR(20) NOT NULL DEFAULT 'present' -- present, absent, late, early_leave, half_day
confidence_score    TINYINT UNSIGNED NOT NULL DEFAULT 100  -- 0-100
latitude            DECIMAL(10,7) NULL
longitude           DECIMAL(10,7) NULL
geofence_matched    BOOLEAN NULL
device_id           BIGINT UNSIGNED NULL FK -> devices
device_identifier   VARCHAR(255) NULL                -- phone device ID for mobile
selfie_path         VARCHAR(500) NULL
offline_token       VARCHAR(255) NULL                -- HMAC for offline records
offline_hash        VARCHAR(64) NULL                 -- integrity hash
synced_at           TIMESTAMP NULL                   -- when offline record was synced
worked_minutes      INT NULL                         -- calculated
overtime_minutes    INT NULL                         -- calculated
notes               TEXT NULL
is_corrected        BOOLEAN NOT NULL DEFAULT false
idempotency_key     VARCHAR(255) NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP

UNIQUE idx_idempotency (tenant_id, idempotency_key)
INDEX idx_tenant_employee_date (tenant_id, employee_id, check_in)
INDEX idx_tenant_date (tenant_id, check_in)
INDEX idx_tenant_source (tenant_id, source)
INDEX idx_tenant_status (tenant_id, status)
INDEX idx_shift (shift_id)
INDEX idx_device (device_id)
```

### attendance_corrections
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
attendance_id       BIGINT UNSIGNED NOT NULL FK -> attendance_records
employee_id         BIGINT UNSIGNED NOT NULL FK -> employees
requested_by        BIGINT UNSIGNED NOT NULL FK -> users
correction_type     VARCHAR(30) NOT NULL             -- check_in, check_out, status, add_missing
original_value      JSON NOT NULL                    -- { field: old_value }
corrected_value     JSON NOT NULL                    -- { field: new_value }
reason              TEXT NOT NULL
attachment_path     VARCHAR(500) NULL
status              VARCHAR(20) NOT NULL DEFAULT 'pending' -- pending, approved, rejected
current_approver_id BIGINT UNSIGNED NULL FK -> users
approved_by         JSON NULL                        -- array of { user_id, role, action, at }
rejected_by         BIGINT UNSIGNED NULL FK -> users
rejected_reason     TEXT NULL
payroll_impact      JSON NULL                        -- preview of payroll change
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_tenant_status (tenant_id, status)
INDEX idx_employee (employee_id)
INDEX idx_current_approver (current_approver_id)
```

---

## Shift Tables

### shifts
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
name                VARCHAR(100) NOT NULL
code                VARCHAR(30) NULL
start_time          TIME NOT NULL
end_time            TIME NOT NULL
break_minutes       INT NOT NULL DEFAULT 0
grace_minutes       INT NOT NULL DEFAULT 0           -- late grace period
early_leave_minutes INT NOT NULL DEFAULT 0           -- early leave threshold
is_night_shift      BOOLEAN NOT NULL DEFAULT false
is_active           BOOLEAN NOT NULL DEFAULT true
color               VARCHAR(7) NULL                  -- hex color for UI
created_at          TIMESTAMP
updated_at          TIMESTAMP

UNIQUE idx_tenant_code (tenant_id, code)
```

### shift_assignments
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
shift_id            BIGINT UNSIGNED NOT NULL FK -> shifts
assignable_type     VARCHAR(100) NOT NULL            -- employee, department, branch
assignable_id       BIGINT UNSIGNED NOT NULL
start_date          DATE NOT NULL
end_date            DATE NULL                        -- null = ongoing
rotation_pattern    VARCHAR(30) NULL                 -- weekly, biweekly, monthly
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_assignable (assignable_type, assignable_id)
INDEX idx_tenant_dates (tenant_id, start_date, end_date)
```

---

## Device Tables

### devices
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
branch_id           BIGINT UNSIGNED NULL FK -> branches
name                VARCHAR(255) NOT NULL
adapter_type        VARCHAR(30) NOT NULL             -- hikvision, zkteco
ip_address          VARCHAR(45) NULL
port                INT NULL
serial_number       VARCHAR(100) NULL
username            TEXT NULL                         -- encrypted
password            TEXT NULL                         -- encrypted
webhook_secret      VARCHAR(255) NULL
status              VARCHAR(20) NOT NULL DEFAULT 'offline' -- online, offline, error
last_heartbeat_at   TIMESTAMP NULL
last_sync_at        TIMESTAMP NULL
config              JSON NULL                        -- adapter-specific config
created_at          TIMESTAMP
updated_at          TIMESTAMP
deleted_at          TIMESTAMP NULL

INDEX idx_tenant_status (tenant_id, status)
INDEX idx_tenant_branch (tenant_id, branch_id)
```

---

## Leave Tables

### leave_types
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
name                VARCHAR(100) NOT NULL
code                VARCHAR(30) NOT NULL
default_days        DECIMAL(5,1) NOT NULL            -- allow half days
accrual_type        VARCHAR(20) NOT NULL             -- monthly, annual, immediate, one_time
carry_forward       BOOLEAN NOT NULL DEFAULT false
max_carry_days      DECIMAL(5,1) NULL
requires_approval   BOOLEAN NOT NULL DEFAULT true
requires_attachment BOOLEAN NOT NULL DEFAULT false   -- e.g. sick leave
min_notice_days     INT NOT NULL DEFAULT 0
max_consecutive     INT NULL
is_paid             BOOLEAN NOT NULL DEFAULT true
is_active           BOOLEAN NOT NULL DEFAULT true
gender_restriction  VARCHAR(10) NULL                 -- male, female, null=any
sort_order          INT NOT NULL DEFAULT 0
created_at          TIMESTAMP
updated_at          TIMESTAMP

UNIQUE idx_tenant_code (tenant_id, code)
```

### leave_balances
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
employee_id         BIGINT UNSIGNED NOT NULL FK -> employees
leave_type_id       BIGINT UNSIGNED NOT NULL FK -> leave_types
year                INT NOT NULL                     -- Ethiopian or Gregorian year
entitled_days       DECIMAL(5,1) NOT NULL DEFAULT 0
used_days           DECIMAL(5,1) NOT NULL DEFAULT 0
carried_days        DECIMAL(5,1) NOT NULL DEFAULT 0
pending_days        DECIMAL(5,1) NOT NULL DEFAULT 0  -- in pending requests
remaining_days      DECIMAL(5,1) GENERATED ALWAYS AS (entitled_days + carried_days - used_days - pending_days)
created_at          TIMESTAMP
updated_at          TIMESTAMP

UNIQUE idx_employee_type_year (tenant_id, employee_id, leave_type_id, year)
```

### leave_requests
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
employee_id         BIGINT UNSIGNED NOT NULL FK -> employees
leave_type_id       BIGINT UNSIGNED NOT NULL FK -> leave_types
start_date          DATE NOT NULL
end_date            DATE NOT NULL
days                DECIMAL(5,1) NOT NULL            -- calculated (excluding holidays/weekends)
reason              TEXT NULL
attachment_path     VARCHAR(500) NULL
status              VARCHAR(20) NOT NULL DEFAULT 'pending' -- pending, approved, rejected, cancelled
current_approver_id BIGINT UNSIGNED NULL FK -> users
approved_by         JSON NULL                        -- approval chain history
rejected_by         BIGINT UNSIGNED NULL FK -> users
rejected_reason     TEXT NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_tenant_status (tenant_id, status)
INDEX idx_employee (employee_id)
INDEX idx_tenant_dates (tenant_id, start_date, end_date)
INDEX idx_current_approver (current_approver_id)
```

---

## Payroll Tables

### payroll_runs
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
period_start        DATE NOT NULL
period_end          DATE NOT NULL
status              VARCHAR(20) NOT NULL DEFAULT 'draft' -- draft, processing, completed, approved, paid
total_gross_cents   BIGINT NOT NULL DEFAULT 0
total_deductions_cents BIGINT NOT NULL DEFAULT 0
total_net_cents     BIGINT NOT NULL DEFAULT 0
employee_count      INT NOT NULL DEFAULT 0
processed_by        BIGINT UNSIGNED NULL FK -> users
approved_by         BIGINT UNSIGNED NULL FK -> users
processed_at        TIMESTAMP NULL
approved_at         TIMESTAMP NULL
notes               TEXT NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_tenant_period (tenant_id, period_start)
INDEX idx_tenant_status (tenant_id, status)
```

### payroll_entries
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
payroll_run_id      BIGINT UNSIGNED NOT NULL FK -> payroll_runs
employee_id         BIGINT UNSIGNED NOT NULL FK -> employees
basic_salary_cents  BIGINT NOT NULL
worked_days         DECIMAL(5,1) NOT NULL
overtime_hours      DECIMAL(5,1) NOT NULL DEFAULT 0
overtime_amount_cents BIGINT NOT NULL DEFAULT 0
allowances          JSON NOT NULL DEFAULT '[]'       -- [{ type, amount_cents }]
total_allowances_cents BIGINT NOT NULL DEFAULT 0
gross_salary_cents  BIGINT NOT NULL
deductions          JSON NOT NULL DEFAULT '[]'       -- [{ type, amount_cents }]
income_tax_cents    BIGINT NOT NULL DEFAULT 0
pension_employee_cents BIGINT NOT NULL DEFAULT 0
pension_employer_cents BIGINT NOT NULL DEFAULT 0
total_deductions_cents BIGINT NOT NULL DEFAULT 0
net_salary_cents    BIGINT NOT NULL
loan_deduction_cents BIGINT NOT NULL DEFAULT 0
payslip_path        VARCHAR(500) NULL                -- MinIO path to PDF
created_at          TIMESTAMP
updated_at          TIMESTAMP

UNIQUE idx_run_employee (payroll_run_id, employee_id)
INDEX idx_employee (employee_id)
INDEX idx_tenant_run (tenant_id, payroll_run_id)
```

### payroll_rules
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
category            VARCHAR(30) NOT NULL             -- tax, pension, overtime, allowance, deduction
name                VARCHAR(100) NOT NULL
code                VARCHAR(50) NOT NULL
formula             JSON NOT NULL                    -- rule definition
is_active           BOOLEAN NOT NULL DEFAULT true
effective_from      DATE NOT NULL
effective_to        DATE NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP

UNIQUE idx_tenant_code (tenant_id, code)
INDEX idx_tenant_category (tenant_id, category)
```

### tax_brackets
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
min_amount_cents    BIGINT NOT NULL
max_amount_cents    BIGINT NULL                      -- null = no upper limit
rate_percent        DECIMAL(5,2) NOT NULL
deduction_cents     BIGINT NOT NULL DEFAULT 0
effective_from      DATE NOT NULL
effective_to        DATE NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_tenant_effective (tenant_id, effective_from)
```

### employee_loans
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
employee_id         BIGINT UNSIGNED NOT NULL FK -> employees
amount_cents        BIGINT NOT NULL
remaining_cents     BIGINT NOT NULL
monthly_deduction_cents BIGINT NOT NULL
reason              TEXT NULL
approved_by         BIGINT UNSIGNED NULL FK -> users
status              VARCHAR(20) NOT NULL DEFAULT 'active' -- active, completed, cancelled
started_at          DATE NOT NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_employee_status (employee_id, status)
INDEX idx_tenant (tenant_id)
```

---

## Notification Tables

### notifications
```sql
id                  CHAR(36) PK                      -- UUID (Laravel default)
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
type                VARCHAR(255) NOT NULL            -- Notification class name
notifiable_type     VARCHAR(255) NOT NULL
notifiable_id       BIGINT UNSIGNED NOT NULL
data                JSON NOT NULL
read_at             TIMESTAMP NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_notifiable (notifiable_type, notifiable_id)
INDEX idx_tenant_read (tenant_id, read_at)
```

### announcement
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
title               VARCHAR(255) NOT NULL
body                TEXT NOT NULL
priority            VARCHAR(10) NOT NULL DEFAULT 'normal' -- low, normal, high, urgent
target_type         VARCHAR(30) NOT NULL DEFAULT 'all'    -- all, department, branch, role
target_id           BIGINT UNSIGNED NULL
published_by        BIGINT UNSIGNED NOT NULL FK -> users
published_at        TIMESTAMP NULL
expires_at          TIMESTAMP NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_tenant_published (tenant_id, published_at)
```

---

## Holiday Tables

### holidays
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
name                VARCHAR(255) NOT NULL
name_am             VARCHAR(255) NULL
date                DATE NOT NULL
is_recurring        BOOLEAN NOT NULL DEFAULT false   -- same date every year
is_ethiopian_calendar BOOLEAN NOT NULL DEFAULT false  -- date is in Ethiopian calendar
is_national         BOOLEAN NOT NULL DEFAULT true
branch_id           BIGINT UNSIGNED NULL FK -> branches  -- null = all branches
source              VARCHAR(20) NOT NULL DEFAULT 'manual' -- manual, auto_detected
created_at          TIMESTAMP
updated_at          TIMESTAMP

INDEX idx_tenant_date (tenant_id, date)
INDEX idx_tenant_year (tenant_id, YEAR(date))
```

---

## Feature Flag Tables

### feature_flags
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
tenant_id           BIGINT UNSIGNED NULL FK -> tenants  -- null = global
key                 VARCHAR(100) NOT NULL
enabled             BOOLEAN NOT NULL DEFAULT false
created_at          TIMESTAMP
updated_at          TIMESTAMP

UNIQUE idx_tenant_key (tenant_id, key)
```

---

## Organization Template Tables

### organization_templates
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
public_id           CHAR(26) UNIQUE NOT NULL
name                VARCHAR(100) NOT NULL
slug                VARCHAR(100) UNIQUE NOT NULL
description         TEXT NULL
org_type            VARCHAR(50) NOT NULL             -- government, bank, hospital, etc.
template_data       JSON NOT NULL                    -- full template configuration
is_active           BOOLEAN NOT NULL DEFAULT true
sort_order          INT NOT NULL DEFAULT 0
created_at          TIMESTAMP
updated_at          TIMESTAMP

-- Global table (no tenant_id)
```

### onboarding_progress
```sql
id                  BIGINT UNSIGNED AUTO_INCREMENT PK
tenant_id           BIGINT UNSIGNED NOT NULL FK -> tenants
step                VARCHAR(50) NOT NULL             -- OnboardingStep enum
payload             JSON NULL
completed           BOOLEAN NOT NULL DEFAULT false
completed_at        TIMESTAMP NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP

UNIQUE idx_tenant_step (tenant_id, step)
```

---

## Indexes Strategy

All tenant-scoped queries benefit from composite indexes starting with `tenant_id`:

```
(tenant_id, status)           -- filtered lists
(tenant_id, created_at)       -- chronological queries
(tenant_id, employee_id)      -- employee-scoped lookups
(tenant_id, department_id)    -- department-scoped lookups
(tenant_id, check_in)         -- attendance date ranges
(tenant_id, period_start)     -- payroll period lookups
```

Full-text search indexes (MariaDB built-in):
```sql
ALTER TABLE employees ADD FULLTEXT idx_search (name, name_am, email, employee_code);
```

---

## Data Integrity Rules

1. `tenant_id` is NOT NULL on all scoped tables (enforced at migration level)
2. `public_id` is UNIQUE and NOT NULL (enforced at migration level)
3. Currency fields end with `_cents` and are `BIGINT` (never decimal)
4. `deleted_at` enables soft deletes — no hard deletes on business entities
5. `audit_log` is append-only — no UPDATE or DELETE operations
6. Employee `salary_cents` and bank `account_number` are encrypted at rest
7. Cross-tenant foreign keys are impossible (BelongsToTenant scope prevents it)
8. Idempotency keys prevent duplicate writes on attendance and imports
